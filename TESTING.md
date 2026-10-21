# PHP 后端测试规范

本文件是本仓库测试流程、用例编写和执行规则的唯一来源。路径和命令以仓库根目录为基准，环境准备见 `README.md`。

## 执行规则

- 按变更影响选择相关测试；纯文档变更检查格式、路径和规则一致性即可。
- PHP 改动先对每个变更文件运行 `php -l <文件>`，通过后再执行行为测试。
- 真实 Webman 接口联调前，修改代码或配置后需重载或重启进程；独立 CLI 脚本无需启动 Webman。
- PHP 脚本各自使用独立进程；确认退出码为 0 且清理完成后，才判定通过。

## 测试入口

| 命令                                  | 关键依赖与边界                                                                                                               |
| ------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `npm run test:api`                    | 手写 YAML 契约工具测试，覆盖模块引用、规范错误、接口标识与归属、路由覆盖、独立分发及只读行为；使用临时文件，只依赖 Node.js。 |
| `npm run test:hooks`                  | 使用临时 Git 仓库，验证已暂存代码与 YAML 的格式化、只读契约检查、失败阻止提交及未暂存内容保护；依赖 Node.js 和 Git。         |
| `npm run api:format:check`            | 检查手写契约 YAML 的格式。                                                                                                   |
| `npm run api:lint`                    | 只读校验 OpenAPI 结构、引用、operation 标识与归属、路径参数和鉴权方案引用；只依赖 Node.js。                                  |
| `npm run api:check`                   | 完成 lint 后，核对真实 Webman 路由覆盖与公开发布策略；需要 Composer 依赖和兼容 PHP，不执行 Controller 动作。                 |
| `php tests/feedback_upload.php`       | 数据库使用替身，实际写入临时文件和上传目录。                                                                                 |
| `php tests/registration_email.php`    | 真实 PostgreSQL / Redis，邮件使用替身。                                                                                      |
| `php tests/installation.php`          | 真实 PostgreSQL / Redis，需创建、删除临时数据库的权限。                                                                      |
| `php tests/sso_flow.php`              | 真实 PostgreSQL / Redis，使用临时 schema 和 Redis 前缀。                                                                     |
| `php tests/website_http.php`          | 真实 Webman 路由与中间件的进程内 HTTP 管线，独立 PostgreSQL / Redis；响应契约经 Node.js 只读加载手写 YAML。                  |
| `node tests/installation_browser.mjs` | 初始化向导交互，API 使用 mock。                                                                                              |
| `node tests/sso_browser.mjs`          | SSO 浏览器流程，连接真实 PostgreSQL / Redis。                                                                                |

PHP 脚本需安装 Composer 依赖，并使用兼容当前 lock 的解释器（当前依赖要求至少 PHP 8.4.1）；可用该解释器直接执行 Composer phar。根目录 Node.js 工具要求 22.22.1+，依赖通过 `npm ci` 安装；`api:check` 支持用 `PHP_BINARY` 指定 PHP 绝对路径。旧有 registration、installation、SSO 数据库测试读取 `.env`，执行前核对目标和权限；Website HTTP 测试使用下文独立配置。浏览器脚本需准备独立的 `web/user-web/`（提供 Playwright）、`web/admin-web/` 的依赖及本机 Chrome / Chromium；`CHROME_BINARY` 可指定浏览器，SSO 脚本还支持 `PHP_BINARY`。

修改接口契约时直接编辑 `api/public/` 或 `api/internal/` 中的 YAML，运行格式检查、lint/check；修改校验工具或提交钩子时运行对应工具测试。引用逻辑变更需验证公开目录可独立解析，不依赖内部版。契约检查不改写源文件，不生成产物；它们证明规范有效、归属互斥、路由覆盖及发布策略一致，不能代替成功、输入错误、鉴权等真实 HTTP 行为验证。新增或修改业务行为时按影响执行相关接口测试并单独报告。

## GitHub CI

`.github/workflows/ci.yml` 在面向 `master` 的 PR、`master` 推送和手动触发时运行。使用 PHP 8.4 和 Node.js 22.22.1 安装锁定的根目录依赖，检查后端 PHP 语法及契约格式，执行 `npm run api:lint`、`npm run api:check`、两组 Node.js 工具测试，再以独立进程依次运行上表中的 5 个 PHP 测试。Website HTTP 另建 `website_http_test_ci` 数据库。`ci-gate` 仅在这些步骤全部成功后通过。

