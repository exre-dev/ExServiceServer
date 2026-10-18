-- ============================================================
-- EXRE B2B2C 平台建库脚本 (蓝图 v0.3 §4, PostgreSQL)
-- 约定: app_ 前缀 / SERIAL 主键 / 时间戳 VARCHAR(50) 由 PHP 写入
-- 幂等: 重复执行先 DROP 再 CREATE (测试阶段, 不保数据)
-- ============================================================

DROP TABLE IF EXISTS app_notification_prefs CASCADE;
DROP TABLE IF EXISTS app_notifications CASCADE;
DROP TABLE IF EXISTS app_user_tags CASCADE;
DROP TABLE IF EXISTS app_tags CASCADE;
DROP TABLE IF EXISTS app_audit_logs CASCADE;
DROP TABLE IF EXISTS app_docs CASCADE;
DROP TABLE IF EXISTS app_doc_categories CASCADE;
DROP TABLE IF EXISTS app_server_client_links CASCADE;
DROP TABLE IF EXISTS app_oauth_grants CASCADE;
DROP TABLE IF EXISTS app_oauth_clients CASCADE;
DROP TABLE IF EXISTS app_member_plugin_perms CASCADE;
DROP TABLE IF EXISTS app_plugin_bindings CASCADE;
DROP TABLE IF EXISTS app_plugin_features CASCADE;
DROP TABLE IF EXISTS app_plugins CASCADE;
DROP TABLE IF EXISTS app_user_idents CASCADE;
DROP TABLE IF EXISTS app_auth_sessions CASCADE;
DROP TABLE IF EXISTS app_server_members CASCADE;
DROP TABLE IF EXISTS app_server_accounts CASCADE;
DROP TABLE IF EXISTS app_users CASCADE;

