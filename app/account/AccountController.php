<?php

namespace app\account;

use app\common\exception\ApiException;
use app\common\model\AuthSessionModel;
use app\common\model\OAuthClientModel;
use app\common\model\OAuthGrantModel;
use app\common\model\UserModel;
use app\common\services\AuditService;
use app\common\services\CaptchaService;
use app\common\services\Email;
use app\common\services\InvitationService;
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
  protected $noNeedLogin = [
    'login',
    'register',
    'send_email_code',
    'avatar_image',
    'invitation_check',
    'invitation_code',
    'invitation_verify',
    'invitation_complete',
    'captcha_challenge',
  ];

  /**
   * 注册（bcrypt 密码, 注册即登录）
   */
  public function register(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ['email', 'password'])) {
      throw new ApiException('参数不匹配');
    }

    $email = trim((string) $request->post('email'));
    $password = (string) $request->post('password');
    $username = trim(
      (string) ($request->post('username') ?: strtok($email, '@')),
    );
    $qq = trim((string) $request->post('qq', ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new ApiException('邮箱格式不正确');
    }
    if (strlen($password) < 6) {
      throw new ApiException('密码至少6位');
    }
    if ($qq !== '' && !ctype_digit($qq)) {
      throw new ApiException('QQ必须是纯数字');
    }

    // 邮箱验证码: 发送过的邮箱必须校验（未发送的过渡期放行, 正式上线改为强制）
    $cachedCode = Redis::get("email_code:{$email}");
    if ($cachedCode !== null) {
      $code = trim((string) $request->post('code', ''));
      if ($code === '' || $code !== $cachedCode) {
        throw new ApiException('校验码不匹配或已过期');
      }
      Redis::del("email_code:{$email}");
    }

    // 唯一性（并发兜底靠 UNIQUE 索引, 这里给友好报错）
    if (UserModel::where('email', $email)->first()) {
      throw new ApiException('邮箱已被注册');
    }
    if ($qq !== '' && UserModel::where('qq', $qq)->first()) {
      throw new ApiException('QQ已被绑定');
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

    return success(
      [
        'token' => $session['token'],
        'expires_in' => $session['expires_in'],
        'user' => [
          'id' => (int) $user->id,
          'email' => $user->email,
          'username' => $user->username,
          'avatar' => $user->avatar,
          'platform_role' => $user->platform_role,
        ],
      ],
      '注册成功',
    );
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
      throw new ApiException('邮箱格式不正确');
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
      "你的验证码是 {$code}，10 分钟内有效。如非本人操作请忽略本邮件。",
    );

    AuditService::log(null, 'user', 'user.email_code', [
      'target_type' => 'email',
      'target_id' => $email,
      'detail' => ['sent' => $sent],
    ]);

    if (!$sent) {
      if (config('app.debug')) {
        // 本地未配置 SMTP 时联调用
        return success(
          ['debug_code' => $code],
          '邮件发送失败(未配置SMTP?), debug 模式直接返回验证码',
        );
      }
      throw new ApiException('邮件发送失败,请稍后再试或联系管理员');
    }
    return success([], '验证码已发送至邮箱, 10 分钟内有效');
  }

  /**
   * 登录（每次登录创建独立会话, 多设备并存）
   * 可选图片验证码: 请求带 captcha {challengeId, answer} 时校验（用户侧网站登录流）
   */
  public function login(Request $request): Response
  {
    $email = trim((string) $request->post('email'));
    $password = (string) $request->post('password');

    if ($email === '' || $password === '') {
      throw new ApiException('邮箱或密码不能为空');
    }
    // 图片验证码（422 = 验证码错误, 前端与凭据错误 400 区分展示）
    // 管理端登录（/api/account/login）必填; 用户侧（/api/auth/login）带则校验
    $captchaRequired = str_starts_with($request->path(), '/api/account/');
    CaptchaService::checkFromRequest(
      $request->post('captcha'),
      $captchaRequired,
    );

    $user = UserModel::where('email', $email)->first();
    // 统一报错, 不泄露"账号不存在"
    if (!$user || !password_verify($password, (string) $user->password_hash)) {
      throw new ApiException('邮箱或密码错误');
    }
    if ((int) $user->ban !== 0) {
      // 403: 身份有效但被拒（封禁），与管理端拦截器及用户侧错误映射约定一致
      throw new ApiException('当前账户已经被禁用', 403);
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
      'detail' => [
        'device' => SessionService::deviceNameFromUa(
          (string) $request->header('user-agent', ''),
        ),
      ],
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
    return success([], '已退出登录');
  }

  /**
   * 当前登录用户信息
   */
  public function me(Request $request): Response
  {
    $user = UserModel::find($request->uid);
    if (!$user) {
      throw new ApiException('用户不存在', 401);
    }
    return success($this->mePayload($user));
  }

  /**
   * 更新当前用户资料（用户侧网站对接, PATCH /api/auth/me）
   * 支持: nickname(显示名) / qq(可 null 清空) / avatar(null 清除头像恢复默认)
   * fivemId 不允许在此设置——游戏身份必须走绑定码/SSO 绑定流程
   */
  public function profile_update(Request $request): Response
  {
    $post = $request->post();
    if (empty($post)) {
      throw new ApiException('参数不匹配');
    }
    $user = UserModel::find($request->uid);
    if (!$user) {
      throw new ApiException('用户不存在', 401);
    }

    if (array_key_exists('nickname', $post)) {
      $nickname = trim((string) $post['nickname']);
      if ($nickname === '' || mb_strlen($nickname) > 32) {
        throw new ApiException('用户名需为 1-32 个字符');
      }
      $user->username = $nickname;
    }

    if (array_key_exists('qq', $post)) {
      $qq = $post['qq'] === null ? '' : trim((string) $post['qq']);
      if ($qq !== '') {
        if (!ctype_digit($qq) || strlen($qq) > 20) {
          throw new ApiException('QQ必须是纯数字');
        }
        if (
          UserModel::where('qq', $qq)->where('id', '<>', $user->id)->first()
        ) {
          throw new ApiException('QQ已被其他账号绑定');
        }
        $user->qq = $qq;
      } else {
        $user->qq = null;
      }
    }

    if (array_key_exists('fivemId', $post) && $post['fivemId'] !== null) {
      throw new ApiException('游戏身份请通过游戏内绑定流程完成, 无法直接填写');
    }

    if (array_key_exists('avatar', $post) && $post['avatar'] === null) {
      $this->deleteAvatarFile((string) $user->avatar);
      $user->avatar = '/app/user/default-avatar.png';
    }

    $user->save();
    AuditService::log((int) $request->uid, 'user', 'user.profile_update', [
      'target_type' => 'user',
      'target_id' => $user->id,
      'detail' => ['fields' => array_keys($post)],
    ]);
    return success($this->mePayload($user));
  }

  /**
   * 上传头像（用户侧网站对接, base64 图片统一转 WebP 存储）
   */
  public function avatar_upload(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ['imageBase64', 'mediaType'])) {
      throw new ApiException('参数不匹配');
    }
    $mediaType = (string) $post['mediaType'];
    if (
      !in_array($mediaType, ['image/png', 'image/jpeg', 'image/webp'], true)
    ) {
      throw new ApiException('仅支持 PNG、JPEG 或 WebP 图片');
    }
    $bytes = base64_decode((string) $post['imageBase64'], true);
    if ($bytes === false || $bytes === '') {
      throw new ApiException('图片数据无效');
    }
    if (strlen($bytes) > 2 * 1024 * 1024) {
      throw new ApiException('图片不能超过 2MiB', 413);
    }

    $img = imagecreatefromstring($bytes);
    if ($img === false) {
      throw new ApiException('图片解析失败, 请更换图片后重试');
    }

    $dir = runtime_path() . '/avatars';
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }
    $avatarId = self::opaqueId();
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $saved = imagewebp($img, $dir . "/{$avatarId}.webp", 85);
    imagedestroy($img);
    if (!$saved) {
      throw new ApiException('头像保存失败, 请稍后重试');
    }

    $user = UserModel::find($request->uid);
    if (!$user) {
      throw new ApiException('用户不存在', 401);
    }
    $this->deleteAvatarFile((string) $user->avatar);
    $user->avatar = "/api/avatars/{$avatarId}";
    $user->save();

    AuditService::log((int) $request->uid, 'user', 'user.avatar_upload', [
      'target_type' => 'user',
      'target_id' => $user->id,
    ]);
    return success($this->mePayload($user), '头像已更新');
  }

  /**
   * 读取头像图片（公开, /api/avatars/{avatarId}）
   */
  public function avatar_image(Request $request, string $avatarId): Response
  {
    $avatarId = (string) $avatarId;
    $path = runtime_path() . '/avatars/' . $avatarId . '.webp';
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $avatarId) || !is_file($path)) {
      return fail('头像不存在', 404);
    }
    return response((string) file_get_contents($path), 200, [
      'Content-Type' => 'image/webp',
    ]);
  }

  /**
   * 用户信息出参（me / 资料更新 / 头像上传共用）
   */
  private function mePayload(UserModel $user): array
  {
    return [
      'id' => (int) $user->id,
      'email' => $user->email,
      'username' => $user->username,
      'avatar' => $user->avatar,
      'qq' => $user->qq,
      'platform_role' => $user->platform_role,
      'last_login_time' => $user->last_login_time,
    ];
  }

  /**
   * 清理旧头像文件（仅删除本模块写入的 /api/avatars/{id} 引用, 忽略默认头像等外部路径）
   */
  private function deleteAvatarFile(string $avatarUrl): void
  {
    if (!str_starts_with($avatarUrl, '/api/avatars/')) {
      return;
    }
    $avatarId = basename($avatarUrl);
    if (preg_match('/^[A-Za-z0-9_-]{43}$/', $avatarId)) {
      $path = runtime_path() . '/avatars/' . $avatarId . '.webp';
      if (is_file($path)) {
        unlink($path);
      }
    }
  }

  /**
   * 43 位不透明标识（base64url(32 字节), 用于头像文件名等对外引用）
   */
  private static function opaqueId(): string
  {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }

  // ==================== 邀请码注册（用户侧网站, /api/auth/invitations/*） ====================

  /**
   * 图片验证码挑战（登录/注册流共用, 5 分钟一次性）
   */
  #[Limit(limit: 10, ttl: 60, message: '请求过于频繁,请稍后再试!')]
  public function captcha_challenge(Request $request): Response
  {
    return success(CaptchaService::create());
  }

  /**
   * 资格检查（未注册邮箱即符合邀请条件）
   */
  public function invitation_check(Request $request): Response
  {
    $email = trim((string) $request->post('email'));
    return success(InvitationService::check($email));
  }

  /**
   * 发送邀请码（6 位, 10 分钟有效, 60 秒冷却）
   */
  public function invitation_code(Request $request): Response
  {
    $email = trim((string) $request->post('email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new ApiException('邮箱格式不正确');
    }
    try {
      $payload = InvitationService::send($email);
    } catch (ApiException $e) {
      if ((int) $e->getCode() === 429) {
        // 页面按 Retry-After 头显示倒计时
        $remain = max(1, (int) Redis::ttl("invite:cd:{$email}"));
        return fail($e->getMessage(), 429)->withHeader(
          'Retry-After',
          (string) $remain,
        );
      }
      throw $e;
    }
    return success($payload, '如邮箱符合邀请条件, 验证码已发送')->withStatus(
      202,
    );
  }

  /**
   * 校验邀请码, 换一次性完成令牌（可带图片验证码防自动注册）
   */
  public function invitation_verify(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ['email', 'code'])) {
      throw new ApiException('参数不匹配');
    }
    CaptchaService::checkFromRequest($post['captcha'] ?? null);
    try {
      $payload = InvitationService::verify(
        trim((string) $post['email']),
        (string) $post['code'],
      );
    } catch (ApiException $e) {
      if ((int) $e->getCode() === 429) {
        $remain = max(
          1,
          (int) Redis::ttl('invite:fail:' . trim((string) $post['email'])),
        );
        return fail($e->getMessage(), 429)->withHeader(
          'Retry-After',
          (string) $remain,
        );
      }
      throw $e;
    }
    return success($payload);
  }

  /**
   * 完成注册（令牌一次性, 建账号不自动登录, 前往登录页）
   */
  public function invitation_complete(Request $request): Response
  {
    $post = $request->post();
    if (
      empty($post) ||
      hasMissingKeys($post, ['completionToken', 'nickname', 'password'])
    ) {
      throw new ApiException('参数不匹配');
    }
    return success(
      InvitationService::complete(
        (string) $post['completionToken'],
        (string) $post['nickname'],
        (string) $post['password'],
      ),
      '注册成功, 请前往登录',
    );
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
    $session = AuthSessionModel::where('id', $sid)
      ->where('user_id', $request->uid)
      ->first();
    if (!$session) {
      throw new ApiException('会话不存在');
    }
    SessionService::revoke($sid);

    AuditService::log((int) $request->uid, 'user', 'session.revoke', [
      'target_type' => 'session',
      'target_id' => $sid,
    ]);
    return success([], '设备已下线');
  }

  /**
   * 下线除当前会话外的全部设备
   */
  public function session_revoke_all(Request $request): Response
  {
    $count = SessionService::revokeAllForUser(
      (int) $request->uid,
      (int) ($request->sid ?? 0),
    );

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
    $clients = OAuthClientModel::whereIn('client_id', $clientIds)
      ->get()
      ->keyBy('client_id');

    $list = $grants
      ->map(function ($g) use ($clients) {
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
      })
      ->values();

    return success(['list' => $list]);
  }

  /**
   * 撤销对某应用的授权（该应用名下我的 token 立即失效）
   */
  public function grant_revoke(Request $request, string $client_id): Response
  {
    if (!OAuthService::revokeGrant((int) $request->uid, $client_id, 'user')) {
      throw new ApiException('授权不存在或已撤销');
    }
    $revoked = SessionService::revokeAllForUserClient(
      (int) $request->uid,
      $client_id,
    );

    AuditService::log((int) $request->uid, 'user', 'oauth.revoke', [
      'client_id' => $client_id,
      'target_type' => 'client',
      'target_id' => $client_id,
      'detail' => ['revoked_sessions' => $revoked],
    ]);
    return success(
      ['revoked_sessions' => $revoked],
      "已撤销授权并下线 {$revoked} 个相关会话",
    );
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
      throw new ApiException('请填写旧密码与新密码');
    }
    if (strlen($new) < 6) {
      throw new ApiException('新密码至少6位');
    }

    $user = UserModel::find($request->uid);
    if (!$user || !password_verify($old, (string) $user->password_hash)) {
      throw new ApiException('旧密码不正确');
    }

    $user->password_hash = password_hash($new, PASSWORD_BCRYPT);
    $user->save();

    $revoked = SessionService::revokeAllForUser(
      (int) $request->uid,
      (int) ($request->sid ?? 0),
    );

    AuditService::log((int) $request->uid, 'user', 'user.password', [
      'detail' => ['revoked_sessions' => $revoked],
    ]);
    return success(
      ['revoked_sessions' => $revoked],
      "密码已修改, 其他 {$revoked} 个设备已下线",
    );
  }
}
