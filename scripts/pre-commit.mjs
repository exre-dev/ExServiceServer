import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';

const manifestPath = 'api/.openapi-manifest.json';
const inputs = [
  'app/',
  'config/',
  'plugin/',
  'support/',
  'scripts/openapi.php',
  'composer.json',
  'composer.lock',
];
const managedPath =
  /^(?:(?:public|internal)\/(?:openapi\.yaml|(?:paths|schemas)\/[a-z][a-z0-9_-]*\.yaml)|generated\/openapi\.(?:public|internal)\.yaml)$/;

function git(args, options = {}) {
  const result = spawnSync('git', args, {
    encoding: 'utf8',
    windowsHide: true,
    ...options,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new Error(result.stderr || `git ${args[0]} 执行失败`);
  }
  return result.stdout;
}

function paths(value) {
  return value.split('\0').filter(Boolean);
}

function checkInputs() {
  const changed = paths(
    git(['diff', '--name-only', '--no-renames', '-z', '--', ...inputs])
  );
  const untracked = paths(
    git(['ls-files', '--others', '--exclude-standard', '-z', '--', ...inputs])
  );
  const pending = [...new Set([...changed, ...untracked])];
  if (pending.length > 0) {
    throw new Error(
      'OpenAPI 输入存在未暂存修改，请先暂存或移走这些修改后重试：\n' +
        pending.map((file) => `  ${file}`).join('\n')
    );
  }
}

function manifestFiles(source) {
  const manifest = JSON.parse(source);
  if (
    manifest.version !== 1 ||
    !Array.isArray(manifest.files) ||
    manifest.files.length > 1024 ||
    manifest.files.some(
      (file) => typeof file !== 'string' || !managedPath.test(file)
    )
  ) {
    throw new Error('非法 OpenAPI 生成清单，拒绝自动暂存');
  }
  return manifest.files.map((file) => `api/${file}`);
}

function stageArtifacts() {
  const tracked = new Set(paths(git(['ls-files', '-z', '--', 'api/'])));
  const previous = tracked.has(manifestPath)
    ? manifestFiles(git(['show', `:${manifestPath}`]))
    : [];
  const current = manifestFiles(readFileSync(manifestPath, 'utf8'));
  // 包含旧清单和旧入口的删除；不把 api/ 中的手写文件自动加入提交。
  const candidates = new Set([
    manifestPath,
    ...previous,
    ...current,
    'api/openapi.public.yaml',
    'api/openapi.internal.yaml',
  ]);
  const targets = [...candidates].filter(
    (file) => existsSync(file) || tracked.has(file)
  );
  git(
    [
      '--literal-pathspecs',
      'add',
      '-A',
      '--pathspec-from-file=-',
      '--pathspec-file-nul',
    ],
    { input: targets.join('\0') + '\0' }
  );
}

try {
  switch (process.argv[2]) {
    case 'check':
      checkInputs();
      break;
    case 'stage':
      stageArtifacts();
      break;
    default:
      throw new Error('用法: node scripts/pre-commit.mjs check|stage');
  }
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
