FROM php:8.4.27-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libonig-dev libpng-dev libpq-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd mbstring pdo_pgsql pcntl posix \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY scripts/container-entrypoint.sh /usr/local/bin/exwebsite-container-entrypoint
RUN chmod 755 /usr/local/bin/exwebsite-container-entrypoint

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
ENTRYPOINT ["/usr/local/bin/exwebsite-container-entrypoint"]
