#!/usr/bin/env bash
set -euo pipefail

# 永远以“脚本所在目录”作为起点，自动找到项目根目录
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# 容器内你的项目根目录（你之前就是这个）
WORKDIR_IN_CONTAINER="/var/www/backend"
PHP_SERVICE="php"
PHP_USER="www-data"

cd "$PROJECT_DIR"

# 规则：依赖 flock 做互斥锁；若环境缺少该命令则直接中止，避免并发部署造成状态错乱
if ! command -v flock >/dev/null 2>&1; then
  echo "缺少 flock 命令（通常由 util-linux 提供），请安装后再执行部署脚本。" >&2
  exit 1
fi

# 防止并发部署
LOCKFILE="/tmp/deploy.lock"
exec 9>"$LOCKFILE"
flock -n 9 || { echo "已有部署在进行中，退出"; exit 1; }

echo "==> 拉取代码"
OLD_HEAD="$(git rev-parse HEAD)"
git pull --ff-only
NEW_HEAD="$(git rev-parse HEAD)"

if [[ "$OLD_HEAD" == "$NEW_HEAD" ]]; then
  echo "==> 没有新提交，结束"
  exit 0
fi

echo "==> 变更范围: $OLD_HEAD -> $NEW_HEAD"
CHANGED_FILES="$(git diff --name-only "$OLD_HEAD" "$NEW_HEAD" || true)"

need_composer=0
need_migrate=0

# 依赖变更才 install
if echo "$CHANGED_FILES" | grep -Eq '^backend/composer\.(lock|json)$|^composer\.(lock|json)$'; then
  need_composer=1
fi

# 迁移文件变更才 migrate（你的迁移在 backend/database/migrations）
if echo "$CHANGED_FILES" | grep -Eq '^backend/database/migrations/'; then
  need_migrate=1
fi

echo "==> 依赖是否变化: $need_composer"
echo "==> 迁移是否变化: $need_migrate"

# 每次部署都清理 HTMLPurifier 缓存
echo "==> 清理 HTMLPurifier 缓存"
docker compose exec -u "$PHP_USER" "$PHP_SERVICE" sh -lc \
  "cd $WORKDIR_IN_CONTAINER && rm -rf storage/framework/cache/htmlpurifier"

if [[ $need_composer -eq 1 ]]; then
  echo "==> 执行 composer install"
  docker compose exec -u "$PHP_USER" "$PHP_SERVICE" sh -lc \
    "cd $WORKDIR_IN_CONTAINER && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader"
else
  echo "==> 跳过 composer install"
fi

echo "==> Laravel: config:cache"
docker compose exec -u "$PHP_USER" "$PHP_SERVICE" sh -lc \
  "cd $WORKDIR_IN_CONTAINER && php artisan config:cache"

echo "==> Laravel: route:cache"
docker compose exec -u "$PHP_USER" "$PHP_SERVICE" sh -lc \
  "cd $WORKDIR_IN_CONTAINER && php artisan route:cache"

echo "==> Laravel: view:cache"
docker compose exec -u "$PHP_USER" "$PHP_SERVICE" sh -lc \
  "cd $WORKDIR_IN_CONTAINER && php artisan view:cache"

if [[ $need_migrate -eq 1 ]]; then
  echo "==> 执行 migrate"
  docker compose exec -u "$PHP_USER" "$PHP_SERVICE" sh -lc \
    "cd $WORKDIR_IN_CONTAINER && php artisan migrate --force"
else
  echo "==> 跳过 migrate"
fi

echo "==> 重启队列（若未使用可忽略）"
docker compose exec -u "$PHP_USER" "$PHP_SERVICE" sh -lc \
  "cd $WORKDIR_IN_CONTAINER && php artisan queue:restart"

echo "==> 部署完成"
