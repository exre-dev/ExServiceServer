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
const contract = 'api/public/openapi.yaml';
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
  };
  for (const key of Object.keys(env)) {
    if (
      /^GIT_(?:DIR|WORK_TREE|INDEX_FILE|COMMON_DIR|CONFIG_COUNT|CONFIG_KEY_\d+|CONFIG_VALUE_\d+)$/.test(
        key
      )
    )
      delete env[key];
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
  write('.gitignore', '/node_modules\n');
  write('composer.json', '{"require":{"php":">=8.1"}}\n');
  write(
    'package.json',
    JSON.stringify({ scripts: { 'api:check': 'node fixture-check.mjs' } })
  );
  write(
    'fixture-check.mjs',
    `
import { readFileSync, writeFileSync } from 'node:fs';
writeFileSync('.git/check-called', readFileSync('${contract}', 'utf8'));
if (process.env.HUSKY_TEST_FAIL_CHECK) process.exit(12);
`
  );
  write('app/SampleController.php', before);
  write(contract, 'openapi: 3.0.3\ninfo: { title: before }\n');
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
  const install = run(process.execPath, [
    path.join(root, 'node_modules/husky/bin.js'),
  ]);
  assert.equal(install.status, 0, install.stderr || install.stdout);
  return { directory, write, run, git, head: git('rev-parse', 'HEAD') };
}

test('格式化已暂存代码和手写契约，只读校验并保留其他未暂存内容', (t) => {
  const f = fixture(t);
  f.write('app/SampleController.php', before.replace("'before'", '"after"'));
  f.write(contract, 'openapi: 3.0.3\ninfo: {title: after}\n');
  const partial = readFileSync(
    path.join(f.directory, 'web/partial.ts'),
    'utf8'
  );
  f.write(
    'web/partial.ts',
    partial.replace("value0 = 'before';", 'value0="after"')
  );
  f.git('add', '--', 'app/SampleController.php', contract, 'web/partial.ts');
  f.write(
    'web/partial.ts',
    partial
      .replace("value0 = 'before';", 'value0="after"')
      .replace("value11 = 'before';", 'value11="scratch"')
  );
  f.write('api/notes.md', 'unstaged notes\n');
  f.write('unrelated.js', 'export const scratch = true;');
  const result = f.run('git', ['commit', '--quiet', '-m', 'exercise hook']);
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.match(
    f.git('show', 'HEAD:app/SampleController.php'),
    /return 'after';/
  );
  assert.match(f.git('show', `HEAD:${contract}`), /info: \{ title: after \}/);
  assert.equal(
    readFileSync(path.join(f.directory, '.git/check-called'), 'utf8').trim(),
    f.git('show', `HEAD:${contract}`)
  );
  assert.equal(existsSync(path.join(f.directory, 'api/generated')), false);
  assert.equal(f.git('show', 'HEAD:api/notes.md'), 'original notes');
  assert.equal(
    readFileSync(path.join(f.directory, 'api/notes.md'), 'utf8'),
    'unstaged notes\n'
  );
  assert.equal(f.git('ls-files', '--', 'unrelated.js'), '');
  assert.match(f.git('show', 'HEAD:web/partial.ts'), /value0 = 'after';/);
  assert.match(f.git('show', 'HEAD:web/partial.ts'), /value11 = 'before';/);
  assert.match(
    readFileSync(path.join(f.directory, 'web/partial.ts'), 'utf8'),
    /value11="scratch"/
  );
});

for (const source of ['php', 'yaml']) {
  for (const kind of ['tracked', 'untracked', 'partially staged']) {
    test(`${source} 输入存在 ${kind} 未暂存修改时阻止提交并保留索引`, (t) => {
      const f = fixture(t);
      f.write('web/change.js', 'export const value="after"');
      f.git('add', '--', 'web/change.js');
      const name = source === 'php' ? 'app/SampleController.php' : contract;
      const value =
        source === 'php' ? before : 'openapi: 3.0.3\ninfo: { title: before }\n';
      if (kind === 'partially staged') {
        f.write(name, value.replace('before', 'staged'));
        f.git('add', '--', name);
      }
      f.write(
        kind === 'untracked'
          ? source === 'php'
            ? 'app/NewController.php'
            : 'api/public/paths/new.yaml'
          : name,
        value.replace('before', 'scratch')
      );
      const index = f.git('write-tree');
      const result = f.run('git', ['commit', '--quiet', '-m', 'must fail']);
      assert.notEqual(result.status, 0);
      assert.match(result.stderr + result.stdout, /未暂存/);
      assert.equal(f.git('write-tree'), index);
      assert.equal(f.git('rev-parse', 'HEAD'), f.head);
      assert.equal(
        existsSync(path.join(f.directory, '.git/check-called')),
        false
      );
    });
  }
}

test('格式化解析失败时阻止提交并保留索引', (t) => {
  const f = fixture(t);
  f.write('web/broken.ts', 'export const = ;');
  f.git('add', '--', 'web/broken.ts');
  const index = f.git('write-tree');
  const result = f.run('git', ['commit', '--quiet', '-m', 'must fail']);
  assert.notEqual(result.status, 0);
  assert.equal(f.git('rev-parse', 'HEAD'), f.head);
  assert.equal(f.git('write-tree'), index);
  assert.equal(existsSync(path.join(f.directory, '.git/check-called')), false);
});

test('契约校验失败时阻止提交，不修改或自动暂存契约', (t) => {
  const f = fixture(t);
  f.write('app/SampleController.php', before.replace('before', 'after'));
  f.git('add', '--', 'app/SampleController.php');
  const result = f.run('git', ['commit', '--quiet', '-m', 'must fail'], {
    HUSKY_TEST_FAIL_CHECK: '1',
  });
  assert.notEqual(result.status, 0);
  assert.equal(f.git('rev-parse', 'HEAD'), f.head);
  assert.equal(
    f.git('show', `:${contract}`),
    'openapi: 3.0.3\ninfo: { title: before }'
  );
  assert.equal(
    readFileSync(path.join(f.directory, contract), 'utf8'),
    'openapi: 3.0.3\ninfo: { title: before }\n'
  );
  assert.equal(existsSync(path.join(f.directory, 'api/generated')), false);
});
