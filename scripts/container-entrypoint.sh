#!/bin/sh
set -eu

cd /app
if [ ! -f composer.lock ]; then
  echo '缺少 composer.lock，无法准备 PHP 容器依赖' >&2
  exit 1
fi

if [ ! -f composer.json ]; then
  echo '缺少 composer.json，无法准备 PHP 容器依赖' >&2
  exit 1
fi
fingerprint="$(sha256sum composer.json composer.lock | sha256sum | cut -d ' ' -f 1)"
stamp=vendor/.exwebsite-composer-lock-sha256
installed=''
if [ -f "$stamp" ]; then
  installed="$(cat "$stamp")"
fi
if [ "$installed" != "$fingerprint" ] || [ ! -f vendor/autoload.php ]; then
  # Webman package scripts overwrite the bind-mounted start.php/windows.php.
  # Project plugin configuration is already versioned; vendor autoload is sufficient.
  composer install --no-scripts --no-dev --prefer-dist --no-interaction --no-progress 1>&2
  printf '%s\n' "$fingerprint" > "$stamp.tmp"
  mv "$stamp.tmp" "$stamp"
fi

exec "$@"
