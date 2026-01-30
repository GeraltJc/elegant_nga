FROM php:8.2-fpm

ARG TZ=Asia/Shanghai
ENV TZ=${TZ}
ENV DEBIAN_FRONTEND=noninteractive

RUN set -eux; \
  ln -snf /usr/share/zoneinfo/$TZ /etc/localtime; \
  echo $TZ > /etc/timezone; \
  sed -i 's|deb.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list.d/debian.sources; \
  sed -i 's|security.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list.d/debian.sources; \
  apt-get update; \
  apt-get install -y --no-install-recommends \
    git unzip procps \
    libonig-dev libzip-dev libicu-dev \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev; \
  rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
    bcmath exif gd intl mbstring opcache pdo_mysql pcntl zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/99-elegant-nga.ini
WORKDIR /var/www/backend
