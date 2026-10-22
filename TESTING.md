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
| `php tests/runtime.php` | 本地启动配置、后台依赖分类、Windows 子进程退出/回收及 Linux Webman adapter/进程组；使用临时文件与受控替身，不连接项目数据库或运行 Docker。Linux 需要 `pcntl`、`posix` 才能运行 adapter 夹具。 |
| `php tests/native_ready.php` | 原生 worker 初始化后的私有 runId 握手、安装状态诊断头和环境保留键保护；使用临时父类/worker 替身与真实 Request/Response 验证发送转发、路径/模式隔离和请求头不可伪造，不监听端口或连接数据库。 |
| `php tests/native_configuration.php` | 本机入口的只读端口输出、环境覆盖和错误保护，供根启动器先检查端口；不启动 worker 或后台服务。 |
| `php tests/container_runtime.php` | 容器内存地址适配、探测状态、原生 check-only 与固定 PHP 版本约束；不操作后台服务。 |
| `php tests/container_install.php` | 显式 Docker 验收：使用已构建 PHP 镜像和一次性 vendor 卷安装依赖，验证安装不改写宿主启动和插件配置；只回收本次临时容器/卷，需要镜像与依赖下载网络。 |
| `php tests/runtime_environment.php` | 临时目录中的 `.env` / `.env.local` 单文件加载、覆盖、变量引用、重载、内部字段拒绝、错误脱敏、安装与 Compose 的配置文件选择，以及 PHP 忽略 `.env.docker`；同时由 `tests/runtime.php` 执行，不连接数据库或 Docker。 |
| `php tests/compose.php` | 用虚构配置验证 Compose 固定管理账号、`.env.docker` 密码隔离、应用端口和 Redis 密码插值，以及缺少 Docker 配置时不回退到应用密码、不影响 Redis；需要 Docker CLI，不启动容器。加 `--runtime` 时使用随机端口和独立测试项目验证 Redis 无密码及带特殊字符密码的连接，最后回收该项目的容器与卷。 |
| `php tests/openapi.php` | OpenAPI 生成工具行为测试，覆盖模块引用、完整文档等价、受控发布及只读检查；使用临时元数据和文件，不依赖数据库、Redis 或运行中的 Webman。 |
| `composer api:lint` | 使用插件生成并校验文档，核对真实 Webman 路由、operation 归属及输出引用；不执行 Controller 动作。 |
| `composer api:check` | 重新生成预期文件集合，只读检查两版入口、模块片段、完整单文件及生成清单是否缺失或陈旧。 |
| `php tests/feedback_upload.php` | 数据库使用替身，实际写入临时文件和上传目录。 |
| `php tests/registration_email.php` | 真实 PostgreSQL / Redis，邮件使用替身。 |
| `php tests/installation.php` | 真实 PostgreSQL / Redis，需创建、删除临时数据库的权限。 |
| `php tests/sso_flow.php` | 真实 PostgreSQL / Redis，使用临时 schema 和 Redis 前缀。 |
| `php tests/website_http.php` | 真实 Webman 路由及全局/局部中间件的进程内 HTTP 管线，独立 PostgreSQL / Redis、临时 BASE_PATH、邮件记录替身；只接受显式测试变量，不读取项目 `.env`。 |

PHP 脚本需安装 Composer 依赖，并使用兼容当前 lock 的解释器（当前依赖要求至少 PHP 8.4.1）；可用该解释器直接执行 Composer phar 或 `scripts/openapi.php`。旧有 registration、installation、SSO 数据库测试读取 `.env`，执行前核对目标和权限；Website HTTP 测试使用下文独立配置。

修改接口元数据或 schema 后，运行 `composer api:generate` 更新 `api/public/`、`api/internal/` 的入口与模块片段，以及 `api/generated/` 的完整单文件，再运行工具行为测试及 lint/check。修改布局或引用逻辑时，还需验证拆分版合并后的契约与完整文档等价，公开目录可独立解析，不依赖内部版。它们证明契约可生成、归属互斥、路由覆盖及产物一致性；不能代替成功、输入错误、鉴权等真实 HTTP 行为验证。新增或修改业务行为时按影响执行相关接口测试并单独报告。

## GitHub CI

`.github/workflows/ci.yml` 在面向 `master` 的 PR、`master` 推送和手动触发时运行。使用 PHP 8.4 并显式启用 `pcntl`、`posix` 安装锁定的 Composer 依赖，检查后端 PHP 语法，执行 `composer api:lint`、`composer api:check`，再以独立进程依次运行上表中的 7 个 PHP 测试。`ci-gate` 仅在这些步骤全部成功后通过。启动器测试使用临时配置与进程替身；Linux 验证 adapter/进程组，跳过仅适用于 Windows 的子进程清理断言。

