# PHP 后端测试规范

本文件是本仓库测试流程、用例编写和执行规则的唯一来源。路径和命令以仓库根目录为基准，环境准备见 `README.md`。

## 执行规则

- 按变更影响选择相关测试；纯文档变更检查格式、路径和规则一致性即可。
- PHP 改动先对每个变更文件运行 `php -l <文件>`，通过后再执行行为测试。
- 真实 Webman 接口联调前，修改代码或配置后需重载或重启进程；独立 CLI 脚本无需启动 Webman。
- PHP 脚本各自使用独立进程；确认退出码为 0 且清理完成后，才判定通过。

## 测试入口

| 命令 | 关键依赖与边界 |
| --- | --- |
| `php tests/openapi.php` | OpenAPI 生成工具行为测试，覆盖模块引用、完整文档等价、受控发布及只读检查；使用临时元数据和文件，不依赖数据库、Redis 或运行中的 Webman。 |
| `composer api:lint` | 使用插件生成并校验文档，核对真实 Webman 路由、operation 归属及输出引用；不执行 Controller 动作。 |
| `composer api:check` | 重新生成预期文件集合，只读检查两版入口、模块片段、完整单文件及生成清单是否缺失或陈旧。 |
| `php tests/feedback_upload.php` | 数据库使用替身，实际写入临时文件和上传目录。 |
| `php tests/registration_email.php` | 真实 PostgreSQL / Redis，邮件使用替身。 |
| `php tests/installation.php` | 真实 PostgreSQL / Redis，需创建、删除临时数据库的权限。 |
| `php tests/sso_flow.php` | 真实 PostgreSQL / Redis，使用临时 schema 和 Redis 前缀。 |
| `node tests/installation_browser.mjs` | 初始化向导交互，API 使用 mock。 |
| `node tests/sso_browser.mjs` | SSO 浏览器流程，连接真实 PostgreSQL / Redis。 |

PHP 脚本需安装 Composer 依赖，并使用兼容当前 lock 的解释器（当前依赖要求至少 PHP 8.4.1）；可用该解释器直接执行 Composer phar 或 `scripts/openapi.php`。数据库测试读取 `.env`，执行前核对目标和权限。浏览器脚本需准备独立的 `web/user-web/`（提供 Playwright）、`web/admin-web/` 的依赖及本机 Chrome / Chromium；`CHROME_BINARY` 可指定浏览器，SSO 脚本还支持 `PHP_BINARY`。

修改接口元数据或 schema 后，运行 `composer api:generate` 更新 `api/public/`、`api/internal/` 的入口与模块片段，以及 `api/generated/` 的完整单文件，再运行工具行为测试及 lint/check。修改布局或引用逻辑时，还需验证拆分版合并后的契约与完整文档等价，公开目录可独立解析，不依赖内部版。它们证明契约可生成、归属互斥、路由覆盖及产物一致性；不能代替成功、输入错误、鉴权等真实 HTTP 行为验证。新增或修改业务行为时按影响执行相关接口测试并单独报告。

## GitHub CI

`.github/workflows/ci.yml` 在面向 `master` 的 PR、`master` 推送和手动触发时运行。使用 PHP 8.4 安装锁定的 Composer 依赖，检查后端 PHP 语法，执行 `composer api:lint`、`composer api:check`，再以独立进程依次运行上表中的 5 个 PHP 测试。`ci-gate` 仅在这些步骤全部成功后通过。

数据库测试连接本次 CI 创建的 PostgreSQL 18、Redis 7.4 临时服务。PostgreSQL 测试账号具有创建和删除临时数据库的权限，测试沿用独立 schema、数据库和 Redis 前缀的隔离与清理规则。CI 不安装前端依赖，不构建或测试 `web/admin-web/`，不运行浏览器测试。

## 用例与数据

- 围绕可观察行为编写断言，覆盖成功、输入错误和适用的鉴权分支；校验状态码、响应结构与数据副作用。
- 数据库、Redis 和文件使用独立测试资源，在 `finally` 中清理；删除前校验名称、前缀或路径，清理范围仅限本次创建的资源。
- 保留现有 `.env`、`install.lock` 与运行数据；导入 `database/schema.sql` 或 `database/seed.sql` 前核实目标库与可重建范围。

## 结果说明

记录实际执行的命令、结果及未执行原因；失败或跳过不能记为通过。明确区分 mock 检查、真实后端检查与外部服务或部署验收。
