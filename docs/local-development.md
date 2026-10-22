# 本地开发启动

本指南的 PHP 命令在本仓库根目录执行。父工程的 `pnpm dev` 和 `pnpm dev:server` 使用本仓库 `compose.yaml` 在 Docker 中运行 PHP 8.4.27、扩展和 Composer；前者还启动 Website 与 Admin。Docker 入口先验证并复用已有 PostgreSQL、Redis，仅在匹配本地配置的目标明确拒绝连接时启动缺少的 Compose 服务。认证失败、缺库或远端故障直接报错。独立 Windows 本机入口为父工程 `pnpm start:server`，内部运行 `php windows.php`，仅检查依赖，不调用 Docker 或启动 PostgreSQL、Redis。

容器挂载当前源码，Webman 监控代码并自动重载。每个 worktree 有独立 vendor / runtime 卷，首次或 Composer 配置、lock 变化时执行 `composer install --no-scripts`；版本化插件配置直接复用，禁用会重写 start.php、windows.php 的安装钩子。`runtime/avatars`、`runtime/uploads`、`public/uploads` 单独保留宿主机挂载。Ctrl+C 只清理本轮 PHP 容器和应用进程，保留后台服务和卷。Docker 开发不需要宿主 PHP、Composer；本机入口仍需 PHP 8.4.27、所需扩展和已有 vendor。

## 服务与配置

当前本机复用环境如下：

| 服务 | 本地地址 | 使用方式 |
| --- | --- | --- |
| PostgreSQL | `127.0.0.1:5432` | 复用 `exwebsite-runtime-postgres-1`，PHP 使用独立 `webman` 数据库和同名账号。 |
| Redis | `127.0.0.1:6379` | 复用 `exwebsite-runtime-redis-1`，按本地约定使用 DB0。 |
| PHP 后端 | Docker 默认从 `8081` 选端口，本机默认 `8787` | 父工程 `pnpm dev:server` / `pnpm start:server`；以前者输出的实际地址为准。 |

PHP 先读取本仓库 `.env`，再由本仓库 `.env.local` 覆盖同名配置；两个文件都可单独存在，均不提交。`POSTGRES_*`、`REDIS_*` 等应用配置只从 PHP 仓库加载，不读取父工程的 `.env.local`。应用模板维护在 `.env.example`；值含空格时用引号包裹，实际密码不要写入文档或提交。`EXWEBSITE_DEV_LISTEN`、`EXWEBSITE_DEV_WEB_URL`、`EXWEBSITE_DEV_RUN_ID`、`EXWEBSITE_NATIVE_RUN_ID` 和 `EXWEBSITE_DOCKER` 是启动器的保留字段，不能写入任一环境文件。完整 dev 上下文只覆盖本轮监听与用户侧 Website 地址，不改变数据库或密钥设置。

`composer dev` 向 Compose 依次传递实际存在的 `.env`、`.env.local` 和 `.env.docker`；前两份提供应用端口和 Redis 配置，最后一份只提供 Docker 初始化管理密码。手动 `docker compose` 默认只读取 `.env`；使用 `.env.local` 时显式添加对应的 `--env-file`，使用 `.env.docker` 时将它作为最后一个 `--env-file`。后文全新 Compose 示例已包含 `.env` 与 `.env.docker`，若还有 `.env.local`，在 `.env.docker` 前追加 `--env-file .env.local`。旧应用配置 `PG_*` 已改名为 `POSTGRES_*`，迁移时保留原值。

本库 Compose 的 PostgreSQL 管理账号与管理库固定为 `postgres`，初始化管理密码使用 `.env.docker` 中的 `DOCKER_POSTGRES_ADMIN_PASSWORD`。PHP 不加载这个文件；应用业务账号的密码使用 `.env` / `.env.local` 中的 `POSTGRES_PASSWORD`。复用现有 PostgreSQL 服务时无需准备 `.env.docker`，仅启动 Redis 也不要求该文件。