数据库测试连接本次 CI 创建的 PostgreSQL 18、Redis 7.4 临时服务。PostgreSQL 测试账号具有创建和删除临时数据库的权限，测试沿用独立 schema、数据库和 Redis 前缀的隔离与清理规则。

## Website HTTP 隔离夹具

先由测试运行器创建一次性 PostgreSQL 18 和 Redis 7.4 服务及专用数据库，再设置以下变量运行 `php tests/website_http.php`。不得指向开发或部署服务；夹具不创建服务或数据库，不回退读取普通 `POSTGRES_*`、`REDIS_*` 或项目 `.env`。

| 变量 | 要求 |
| --- | --- |
| `WEBSITE_HTTP_TEST_ENABLED` | 必须为 `1`。 |
| `WEBSITE_HTTP_TEST_POSTGRES_HOST`、`WEBSITE_HTTP_TEST_REDIS_HOST` | 临时服务的回环地址 `127.0.0.1`、`localhost` 或 `::1`。 |
| `WEBSITE_HTTP_TEST_POSTGRES_PORT`、`WEBSITE_HTTP_TEST_REDIS_PORT` | 临时服务映射端口，必须显式提供。 |
| `WEBSITE_HTTP_TEST_POSTGRES_DB` | 已创建的专用数据库，名称匹配 `website_http_test_[a-z0-9_]+`。 |
| `WEBSITE_HTTP_TEST_POSTGRES_USER`、`WEBSITE_HTTP_TEST_POSTGRES_PASSWORD` | 专用数据库账号，需具备创建和删除自身 schema 的权限。 |
| `WEBSITE_HTTP_TEST_REDIS_DATABASE`、`WEBSITE_HTTP_TEST_REDIS_PASSWORD` | 显式 database（0–15）及密码；无密码可写 `__EMPTY__`，兼容 Windows 无法保留空进程变量。 |

夹具在 Composer 加载前创建独占临时 `BASE_PATH`，复制版本化启动配置及真实路由，加载生产 `support/bootstrap.php`，用真实 `Http::onMessage` 执行请求。传输连接只捕获响应，不监听 socket；不使用手工路由或替换业务模型。全局安装保护、登录验证、CORS、限流及局部 PlatformAdmin 均保留。普通用例沿用默认 debug/loopback 限流豁免，另用独立请求 IP 验证限流拒绝分支。邮件仅记录在进程内。

随机 schema、Redis prefix 和 JWT 密钥在首次配置刷新前设置，临时配置包装器保证新连接与后续加载都保留隔离；文件、头像、multipart 和日志写入临时根目录。只从版本化 SQL 提取所需建表与索引定义，不执行整份含 DROP 的 schema 或 seed。`finally` 校验资源名称和路径后分别清理，并核验 schema、Redis namespace 和临时目录已消失；测试运行器负责回收其创建的临时服务和数据库。

覆盖账号登录/退出、资料/头像、邀请注册、通知/偏好、file 上传/下载、反馈收取/附件/完成以及 SSO inspect/login/confirm，检查实际状态、副作用和 401/403/409/413/422 等分支。SSO 通过真实 public device-init 建立请求，保留未登录 `/oauth/device` 的既有 401。OAuth userinfo 通过真实授权确认和 public token 接口签发 `user.basic user.email`、`user.basic` 两种会话，逐一验证首次缓存命中、仅删除该会话缓存后的数据库回源和重建后命中的响应与快照一致；累计 grant 中的邮箱授权不得扩大 basic token 的字段权限。

响应结构直接读取 `api/generated/openapi.internal.yaml`，public OAuth setup/token/userinfo 单独读取 `api/generated/openapi.public.yaml`，按真实 operation、状态和媒体类型选择 schema。限定校验覆盖对象/数组、必填字段、额外属性、引用、nullable、oneOf、类型/枚举及长度/数量/数值约束，未知语义明确失败；format、示例和描述仅作为注释。负向用例证明错误响应会被拒绝。二进制响应另验字节和相关头。此测试不校验完整 OpenAPI 请求语义，也不代表 socket、常驻 worker、代理、浏览器、SMTP 或部署验收。

## 用例与数据

- 围绕可观察行为编写断言，覆盖成功、输入错误和适用的鉴权分支；校验状态码、响应结构与数据副作用。
- 数据库、Redis 和文件使用独立测试资源，在 `finally` 中清理；删除前校验名称、前缀或路径，清理范围仅限本次创建的资源。
- 保留现有 `.env`、`install.lock` 与运行数据；导入 `database/schema.sql` 或 `database/seed.sql` 前核实目标库与可重建范围。

## 结果说明

记录实际执行的命令、结果及未执行原因；失败或跳过不能记为通过。明确区分 mock 检查、真实后端检查与外部服务或部署验收。
