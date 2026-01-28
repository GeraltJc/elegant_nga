FROM php:8.2-fpm

ARG TZ=Asia/Shanghai

# 1) 替换 Debian APT 源为阿里云（Bookworm），解决国内拉包超时
RUN set -eux; \
  cat > /etc/apt/sources.list <<'EOF'
deb https://mirrors.aliyun.com/debian/ bookworm main non-free non-free-firmware contrib
deb-src https://mirrors.aliyun.com/debian/ bookworm main non-free non-free-firmware contrib
deb https://mirrors.aliyun.com/debian-security/ bookworm-security main
deb-src https://mirrors.aliyun.com/debian-security/ bookworm-security main
deb https://mirrors.aliyun.com/debian/ bookworm-updates main non-free non-free-firmware contrib
deb-src https://mirrors.aliyun.com/debian/ bookworm-updates main non-free non-free-firmware contrib
deb https://mirrors.aliyun.com/debian/ bookworm-backports main non-free non-free-firmware contrib
deb-src https://mirrors.aliyun.com/debian/ bookworm-backports main non-free non-free-firmware contrib
EOF

# 2) 安装系统依赖（加上重试与非交互，构建更稳）
RUN set -eux; \
  export DEBIAN_FRONTEND=noninteractive; \
  apt-get update; \
  apt-get install -y --no-install-recommends \
    git \
    unzip \
    libonig-dev \
    libzip-dev \
    libicu-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev; \
  rm -rf /var/lib/apt/lists/*

# 3) 安装 PHP 扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j"$(nproc)" \
    bcmath \
    exif \
    gd \
    intl \
    mbstring \
    opcache \
    pdo_mysql \
    pcntl \
    zip

# 4) 拷贝 Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 5) 自定义 php.ini
COPY php.ini /usr/local/etc/php/conf.d/99-elegant-nga.ini

WORKDIR /var/www/backend
