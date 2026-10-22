# EXRE B2B2C 平台（Webman 后端）

本仓库是独立的 PHP/Webman 服务，负责 Website HTTP、管理端接口、OAuth、PostgreSQL、Redis 和服务端文件。父工程 ExWebsite 维护用户网站和管理端 React 前端，消费本仓库生成的内部 OpenAPI；本仓库的 Composer 命令和运行数据由本仓库独立管理。测试规则见 `TESTING.md`。

## 目录结构

```
app/            webman 后端（PHP；/api 前缀：account / admin / server / open / oauth / docs）
config/         后端配置          database/  建库脚本(schema.sql + seed.sql)
docs/           设计与对接文档     sdk/       FiveM 插件端 EXCore Lua SDK
```

**本地开发**：父工程 `pnpm dev` 启动 Docker PHP 8.4.27、管理端与 Website；`pnpm dev:server` 单独运行同一 Docker 后端流程，默认从 8081 选端口；`pnpm start:server` 用本机 PHP 8.4.27 调用 `windows.php`，普通 `SERVER_PORT` 默认 8787，依赖或端口不可用直接报错，不自动启动后台服务。Docker 开发不要求宿主 PHP 或 Composer，源码自动重载，依赖按 lock 在独立 vendor 卷安装。启动不创建数据库、账号或迁移业务数据。完整步骤见 `docs/local-development.md`，前端命令见父工程 README。

在本仓库根目录安装 Composer 依赖并准备 `.env` 或 `.env.local` 后，`composer start` 直接以前台方式运行 PHP，不检查 Docker；`composer dev` 先只读探测 PostgreSQL 与 Redis，在符合本地 Compose 映射且明确连接拒绝时才启动缺失的服务。PHP 先加载 `.env`，再由 `.env.local` 覆盖同名配置；CLI、worker 与安装配置刷新遵守同一顺序，`composer dev` 也按此顺序为 Compose 指定环境文件。Linux 前台启动需要 Webman 原有的 `pcntl` 与 `posix` 扩展，启动器会在依赖探测前检查。两者都保留现有环境文件、`install.lock` 与容器数据，Ctrl+C 只回收本轮 PHP 应用进程。

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

1. 推荐用父工程 Docker 开发入口，镜像提供 PHP 8.4.27 及扩展；本机 Windows 入口需 PHP 8.4.27（启用 `pdo_pgsql`、`redis`、`gd`、`mbstring`）并执行 `composer install --no-scripts`。两种模式均需准备已有业务数据库和账号。
2. 准备目标 PostgreSQL 数据库及具备该数据库 `public` schema 建表权限的用户。默认需要空数据库；强制模式还需有业务表的删除权限。确保项目目录及 `.env` 可写；`.env` 不存在时会自动创建。
3. 开发时可用 `composer start` 或 `composer dev` 前台启动后端；既有 Linux `php start.php start -d` 与 Windows `php windows.php` 入口仍可用于原有部署流程。构建并部署管理端时，网关将静态资源指向其 `dist/`，SPA 路径回退到 `index.html`，`/api` 转发至配置的后端端口。
4. 第一次打开管理端任意页面会自动进入 `/install`，依次检查环境、填写 PostgreSQL / Redis 连接信息、设置超级管理员邮箱及密码。
5. 提交后自动导入 `database/schema.sql` 的建表部分、创建超级管理员、保存环境配置并生成独立 JWT 密钥，不导入 `seed.sql` 的测试账号。存在 `.env.local` 时写入该覆盖文件，否则写入 `.env`。完成后进入登录页。

初始化接口免登录，且在数据库、Redis 未配置时可访问；其他业务接口在初始化前返回 503。默认拒绝覆盖已有 `app_` 业务表。数据库步骤可勾选“强制初始化（清空已有平台数据）”，提交前输入“重置数据库”确认后，将删除所填数据库 `public` 下全部 `app_` 业务表及数据，再重新建表并写入新管理员；其他表保留。删表和导入在同一个事务内完成，失败会回滚并允许修正后重试。存在其他表或视图依赖这些业务表时会拒绝重置并回滚。安装使用文件锁和 PostgreSQL 事务锁防止并发重复提交。

强制选项仅用于尚未完成初始化的部署，不绕过已完成安装的 `install.lock` 和旧部署识别保护。

安装状态保存在项目根目录 `install.lock`，请与 `.env`、数据库一起持久化备份，不要清除安装标记。已有 `.env` 且 PostgreSQL 中存在超级管理员的旧部署会自动识别为已初始化，保留现有账号和数据。安装完成后 HTTP 进程在下一次请求前自动加载新配置；后续手动修改配置仍应重启 Webman。
