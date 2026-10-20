import SwaggerParser from '@apidevtools/swagger-parser';
import { spawnSync } from 'node:child_process';
import { readFileSync, realpathSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parse } from 'yaml';

const root = fileURLToPath(new URL('../', import.meta.url));
const methods = [
  'get',
  'post',
  'put',
  'patch',
  'delete',
  'head',
  'options',
  'trace',
];

function checkReferences(value, seen = new WeakSet()) {
  if (!value || typeof value !== 'object' || seen.has(value)) return value;
  seen.add(value);
  const reference = value.$ref;
  if (
    typeof reference === 'string' &&
    (/^(?:[a-z][a-z0-9+.-]*:|[\\/])/i.test(reference) ||
      reference.includes('\\'))
  ) {
    throw new Error(`契约必须使用相对引用: ${reference}`);
  }
  for (const child of Object.values(value)) checkReferences(child, seen);
  return value;
}

/** 每份契约的引用仅从所属目录读取，保证公开目录可独立分发。 */
export async function readContract(entry) {
  const directory = realpathSync(path.dirname(entry));
  return SwaggerParser.validate(entry, {
    resolve: {
      http: false,
      file: {
        read(file) {
          const filename = realpathSync(
            file.url.startsWith('file:')
              ? fileURLToPath(file.url)
              : decodeURIComponent(file.url)
          );
          const relative = path.relative(directory, filename);
          if (
            relative.startsWith(`..${path.sep}`) ||
            relative === '..' ||
            path.isAbsolute(relative)
          ) {
            throw new Error(`引用超出契约目录范围: ${file.url}`);
          }
          return readFileSync(filename, 'utf8');
        },
      },
    },
    parse: {
      json: {
        parse: (file) => checkReferences(JSON.parse(file.data.toString())),
      },
      yaml: {
        parse: (file) =>
          checkReferences(parse(file.data.toString(), { uniqueKeys: true })),
      },
    },
  });
}

function checkSecurity(document, security, key) {
  for (const requirement of security ?? []) {
    for (const name of Object.keys(requirement)) {
      if (!Object.hasOwn(document.components?.securitySchemes ?? {}, name)) {
        throw new Error(`${key} 引用了未定义的鉴权 security: ${name}`);
      }
    }
  }
}

/** 标准规范检查之后，补充项目的归属、标识和接口语义约束。 */
export async function loadContracts(apiRoot = path.join(root, 'api')) {
  const operations = new Map();
  const ids = new Set();
  for (const audience of ['public', 'internal']) {
    const document = await readContract(
      path.join(apiRoot, audience, 'openapi.yaml')
    );
    checkSecurity(document, document.security, audience);
    for (const [url, item] of Object.entries(document.paths)) {
      for (const method of methods) {
        const operation = item[method];
        if (!operation) continue;
        const key = `${method.toUpperCase()} ${url}`;
        if (operations.has(key)) throw new Error(`重复接口: ${key}`);
        if (operation['x-audience'] !== audience) {
          throw new Error(`${key} 的 x-audience 必须为 ${audience}`);
        }
        const id = operation.operationId;
        if (typeof id !== 'string' || !id.trim() || ids.has(id)) {
          throw new Error(`${key} 缺少或重复 operationId: ${id ?? ''}`);
        }
        ids.add(id);
        checkSecurity(document, operation.security, key);
        const parameters = [
          ...(item.parameters ?? []),
          ...(operation.parameters ?? []),
        ];
        const required = [...url.matchAll(/\{([^{}]+)\}/g)].map(
          (match) => match[1]
        );
        for (const name of required) {
          if (
            !parameters.some(
              (p) => p.in === 'path' && p.name === name && p.required === true
            )
          ) {
            throw new Error(`${key} 缺少必填路径参数: ${name}`);
          }
        }
        for (const parameter of parameters) {
          if (parameter.in === 'path' && !required.includes(parameter.name)) {
            throw new Error(`${key} 存在多余路径参数: ${parameter.name}`);
          }
        }
        operations.set(key, audience);
      }
    }
  }
  return { operations };
}

export function checkRoutes({ operations }, { routes, publicOperations }) {
  const actual = new Set();
  for (const key of routes) {
    if (actual.has(key)) throw new Error(`重复注册路由: ${key}`);
    actual.add(key);
  }
  const missing = [...actual].filter((key) => !operations.has(key));
  const extra = [...operations.keys()].filter((key) => !actual.has(key));
  if (missing.length)
    throw new Error(`OpenAPI 缺少路由: ${missing.join(', ')}`);
  if (extra.length) throw new Error(`OpenAPI 多余路由: ${extra.join(', ')}`);
  const published = [...operations]
    .filter(([, audience]) => audience === 'public')
    .map(([key]) => key)
    .sort();
  if (
    JSON.stringify(published) !== JSON.stringify([...publicOperations].sort())
  ) {
    throw new Error('公开接口归属与 config/openapi.php 发布策略不一致');
  }
}

function routeManifest() {
  const result = spawnSync(
    process.env.PHP_BINARY || 'php',
    [path.join(root, 'scripts/openapi-routes.php')],
    {
      cwd: root,
      encoding: 'utf8',
      windowsHide: true,
      maxBuffer: 4 * 1024 * 1024,
    }
  );
  if (result.error) throw result.error;
  if (result.status !== 0)
    throw new Error(result.stderr || result.stdout || '读取 Webman 路由失败');
  return JSON.parse(result.stdout);
}

async function main() {
  const mode = process.argv[2];
  if (process.argv.length !== 3 || !['lint', 'check'].includes(mode)) {
    throw new Error('用法: node scripts/openapi.mjs lint|check');
  }
  const contracts = await loadContracts();
  if (mode === 'check') checkRoutes(contracts, routeManifest());
  console.log(
    `OpenAPI ${mode} 通过（${contracts.operations.size} 个接口，只读）`
  );
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href
) {
  main().catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
  });
}
