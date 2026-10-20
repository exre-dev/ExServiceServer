# EXRE B2B2C 平台（webman 后端 + 双前端）

> 设计蓝图 `docs/b2b2c-blueprint.md`（P0-P5 已全部落地）；测试手册 `docs/test-accounts.md`；用户侧 API 对接 `docs/user-api.md`；代码约定 `.agents/skills/webman-backend/SKILL.md`。

## 目录结构

```
app/            webman 后端（PHP；/api 前缀：account / admin / server / open / oauth / docs）
config/         后端配置          database/  建库脚本(schema.sql + seed.sql)
docs/           设计与对接文档     sdk/       FiveM 插件端 EXCore Lua SDK
web/
├── admin-web/  管理端 UI（React19 + antd，随本仓库开发，dev :5173）
└── user-web/   用户侧 UI（ExWebsite，独立项目：自带 .git、独立 exre/hydra 数据库
                与 Hydra OIDC，pnpm monorepo，dev :8080；父仓库不追踪其内容）
```

**端口约定**：后端 8787 / 管理端 5173 / 用户侧 8080-8082+4444。注意：user-web 默认
`LOCAL_POSTGRES_PORT=5432` 会与后端容器 `webman-pgsql`（已占 5432）冲突，启动 user-web
前在其 `.env.local` 改为其他端口（如 5433）。

## OpenAPI 接口契约

本 PHP 服务使用 `webman-tech/swagger` 从 Controller 的 PHP Attributes 和 `app/common/openapi/` 的 schema 生成公开版与内部版契约。日常从模块化入口查阅，完整单文件供导入工具或分发：

| 文件 | 使用范围 |
| --- | --- |
| `api/public/openapi.yaml` | 公开版入口，包含外部插件、第三方应用与 OAuth 接入入口。 |
| `api/internal/openapi.yaml` | 内部版入口，包含本站账号、页面、平台与空间管理等其余接口。 |
| `api/generated/openapi.public.yaml` | 无外部引用的完整公开版，适合对外分发、导入工具或生成客户端。 |
| `api/generated/openapi.internal.yaml` | 无外部引用的完整内部版。 |

公开版和内部版各自包含 `paths/`、`schemas/`，按业务模块聚合，通过入口的相对 `$ref` 引用。两版业务接口互不重叠，合起来覆盖 Webman 实际注册的全部业务路由；是否公开与是否免登录分别定义。对外交付时可以复制完整 `api/public/` 目录，或只提供完整公开版单文件；模块化入口需连同引用文件一起提供。

所有 YAML 和生成清单都是产物，接口变更修改 PHP 定义，保留既有 `app/route.php` 显式路由注册。CLI 管理生成物更新与过期文件清理，保留非本工具管理的文件。

安装 Composer 依赖后，在本仓库根目录运行：

```sh
composer api:generate
composer api:lint
composer api:check
```

generate 更新两版入口、模块文件及完整单文件；lint 检查 PHP 接口定义、路由覆盖和输出引用；check 只读核对完整生成集合是否与 PHP 源一致，包括片段缺失或陈旧。直接执行入口为 `php scripts/openapi.php`，可查看命令帮助。生成不需要启动 Webman、PostgreSQL 或 Redis，也不加载本地 `.env`。插件默认 HTTP 文档入口和业务路由自动注册保持关闭。

使用满足当前 Composer lock 的 PHP 运行 Composer 和 CLI。当前锁定依赖要求 PHP 至少 8.4.1；若 PATH 中的 PHP 较旧，使用兼容解释器执行 Composer phar 或直接执行 CLI，不跳过 Composer 平台校验。CLI 检查与真实接口验证的范围见 `TESTING.md`。

## 首次部署初始化

1. 安装 Composer 依赖，准备 PHP 8.4.1+（启用 `pdo_pgsql`、`redis`、`gd`、`mbstring`）、PostgreSQL 和 Redis。
2. 准备目标 PostgreSQL 数据库及具备该数据库 `public` schema 建表权限的用户。默认需要空数据库；强制模式还需有业务表的删除权限。确保项目目录及 `.env` 可写；`.env` 不存在时会自动创建。
3. 启动后端（Linux：`php start.php start -d`；Windows：`php windows.php`），构建并部署 `web/admin-web` 管理端。网关将管理端静态资源指向其 `dist/`，SPA 路径回退到 `index.html`，`/api` 转发至后端 8787。
4. 第一次打开管理端任意页面会自动进入 `/install`，依次检查环境、填写 PostgreSQL / Redis 连接信息、设置超级管理员邮箱及密码。
5. 提交后自动导入 `database/schema.sql` 的建表部分、创建超级管理员、保存 `.env` 并生成独立 JWT 密钥，不导入 `seed.sql` 的测试账号。完成后进入登录页。

初始化接口免登录，且在数据库、Redis 未配置时可访问；其他业务接口在初始化前返回 503。默认拒绝覆盖已有 `app_` 业务表。数据库步骤可勾选“强制初始化（清空已有平台数据）”，提交前输入“重置数据库”确认后，将删除所填数据库 `public` 下全部 `app_` 业务表及数据，再重新建表并写入新管理员；其他表保留。删表和导入在同一个事务内完成，失败会回滚并允许修正后重试。存在其他表或视图依赖这些业务表时会拒绝重置并回滚。安装使用文件锁和 PostgreSQL 事务锁防止并发重复提交。

强制选项仅用于尚未完成初始化的部署，不绕过已完成安装的 `install.lock` 和旧部署识别保护。

安装状态保存在项目根目录 `install.lock`，请与 `.env`、数据库一起持久化备份，不要清除安装标记。已有 `.env` 且 PostgreSQL 中存在超级管理员的旧部署会自动识别为已初始化，保留现有账号和数据。安装完成后 HTTP 进程在下一次请求前自动加载新配置；后续手动修改配置仍应重启 Webman。
