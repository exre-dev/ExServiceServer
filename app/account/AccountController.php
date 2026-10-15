<?php

namespace app\account;

use app\common\exception\ApiException;
use app\common\model\AuthSessionModel;
use app\common\model\OAuthClientModel;
use app\common\model\OAuthGrantModel;
use app\common\model\UserModel;
use app\common\services\AuditService;
use app\common\services\Email;
use app\common\services\OAuthService;
use app\common\services\SessionService;

use support\Redis;
use support\limiter\annotation\Limit;
use support\Request;
use support\Response;

/**
 * 账号安全中心（登录/注册/登出/自身信息 + 会话/授权/改密）
 */
class AccountController
{
  /**
   * 不需要登录验证的方法
   * @var string[]
   */
  protected $noNeedLogin = ['login', 'register', 'send_email_code'];

  /**
   * 注册（bcrypt 密码, 注册即登录）
   */
  public function register(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ["email", "password"])) {
      throw new ApiException("参数不匹配");
    }

    $email = trim((string) $request->post('email'));
    $password = (string) $request->post('password');
    $username = trim((string) ($request->post('username') ?: strtok($email, '@')));
    $qq = trim((string) $request->post('qq', ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new ApiException("邮箱格式不正确");
    }
    if (strlen($password) < 6) {
      throw new ApiException("密码至少6位");
    }
    if ($qq !== '' && !ctype_digit($qq)) {
      throw new ApiException("QQ必须是纯数字");
    }

    // 邮箱验证码: 发送过的邮箱必须校验（未发送的过渡期放行, 正式上线改为强制）
    $cachedCode = Redis::get("email_code:{$email}");
    if ($cachedCode !== null) {
      $code = trim((string) $request->post('code', ''));
      if ($code === '' || $code !== $cachedCode) {
        throw new ApiException("校验码不匹配或已过期");
      }
      Redis::del("email_code:{$email}");
    }

    // 唯一性（并发兜底靠 UNIQUE 索引, 这里给友好报错）
    if (UserModel::where('email', $email)->first()) {
      throw new ApiException("邮箱已被注册");
    }
    if ($qq !== '' && UserModel::where('qq', $qq)->first()) {
      throw new ApiException("QQ已被绑定");
    }

    $user = new UserModel();
    $user->email = $email;
    $user->username = $username;
    $user->password_hash = password_hash($password, PASSWORD_BCRYPT);
    $user->qq = $qq !== '' ? $qq : null;
    $user->save();

    // 注册即登录（创建会话）
    $session = SessionService::create((int) $user->id, [
      'ip' => $request->getRealIp() ?? '',
      'ua' => (string) $request->header('user-agent', ''),
    ]);

    AuditService::log((int) $user->id, 'user', 'user.register', [
      'target_type' => 'user',
      'target_id' => $user->id,
    ]);

    return success([
      'token' => $session['token'],
      'expires_in' => $session['expires_in'],
      'user' => [
        'id' => (int) $user->id,
        'email' => $user->email,
        'username' => $user->username,
        'avatar' => $user->avatar,
        'platform_role' => $user->platform_role,
      ],
    ], "注册成功");
  }

  /**
   * 发送邮箱验证码（注册用）
   * 同邮箱 60 秒冷却; 验证码 10 分钟有效; debug 模式下发送失败会回传 debug_code 供联调
   */
  #[Limit(limit: 5, ttl: 60, message: '发送过于频繁,请稍后再试!')]
  public function send_email_code(Request $request): Response
  {
    $email = trim((string) $request->post('email', ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new ApiException("邮箱格式不正确");
    }

    $cooldownKey = "email_code_cd:{$email}";
    if (Redis::exists($cooldownKey)) {
      $remain = (int) Redis::ttl($cooldownKey);
      throw new ApiException("发送过于频繁,请 {$remain} 秒后再试");
    }

    $code = random_code(6, true);
    Redis::setex("email_code:{$email}", 600, $code);
    Redis::setex($cooldownKey, 60, '1');

    $sent = Email::send(
      $email,
      'EXRE 社区注册验证码',
      "你的验证码是 {$code}，10 分钟内有效。如非本人操作请忽略本邮件。"
    );

    AuditService::log(null, 'user', 'user.email_code', [
      'target_type' => 'email',
      'target_id' => $email,
      'detail' => ['sent' => $sent],
    ]);

    if (!$sent) {
      if (config('app.debug')) {
        // 本地未配置 SMTP 时联调用
        return success(['debug_code' => $code], "邮件发送失败(未配置SMTP?), debug 模式直接返回验证码");
      }
      throw new ApiException("邮件发送失败,请稍后再试或联系管理员");
    }
    return success([], "验证码已发送至邮箱, 10 分钟内有效");
  }

  /**
   * 登录（每次登录创建独立会话, 多设备并存）
   */
  public function login(Request $request): Response
  {
    $email = trim((string) $request->post('email'));
    $password = (string) $request->post('password');

    if ($email === '' || $password === '') {
      throw new ApiException("邮箱或密码不能为空");
    }

    $user = UserModel::where('email', $email)->first();
    // 统一报错, 不泄露"账号不存在"
    if (!$user || !password_verify($password, (string) $user->password_hash)) {
      throw new ApiException("邮箱或密码错误");
    }
    if ((int) $user->ban !== 0) {
      throw new ApiException("当前账户已经被禁用");
    }

    $user->last_login_time = date('Y-m-d H:i:s');
    $user->save();

    $session = SessionService::create((int) $user->id, [
      'ip' => $request->getRealIp() ?? '',
      'ua' => (string) $request->header('user-agent', ''),
    ]);

    AuditService::log((int) $user->id, 'user', 'user.login', [
      'target_type' => 'session',
      'target_id' => $session['sid'],
      'detail' => ['device' => SessionService::deviceNameFromUa((string) $request->header('user-agent', ''))],
    ]);

    return success([
      'token' => $session['token'],
      'expires_in' => $session['expires_in'],
      'user' => [
        'id' => (int) $user->id,
        'email' => $user->email,
        'username' => $user->username,
        'avatar' => $user->avatar,
        'platform_role' => $user->platform_role,
      ],
    ]);
  }

  /**
   * 登出（撤销当前会话, token 立即失效）
   */
  public function logout(Request $request): Response
  {
    $sid = (int) ($request->sid ?? 0);
    if ($sid > 0) {
      SessionService::revoke($sid);
      AuditService::log((int) $request->uid, 'user', 'user.logout', [
        'target_type' => 'session',
        'target_id' => $sid,
      ]);
    }
    return success([], "已退出登录");
  }

  /**
   * 当前登录用户信息
   */
  public function me(Request $request): Response
  {
    $user = UserModel::find($request->uid);
    if (!$user) {
      throw new ApiException("用户不存在");
    }
    return success([
      'id' => (int) $user->id,
      'email' => $user->email,
      'username' => $user->username,
      'avatar' => $user->avatar,
      'qq' => $user->qq,
      'platform_role' => $user->platform_role,
      'last_login_time' => $user->last_login_time,
    ]);
  }

  // ==================== 设备与会话管理（P4） ====================

  /**
   * 我的登录会话列表（多设备/多平台）
   */
  public function sessions(Request $request): Response
  {
    $list = AuthSessionModel::where('user_id', $request->uid)
      ->orderByDesc('id')
      ->limit(100)
      ->get();

    $currentSid = (int) ($request->sid ?? 0);
    $rows = array_map(function ($row) use ($currentSid) {
      $item = $row->toArray();
      $item['current'] = (int) $row->id === $currentSid;
      return $item;
    }, $list->all());

    return success(['list' => array_values($rows)]);
  }

  /**
   * 撤销指定会话（下线某设备）
   */
  public function session_revoke(Request $request, int $sid): Response
  {
    $session = AuthSessionModel::where('id', $sid)->where('user_id', $request->uid)->first();
    if (!$session) {
      throw new ApiException("会话不存在");
    }
    SessionService::revoke($sid);

    AuditService::log((int) $request->uid, 'user', 'session.revoke', [
      'target_type' => 'session',
      'target_id' => $sid,
    ]);
    return success([], "设备已下线");
  }

  /**
   * 下线除当前会话外的全部设备
   */
  public function session_revoke_all(Request $request): Response
  {
    $count = SessionService::revokeAllForUser((int) $request->uid, (int) ($request->sid ?? 0));

    AuditService::log((int) $request->uid, 'user', 'session.revoke_all', [
      'detail' => ['revoked' => $count],
    ]);
    return success(['revoked' => $count], "已下线 {$count} 个其他设备");
  }

  // ==================== 第三方授权管理（P4） ====================

  /**
   * 我授权过的应用列表
   */
  public function grants(Request $request): Response
  {
    $grants = OAuthGrantModel::where('user_id', $request->uid)
      ->orderByDesc('id')
      ->get();

    $clientIds = $grants->pluck('client_id')->unique()->all();
    $clients = OAuthClientModel::whereIn('client_id', $clientIds)->get()->keyBy('client_id');

    $list = $grants->map(function ($g) use ($clients) {
      $c = $clients->get($g->client_id);
      return [
        'client_id' => $g->client_id,
        'app_name' => $c?->name ?? '(应用已删除)',
        'app_type' => $c?->type,
        'app_status' => $c?->status,
        'scopes' => $g->scopes,
        'status' => $g->status,
        'granted_time' => $g->granted_time,
        'revoked_time' => $g->revoked_time,
      ];
    })->values();

    return success(['list' => $list]);
  }

  /**
   * 撤销对某应用的授权（该应用名下我的 token 立即失效）
   */
  public function grant_revoke(Request $request, string $client_id): Response
  {
    if (!OAuthService::revokeGrant((int) $request->uid, $client_id, 'user')) {
      throw new ApiException("授权不存在或已撤销");
    }
    $revoked = SessionService::revokeAllForUserClient((int) $request->uid, $client_id);

    AuditService::log((int) $request->uid, 'user', 'oauth.revoke', [
      'client_id' => $client_id,
      'target_type' => 'client',
      'target_id' => $client_id,
      'detail' => ['revoked_sessions' => $revoked],
    ]);
    return success(['revoked_sessions' => $revoked], "已撤销授权并下线 {$revoked} 个相关会话");
  }

  // ==================== 修改密码（P4） ====================

  /**
   * 修改密码（成功后下线其他全部设备）
   */
  public function password(Request $request): Response
  {
    $old = (string) $request->post('old_password', '');
    $new = (string) $request->post('new_password', '');
    if ($old === '' || $new === '') {
      throw new ApiException("请填写旧密码与新密码");
    }
    if (strlen($new) < 6) {
      throw new ApiException("新密码至少6位");
    }

    $user = UserModel::find($request->uid);
    if (!$user || !password_verify($old, (string) $user->password_hash)) {
      throw new ApiException("旧密码不正确");
    }

    $user->password_hash = password_hash($new, PASSWORD_BCRYPT);
    $user->save();

    $revoked = SessionService::revokeAllForUser((int) $request->uid, (int) ($request->sid ?? 0));

    AuditService::log((int) $request->uid, 'user', 'user.password', [
      'detail' => ['revoked_sessions' => $revoked],
    ]);
    return success(['revoked_sessions' => $revoked], "密码已修改, 其他 {$revoked} 个设备已下线");
  }
}