-- ------------------------------------------------------------
-- 001 用户表 (C 端账号, 同时承载平台角色)
-- ------------------------------------------------------------
CREATE TABLE app_users (
  id              SERIAL PRIMARY KEY,
  email           VARCHAR(255) NOT NULL,
  username        VARCHAR(64)  NOT NULL DEFAULT '',
  password_hash   VARCHAR(255) NOT NULL,
  avatar          VARCHAR(255) NOT NULL DEFAULT '/app/user/default-avatar.png',
  qq              VARCHAR(20)  DEFAULT NULL,
  level           INTEGER      NOT NULL DEFAULT 0,
  platform_role   VARCHAR(20)  NOT NULL DEFAULT '',
  ban             INTEGER      NOT NULL DEFAULT 0,
  last_login_time VARCHAR(50)  DEFAULT NULL,
  created_time    VARCHAR(50)  DEFAULT NULL,
  updated_time    VARCHAR(50)  DEFAULT NULL
);
CREATE UNIQUE INDEX uq_users_email ON app_users (email);
CREATE UNIQUE INDEX uq_users_qq ON app_users (qq) WHERE qq IS NOT NULL;
COMMENT ON TABLE app_users IS '用户账号表(C端)';
COMMENT ON COLUMN app_users.id IS '用户唯一标识,主键';
COMMENT ON COLUMN app_users.email IS '登录邮箱,全局唯一';
COMMENT ON COLUMN app_users.username IS '显示昵称';
COMMENT ON COLUMN app_users.password_hash IS '密码哈希(bcrypt, password_hash生成)';
COMMENT ON COLUMN app_users.avatar IS '头像地址';
COMMENT ON COLUMN app_users.qq IS '联系QQ号,可空,全局唯一(不含空值)';
COMMENT ON COLUMN app_users.level IS '用户等级,预留字段暂无业务消费';
COMMENT ON COLUMN app_users.platform_role IS '平台角色: 空=普通用户 / ops=运营 / superadmin=超级管理员';
COMMENT ON COLUMN app_users.ban IS '封禁标记: 0=正常 1=封禁(拒绝登录+吊销全部会话)';
COMMENT ON COLUMN app_users.last_login_time IS '最近一次登录时间';
COMMENT ON COLUMN app_users.created_time IS '创建时间';
COMMENT ON COLUMN app_users.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 002 服务器空间表 (B2, 平台超管创建)
-- ------------------------------------------------------------
CREATE TABLE app_server_accounts (
  id            SERIAL PRIMARY KEY,
  name          VARCHAR(64) NOT NULL,
  slug          VARCHAR(64) NOT NULL,
  description   TEXT        NOT NULL DEFAULT '',
  logo          VARCHAR(255) NOT NULL DEFAULT '',
  owner_user_id INTEGER     NOT NULL,
  contact_qq    VARCHAR(20) NOT NULL DEFAULT '',
  status        VARCHAR(20) NOT NULL DEFAULT 'active',
  note          TEXT        NOT NULL DEFAULT '',
  created_time  VARCHAR(50) DEFAULT NULL,
  updated_time  VARCHAR(50) DEFAULT NULL,
  CONSTRAINT fk_server_owner FOREIGN KEY (owner_user_id) REFERENCES app_users (id) ON DELETE CASCADE,
  CONSTRAINT ck_server_status CHECK (status IN ('active', 'suspended', 'closed'))
);
CREATE UNIQUE INDEX uq_server_slug ON app_server_accounts (slug);
COMMENT ON TABLE app_server_accounts IS '服务器空间表(B2端): 一个外部FiveM服务器主体一个空间';
COMMENT ON COLUMN app_server_accounts.id IS '空间唯一标识,主键';
COMMENT ON COLUMN app_server_accounts.name IS '空间名称';
COMMENT ON COLUMN app_server_accounts.slug IS '空间短标识,全局唯一,用于URL';
COMMENT ON COLUMN app_server_accounts.description IS '空间介绍';
COMMENT ON COLUMN app_server_accounts.logo IS '空间图标地址';
COMMENT ON COLUMN app_server_accounts.owner_user_id IS '空间Owner用户id(平台超管创建时指定的责任人)';
COMMENT ON COLUMN app_server_accounts.contact_qq IS '空间联系方式QQ';
COMMENT ON COLUMN app_server_accounts.status IS '状态: active=正常 suspended=暂停(冻结全部绑定码) closed=注销';
COMMENT ON COLUMN app_server_accounts.note IS '平台备注,仅超管可见';
COMMENT ON COLUMN app_server_accounts.created_time IS '创建时间';
COMMENT ON COLUMN app_server_accounts.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 003 空间成员表 (用户↔空间 多对多)
-- ------------------------------------------------------------
CREATE TABLE app_server_members (
  id           SERIAL PRIMARY KEY,
  server_id    INTEGER     NOT NULL,
  user_id      INTEGER     NOT NULL,
  role_key     VARCHAR(10) NOT NULL DEFAULT 'member',
  status       VARCHAR(10) NOT NULL DEFAULT 'active',
  source       VARCHAR(10) NOT NULL DEFAULT 'manual',
  joined_time  VARCHAR(50) DEFAULT NULL,
  created_time VARCHAR(50) DEFAULT NULL,
  updated_time VARCHAR(50) DEFAULT NULL,
  CONSTRAINT fk_member_server FOREIGN KEY (server_id) REFERENCES app_server_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE,
  CONSTRAINT ck_member_role CHECK (role_key IN ('owner', 'admin', 'member')),
  CONSTRAINT ck_member_status CHECK (status IN ('active', 'banned', 'left')),
  CONSTRAINT ck_member_source CHECK (source IN ('grant', 'manual', 'bind')),
  CONSTRAINT uq_member UNIQUE (server_id, user_id)
);
COMMENT ON TABLE app_server_members IS '空间成员表: 用户加入空间的关系与角色';
COMMENT ON COLUMN app_server_members.id IS '主键';
COMMENT ON COLUMN app_server_members.server_id IS '空间id';
COMMENT ON COLUMN app_server_members.user_id IS '用户id';
COMMENT ON COLUMN app_server_members.role_key IS '空间角色: owner=所有者 admin=管理员 member=普通成员';
COMMENT ON COLUMN app_server_members.status IS '成员状态: active=生效 banned=被踢禁 left=已离开';
COMMENT ON COLUMN app_server_members.source IS '入空间方式: grant=超管指定Owner manual=管理员手动添加 bind=游戏绑定码自动加入';
COMMENT ON COLUMN app_server_members.joined_time IS '加入时间';
COMMENT ON COLUMN app_server_members.created_time IS '创建时间';
COMMENT ON COLUMN app_server_members.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 004 用户游戏标识表 (license 全局一对一)
-- ------------------------------------------------------------
CREATE TABLE app_user_idents (
  id               SERIAL PRIMARY KEY,
  user_id          INTEGER      NOT NULL,
  ident_type       VARCHAR(20)  NOT NULL,
  ident_value      VARCHAR(255) NOT NULL,
  bound_server_id  INTEGER      DEFAULT NULL,
  created_time     VARCHAR(50)  DEFAULT NULL,
  CONSTRAINT fk_ident_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ident_server FOREIGN KEY (bound_server_id) REFERENCES app_server_accounts (id) ON DELETE SET NULL,
  CONSTRAINT ck_ident_type CHECK (ident_type IN ('fivem_license', 'steam', 'discord', 'qq')),
  CONSTRAINT uq_ident UNIQUE (ident_type, ident_value)
);
COMMENT ON TABLE app_user_idents IS '用户游戏标识表: 一个标识全局只能属于一个用户';
COMMENT ON COLUMN app_user_idents.id IS '主键';
COMMENT ON COLUMN app_user_idents.user_id IS '归属用户id';
COMMENT ON COLUMN app_user_idents.ident_type IS '标识类型: fivem_license/steam/discord/qq';
COMMENT ON COLUMN app_user_idents.ident_value IS '标识值,如 license:xxxx';
COMMENT ON COLUMN app_user_idents.bound_server_id IS '在哪个空间完成绑定(追溯用),空间删除后置空';
COMMENT ON COLUMN app_user_idents.created_time IS '绑定时间';