复用环境中的 Redis DB0 与其他使用方共用，不提供项目级键隔离；停止本项目时保留 Redis，清理时仅处理已确认属于本项目的键，不执行 `FLUSHDB` 或 `FLUSHALL`。

## 全新环境使用 Compose

没有现成 PostgreSQL、Redis 时，可以选择本仓库 `compose.yaml`。它包含 PHP、PostgreSQL、Redis，沿用项目名 `exre-php-local` 和后台数据卷，默认将 PostgreSQL、Redis 分别绑定到 `127.0.0.1:5432`、`127.0.0.1:6379`。父工程启动器自动选择需要的服务；手动准备后台时显式选择 `postgres redis`，以免提前启动 PHP。已有服务占用端口时验证并复用，不重建另一套占用相同端口的服务。

首次且 `.env` 不存在时，复制配置模板：

```powershell
Copy-Item -LiteralPath .env.example -Destination .env
```

应用 `.env` 中只填写业务连接。首次使用本库 Compose 且 `.env.docker` 不存在时，另行复制 Docker 配置模板：

```powershell
Copy-Item -LiteralPath .env.docker.example -Destination .env.docker
```

在 `.env.docker` 的 `DOCKER_POSTGRES_ADMIN_PASSWORD` 中填写独立随机的管理密码；该文件被 Git 忽略，只交给 Compose。Compose 将其映射为 PostgreSQL 镜像要求的容器变量 `POSTGRES_PASSWORD`。管理账号与管理库均固定为 `postgres`，无需额外配置。PHP 应用仍使用后续创建的 `webman` 专用账号，以及应用 `.env` / `.env.local` 中的业务密码 `POSTGRES_PASSWORD`。空持久卷首次初始化时必须提供非空管理密码，PostgreSQL 镜像会拒绝缺少密码的初始化。

