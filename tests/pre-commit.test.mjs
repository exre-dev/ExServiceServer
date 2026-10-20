import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import {
  copyFileSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = fileURLToPath(new URL('../', import.meta.url));
const manifest = 'api/.openapi-manifest.json';
const output = 'api/generated/openapi.public.yaml';
const obsolete = 'api/public/paths/obsolete.yaml';
const before =
  "<?php\n\nclass SampleController\n{\n  public function value(): string\n  {\n    return 'before';\n  }\n}\n";

function fixture(t) {
  const directory = mkdtempSync(path.join(tmpdir(), 'exre-pre-commit-'));
  t.after(() => rmSync(directory, { recursive: true, force: true }));
  const write = (name, content) => {
    const target = path.join(directory, name);
    mkdirSync(path.dirname(target), { recursive: true });
    writeFileSync(target, content);
  };
  const env = {
    ...process.env,
    GIT_AUTHOR_NAME: 'Hook Test',
    GIT_AUTHOR_EMAIL: 'hook-test@example.invalid',
    GIT_COMMITTER_NAME: 'Hook Test',
    GIT_COMMITTER_EMAIL: 'hook-test@example.invalid',
    GIT_CONFIG_NOSYSTEM: '1',
    HUSKY: '1',
    HUSKY_TEST_COMPOSER: path.join(directory, 'fixture-composer.mjs'),
    PATH: `${path.join(directory, 'fixture-bin')}${path.delimiter}${process.env.PATH}`,
  };
  // 测试不继承宿主提交的索引、工作树或配置覆盖。
  for (const key of Object.keys(env)) {
    if (
      /^GIT_(?:DIR|WORK_TREE|INDEX_FILE|COMMON_DIR|CONFIG_COUNT|CONFIG_KEY_\d+|CONFIG_VALUE_\d+)$/.test(
        key
      )
    ) {
      delete env[key];
    }
  }
  const run = (command, args, overrides = {}) =>
    spawnSync(command, args, {
      cwd: directory,
      env: { ...env, ...overrides },
      encoding: 'utf8',
      windowsHide: true,
    });
  const git = (...args) => {
    const result = run('git', args);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout.trim();
  };

  git('init', '--quiet');
  git('config', 'core.autocrlf', 'false');
  git('config', 'commit.gpgsign', 'false');
  for (const name of [
    '.husky/pre-commit',
    '.lintstagedrc.json',
    '.prettierrc.json',
    '.prettierignore',
    'scripts/pre-commit.mjs',
  ]) {
    mkdirSync(path.dirname(path.join(directory, name)), { recursive: true });
    copyFileSync(path.join(root, name), path.join(directory, name));
  }
  symlinkSync(
    path.join(root, 'node_modules'),
    path.join(directory, 'node_modules'),
    'junction'
  );
  write('.gitignore', '/node_modules\n/fixture-bin\n/fixture-composer.mjs\n');
  write('composer.json', '{"require":{"php":">=8.1"}}\n');
  write('app/SampleController.php', before);
  write(output, 'before\n');
  write(obsolete, 'obsolete\n');
  write(
    manifest,
    JSON.stringify({
      version: 1,
      files: ['generated/openapi.public.yaml', 'public/paths/obsolete.yaml'],
    })
  );
  write('api/notes.md', 'original notes\n');
  write(
    'web/partial.ts',
    Array.from(
      { length: 12 },
      (_, index) => `export const value${index} = 'before';`
    ).join('\n') + '\n'
  );
  git('add', '--all');
  git('-c', 'core.hooksPath=', 'commit', '--quiet', '-m', 'fixture baseline');

  write(
    'fixture-bin/composer',
    '#!/bin/sh\nnode "$HUSKY_TEST_COMPOSER" "$@"\n'
  );
  write(
    'fixture-composer.mjs',
    `
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
const mode = process.argv[2];
if (process.env.HUSKY_TEST_FAIL === mode) process.exit(12);
if (mode === 'api:generate') {
  const source = readFileSync('app/SampleController.php', 'utf8');
  mkdirSync('api/generated', { recursive: true });
  writeFileSync('${output}', 'source: ' + JSON.stringify(source) + '\\n');
  rmSync('${obsolete}', { force: true });
  writeFileSync('${manifest}', JSON.stringify({ version: 1, files: [
    process.env.HUSKY_TEST_UNSAFE ? '../outside.ts' : 'generated/openapi.public.yaml'
  ] }));
}
if (mode === 'api:lint' && readFileSync('${output}', 'utf8') === 'before\\n') process.exit(13);
if (mode === 'api:check' && !readFileSync('${output}', 'utf8').includes("return 'after';")) process.exit(14);
`
  );
  const install = run(process.execPath, [
    path.join(root, 'node_modules/husky/bin.js'),
  ]);
  assert.equal(install.status, 0, install.stderr || install.stdout);
  return { directory, write, run, git, head: git('rev-parse', 'HEAD') };
}

test('提交前格式化暂存源码，生成并提交受管产物，保留其他未暂存内容', (t) => {
  const f = fixture(t);
  f.write('app/SampleController.php', before.replace("'before'", '"after"'));
  const files = {
    'web/space name.js': 'export const value="after"',
    'web/sample.ts': 'export const value:string="after"',
    'web/sample.jsx': 'export const value=<div>after</div>',
    'web/sample.tsx': 'export const value=<div>after</div>',
    'web/sample.mjs': 'export const value="after"',
    'web/sample.cjs': 'module.exports={value:"after"}',
  };
  for (const [name, source] of Object.entries(files)) f.write(name, source);
  const partial = readFileSync(
    path.join(f.directory, 'web/partial.ts'),
    'utf8'
  );
  f.write(
    'web/partial.ts',
    partial.replace("value0 = 'before';", 'value0="after"')
  );
  f.git('add', '--', 'app/SampleController.php', 'web');
  f.write(
    'web/partial.ts',
    partial
      .replace("value0 = 'before';", 'value0="after"')
      .replace("value11 = 'before';", 'value11="scratch"')
  );
  f.write('api/notes.md', 'unstaged notes\n');

  const result = f.run('git', ['commit', '--quiet', '-m', 'exercise hook']);
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.notEqual(f.git('rev-parse', 'HEAD'), f.head);
  assert.match(
    f.git('show', 'HEAD:app/SampleController.php'),
    /return 'after';/
  );
  assert.match(f.git('show', `HEAD:${output}`), /return 'after';/);
  assert.equal(f.git('ls-files', '--', obsolete), '');
  assert.equal(existsSync(path.join(f.directory, obsolete)), false);
  assert.equal(f.git('show', 'HEAD:api/notes.md'), 'original notes');
  assert.equal(
    readFileSync(path.join(f.directory, 'api/notes.md'), 'utf8'),
    'unstaged notes\n'
  );
  for (const name of Object.keys(files)) {
    assert.ok(f.git('show', `HEAD:${name}`).endsWith(';'), name);
  }
  assert.match(f.git('show', 'HEAD:web/partial.ts'), /value0 = 'after';/);
  assert.match(f.git('show', 'HEAD:web/partial.ts'), /value11 = 'before';/);
  assert.match(
    readFileSync(path.join(f.directory, 'web/partial.ts'), 'utf8'),
    /value11="scratch"/
  );
});

for (const kind of ['tracked', 'untracked', 'partially staged']) {
  test(`接口定义存在 ${kind} 未暂存修改时阻止提交并保留索引`, (t) => {
    const f = fixture(t);
    f.write('web/change.js', 'export const value="after"');
    f.git('add', '--', 'web/change.js');
    if (kind === 'partially staged') {
      f.write('app/SampleController.php', before.replace('before', 'staged'));
      f.git('add', '--', 'app/SampleController.php');
    }
    f.write(
      kind === 'untracked'
        ? 'app/NewController.php'
        : 'app/SampleController.php',
      before.replace('before', 'scratch')
    );
    const index = f.git('write-tree');

    const result = f.run('git', ['commit', '--quiet', '-m', 'must fail']);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr + result.stdout, /未暂存/);
    assert.equal(f.git('write-tree'), index);
    assert.equal(f.git('rev-parse', 'HEAD'), f.head);
    assert.equal(
      readFileSync(path.join(f.directory, output), 'utf8'),
      'before\n'
    );
  });
}

