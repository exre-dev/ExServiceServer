<?php

namespace app\common\process;

use Webman\App;

class Http extends App
{
    /** 在路由解析和模型依赖注入前应用安装配置。 */
    public function onMessage($connection, $request)
    {
        \app\common\services\InstallationService::refreshConfiguration();
        parent::onMessage($connection, $request);
    }
}
