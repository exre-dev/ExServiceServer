# 本地测试环境

需要本地跑起来联调时读本文件。

- PostgreSQL 跑在 docker 容器 `webman-pgsql`（`postgres:16-alpine`，镜像经 `docker.m.daocloud.io` 加速源拉取，Docker Hub 直连不通）。端口 `127.0.0.1:5432`，数据持久化在命名卷 `webman_pgsql_data`；启停：`docker start/stop webman-pgsql`。
- 连接凭据在 `.env` 的 `POSTGRES_*` 键（`DB_*` 是 MySQL 占位，别混用）；`config/database.php` 中 `default => 'pgsql'`。
- **建库脚本**：`database/schema.sql`（`app_*` 表、每字段中文 COMMENT、幂等 DROP+CREATE）、`database/seed.sql`（种子数据）。改表结构就改 schema.sql 重新执行（测试阶段不写迁移）。
- 种子账号：超管 `admin@exre.local / Admin@2026`，空间 Owner `test@example.com / 123456`，普通成员 `p0-test@example.com / abc123`。
- 启动本地验证：`php windows.php` 后台跑，curl `http://127.0.0.1:8787`；Redis 在本机 6379。
- 注意：Git Bash 下 curl 传中文 JSON body 会被转码导致 post() 解析为空（报"参数不匹配"），测试接口用 ASCII body 或写临时 PHP 脚本。
- `Limit` 中间件在 debug 开启时对 127.0.0.1/::1 自动豁免，本地联调不会误触限流。
