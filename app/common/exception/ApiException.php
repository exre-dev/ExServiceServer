<?php

namespace app\common\exception;

use support\exception\BusinessException;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 接口错误返回
 * code 同时作为 HTTP 状态码: 400 业务错误(默认) / 401 登录态失效 / 403 无权限
 */
class ApiException extends BusinessException
{
  protected $code = 400;

  public function render(Request $request): ?Response
  {
    return fail($this->getMessage(), (int) $this->getCode() ?: 400);
  }
}