数据库测试连接本次 CI 创建的 PostgreSQL 18、Redis 7.4 临时服务。PostgreSQL 测试账号具有创建和删除临时数据库的权限，测试沿用独立 schema、数据库和 Redis 前缀的隔离与清理规则。Node.js 依赖仅用于根目录契约、格式和钩子检查；CI 不安装前端应用依赖，不构建或测试 `web/admin-web/`，不运行浏览器测试。

## Website HTTP 隔离夹具

先安装锁定的 Composer 与根目录 Node.js 依赖，由测试运行器创建一次性 PostgreSQL 18、Redis 7.4 服务和专用数据库，再设置下列变量运行 `php tests/website_http.php`。夹具不读取项目 `.env`，不回退使用普通 `PG_*` / `REDIS_*`，不得指向开发或部署服务。

| 变量 | 要求 |
| --- | --- |
| `WEBSITE_HTTP_TEST_ENABLED` | 必须为 `1`。 |
| `WEBSITE_HTTP_TEST_PG_HOST`、`WEBSITE_HTTP_TEST_REDIS_HOST` | 临时服务的回环地址 `127.0.0.1`、`localhost` 或 `::1`。 |
| `WEBSITE_HTTP_TEST_PG_PORT`、`WEBSITE_HTTP_TEST_REDIS_PORT` | 必须显式提供映射端口。 |
| `WEBSITE_HTTP_TEST_PG_DB` | 已创建的专用数据库，名称匹配 `website_http_test_[a-z0-9_]+`。 |
| `WEBSITE_HTTP_TEST_PG_USER`、`WEBSITE_HTTP_TEST_PG_PASSWORD` | 专用测试账号，具备创建和删除自身 schema 的权限。 |
| `WEBSITE_HTTP_TEST_REDIS_DATABASE`、`WEBSITE_HTTP_TEST_REDIS_PASSWORD` | 显式 database（0–15）与密码；无密码使用 `__EMPTY__`，兼容 Windows 空环境变量。 |

夹具创建随机 schema、Redis prefix、JWT 密钥和私有临时 `BASE_PATH`，从版本化 SQL 提取所需表与索引，不执行含 DROP 的整份 schema 或 seed。它加载生产启动文件、真实路由与全局/局部中间件，以 `Http::onMessage` 执行请求；仅传输连接与发信使用替身。`finally` 校验清理范围并核验 schema、Redis namespace 和临时目录均已消失；测试运行器负责回收其创建的临时服务和数据库。

响应契约由 `tests/website_http_contract.mjs` 复用 `scripts/openapi.mjs` 的加载器，直接读取并解析两版手写 YAML，不生成或改写文件；可用 `NODE_BINARY` 指定解释器。限定响应校验覆盖类型、必填字段、引用、nullable、oneOf、额外属性及长度/数量/数值约束，未知语义明确失败，format/示例/描述仅作注释。负向断言验证错误响应会被拒绝；二进制响应另验字节与响应头。

覆盖账号、邀请注册、通知、存储、反馈与 SSO 的成功、错误及副作用。OAuth 边界使用真实授权码/设备码签发的令牌，覆盖普通用户、运营、超管及缓存命中、数据库回源、重建缓存：本站账号读写与管理端读取/封禁返回仅含错误消息的 401，用户资料与封禁状态保持不变；本站会话仍能读写并按角色访问管理端。userinfo 按 token 自身 scope 返回字段，授权关系扩展不扩张旧 token，数据库回源仍保留 scope。

这是进程内真实 Webman 管线证据，不包含监听 socket、常驻 worker、反向代理、SMTP、浏览器或部署验收。

## 用例与数据

- 围绕可观察行为编写断言，覆盖成功、输入错误和适用的鉴权分支；校验状态码、响应结构与数据副作用。
- 数据库、Redis 和文件使用独立测试资源，在 `finally` 中清理；删除前校验名称、前缀或路径，清理范围仅限本次创建的资源。
- 保留现有 `.env`、`install.lock` 与运行数据；导入 `database/schema.sql` 或 `database/seed.sql` 前核实目标库与可重建范围。

## 结果说明

记录实际执行的命令、结果及未执行原因；失败或跳过不能记为通过。明确区分 mock 检查、真实后端检查与外部服务或部署验收。
