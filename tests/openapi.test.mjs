import assert from 'node:assert/strict';
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parse, stringify } from 'yaml';

const implementation = new URL('../scripts/openapi.mjs', import.meta.url);

async function tools() {
  assert.ok(existsSync(implementation), '尚未实现手写 YAML 契约的只读校验入口');
  return import(implementation);
}

function fixture(t) {
  const root = mkdtempSync(path.join(tmpdir(), 'exre-api-contract-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  const write = (name, value) => {
    const filename = path.join(root, name);
    mkdirSync(path.dirname(filename), { recursive: true });
    writeFileSync(
      filename,
      typeof value === 'string' ? value : stringify(value)
    );
  };
  for (const audience of ['public', 'internal']) {
    write(`${audience}/openapi.yaml`, {
      openapi: '3.0.3',
      info: { title: audience, version: '1.0.0' },
      paths: { [`/api/${audience}`]: { $ref: './paths/sample.yaml#/item' } },
    });
    write(`${audience}/paths/sample.yaml`, {
      item: {
        get: {
          operationId: `${audience}Read`,
          'x-audience': audience,
          responses: { 200: { description: '成功' } },
        },
      },
    });
  }
  const update = (audience, operation) =>
    write(`${audience}/paths/sample.yaml`, { item: { get: operation } });
  return { root, write, update };
}

const operation = (overrides = {}) => ({
  operationId: 'publicRead',
  'x-audience': 'public',
  responses: { 200: { description: '成功' } },
  ...overrides,
});

for (const kind of ['file URI', 'absolute path']) {
  test(`拒绝同一契约目录中的 ${kind} 引用，保证分发后仍可解析`, async (t) => {
    const f = fixture(t);
    f.write('public/paths/portable.yaml', { item: { get: operation() } });
    const filename = path.join(f.root, 'public/paths/portable.yaml');
    const reference =
      kind === 'file URI'
        ? pathToFileURL(filename).href
        : filename.replaceAll('\\', '/');
    f.write('public/paths/sample.yaml', {
      item: { $ref: `${reference}#/item` },
    });
    const { loadContracts } = await tools();
    await assert.rejects(loadContracts(f.root), /相对引用/);
  });
}

test('直接读取模块化 YAML，校验不改写源文件或生成其他文件', async (t) => {
  const f = fixture(t);
  const before = readFileSync(path.join(f.root, 'public/openapi.yaml'), 'utf8');
  const { loadContracts, checkRoutes } = await tools();
  const contracts = await loadContracts(f.root);
  checkRoutes(contracts, {
    routes: ['GET /api/public', 'GET /api/internal'],
    publicOperations: ['GET /api/public'],
  });
  assert.equal(contracts.operations.size, 2);
  assert.equal(
    readFileSync(path.join(f.root, 'public/openapi.yaml'), 'utf8'),
    before
  );
  assert.equal(existsSync(path.join(f.root, 'generated')), false);
  assert.equal(existsSync(path.join(f.root, '.openapi-manifest.json')), false);
});

test('公开目录可单独分发和解析', async (t) => {
  const f = fixture(t);
  rmSync(path.join(f.root, 'internal'), { recursive: true });
  const { readContract } = await tools();
  const document = await readContract(path.join(f.root, 'public/openapi.yaml'));
  assert.equal(document.paths['/api/public'].get.operationId, 'publicRead');
});

test('真实账号 schema 可独立解析，且与入口命名导出一致', async (t) => {
  const f = fixture(t);
  const source = readFileSync(
    new URL('../api/internal/schemas/account.yaml', import.meta.url),
    'utf8'
  );
  const names = Object.keys(parse(source));
  f.write('internal/schemas/account.yaml', source);
  f.write('internal/openapi.yaml', {
    openapi: '3.0.3',
    info: { title: '独立账号 schema', version: '1.0.0' },
    paths: {},
    components: {
      // 使用消费者自己的导出名，验证模块不依赖原入口的 schema catalog。
      schemas: Object.fromEntries(
        names.map((name) => [
          `Local${name}`,
          { $ref: `./schemas/account.yaml#/${name}` },
        ])
      ),
    },
  });
  const { readContract } = await tools();
  const isolated = await readContract(
    path.join(f.root, 'internal/openapi.yaml')
  );
  const document = await readContract(
    fileURLToPath(new URL('../api/internal/openapi.yaml', import.meta.url))
  );
  for (const name of names) {
    assert.deepEqual(
      isolated.components.schemas[`Local${name}`],
      document.components.schemas[name],
      name
    );
  }
});

test('真实账号 paths 无需根 schema catalog，解析结果与完整契约一致', async (t) => {
  const f = fixture(t);
  const entry = new URL('../api/internal/openapi.yaml', import.meta.url);
  const source = parse(readFileSync(entry, 'utf8'));
  const paths = Object.fromEntries(
    Object.entries(source.paths).filter(([, item]) =>
      item.$ref?.startsWith('./paths/account.yaml#/')
    )
  );
  assert.ok(Object.keys(paths).length > 0, '真实入口应包含账号路径');
  const { readContract } = await tools();
  const document = await readContract(fileURLToPath(entry));
  for (const filename of [
    'paths/account.yaml',
    'schemas/account.yaml',
    'schemas/common.yaml',
  ]) {
    f.write(
      `internal/${filename}`,
      readFileSync(new URL(filename, entry), 'utf8')
    );
  }
  f.write('internal/openapi.yaml', {
    openapi: source.openapi,
    info: source.info,
    paths,
    components: {
      // 复用已解析的公共响应和鉴权，独立验证 paths 的 schema 依赖。
      responses: document.components.responses,
      securitySchemes: document.components.securitySchemes,
    },
  });
  const isolated = await readContract(
    path.join(f.root, 'internal/openapi.yaml')
  );
  assert.deepEqual(
    isolated.paths,
    Object.fromEntries(
      Object.keys(paths).map((url) => [url, document.paths[url]])
    )
  );
});

for (const [name, change, expected] of [
  [
    '缺失引用',
    (f) =>
      f.write('public/paths/sample.yaml', { item: { $ref: './missing.yaml' } }),
    /missing|ENOENT|Error opening/,
  ],
  [
    '不存在的引用片段',
    (f) => f.write('public/paths/sample.yaml', { item: { $ref: '#/absent' } }),
    /absent|token|pointer/i,
  ],
  [
    '非法 OpenAPI 响应',
    (f) => f.update('public', operation({ responses: { 200: {} } })),
    /description|schema|valid/i,
  ],
  [
    '重复 YAML 键',
    (f) => f.write('public/paths/sample.yaml', 'item: {}\nitem: {}\n'),
    /unique|duplicate/i,
  ],
  [
    '缺失 operationId',
    (f) => f.update('public', operation({ operationId: undefined })),
    /operationId/,
  ],
  [
    '跨版本重复 operationId',
    (f) => f.update('internal', operation({ 'x-audience': 'internal' })),
    /operationId/,
  ],
  [
    '错误接口归属',
    (f) => f.update('public', operation({ 'x-audience': 'internal' })),
    /x-audience/,
  ],
  [
    '未定义鉴权方案',
    (f) => f.update('public', operation({ security: [{ missingAuth: [] }] })),
    /security|鉴权/,
  ],
  [
    '缺失路径参数',
    (f) =>
      f.write('public/openapi.yaml', {
        openapi: '3.0.3',
        info: { title: 'test', version: '1' },
        paths: { '/api/{id}': { get: operation() } },
      }),
    /路径参数/,
  ],
  [
    '跨目录引用',
    (f) =>
      f.write('public/paths/sample.yaml', {
        item: { $ref: '../../internal/paths/sample.yaml#/item' },
      }),
    /目录|范围/,
  ],
  [
    '远程引用',
    (f) =>
      f.write('public/paths/sample.yaml', {
        item: { $ref: 'https://example.invalid/schema.yaml' },
      }),
    /resolve|resolver|读取|引用/i,
  ],
]) {
  test(`拒绝${name}`, async (t) => {
    const f = fixture(t);
    change(f);
    const { loadContracts } = await tools();
    await assert.rejects(loadContracts(f.root), expected);
  });
}

test('核对实际路由、重复注册、公开发布策略和多余接口', async (t) => {
  const f = fixture(t);
  const { loadContracts, checkRoutes } = await tools();
  const contracts = await loadContracts(f.root);
  const valid = {
    routes: ['GET /api/public', 'GET /api/internal'],
    publicOperations: ['GET /api/public'],
  };
  assert.throws(
    () =>
      checkRoutes(contracts, {
        ...valid,
        routes: [...valid.routes, 'POST /api/new'],
      }),
    /缺少路由/
  );
  assert.throws(
    () =>
      checkRoutes(contracts, {
        ...valid,
        routes: [...valid.routes, valid.routes[0]],
      }),
    /重复注册/
  );
  assert.throws(
    () => checkRoutes(contracts, { ...valid, routes: [valid.routes[0]] }),
    /多余路由/
  );
  assert.throws(
    () => checkRoutes(contracts, { ...valid, publicOperations: [] }),
    /公开接口/
  );
});