-- ------------------------------------------------------------
-- 005 插件目录表
-- ------------------------------------------------------------
CREATE TABLE app_plugins (
  id              SERIAL PRIMARY KEY,
  slug            VARCHAR(64) NOT NULL,
  name            VARCHAR(64) NOT NULL,
  summary         VARCHAR(255) NOT NULL DEFAULT '',
  description_md  TEXT        NOT NULL DEFAULT '',
  status          VARCHAR(10) NOT NULL DEFAULT 'draft',
  latest_version  VARCHAR(20) NOT NULL DEFAULT '',
  doc_category_id INTEGER     DEFAULT NULL,
  created_time    VARCHAR(50) DEFAULT NULL,
  updated_time    VARCHAR(50) DEFAULT NULL,
  CONSTRAINT ck_plugin_status CHECK (status IN ('draft', 'online', 'offline'))
);
CREATE UNIQUE INDEX uq_plugin_slug ON app_plugins (slug);
COMMENT ON TABLE app_plugins IS '插件目录表: 平台维护,无价格(售卖在外部平台)';
COMMENT ON COLUMN app_plugins.id IS '主键';
COMMENT ON COLUMN app_plugins.slug IS '插件唯一标识,与插件端共用,如 ex-tuner';
COMMENT ON COLUMN app_plugins.name IS '插件名称';
COMMENT ON COLUMN app_plugins.summary IS '一句话简介';
COMMENT ON COLUMN app_plugins.description_md IS '详情(Markdown)';
COMMENT ON COLUMN app_plugins.status IS '状态: draft=草稿 online=上线(可生成绑定码) offline=下架(校验直接拒绝)';
COMMENT ON COLUMN app_plugins.latest_version IS '最新版本号,展示用';
COMMENT ON COLUMN app_plugins.doc_category_id IS '平台文档默认分类id,预留';
COMMENT ON COLUMN app_plugins.created_time IS '创建时间';
COMMENT ON COLUMN app_plugins.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 006 插件功能点字典
-- ------------------------------------------------------------
CREATE TABLE app_plugin_features (
  id              SERIAL PRIMARY KEY,
  plugin_id       INTEGER     NOT NULL,
  feature_key     VARCHAR(64) NOT NULL,
  name            VARCHAR(64) NOT NULL,
  default_enabled BOOLEAN     NOT NULL DEFAULT TRUE,
  CONSTRAINT fk_feature_plugin FOREIGN KEY (plugin_id) REFERENCES app_plugins (id) ON DELETE CASCADE,
  CONSTRAINT uq_feature UNIQUE (plugin_id, feature_key)
);
COMMENT ON TABLE app_plugin_features IS '插件功能点字典: 权限矩阵的列头';
COMMENT ON COLUMN app_plugin_features.id IS '主键';
COMMENT ON COLUMN app_plugin_features.plugin_id IS '所属插件id';
COMMENT ON COLUMN app_plugin_features.feature_key IS '功能键,如 nitro; 有绑定码使用后不可改名';
COMMENT ON COLUMN app_plugin_features.name IS '功能中文名';
COMMENT ON COLUMN app_plugin_features.default_enabled IS '空间未显式配置功能面时是否默认开启';

