<?php

/**
 * 用户侧网站（ExWebsite）对接配置
 */
return [
  /** 站点根地址（dev 为 vite 端口）; 用于 OAuth 设备码流签发 ssoRequestId 后拼接登录跳转 */
  'base_url' => \support\LocalDevelopment\RuntimeConfiguration::current()->webUrl(),
];
