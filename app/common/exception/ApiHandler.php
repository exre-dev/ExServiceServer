<?php

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace app\common\exception;

use Dotenv\Dotenv;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use support\exception\Handler;

/**
 * Class Handler
 * @package support\exception
 */
class ApiHandler extends Handler
{
  public function render(Request $request, Throwable $e): Response
  {

    if ($e instanceof ApiException) {
      return parent::render($request, $e);
    }



    return new Response(
      500,
      [],
      json_encode([
        "message" => $e->getMessage()
      ])
    );
  }
}
