# Contributing

在创建 Commit 或 Pull Request 之前，请先阅读本文件。`AGENTS.md` 中的仓库专属规则、模块文档以及 CI 配置的优先级高于本文件。

## 变更

- 保持变更聚焦；不要混入无关的重构、格式化或生成文件。
- 行为变更所必需的测试与文档，应同步新增或更新。
- 测试与检查的执行时机、范围、用例规则及结果披露统一以 `TESTING.md` 为准。

## Commit

除非仓库已定义其他约定，否则使用 Conventional Commits：

- `feat: add new feature`
- `fix: fix bug`
- `docs: update documentation`
- `test: add tests`
- `refactor: refactor code`
- `chore: update dependencies`

Commit message 应说明该变更的目的。绝不提交凭据、私有配置或无关产物。

## Pull Request

- 说明问题、采用的做法、影响范围以及校验结果。
- 明确指出破坏性变更、迁移步骤、已知限制或后续工作。
- 仅在仓库有要求时，才补充关联 Issue、截图、DCO 签署或其他元数据。
