import { spawnSync } from 'node:child_process';
const inputs = [
  'app/',
  'config/',
  'plugin/',
  'support/',
  ':(glob)api/**/*.yaml',
  'scripts/openapi.mjs',
  'scripts/openapi-routes.php',
  'package.json',
  'package-lock.json',
  'composer.json',
  'composer.lock',
];

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

try {
  switch (process.argv[2]) {
    case 'check':
      checkInputs();
      break;
    default:
      throw new Error('用法: node scripts/pre-commit.mjs check');
  }
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