-- ------------------------------------------------------------
-- 007 插件绑定码表 (服务开通凭证, 一空间一插件一码)
-- ------------------------------------------------------------
CREATE TABLE app_plugin_bindings (
  id                 SERIAL PRIMARY KEY,
  server_id          INTEGER      NOT NULL,
  plugin_id          INTEGER      NOT NULL,
  code               VARCHAR(40)  NOT NULL,
  status             VARCHAR(10)  NOT NULL DEFAULT 'active',
  server_key_hash    VARCHAR(64)  NOT NULL DEFAULT '',
  features           JSONB        NOT NULL DEFAULT '{}',
  expires_time       VARCHAR(50)  DEFAULT NULL,
  last_heartbeat     VARCHAR(50)  DEFAULT NULL,
  last_heartbeat_ip  VARCHAR(45)  NOT NULL DEFAULT '',
  created_by         INTEGER      DEFAULT NULL,
  created_time       VARCHAR(50)  DEFAULT NULL,
  updated_time       VARCHAR(50)  DEFAULT NULL,
  CONSTRAINT fk_binding_server FOREIGN KEY (server_id) REFERENCES app_server_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_binding_plugin FOREIGN KEY (plugin_id) REFERENCES app_plugins (id) ON DELETE CASCADE,
  CONSTRAINT ck_binding_status CHECK (status IN ('active', 'revoked', 'disabled')),
  CONSTRAINT uq_binding UNIQUE (server_id, plugin_id)
);
CREATE UNIQUE INDEX uq_binding_code ON app_plugin_bindings (code);
COMMENT ON TABLE app_plugin_bindings IS '插件绑定码表: 空间管理员生成、填入外部购买的插件完成校验即开通服务';
COMMENT ON COLUMN app_plugin_bindings.id IS '主键';
COMMENT ON COLUMN app_plugin_bindings.server_id IS '空间id';
COMMENT ON COLUMN app_plugin_bindings.plugin_id IS '插件id';
COMMENT ON COLUMN app_plugin_bindings.code IS '绑定码: EX-XXXX-XXXX-XXXX-XXXX-XXXX 全局唯一';
COMMENT ON COLUMN app_plugin_bindings.status IS '状态: active=生效 revoked=超管吊销 disabled=空间自行停用';
COMMENT ON COLUMN app_plugin_bindings.server_key_hash IS '首次校验锁定的 sv_licenseKey SHA-256(只存哈希); 换服需重置码';
COMMENT ON COLUMN app_plugin_bindings.features IS '该空间开通的功能面 {feature_key:bool}, 空对象=插件全部默认开启功能';
COMMENT ON COLUMN app_plugin_bindings.expires_time IS '过期时间,空=长期(外部订阅期由售卖平台管)';
COMMENT ON COLUMN app_plugin_bindings.last_heartbeat IS '最近心跳时间,超3个周期(30分钟)视为异常';
COMMENT ON COLUMN app_plugin_bindings.last_heartbeat_ip IS '最近心跳来源IP';
COMMENT ON COLUMN app_plugin_bindings.created_by IS '生成者用户id(审计)';
COMMENT ON COLUMN app_plugin_bindings.created_time IS '创建时间';
COMMENT ON COLUMN app_plugin_bindings.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 008 成员插件权限表 (需求6: 服务器控制用户对插件的使用与功能)
-- ------------------------------------------------------------
CREATE TABLE app_member_plugin_perms (
  id           SERIAL PRIMARY KEY,
  server_id    INTEGER     NOT NULL,
  user_id      INTEGER     NOT NULL,
  plugin_id    INTEGER     NOT NULL,
  allowed      BOOLEAN     NOT NULL DEFAULT TRUE,
  features     JSONB       DEFAULT NULL,
  note         VARCHAR(255) NOT NULL DEFAULT '',
  updated_by   INTEGER     DEFAULT NULL,
  created_time VARCHAR(50) DEFAULT NULL,
  updated_time VARCHAR(50) DEFAULT NULL,
  CONSTRAINT fk_perm_server FOREIGN KEY (server_id) REFERENCES app_server_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_perm_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_perm_plugin FOREIGN KEY (plugin_id) REFERENCES app_plugins (id) ON DELETE CASCADE,
  CONSTRAINT uq_perm UNIQUE (server_id, user_id, plugin_id)
);
COMMENT ON TABLE app_member_plugin_perms IS '成员插件权限表: 服务器级功能面内的用户级收窄,无记录=默认允许全量';
COMMENT ON COLUMN app_member_plugin_perms.id IS '主键';
COMMENT ON COLUMN app_member_plugin_perms.server_id IS '空间id';
COMMENT ON COLUMN app_member_plugin_perms.user_id IS '用户id';
COMMENT ON COLUMN app_member_plugin_perms.plugin_id IS '插件id';
COMMENT ON COLUMN app_member_plugin_perms.allowed IS '是否允许该用户使用此插件,false=空间禁用某人';
COMMENT ON COLUMN app_member_plugin_perms.features IS '功能覆盖 {feature_key:bool},空=继承绑定码全量功能面';
COMMENT ON COLUMN app_member_plugin_perms.note IS '管理员备注';
COMMENT ON COLUMN app_member_plugin_perms.updated_by IS '最后修改人用户id';
COMMENT ON COLUMN app_member_plugin_perms.created_time IS '创建时间';
COMMENT ON COLUMN app_member_plugin_perms.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 009 OAuth 应用表 (第三方平台/插件, 仅超管管理)
-- ------------------------------------------------------------
CREATE TABLE app_oauth_clients (
  id                SERIAL PRIMARY KEY,
  client_id         VARCHAR(64) NOT NULL,
  name              VARCHAR(64) NOT NULL,
  type              VARCHAR(20) NOT NULL DEFAULT 'third_party',
  server_id         INTEGER     DEFAULT NULL,
  client_secret_hash VARCHAR(64) NOT NULL DEFAULT '',
  redirect_uris     JSONB       NOT NULL DEFAULT '[]',
  scopes            JSONB       NOT NULL DEFAULT '[]',
  status            VARCHAR(10) NOT NULL DEFAULT 'active',
  note              TEXT        NOT NULL DEFAULT '',
  created_by        INTEGER     DEFAULT NULL,
  created_time      VARCHAR(50) DEFAULT NULL,
  updated_time      VARCHAR(50) DEFAULT NULL,
  CONSTRAINT fk_client_server FOREIGN KEY (server_id) REFERENCES app_server_accounts (id) ON DELETE CASCADE,
  CONSTRAINT ck_client_type CHECK (type IN ('third_party', 'plugin')),
  CONSTRAINT ck_client_status CHECK (status IN ('active', 'suspended'))
);
CREATE UNIQUE INDEX uq_client_id ON app_oauth_clients (client_id);
COMMENT ON TABLE app_oauth_clients IS 'OAuth应用表: 第三方平台与插件的接入凭证,注册/密钥/停用仅平台超管可操作';
COMMENT ON COLUMN app_oauth_clients.id IS '主键';
COMMENT ON COLUMN app_oauth_clients.client_id IS '公开client_id,随机32位';
COMMENT ON COLUMN app_oauth_clients.name IS '应用名称';
COMMENT ON COLUMN app_oauth_clients.type IS '类型: third_party=外部平台(授权码+PKCE) plugin=插件(设备码流)';
COMMENT ON COLUMN app_oauth_clients.server_id IS 'plugin类应用归属的空间id,空=平台级';
COMMENT ON COLUMN app_oauth_clients.client_secret_hash IS 'client_secret的SHA-256哈希,明文仅注册/重置时展示一次';
COMMENT ON COLUMN app_oauth_clients.redirect_uris IS '回调地址白名单JSON数组,授权码流必须精确匹配';
COMMENT ON COLUMN app_oauth_clients.scopes IS '允许申请的scope列表JSON数组';
COMMENT ON COLUMN app_oauth_clients.status IS '状态: active=生效 suspended=超管停用(全部token立即失效)';
COMMENT ON COLUMN app_oauth_clients.note IS '对接备注,仅超管可见';
COMMENT ON COLUMN app_oauth_clients.created_by IS '创建者用户id';
COMMENT ON COLUMN app_oauth_clients.created_time IS '创建时间';
COMMENT ON COLUMN app_oauth_clients.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 010 用户↔应用授权表 (撤销对象)
-- ------------------------------------------------------------
CREATE TABLE app_oauth_grants (
  id           SERIAL PRIMARY KEY,
  user_id      INTEGER     NOT NULL,
  client_id    VARCHAR(64) NOT NULL,
  scopes       JSONB       NOT NULL DEFAULT '[]',
  status       VARCHAR(10) NOT NULL DEFAULT 'active',
  granted_time VARCHAR(50) DEFAULT NULL,
  revoked_time VARCHAR(50) DEFAULT NULL,
  revoked_by   VARCHAR(20) NOT NULL DEFAULT '',
  created_time VARCHAR(50) DEFAULT NULL,
  updated_time VARCHAR(50) DEFAULT NULL,
  CONSTRAINT fk_grant_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_grant_client FOREIGN KEY (client_id) REFERENCES app_oauth_clients (client_id) ON DELETE CASCADE,
  CONSTRAINT ck_grant_status CHECK (status IN ('active', 'revoked')),
  CONSTRAINT ck_grant_revoked_by CHECK (revoked_by IN ('', 'user', 'server_admin', 'platform')),
  CONSTRAINT uq_grant UNIQUE (user_id, client_id)
);
COMMENT ON TABLE app_oauth_grants IS '用户对应用的授权关系表: 用户可在账号安全中心撤销';
COMMENT ON COLUMN app_oauth_grants.id IS '主键';
COMMENT ON COLUMN app_oauth_grants.user_id IS '授权用户id';
COMMENT ON COLUMN app_oauth_grants.client_id IS '被授权的应用client_id';
COMMENT ON COLUMN app_oauth_grants.scopes IS '实际授予的scope列表JSON数组';
COMMENT ON COLUMN app_oauth_grants.status IS '状态: active=生效 revoked=已撤销(该应用名下该用户token全失效)';
COMMENT ON COLUMN app_oauth_grants.granted_time IS '授权时间';
COMMENT ON COLUMN app_oauth_grants.revoked_time IS '撤销时间';
COMMENT ON COLUMN app_oauth_grants.revoked_by IS '撤销方: user=用户本人 server_admin=空间管理员 platform=平台';
COMMENT ON COLUMN app_oauth_grants.created_time IS '创建时间';
COMMENT ON COLUMN app_oauth_grants.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 011 空间↔应用关联表 (空间管理员撤销对象)
-- ------------------------------------------------------------
CREATE TABLE app_server_client_links (
  id                SERIAL PRIMARY KEY,
  server_id         INTEGER     NOT NULL,
  client_id         VARCHAR(64) NOT NULL,
  status            VARCHAR(20) NOT NULL DEFAULT 'active',
  linked_time       VARCHAR(50) DEFAULT NULL,
  disconnected_time VARCHAR(50) DEFAULT NULL,
  created_time      VARCHAR(50) DEFAULT NULL,
  updated_time      VARCHAR(50) DEFAULT NULL,
  CONSTRAINT fk_link_server FOREIGN KEY (server_id) REFERENCES app_server_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_link_client FOREIGN KEY (client_id) REFERENCES app_oauth_clients (client_id) ON DELETE CASCADE,
  CONSTRAINT ck_link_status CHECK (status IN ('active', 'disconnected')),
  CONSTRAINT uq_link UNIQUE (server_id, client_id)
);
COMMENT ON TABLE app_server_client_links IS '空间与应用关联表: 空间管理员可断开,断开后该应用无法获取本空间数据';
COMMENT ON COLUMN app_server_client_links.id IS '主键';
COMMENT ON COLUMN app_server_client_links.server_id IS '空间id';
COMMENT ON COLUMN app_server_client_links.client_id IS '应用client_id';
COMMENT ON COLUMN app_server_client_links.status IS '状态: active=关联生效 disconnected=已断开';
COMMENT ON COLUMN app_server_client_links.linked_time IS '关联时间';
COMMENT ON COLUMN app_server_client_links.disconnected_time IS '断开时间';
COMMENT ON COLUMN app_server_client_links.created_time IS '创建时间';
COMMENT ON COLUMN app_server_client_links.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 012 登录会话表 (多设备/多平台记录, 撤销token的对象)
-- ------------------------------------------------------------
CREATE TABLE app_auth_sessions (
  id             SERIAL PRIMARY KEY,
  user_id        INTEGER     NOT NULL,
  login_type     VARCHAR(10) NOT NULL DEFAULT 'password',
  client_id      VARCHAR(64) DEFAULT NULL,
  device_name    VARCHAR(64) NOT NULL DEFAULT '',
  platform       VARCHAR(20) NOT NULL DEFAULT 'web',
  ip             VARCHAR(45) NOT NULL DEFAULT '',
  ua_hash        VARCHAR(64) NOT NULL DEFAULT '',
  scope          VARCHAR(255) NOT NULL DEFAULT '',
  status         VARCHAR(10) NOT NULL DEFAULT 'active',
  created_time   VARCHAR(50) DEFAULT NULL,
  last_used_time VARCHAR(50) DEFAULT NULL,
  expires_time   VARCHAR(50) DEFAULT NULL,
  updated_time   VARCHAR(50) DEFAULT NULL,
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE,
  CONSTRAINT ck_session_type CHECK (login_type IN ('password', 'oauth')),
  CONSTRAINT ck_session_platform CHECK (platform IN ('web', 'fivem', 'third_party')),
  CONSTRAINT ck_session_status CHECK (status IN ('active', 'revoked', 'expired'))
);
CREATE INDEX idx_session_user ON app_auth_sessions (user_id);
COMMENT ON TABLE app_auth_sessions IS '登录会话表: 每次登录(密码/SSO/插件)一条记录,id写入JWT的sid claim';
COMMENT ON COLUMN app_auth_sessions.id IS '会话id,即JWT中的sid';
COMMENT ON COLUMN app_auth_sessions.user_id IS '归属用户id';
COMMENT ON COLUMN app_auth_sessions.login_type IS '登录方式: password=密码 oauth=SSO签发';
COMMENT ON COLUMN app_auth_sessions.client_id IS 'OAuth时关联的应用client_id,空=本站登录';
COMMENT ON COLUMN app_auth_sessions.device_name IS '设备名(UA自动识别),插件类如 FiveM插件·ex-tuner';
COMMENT ON COLUMN app_auth_sessions.platform IS '平台: web=管理端网页 fivem=游戏内 third_party=第三方平台';
COMMENT ON COLUMN app_auth_sessions.ip IS '登录来源IP';
COMMENT ON COLUMN app_auth_sessions.ua_hash IS 'UA摘要(SHA-256前64位),识别同设备';
COMMENT ON COLUMN app_auth_sessions.scope IS 'OAuth时授予的scope,逗号分隔';
COMMENT ON COLUMN app_auth_sessions.status IS '状态: active=生效 revoked=已撤销 expired=已过期';
COMMENT ON COLUMN app_auth_sessions.created_time IS '登录时间';
COMMENT ON COLUMN app_auth_sessions.last_used_time IS '最近使用时间(5分钟节流更新)';
COMMENT ON COLUMN app_auth_sessions.expires_time IS '会话过期时间,与JWT access token同寿';
COMMENT ON COLUMN app_auth_sessions.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 013 文档分类表
-- ------------------------------------------------------------
CREATE TABLE app_doc_categories (
  id           SERIAL PRIMARY KEY,
  owner_type   VARCHAR(10) NOT NULL DEFAULT 'platform',
  server_id    INTEGER     DEFAULT NULL,
  name         VARCHAR(64) NOT NULL,
  sort         INTEGER     NOT NULL DEFAULT 0,
  created_time VARCHAR(50) DEFAULT NULL,
  updated_time VARCHAR(50) DEFAULT NULL,
  CONSTRAINT fk_doccat_server FOREIGN KEY (server_id) REFERENCES app_server_accounts (id) ON DELETE CASCADE,
  CONSTRAINT ck_doccat_owner CHECK (owner_type IN ('platform', 'server'))
);
COMMENT ON TABLE app_doc_categories IS '文档分类表: 平台文档与空间文档共用,靠owner_type区分';
COMMENT ON COLUMN app_doc_categories.id IS '主键';
COMMENT ON COLUMN app_doc_categories.owner_type IS '归属: platform=平台 server=空间';
COMMENT ON COLUMN app_doc_categories.server_id IS 'owner_type=server时的空间id';
COMMENT ON COLUMN app_doc_categories.name IS '分类名';
COMMENT ON COLUMN app_doc_categories.sort IS '排序,小的在前';
COMMENT ON COLUMN app_doc_categories.created_time IS '创建时间';
COMMENT ON COLUMN app_doc_categories.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 014 文档表
-- ------------------------------------------------------------
CREATE TABLE app_docs (
  id              SERIAL PRIMARY KEY,
  owner_type      VARCHAR(10)  NOT NULL DEFAULT 'platform',
  server_id       INTEGER      DEFAULT NULL,
  category_id     INTEGER      DEFAULT NULL,
  title           VARCHAR(128) NOT NULL,
  slug            VARCHAR(128) NOT NULL,
  content_md      TEXT         NOT NULL DEFAULT '',
  visibility      VARCHAR(10)  NOT NULL DEFAULT 'members',
  visible_roles   JSONB        DEFAULT NULL,
  visible_servers JSONB        DEFAULT NULL,
  visible_tags    JSONB        DEFAULT NULL,
  status          VARCHAR(10)  NOT NULL DEFAULT 'draft',
  author_user_id  INTEGER      DEFAULT NULL,
  version         INTEGER      NOT NULL DEFAULT 1,
  created_time    VARCHAR(50)  DEFAULT NULL,
  updated_time    VARCHAR(50)  DEFAULT NULL,
  CONSTRAINT fk_doc_server FOREIGN KEY (server_id) REFERENCES app_server_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_category FOREIGN KEY (category_id) REFERENCES app_doc_categories (id) ON DELETE SET NULL,
  CONSTRAINT ck_doc_owner CHECK (owner_type IN ('platform', 'server')),
  CONSTRAINT ck_doc_visibility CHECK (visibility IN ('public', 'login', 'members', 'roles', 'servers', 'tags')),
  CONSTRAINT ck_doc_status CHECK (status IN ('draft', 'published'))
);
CREATE UNIQUE INDEX uq_doc_slug ON app_docs (slug);
COMMENT ON TABLE app_docs IS '文档表: Markdown存储,v1不存历史版本';
COMMENT ON COLUMN app_docs.id IS '主键';
COMMENT ON COLUMN app_docs.owner_type IS '归属: platform=平台(插件手册) server=空间';
COMMENT ON COLUMN app_docs.server_id IS 'owner_type=server时的空间id';
COMMENT ON COLUMN app_docs.category_id IS '分类id,分类删除后置空';
COMMENT ON COLUMN app_docs.title IS '标题';
COMMENT ON COLUMN app_docs.slug IS '全局唯一URL标识';
COMMENT ON COLUMN app_docs.content_md IS '正文Markdown';
COMMENT ON COLUMN app_docs.visibility IS '可见性: public=任何人 members=本空间active成员 roles=指定角色';
COMMENT ON COLUMN app_docs.visible_roles IS 'visibility=roles时的角色数组,owner/admin恒可见';
COMMENT ON COLUMN app_docs.visible_servers IS 'visibility=servers时可见的空间id数组(平台文档用户组)';
COMMENT ON COLUMN app_docs.visible_tags IS 'visibility=tags时可见的用户标签id数组';
COMMENT ON COLUMN app_docs.status IS '状态: draft=草稿 published=已发布';
COMMENT ON COLUMN app_docs.author_user_id IS '作者用户id';
COMMENT ON COLUMN app_docs.version IS '版本号,每次编辑+1';
COMMENT ON COLUMN app_docs.created_time IS '创建时间';
COMMENT ON COLUMN app_docs.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 016 用户标签字典
-- ------------------------------------------------------------
CREATE TABLE app_tags (
  id          SERIAL PRIMARY KEY,
  name        VARCHAR(64)  NOT NULL,
  note        VARCHAR(255) NOT NULL DEFAULT '',
  created_time VARCHAR(50) DEFAULT NULL,
  CONSTRAINT uq_tag_name UNIQUE (name)
);
COMMENT ON TABLE app_tags IS '用户标签字典';
COMMENT ON COLUMN app_tags.id IS '主键';
COMMENT ON COLUMN app_tags.name IS '标签名,全局唯一';
COMMENT ON COLUMN app_tags.note IS '备注';
COMMENT ON COLUMN app_tags.created_time IS '创建时间';