按模板将实际管理密码填在单引号内，例如 `DOCKER_POSTGRES_ADMIN_PASSWORD='替换为实际密码'`，避免 `$NAME` 或 `${NAME}` 被当作环境变量替换；密码含单引号时用反斜杠转义该引号。解析规则见 [Docker 环境文件文档](https://docs.docker.com/compose/how-tos/environment-variables/variable-interpolation/#env-file-syntax)。

原先写在应用环境文件中的 `POSTGRES_ADMIN_PASSWORD` 可移到 `.env.docker` 的 `DOCKER_POSTGRES_ADMIN_PASSWORD`，保留原值；旧 `POSTGRES_ADMIN_USER` 和 `POSTGRES_ADMIN_DB` 不再使用。此配置调整不会修改已有数据库里的账号或密码。

检查并启动后台服务：

```powershell
docker compose --env-file .env --env-file .env.docker config --quiet
docker compose --env-file .env --env-file .env.docker up -d --wait postgres redis
docker compose --env-file .env --env-file .env.docker ps
```

首次启动需要能够获取 `postgres:18` 和 `redis:7.4-alpine` 镜像。服务就绪后，按“首次准备数据库”创建空 `webman` 库和专用账号，再通过管理端 `/install` 创建业务表。Compose 不导入 `database/schema.sql` 或 `database/seed.sql`。

使用这套服务时，PHP 应用 `.env` 或安装向导中的 `POSTGRES_PORT` 默认填 `5432`，`REDIS_PORT` 默认填 `6379`，Redis 使用 DB0。Compose 的宿主机端口直接读取环境文件中的 `POSTGRES_PORT` 和 `REDIS_PORT`；如果显式修改端口，安装向导填写相同值。`REDIS_PASSWORD` 为空时不启用认证；非空时 Compose 为 Redis 启用该密码，健康检查与 PHP 使用同一值。连接主机 `POSTGRES_HOST`、`REDIS_HOST` 是 PHP 连接目标，Compose 端口仍只绑定 `127.0.0.1`。

仅停止这套服务并保留持久数据：

```powershell
docker compose --env-file .env --env-file .env.docker stop
```

持久卷已初始化后，修改 `.env.docker` 的 `DOCKER_POSTGRES_ADMIN_PASSWORD` 不会更新数据库里已有账号的密码；修改密码需单独执行数据库维护。不要用删除持久卷的方式更新密码。

## 日常启动

### 1. 检查后台服务

本节的容器名称适用于上表的复用环境。使用本库 Compose 时，后台服务的启动、状态检查与停止使用上一节的 Compose 命令；后续 PHP 启动步骤相同。以下是手动诊断步骤；常规 `composer dev` 会在 120 秒总截止时间内探测应用账号和数据库、Redis 认证及 PING，仅在回环连接明确拒绝时尝试启动缺失的 Compose 服务。远端失败、认证错误、缺库或协议错误不会触发 Compose。

先启动 Docker Desktop，再检查已有容器：

```powershell
docker info
docker ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'
```

确认容器名称、镜像和端口对应上表。如果容器存在且已停止，执行：

```powershell
docker start exwebsite-runtime-postgres-1 exwebsite-runtime-redis-1
```

检查服务是否可用：

```powershell
docker exec exwebsite-runtime-postgres-1 pg_isready -h 127.0.0.1 -p 5432
docker exec exwebsite-runtime-redis-1 redis-cli -n 0 PING
```

预期分别返回 `accepting connections` 和 `PONG`。这两项只证明服务可访问；PostgreSQL 应用账号是否有效，还需通过安装向导或下面的交互连接验证。

容器不存在时，先向所属项目核对持久卷和启动配置。本机现有容器记录的原 Compose 文件路径已失效，不能依赖该路径重建；主项目当前 Compose 也没有 Redis 定义。保留现有容器、卷和数据，不使用本库命令接管或重建。

### 2. 选择兼容的 PHP

当前 Composer lock 要求 PHP 8.4.1 及以上，并需启用 `pdo_pgsql`、`redis`、`gd`、`mbstring`。先检查默认解释器：

```powershell
php -v
php -m
```

如果 PATH 仍指向 PHP 7.4，本机已安装的兼容解释器可这样选择：

```powershell
$phpBinary = Join-Path $env:LOCALAPPDATA 'CodexTools\php\8.4.26\php.exe'
& $phpBinary -v
& $phpBinary -m
$env:Path = (Split-Path -Parent $phpBinary) + ';' + $env:Path
```

这只修改当前终端的 PATH。其他机器使用各自安装的 PHP 8.4.1+ 路径，并检查 `php -v`。

仅使用 Windows 本机模式时，首次安装依赖或 Composer lock 更新后，使用 PHP 8.4.27 执行：

```powershell
composer install --no-scripts
```

不要执行 `composer update` 或忽略平台要求来绕过版本检查。

### 3. 启动 PHP 后端

Windows 在已选择兼容 PHP 的终端执行：

```powershell
composer dev
```

推荐在父工程运行 `pnpm dev:server` 使用 Docker 开发；本机 Windows 使用 `pnpm start:server` 或本目录 `php windows.php`，依赖不可用时直接报错。旧 `composer dev` 仍为宿主 PHP 开发入口，按原规则有条件准备缺少的本地后台服务；`composer start` 不调用 Docker。Linux 部署仍使用 `php start.php start` / `php start.php start -d`，需要 `pcntl`、`posix`，部署需单独验收。Windows 在启动终端按 Ctrl+C 结束本轮 PHP 子进程。

不论使用哪个入口，应用库和专用账号都需要提前准备；命令不会建库、建账号、导入 schema/seed 或改写 `.env`、`install.lock`。首次准备流程见下文。两个 worktree 若指向同一 PostgreSQL / Redis，会共享业务数据；各自的应用监听及运行锁属于各自 checkout。

旧直接入口示例：

```sh
php start.php start
```

如果 `8787` 已被占用，先核对已有进程是否属于本 checkout，不重复启动，也不按端口直接结束其他项目的进程。修改 `.env` 或 `.env.local` 后重启自己的 Webman 进程。

用户网站和管理端由父工程 ExWebsite 维护，启动命令与前端配置见父工程 README。首次安装进入该管理端的 `/install`，不是后端 `8787` 上的页面。数据库和 Redis 配置按下一节填写；已经安装完成的环境直接登录。

## 首次准备数据库

目标 PostgreSQL 实例中已有 `webman` 库、专用账号，且应用 `.env` 已指向该实例时跳过本节，不重复建库或轮换密码。

在选定的 PostgreSQL 实例中，新建 PHP 专用数据库和账号，不复用 `exre`、`hydra` 等其他业务库。应用不使用 PostgreSQL 管理账号。

复用本机现有实例时，使用管理账号 `user` 进入交互式 psql：

```powershell
docker exec -it exwebsite-runtime-postgres-1 psql -X -U user -d postgres
```

如果使用本库 Compose 创建的实例，改用其管理账号 `postgres`：

```powershell
docker compose --env-file .env --env-file .env.docker exec postgres psql -X -U postgres -d postgres
```

在 psql 中执行：

```sql
CREATE ROLE webman LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
\password webman
CREATE DATABASE webman OWNER webman TEMPLATE template0 ENCODING 'UTF8';
\q
```

`\password` 会交互提示设置数据库账号密码；使用独立随机密码，并将其保存到本地 `.env`。如果同名库或账号已经存在，先核对 owner、用途和已有数据，不删除或覆盖。

首次且 `.env` 不存在时，可以从 `.env.example` 复制并填写实际值；已有 `.env` 时只核对相关配置，不整份覆盖。连接参数如下：

| 配置键 | 值 |
| --- | --- |
| `POSTGRES_HOST` | `127.0.0.1` |
| `POSTGRES_PORT` | `5432` |
| `POSTGRES_DB` | `webman` |
| `POSTGRES_USER` | `webman` |
| `POSTGRES_PASSWORD` | 新建账号的密码，从本地 `.env` 读取。 |
| `REDIS_HOST` | `127.0.0.1` |
| `REDIS_PORT` | `6379` |
| `REDIS_DATABASE` | `0` |
| `REDIS_PASSWORD` | 当前本地实例留空；实例启用认证后填写实际密码。 |

可以通过 TCP 交互连接验证新账号；密码在提示时输入，不放进命令参数：

```powershell
docker exec -it exwebsite-runtime-postgres-1 psql -X -h 127.0.0.1 -U webman -d webman -W
```

本库 Compose 环境使用：

```powershell
docker compose --env-file .env --env-file .env.docker exec postgres psql -X -h 127.0.0.1 -U webman -d webman -W
```

在 psql 中检查当前数据库、身份及建表权限：

```sql
SELECT current_database(), current_user, has_schema_privilege(current_user, 'public', 'CREATE');
\q
```

预期数据库和账号都是 `webman`，建表权限为 `t`。专用账号不具备创建其他数据库的权限；`tests/installation.php` 需要的临时建库权限另行按 `TESTING.md` 准备，不授予日常应用账号。

## 通过安装向导创建业务表

启动后端和管理端后，在管理端 `/install` 中：

1. 检查 PHP 环境。
2. 填写上表的 PostgreSQL 和 Redis 连接信息，数据库密码使用本地 `.env` 中的实际值。
3. 填写自己的超级管理员邮箱、昵称和密码，提交安装。

新数据库保持为空，使用默认初始化，不勾选强制重置。安装向导导入 `database/schema.sql` 的建表部分，创建你填写的超级管理员，保存环境配置和 `install.lock`，生成独立 JWT 密钥；不导入 `database/seed.sql`。存在 `.env.local` 时将安装配置保存到该文件，否则写入 `.env`，避免刚保存的连接和密钥又被本地覆盖文件遮盖。

安装完成后保留 `.env`、`.env.local`、`install.lock` 和数据库。后端的真实业务接口验证、管理端浏览器验证与外部部署验证分别记录，后台服务连通不代表安装或业务验收完成。
