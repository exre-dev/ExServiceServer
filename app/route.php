<?php

use Webman\Route;

/** options 请求（CORS 预检） */
Route::options('[{path:.+}]', function () {
  return response('');
});

// ============================================================
// 所有后端接口统一挂在 /api 前缀下（前端侧 axios baseURL='/api'）
// ============================================================
Route::group('/api', function () {

  // 首次部署初始化（免登录，完成后禁止再次安装）
  Route::get('/installation/status', [app\system\InstallationController::class, 'status']);
  // 导入数据库并创建首个超级管理员
  Route::post('/installation/install', [app\system\InstallationController::class, 'install']);

  // ==================== 账号安全中心 ====================
  Route::group('/account', function () {
    // 登录
    Route::post('/login', [app\account\AccountController::class, 'login']);
    // 注册
    Route::post('/register', [app\account\AccountController::class, 'register']);
    // 发送邮箱验证码（注册用, 60秒冷却）
    Route::post('/send-email-code', [app\account\AccountController::class, 'send_email_code']);
    // 登出（撤销当前会话）
    Route::post('/logout', [app\account\AccountController::class, 'logout']);
    // 当前登录用户信息
    Route::get('/me', [app\account\AccountController::class, 'me']);

    // 设备与会话管理（P4）
    Route::get('/sessions', [app\account\AccountController::class, 'sessions']);
    Route::post('/sessions/revoke-all', [app\account\AccountController::class, 'session_revoke_all']);
    Route::post('/sessions/{sid}/revoke', [app\account\AccountController::class, 'session_revoke']);

    // 第三方授权管理（P4）
    Route::get('/grants', [app\account\AccountController::class, 'grants']);
    Route::post('/grants/{client_id}/revoke', [app\account\AccountController::class, 'grant_revoke']);

    // 修改密码（P4）
    Route::post('/password', [app\account\AccountController::class, 'password']);
  });

  // ==================== 用户侧网站对接（user-web, ExWebsite Contract） ====================
  // 契约路径 /auth/*（openapi.yaml）复用 account 控制器实现, 响应遵循全站 success/fail 方言
  Route::group('/auth', function () {
    // 登录
    Route::post('/login', [app\account\AccountController::class, 'login']);
    // 图片验证码挑战（登录/注册流共用, 5 分钟一次性）
    Route::post('/captcha/challenges', [app\account\AccountController::class, 'captcha_challenge']);
    // 登出（撤销当前会话）
    Route::post('/logout', [app\account\AccountController::class, 'logout']);
    // 当前登录用户信息
    Route::get('/me', [app\account\AccountController::class, 'me']);
    // 更新当前用户资料（昵称/QQ/清除头像）
    Route::patch('/me', [app\account\AccountController::class, 'profile_update']);
    // 上传头像（base64, 统一转 WebP）
    Route::post('/me/avatar', [app\account\AccountController::class, 'avatar_upload']);
    // 邀请码注册（资格/发码/校验/完成）
    Route::post('/invitations/check', [app\account\AccountController::class, 'invitation_check']);
    Route::post('/invitations/code', [app\account\AccountController::class, 'invitation_code']);
    Route::post('/invitations/verify', [app\account\AccountController::class, 'invitation_verify']);
    Route::post('/invitations/complete', [app\account\AccountController::class, 'invitation_complete']);
    // SSO 接收端（ExWebsite 登录 UI 操作 ssoRequestId; confirm 走 CheckLogin）
    Route::post('/sso/inspect', [app\oauth\SsoController::class, 'inspect']);
    Route::post('/sso/login', [app\oauth\SsoController::class, 'login']);
    Route::post('/sso/confirm', [app\oauth\SsoController::class, 'confirm']);
  });
  // 头像读取（公开, 文件在 runtime/avatars/{43位id}.webp）
  Route::get('/avatars/{avatarId}', [app\account\AccountController::class, 'avatar_image']);

  // ==================== 健康检查（system, 公开） ====================
  Route::get('/health', [app\system\HealthController::class, 'index']);

  // ==================== 站内通知（用户侧网站, 登录） ====================
  // 固定段路由须在 {notificationId} 参数路由之前注册
  Route::get('/notifications/summary', [app\notify\NotificationController::class, 'summary']);
  Route::post('/notifications/read-all', [app\notify\NotificationController::class, 'read_all']);
  Route::post('/notifications/clear-preview', [app\notify\NotificationController::class, 'clear_preview']);
  Route::post('/notifications/clear-all', [app\notify\NotificationController::class, 'clear_all']);
  // 通知列表（keyset 分页）
  Route::get('/notifications', [app\notify\NotificationController::class, 'index']);
  // 通知详情
  Route::get('/notifications/{notificationId}', [app\notify\NotificationController::class, 'detail']);
  // 单条已读/未读（乐观锁 409）
  Route::patch('/notifications/{notificationId}/state', [app\notify\NotificationController::class, 'state']);

  // ==================== 通知偏好（用户侧网站, 登录） ====================
  Route::get('/notification-preferences', [app\notify\PreferenceController::class, 'index']);
  Route::put('/notification-preferences', [app\notify\PreferenceController::class, 'update']);

  // ==================== 通用内容存储（用户侧, file 介质） ====================
  Route::post('/storage/uploads/prepare', [app\storage\StorageController::class, 'prepare']);
  Route::post('/storage/uploads', [app\storage\StorageController::class, 'upload']);
  Route::post('/storage/uploads/complete', [app\storage\StorageController::class, 'complete']);
  Route::get('/storage/files', [app\storage\StorageController::class, 'files']);

  // ==================== 用户反馈（用户侧, 登录） ====================
  Route::get('/support/feedback/options', [app\support\FeedbackController::class, 'options']);
  Route::post('/support/feedback/submissions', [app\support\FeedbackController::class, 'prepare']);
  Route::get('/support/feedback/submissions/lookup', [app\support\FeedbackController::class, 'lookup']);
  Route::get('/support/feedback/submissions/{submissionId}', [app\support\FeedbackController::class, 'detail']);
  Route::put('/support/feedback/submissions/{submissionId}/attachments/{attachmentId}', [app\support\FeedbackController::class, 'attachment_upload']);
  Route::post('/support/feedback/submissions/{submissionId}/advance', [app\support\FeedbackController::class, 'advance']);

  // ==================== 平台管理端（P1） ====================
  Route::group('/admin', function () {
    // 概览
    Route::get('/dashboard', [app\admin\DashboardController::class, 'index']);

    // 空间管理
    Route::get('/servers', [app\admin\ServerController::class, 'index']);
    Route::post('/servers', [app\admin\ServerController::class, 'create']);
    Route::get('/servers/{sid}', [app\admin\ServerController::class, 'detail']);
    Route::put('/servers/{sid}', [app\admin\ServerController::class, 'update']);
    Route::post('/servers/{sid}/status', [app\admin\ServerController::class, 'status']);

    // 用户管理
    Route::get('/users', [app\admin\UserController::class, 'index']);
    Route::get('/users/{id}', [app\admin\UserController::class, 'detail']);
    Route::post('/users/{id}/ban', [app\admin\UserController::class, 'ban']);
    Route::post('/users/{id}/platform-role', [app\admin\UserController::class, 'platform_role']);
    Route::post('/users/{id}/tags', [app\admin\TagController::class, 'set_user_tags']);

    // 用户标签
    Route::get('/tags', [app\admin\TagController::class, 'index']);
    Route::post('/tags', [app\admin\TagController::class, 'create']);
    Route::delete('/tags/{id}', [app\admin\TagController::class, 'delete']);

    // 空间下拉选项
    Route::get('/server-options', [app\admin\ServerController::class, 'options']);

    // 插件目录
    Route::get('/plugins', [app\admin\PluginController::class, 'index']);
    Route::get('/plugin-options', [app\admin\PluginController::class, 'options']);
    Route::post('/plugins', [app\admin\PluginController::class, 'create']);
    Route::put('/plugins/{id}', [app\admin\PluginController::class, 'update']);
    Route::post('/plugins/{id}/status', [app\admin\PluginController::class, 'status']);
    Route::get('/plugins/{id}/features', [app\admin\PluginController::class, 'features']);
    Route::post('/plugins/{id}/features', [app\admin\PluginController::class, 'feature_create']);
    Route::put('/plugins/{id}/features/{fid}', [app\admin\PluginController::class, 'feature_update']);
    Route::delete('/plugins/{id}/features/{fid}', [app\admin\PluginController::class, 'feature_delete']);

    // 绑定码监管
    Route::get('/bindings', [app\admin\BindingController::class, 'index']);
    Route::post('/bindings/{id}/revoke', [app\admin\BindingController::class, 'revoke']);

    // 第三方应用（SSO client）
    Route::get('/clients', [app\admin\ClientController::class, 'index']);
    Route::post('/clients', [app\admin\ClientController::class, 'create']);
    Route::post('/clients/{id}/secret-reset', [app\admin\ClientController::class, 'secret_reset']);
    Route::post('/clients/{id}/status', [app\admin\ClientController::class, 'status']);

    // 审计日志
    Route::get('/logs', [app\admin\LogController::class, 'index']);

    // 站内通知管理（发送/撤回/删除, 按批）
    Route::get('/notifications', [app\admin\NotificationController::class, 'index']);
    Route::post('/notifications', [app\admin\NotificationController::class, 'create']);
    Route::post('/notifications/{batch}/expire', [app\admin\NotificationController::class, 'expire']);
    Route::delete('/notifications/{batch}', [app\admin\NotificationController::class, 'delete']);

    // 设备/SSO 授权请求管理（实时列表 + 撤销）
    Route::get('/sso-requests', [app\admin\SsoController::class, 'index']);
    Route::post('/sso-requests/{deviceCode}/revoke', [app\admin\SsoController::class, 'revoke']);

    // 反馈工单管理
    Route::get('/feedback', [app\admin\FeedbackController::class, 'index']);
    Route::get('/feedback/{id}', [app\admin\FeedbackController::class, 'detail']);
    Route::post('/feedback/{id}/status', [app\admin\FeedbackController::class, 'status']);

    // 平台文档（P5）
    Route::get('/docs', [app\admin\DocController::class, 'index']);
    Route::post('/docs', [app\admin\DocController::class, 'create']);
    Route::put('/docs/{id}', [app\admin\DocController::class, 'update']);
    Route::post('/docs/{id}/status', [app\admin\DocController::class, 'status']);
    Route::delete('/docs/{id}', [app\admin\DocController::class, 'delete']);
    Route::get('/doc-categories', [app\admin\DocController::class, 'categories']);
    Route::post('/doc-categories', [app\admin\DocController::class, 'category_create']);
    Route::delete('/doc-categories/{id}', [app\admin\DocController::class, 'category_delete']);
  })->middleware([app\common\middleware\PlatformAdmin::class]);

  // ==================== 空间管理端（P2） ====================
  // 我管理的空间（切换器）——必须在 {sid} 参数组之前注册
  Route::get('/server/mine', [app\server\ServerController::class, 'mine']);

  Route::group('/server/{sid}', function () {
    // 空间概览 / 设置
    Route::get('', [app\server\ServerController::class, 'overview']);
    Route::put('', [app\server\ServerController::class, 'settings']);

    // 成员管理
    Route::get('/members', [app\server\MemberController::class, 'index']);
    Route::post('/members', [app\server\MemberController::class, 'add']);
    Route::post('/members/{uid}/role', [app\server\MemberController::class, 'role']);
    Route::post('/members/{uid}/remove', [app\server\MemberController::class, 'remove']);
    Route::post('/members/{uid}/ban', [app\server\MemberController::class, 'ban']);
    Route::post('/members/{uid}/unban', [app\server\MemberController::class, 'unban']);

    // 游戏绑定
    Route::get('/idents', [app\server\IdentController::class, 'index']);
    Route::post('/idents/bind', [app\server\IdentController::class, 'bind']);
    Route::post('/idents/{id}/unbind', [app\server\IdentController::class, 'unbind']);

    // 插件绑定码
    Route::get('/bindings', [app\server\BindingController::class, 'index']);
    Route::post('/bindings', [app\server\BindingController::class, 'create']);
    Route::post('/bindings/{id}/reset', [app\server\BindingController::class, 'reset']);
    Route::post('/bindings/{id}/disable', [app\server\BindingController::class, 'disable']);
    Route::post('/bindings/{id}/enable', [app\server\BindingController::class, 'enable']);

    // 成员插件权限（矩阵 + 按成员聚合）
    Route::get('/plugin-perms', [app\server\PermController::class, 'matrix']);
    Route::get('/member-perms', [app\server\PermController::class, 'member_perms']);
    Route::post('/plugin-perms', [app\server\PermController::class, 'save']);
    Route::post('/plugin-perms/reset', [app\server\PermController::class, 'reset']);
    Route::post('/plugin-perms/push', [app\server\PermController::class, 'push']);

    // 应用授权（撤销权）
    Route::get('/clients', [app\server\ClientController::class, 'index']);
    Route::post('/clients/disconnect', [app\server\ClientController::class, 'disconnect']);
    Route::post('/clients/connect', [app\server\ClientController::class, 'connect']);

    // 空间文档（P5）
    Route::get('/docs', [app\server\DocController::class, 'index']);
    Route::post('/docs', [app\server\DocController::class, 'create']);
    Route::put('/docs/{id}', [app\server\DocController::class, 'update']);
    Route::post('/docs/{id}/status', [app\server\DocController::class, 'status']);
    Route::delete('/docs/{id}', [app\server\DocController::class, 'delete']);
    Route::get('/doc-categories', [app\server\DocController::class, 'categories']);
    Route::post('/doc-categories', [app\server\DocController::class, 'category_create']);
    Route::delete('/doc-categories/{id}', [app\server\DocController::class, 'category_delete']);
  })->middleware([app\common\middleware\ServerMember::class]);

  // ==================== 开放 API（P3, FiveM 插件机器调用） ====================
  Route::group('/open', function () {
    // 启动校验 + 心跳（600s 周期, body: bind_code + server_key_hash）
    Route::post('/license/validate', [app\open\LicenseController::class, 'validate']);
    // 玩家游戏内绑定码生成（body: ident, ident_type?）
    Route::post('/ident/bind-ticket', [app\open\IdentController::class, 'ticket']);
    // 成员权限批量下发（心跳顺带拉取）
    Route::get('/perm/batch', [app\open\PermController::class, 'batch']);
    // 权限版本号（EXCore ≥1.1 快速同步通道, 「下发权限」后数秒生效）
    Route::get('/perm/rev', [app\open\PermController::class, 'rev']);
    // 遥测上报（可选）
    Route::post('/telemetry', [app\open\TelemetryController::class, 'index']);
  })->middleware([app\common\middleware\BindCodeAuth::class]);

  // OAuth 用户资源（第三方/插件凭 Bearer 访问令牌, OAuthTokenAuth 鉴权）
  Route::get('/open/userinfo', [app\open\OAuthUserController::class, 'userinfo'])
    ->middleware([app\common\middleware\OAuthTokenAuth::class]);
  Route::post('/open/ident/bind-self', [app\open\OAuthUserController::class, 'bind_self'])
    ->middleware([app\common\middleware\OAuthTokenAuth::class]);

  // ==================== 文档浏览（P5, 免登录可看 public） ====================
  Route::get('/docs', [app\docs\DocViewController::class, 'index']);
  Route::get('/docs/{slug}', [app\docs\DocViewController::class, 'detail']);

  // ==================== OAuth2（P4 SSO） ====================
  Route::group('/oauth', function () {
    // 浏览器侧（需登录）
    Route::get('/authorize-info', [app\oauth\AuthorizeController::class, 'info']);
    Route::post('/authorize/confirm', [app\oauth\AuthorizeController::class, 'confirm']);
    Route::post('/device/verify', [app\oauth\DeviceController::class, 'verify']);

    // 机器侧（客户端凭证鉴权, 免登录）
    Route::post('/token', [app\oauth\TokenController::class, 'issue']);
    Route::post('/device/init', [app\oauth\DeviceController::class, 'init']);
    Route::post('/device/token', [app\oauth\DeviceController::class, 'token']);
  });
});

// ==================== OAuth 浏览器页面（/api 之外, 服务端 HTML） ====================
// 第三方平台跳转的授权确认页（授权码+PKCE）
Route::get('/oauth/authorize', [app\oauth\AuthorizeController::class, 'page']);
// 设备码验证页（玩家任意设备打开, 输入游戏内 6 位验证码）
Route::get('/oauth/device', [app\oauth\DeviceController::class, 'page']);

// 404（置底保留；miss 模式的 Limit 会把 404 也计入 IP 错误计数）
Route::fallback(function () {
  return fail('API NOT FOUND', 404);
})->middleware([new app\common\middleware\Limit('miss'), app\common\middleware\Timing::class, app\common\middleware\AccessControl::class]);