-- ------------------------------------------------------------
-- 017 用户↔标签关联
-- ------------------------------------------------------------
CREATE TABLE app_user_tags (
  id           SERIAL PRIMARY KEY,
  user_id      INTEGER     NOT NULL,
  tag_id       INTEGER     NOT NULL,
  created_time VARCHAR(50) DEFAULT NULL,
  CONSTRAINT fk_utag_user FOREIGN KEY (user_id) REFERENCES app_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_utag_tag FOREIGN KEY (tag_id) REFERENCES app_tags (id) ON DELETE CASCADE,
  CONSTRAINT uq_user_tag UNIQUE (user_id, tag_id)
);
COMMENT ON TABLE app_user_tags IS '用户↔标签关联';
COMMENT ON COLUMN app_user_tags.user_id IS '用户id';
COMMENT ON COLUMN app_user_tags.tag_id IS '标签id';
COMMENT ON COLUMN app_user_tags.created_time IS '打标时间';

-- ------------------------------------------------------------
-- 015 审计日志表
-- ------------------------------------------------------------
CREATE TABLE app_audit_logs (
  id            SERIAL PRIMARY KEY,
  actor_user_id INTEGER      DEFAULT NULL,
  actor_type    VARCHAR(10)  NOT NULL DEFAULT 'user',
  server_id     INTEGER      DEFAULT NULL,
  client_id     VARCHAR(64)  DEFAULT NULL,
  action        VARCHAR(64)  NOT NULL,
  target_type   VARCHAR(32)  NOT NULL DEFAULT '',
  target_id     VARCHAR(64)  NOT NULL DEFAULT '',
  detail        JSONB        DEFAULT NULL,
  ip            VARCHAR(45)  NOT NULL DEFAULT '',
  created_time  VARCHAR(50)  DEFAULT NULL
);
CREATE INDEX idx_audit_actor ON app_audit_logs (actor_user_id);
CREATE INDEX idx_audit_action ON app_audit_logs (action);
COMMENT ON TABLE app_audit_logs IS '审计日志表: 敏感写操作全量记录';
COMMENT ON COLUMN app_audit_logs.id IS '主键';
COMMENT ON COLUMN app_audit_logs.actor_user_id IS '操作者用户id,系统操作为空';
COMMENT ON COLUMN app_audit_logs.actor_type IS '操作者类型: platform/server/user/client';
COMMENT ON COLUMN app_audit_logs.server_id IS '相关空间id,无则空';
COMMENT ON COLUMN app_audit_logs.client_id IS '相关应用client_id,无则空';
COMMENT ON COLUMN app_audit_logs.action IS '动作名,如 user.login / binding.revoke / client.create';
COMMENT ON COLUMN app_audit_logs.target_type IS '目标类型,如 server/binding/session/client';
COMMENT ON COLUMN app_audit_logs.target_id IS '目标id';
COMMENT ON COLUMN app_audit_logs.detail IS '详情JSON';
COMMENT ON COLUMN app_audit_logs.ip IS '来源IP';
COMMENT ON COLUMN app_audit_logs.created_time IS '记录时间';

