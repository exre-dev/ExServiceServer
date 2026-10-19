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

## 首次部署初始化

1. 安装 Composer 依赖，准备 PHP 8.1+（启用 `pdo_pgsql`、`redis`、`gd`、`mbstring`）、PostgreSQL 和 Redis。
2. 准备目标 PostgreSQL 数据库及具备该数据库 `public` schema 建表权限的用户。默认需要空数据库；强制模式还需有业务表的删除权限。确保项目目录及 `.env` 可写；`.env` 不存在时会自动创建。
3. 启动后端（Linux：`php start.php start -d`；Windows：`php windows.php`），构建并部署 `web/admin-web` 管理端。网关将管理端静态资源指向其 `dist/`，SPA 路径回退到 `index.html`，`/api` 转发至后端 8787。
4. 第一次打开管理端任意页面会自动进入 `/install`，依次检查环境、填写 PostgreSQL / Redis 连接信息、设置超级管理员邮箱及密码。
5. 提交后自动导入 `database/schema.sql` 的建表部分、创建超级管理员、保存 `.env` 并生成独立 JWT 密钥，不导入 `seed.sql` 的测试账号。完成后进入登录页。

初始化接口免登录，且在数据库、Redis 未配置时可访问；其他业务接口在初始化前返回 503。默认拒绝覆盖已有 `app_` 业务表。数据库步骤可勾选“强制初始化（清空已有平台数据）”，提交前输入“重置数据库”确认后，将删除所填数据库 `public` 下全部 `app_` 业务表及数据，再重新建表并写入新管理员；其他表保留。删表和导入在同一个事务内完成，失败会回滚并允许修正后重试。存在其他表或视图依赖这些业务表时会拒绝重置并回滚。安装使用文件锁和 PostgreSQL 事务锁防止并发重复提交。

强制选项仅用于尚未完成初始化的部署，不绕过已完成安装的 `install.lock` 和旧部署识别保护。

安装状态保存在项目根目录 `install.lock`，请与 `.env`、数据库一起持久化备份，不要清除安装标记。已有 `.env` 且 PostgreSQL 中存在超级管理员的旧部署会自动识别为已初始化，保留现有账号和数据。安装完成后 HTTP 进程在下一次请求前自动加载新配置；后续手动修改配置仍应重启 Webman。

---

<div style="padding:18px;max-width: 1024px;margin:0 auto;background-color:#fff;color:#333">
<h1>webman</h1>

基于<a href="https://www.workerman.net" target="__blank">workerman</a>开发的超高性能PHP框架


<h1>学习</h1>

<ul>
  <li>
    <a href="https://www.workerman.net/webman" target="__blank">主页 / Home page</a>
  </li>
  <li>
    <a href="https://webman.workerman.net" target="__blank">文档 / Document</a>
  </li>
  <li>
    <a href="https://www.workerman.net/doc/webman/install.html" target="__blank">安装 / Install</a>
  </li>
  <li>
    <a href="https://www.workerman.net/questions" target="__blank">问答 / Questions</a>
  </li>
  <li>
    <a href="https://www.workerman.net/apps" target="__blank">市场 / Apps</a>
  </li>
  <li>
    <a href="https://www.workerman.net/sponsor" target="__blank">赞助 / Sponsors</a>
  </li>
  <li>
    <a href="https://www.workerman.net/doc/webman/thanks.html" target="__blank">致谢 / Thanks</a>
  </li>
</ul>

<div style="float:left;padding-bottom:30px;">

  <h1>赞助商</h1>

  <h4>特别赞助</h4>
  <a href="https://www.crmeb.com/?form=workerman" target="__blank">
    <img src="https://www.workerman.net/img/sponsors/6429/20230719111500.svg" width="200">
  </a>

  <h4>铂金赞助</h4>
  <a href="https://www.fadetask.com/?from=workerman" target="__blank"><img src="https://www.workerman.net/img/sponsors/1/20230719084316.png" width="200"></a>
  <a href="https://www.yilianyun.net/?from=workerman" target="__blank" style="margin-left:20px;"><img src="https://www.workerman.net/img/sponsors/6218/20230720114049.png" width="200"></a>


</div>


<div style="float:left;padding-bottom:30px;clear:both">

  <h1>请作者喝咖啡</h1>

<img src="https://www.workerman.net/img/wx_donate.png" width="200">
<img src="https://www.workerman.net/img/ali_donate.png" width="200">
<br>
<b>如果您觉得webman对您有所帮助，欢迎捐赠。</b>


</div>


<div style="clear: both">
<h1>LICENSE</h1>
The webman is open-sourced software licensed under the MIT.
</div>

</div>