test('格式化解析失败时阻止提交，不生成或暂存契约', (t) => {
  const f = fixture(t);
  f.write('app/SampleController.php', before.replace('before', 'after'));
  f.write('web/broken.ts', 'export const = ;');
  f.git('add', '--', 'app/SampleController.php', 'web/broken.ts');
  const index = f.git('write-tree');
  const result = f.run('git', ['commit', '--quiet', '-m', 'must fail']);
  assert.notEqual(result.status, 0);
  assert.equal(f.git('rev-parse', 'HEAD'), f.head);
  assert.equal(f.git('write-tree'), index);
  assert.equal(
    readFileSync(path.join(f.directory, output), 'utf8'),
    'before\n'
  );
});

for (const mode of ['api:generate', 'api:lint', 'api:check']) {
  test(`${mode} 失败时阻止提交，生成物不进入索引`, (t) => {
    const f = fixture(t);
    f.write('app/SampleController.php', before.replace('before', 'after'));
    f.git('add', '--', 'app/SampleController.php');
    const result = f.run('git', ['commit', '--quiet', '-m', 'must fail'], {
      HUSKY_TEST_FAIL: mode,
    });
    assert.notEqual(result.status, 0);
    assert.equal(f.git('rev-parse', 'HEAD'), f.head);
    assert.equal(f.git('show', `:${output}`), 'before');
    assert.equal(f.git('show', `:${obsolete}`), 'obsolete');
  });
}

test('清单中的越界路径不能使无关文件自动进入提交', (t) => {
  const f = fixture(t);
  f.write('app/SampleController.php', before.replace('before', 'after'));
  f.git('add', '--', 'app/SampleController.php');
  f.write('outside.ts', 'export const secret = true;');
  const result = f.run('git', ['commit', '--quiet', '-m', 'must fail'], {
    HUSKY_TEST_UNSAFE: '1',
  });
  assert.notEqual(result.status, 0);
  assert.equal(f.git('rev-parse', 'HEAD'), f.head);
  assert.equal(f.git('ls-files', '--', 'outside.ts'), '');
});