-- ------------------------------------------------------------
-- 016 站内通知表（用户侧网站, 每行=一条个人回执）
-- ------------------------------------------------------------
CREATE TABLE app_notifications (
  id             SERIAL PRIMARY KEY,
  uuid           CHAR(36)    NOT NULL,
  batch_uuid     CHAR(36)    NOT NULL,
  user_id        INTEGER     NOT NULL,
  scope          VARCHAR(20) NOT NULL DEFAULT 'personal',
  category       VARCHAR(30) NOT NULL DEFAULT 'announcement',
  necessity      VARCHAR(10) NOT NULL DEFAULT 'optional',
  title          VARCHAR(240) NOT NULL,
  summary_text   VARCHAR(320) NOT NULL DEFAULT '',
  body_html      TEXT         DEFAULT NULL,
  body_text      TEXT         DEFAULT NULL,
  actions        JSONB        DEFAULT NULL,
  expires_time   VARCHAR(50)  DEFAULT NULL,
  read_at        BIGINT       DEFAULT NULL,
  cleared_at     BIGINT       DEFAULT NULL,
  state_revision INTEGER      NOT NULL DEFAULT 0,
  clear_revision INTEGER      NOT NULL DEFAULT 0,
  created_time   VARCHAR(50)  DEFAULT NULL,
  updated_time   VARCHAR(50)  DEFAULT NULL
);
CREATE UNIQUE INDEX uq_notification_uuid ON app_notifications (uuid);
CREATE INDEX idx_notification_batch ON app_notifications (batch_uuid);
CREATE INDEX idx_notification_user ON app_notifications (user_id, id);
COMMENT ON TABLE app_notifications IS '站内通知表: 每行是一条个人回执(receipt), uuid 即契约 receiptId';
COMMENT ON COLUMN app_notifications.id IS '主键, 兼作个人投递序号 sequence(新->旧翻页)';
COMMENT ON COLUMN app_notifications.uuid IS '回执UUID(v4), 对外 receiptId';
COMMENT ON COLUMN app_notifications.batch_uuid IS '发送批次UUID: 一次管理端发送(广播或个人)共用, 撤回/删除按批';
COMMENT ON COLUMN app_notifications.user_id IS '归属用户id';
COMMENT ON COLUMN app_notifications.scope IS '范围: community=社区广播 personal=个人';
COMMENT ON COLUMN app_notifications.category IS '类别: announcement/personal_update/service_notice';
COMMENT ON COLUMN app_notifications.necessity IS '必要性: optional=可关闭投影 necessary=必要(偏好不可关)';
COMMENT ON COLUMN app_notifications.title IS '标题';
COMMENT ON COLUMN app_notifications.summary_text IS '列表摘要纯文本';
COMMENT ON COLUMN app_notifications.body_html IS '正文安全HTML(录入端约束, 无脚本/事件/样式)';
COMMENT ON COLUMN app_notifications.body_text IS '正文纯文本回退';
COMMENT ON COLUMN app_notifications.actions IS '动作数组JSON [{actionId,kind,label,emphasis,enabled,unavailableReason,navigationTarget}]';
COMMENT ON COLUMN app_notifications.expires_time IS '过期时间,空=不过期(过期后不入未读)';
COMMENT ON COLUMN app_notifications.read_at IS '已读时间戳(unix秒),空=未读';
COMMENT ON COLUMN app_notifications.cleared_at IS '清理时间戳(unix秒),不可恢复';
COMMENT ON COLUMN app_notifications.state_revision IS '读状态修订号,乐观锁用';
COMMENT ON COLUMN app_notifications.clear_revision IS '清理修订号';
COMMENT ON COLUMN app_notifications.created_time IS '创建时间';
COMMENT ON COLUMN app_notifications.updated_time IS '更新时间';

-- ------------------------------------------------------------
-- 017 通知偏好表
-- ------------------------------------------------------------
CREATE TABLE app_notification_prefs (
  user_id        INTEGER PRIMARY KEY,
  announcement   BOOLEAN     NOT NULL DEFAULT TRUE,
  personal_update BOOLEAN    NOT NULL DEFAULT TRUE,
  revision       INTEGER     NOT NULL DEFAULT 0,
  updated_time   VARCHAR(50) DEFAULT NULL
);
COMMENT ON TABLE app_notification_prefs IS '通知偏好表: optional 类别开关(整体替换+乐观锁)';
COMMENT ON COLUMN app_notification_prefs.user_id IS '用户id(主键)';
COMMENT ON COLUMN app_notification_prefs.announcement IS 'announcement 类别投影开关';
COMMENT ON COLUMN app_notification_prefs.personal_update IS 'personal_update 类别投影开关';
COMMENT ON COLUMN app_notification_prefs.revision IS '偏好修订号,乐观锁用';
COMMENT ON COLUMN app_notification_prefs.updated_time IS '更新时间';
