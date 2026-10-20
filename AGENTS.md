# PHP 后端工程规范

适用于本 PHP 仓库：EXRE 社区 B2B2C 平台的 Webman / Workerman 常驻内存后端，使用 PostgreSQL 和 Redis。本文路径均以本仓库根目录为基准。

## 开工前

- 安装、启动与环境准备见 `README.md`。资料与当前实现不一致时，先核实相关代码与配置。
- 修改前查找并复用已有模型、中间件和服务。

### Testing and contribution rules

- Testing rules: if this repository has a `TESTING.md`, read it before testing; when it exists, it is the single source of truth for test workflow, test-case authoring, and execution rules. When it does not exist, follow the repository's own testing conventions instead.
- Contribution rules: `@CONTRIBUTING.md`. Read this file before creating a commit or Pull Request; it is the single source of truth for contribution, commit, and PR rules.

## 目录职责

| 路径                     | 职责                                                                |
| ------------------------ | ------------------------------------------------------------------- |
| `app/<模块>/`            | 所属模块的控制器。                                                  |
| `app/common/model/`      | 跨模块模型与复杂查询。                                              |
| `app/common/middleware/` | 跨模块中间件。                                                      |
| `app/common/services/`   | 跨模块服务与外部服务逻辑。                                          |
| `api/`                   | 手写的公开、内部 OpenAPI 契约，按 `paths/`、`schemas/` 分模块维护。 |
| `app/route.php`          | 显式注册全部路由。                                                  |
| `config/middleware.php`  | 注册全局中间件。                                                    |
| `database/schema.sql`    | 表结构。                                                            |
| `database/seed.sql`      | 种子数据。                                                          |

## OpenAPI 与 Contract-first

1. 实现或调用本 PHP 服务接口前，读取 `api/public/openapi.yaml` 或 `api/internal/openapi.yaml`，沿相对 `$ref` 查阅对应 `paths/`、`schemas/` 模块；路径、方法、参数、字段和鉴权以对应契约为准。所属主项目的 Fastify Contract 单独描述其服务。
2. `api/` 下的入口、`paths/` 和 `schemas/` YAML 是接口定义的唯一来源，直接编辑并评审。API 变更先修改契约，再实现业务；Controller 专注业务逻辑，不维护 OpenAPI Attributes。
3. 每个 operation 明确声明唯一的 `operationId` 与 `x-audience: public` 或 `internal`。公开版仅包含外部插件、第三方应用接口和 OAuth 接入入口；内部版不重复公开接口，两份合起来覆盖全部注册业务路由。公开范围变更同时核对 `config/openapi.php` 的发布策略。
4. 业务路由继续在 `app/route.php` 显式注册；方法、完整路径及参数与契约一致。契约校验只加载路由，不执行 Controller 动作，不依赖运行中的 HTTP 服务、数据库或 Redis。
5. 修改后执行 `npm run api:format:check`、`npm run api:lint` 和 `npm run api:check`，同步交付 YAML 与业务实现。lint 校验规范、引用、标识、归属及路径参数；check 另核对真实路由和公开发布策略。运行要求与测试范围遵守 `TESTING.md`。
6. 契约准确描述实际状态码、成功和错误信封、文件或 HTML 响应及特殊分支；同步检查调用方、对接文档和 `sdk/fivem-excore/`。公开交付使用完整 `api/public/` 目录；每版的引用仅指向本版目录中的文件，入口与引用文件一起分发。
7. 交付时分别说明静态契约检查、真实 HTTP 联调、浏览器和外部部署的实际结果及未验证项。

## 编码约定

### 路由与控制器

- 接口统一使用 `/api` 前缀，OAuth 浏览器页面保留独立路径。
- 每条路由写中文注释，路由参数名与方法参数名一致；保留 OPTIONS 预检和 404 fallback。
- Controller 不继承基类。动作用 `public function xxx(Request $request): Response` 声明，类型使用 `support\Request` / `support\Response`；需要的模型通过方法参数注入。
- 读取请求参数后，先做必填与业务校验。
- 成功返回 `success($data)`，保持 `{msg, data}` 格式；模型用 `toArray()` 序列化，新接口不增加旧式 `code` 字段。
- 业务错误抛 `ApiException('中文消息', $httpStatus)`，默认 400；登录态失效用 401，身份有效但无权限或被封禁用 403。

### 模型与数据访问

- 模型继承 `BaseModel`，显式声明 `$table`，跟随默认数据库连接。
- 时间字段使用 `CREATED_AT = 'created_time'` / `UPDATED_AT = 'updated_time'`。
- 字段写中文 `@property`；批量赋值使用白名单，敏感字段加入 `$hidden`。
- 使用 `with()` / `withCount()` 前，确认模型已定义对应关系。

### 外部服务与常驻进程

- 外部 HTTP 请求统一使用已有 `HttpClient`；请求体的 `json()` / `form()` / `body()` 三选一。
- 读取配置用 `config()`，环境变量用 `getenv()`；临时 token 等缓存存 Redis 并设置过期时间。
- 请求状态保存在 Request 或局部变量中，避免全局或静态属性跨请求累积数据。
- 缩进沿用所在文件，注释和业务错误使用中文；新代码不加 `strict_types`，日志使用 `support\Log`。

## 安全与数据

### 身份与权限

- 密码使用 bcrypt；JWT 统一由 `SessionService::create()` 签发，保留会话撤销校验。
- 默认经过 `CheckLogin`，免登录方法写入 `$noNeedLogin`，整控制器免登录用 `'*'`；JWT 校验失败转换为 401，保留会话与用户状态校验。
- 平台管理端使用 `PlatformAdmin`，空间接口使用 `ServerMember`，管理动作调用 `assertManager()` / `assertOwner()`；机器调用使用 `BindCodeAuth`，OAuth 访问令牌接口使用 `OAuthTokenAuth`。

### 中间件与运行数据

- 新中间件实现 `Webman\MiddlewareInterface`，在 `config/middleware.php` 注册全局中间件，或通过路由 `->middleware()` 局部挂载；放行时返回 `$next($request)`。
- CORS 统一由 `AccessControl` 处理，保留首次安装保护及现有中间件顺序。
- 凭据放 `.env`；保留已有 `.env`、`install.lock` 与运行数据。

### 数据库与业务边界

- 表结构修改同步 `database/schema.sql`，种子数据维护在 `database/seed.sql`；表名使用 `app_` 前缀，字段附中文 COMMENT。
- 建表脚本含删表操作，执行前核实目标库与可重建范围。
- QQBot / TxAdmin 保持自营私有；历史响应格式和 Gateway 依赖问题仅在任务明确涉及时处理。
