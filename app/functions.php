<?php

/**
 * 成功数据
 */
function success(mixed $data = [], $msg = 'ok')
{
  return json([
    'msg' => $msg,
    'data' => $data,
  ]);
}

/** 
 * 错误消息
 * @param string $msg 错误信息
 * @param int $http_code HTTP状态码 
 */
function fail(string $msg, int $http_code = 400)
{
  return Response(json_encode([
    'msg' => $msg
  ]), $http_code, [
    'Content-Type' => 'application/json'
  ]);
}


/**
 * 判断是否为json
 */
function isJson($data = '', $assoc = false)
{
  $data = json_decode($data, $assoc);
  if (($data && is_object($data)) || (is_array($data) && !empty($data))) {
    return $data;
  }
  return false;
}

/**
 * 判断必需的 key 是否有缺失（参数校验用：任一 key 不存在则返回 true）
 */
function hasMissingKeys(array $array, array $keys): bool
{
  foreach ($keys as $key) {
    if (!array_key_exists($key, $array)) {
      return true;
    }
  }
  return false;
}


/**
 * 根据数组内值排序
 * @param mixed $array
 * @param mixed $on
 * @param mixed $order
 * @return array
 */
function array_sort($array, $on, $order = SORT_ASC)
{
  $new_array = array();
  $sortable_array = array();

  if (count($array) > 0) {
    foreach ($array as $k => $v) {
      if (is_array($v)) {
        foreach ($v as $k2 => $v2) {
          if ($k2 == $on) {
            $sortable_array[$k] = $v2;
          }
        }
      } else {
        $sortable_array[$k] = $v;
      }
    }

    switch ($order) {
      case SORT_ASC:
        asort($sortable_array);
        break;
      case SORT_DESC:
        arsort($sortable_array);
        break;
    }

    foreach ($sortable_array as $k => $v) {
      $new_array[] = $array[$k];
    }
  }

  return $new_array;
}

/**
 * 生成指定长度的纯数字码
 * @param int $length 长度，默认 6
 * @param bool $allowLeadingZero 是否允许首位为 0
 * @return string
 */
function random_code(int $length = 6, bool $allowLeadingZero = false): string
{
  if ($allowLeadingZero) {
    $max = (int) str_repeat('9', $length);
    return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
  }

  $min = (int) ('1' . str_repeat('0', $length - 1));
  $max = (int) str_repeat('9', $length);
  return (string) random_int($min, $max);
}