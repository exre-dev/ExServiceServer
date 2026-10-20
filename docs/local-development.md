# 本地开发启动

本指南在 PHP 仓库根目录执行命令。Docker 提供 PostgreSQL 和 Redis，PHP 与管理端在宿主机运行。本机继续复用已有容器；全新环境可以使用本仓库的 `compose.yaml`。

## 服务与配置

当前本机复用环境如下：

| 服务 | 本地地址 | 使用方式 |
| --- | --- | --- |
| PostgreSQL | `127.0.0.1:5432` | 复用 `exwebsite-runtime-postgres-1`，PHP 使用独立 `webman` 数据库和同名账号。 |
| Redis | `127.0.0.1:6379` | 复用 `exwebsite-runtime-redis-1`，按本地约定使用 DB0。 |
| PHP 后端 | `http://127.0.0.1:8787` | Windows 使用 `windows.php`，Linux 使用 `start.php`。 |
| 管理端 | `http://localhost:5173` | `web/admin-web/` 中的 Vite 开发服务，将 `/api` 代理到 PHP 后端。 |

PHP 读取本仓库 `.env` 中的 `PG_*` 和 `REDIS_*`，不读取主项目 `.env.local`。Compose 也默认读取同一份 `.env`，模板统一维护在 `.env.example`；实际密码不要写入文档或提交。

复用环境中的 Redis DB0 与其他使用方共用，不提供项目级键隔离；停止本项目时保留 Redis，清理时仅处理已确认属于本项目的键，不执行 `FLUSHDB` 或 `FLUSHALL`。

## 全新环境使用 Compose

没有现成 PostgreSQL、Redis 时，可以选择本仓库 `compose.yaml`。它使用独立项目名 `exre-php-local` 和两个持久卷，默认将 PostgreSQL、Redis 分别绑定到 `127.0.0.1:5432`、`127.0.0.1:6379`，带健康检查。PHP 仍在宿主机运行。已有服务占用这些端口时，按后面的复用步骤运行，不同时启动第二套占用相同端口的服务。

首次且 `.env` 不存在时，复制配置模板：

```powershell
Copy-Item -LiteralPath .env.example -Destination .env
```

编辑 `.env`，填写独立随机的 `POSTGRES_PASSWORD`；已有该文件时仅补充缺少的配置，不重复复制。它是 PostgreSQL 管理账号 `postgres` 的密码；PHP 应用使用后续创建的 `webman` 专用账号和 `PG_PASSWORD`。两种账号的配置都维护在 `.env.example`，实际值保存在被 Git 忽略的 `.env`。

检查并启动后台服务：

```powershell
docker compose config --quiet
docker compose up -d --wait
docker compose ps
```

首次启动需要能够获取 `postgres:18` 和 `redis:7.4-alpine` 镜像。服务就绪后，按“首次准备数据库”创建空 `webman` 库和专用账号，再通过管理端 `/install` 创建业务表。Compose 不导入 `database/schema.sql` 或 `database/seed.sql`。

使用这套服务时，PHP 应用 `.env` 或安装向导中的 `PG_PORT` 默认填 `5432`，`REDIS_PORT` 默认填 `6379`，Redis 使用 DB0。Compose 的宿主机端口直接读取同一份 `.env` 中的 `PG_PORT` 和 `REDIS_PORT`；如果显式修改端口，安装向导填写相同值。

仅停止这套服务并保留持久数据：

```powershell
docker compose stop
```

持久卷已初始化后，修改 `POSTGRES_PASSWORD` 不会更新数据库里已有账号的密码；修改密码需单独执行数据库维护。不要用删除持久卷的方式更新密码。

## 日常启动

### 1. 检查后台服务

本节的容器名称适用于上表的复用环境。使用本库 Compose 时，后台服务的启动、状态检查与停止使用上一节的 Compose 命令；后续 PHP 和管理端启动步骤相同。

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

首次安装依赖，或 Composer lock 更新后，执行：

```powershell
composer install
```

不要执行 `composer update` 或忽略平台要求来绕过版本检查。

### 3. 启动 PHP 后端

Windows 在已选择兼容 PHP 的终端执行：

```powershell
php windows.php
```

Linux 开发时前台运行：

```sh
php start.php start
```

Linux 需要后台运行时使用 `php start.php start -d`，停止后台进程使用 `php start.php stop`。Windows 在启动终端按 Ctrl+C 结束进程。

如果 `8787` 已被占用，先核对已有进程是否属于本 checkout，不重复启动，也不按端口直接结束其他项目的进程。修改 `.env` 后重启自己的 Webman 进程。

### 4. 启动管理端

在另一个终端进入管理端目录：

```powershell
Set-Location web/admin-web
npm ci
npm run dev
```

依赖未变化时可跳过 `npm ci`。打开 Vite 实际输出的地址，默认是 `http://localhost:5173`；如果 `5173` 已被占用，Vite 可能选择其他端口，先确认是否已有本项目的管理端。

首次安装进入管理端 `/install`，不是后端 `8787` 上的页面。数据库和 Redis 配置按下一节填写；已经安装完成的环境直接登录。

## 首次准备数据库

目标 PostgreSQL 实例中已有 `webman` 库、专用账号，且应用 `.env` 已指向该实例时跳过本节，不重复建库或轮换密码。

在选定的 PostgreSQL 实例中，新建 PHP 专用数据库和账号，不复用 `exre`、`hydra` 等其他业务库。应用不使用 PostgreSQL 管理账号。

复用本机现有实例时，使用管理账号 `user` 进入交互式 psql：

```powershell
docker exec -it exwebsite-runtime-postgres-1 psql -X -U user -d postgres
```

如果使用本库 Compose 创建的实例，改用其管理账号 `postgres`：

```powershell
docker compose exec postgres psql -X -U postgres -d postgres
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
| `PG_HOST` | `127.0.0.1` |
| `PG_PORT` | `5432` |
| `PG_DB` | `webman` |
| `PG_USER` | `webman` |
| `PG_PASSWORD` | 新建账号的密码，从本地 `.env` 读取。 |
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
docker compose exec postgres psql -X -h 127.0.0.1 -U webman -d webman -W
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

新数据库保持为空，使用默认初始化，不勾选强制重置。安装向导导入 `database/schema.sql` 的建表部分，创建你填写的超级管理员，保存 `.env` 和 `install.lock`，生成独立 JWT 密钥；不导入 `database/seed.sql`。

安装完成后保留 `.env`、`install.lock` 和数据库。后端的真实业务接口验证、管理端浏览器验证与外部部署验证分别记录，后台服务连通不代表安装或业务验收完成。
