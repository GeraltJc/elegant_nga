# **ECS 首次发布 ELK 搜索功能 Runbook**

- 项目：elegant_nga
- 环境：阿里云 ECS
- 发布分支：main
- 场景：首次上线 Elasticsearch / Kibana 搜索能力
- 日期：2026-06-16

## **1. 发布原则**

ECS 服务器只部署 `main` 分支。

流程：

1. 本地功能分支开发。
2. 合并到 GitHub `main`。
3. ECS 服务器拉取 `main`。
4. 按本 Runbook 完成首次 ELK 发布。
5. 首次发布成功后，再增强 `scripts/deploy-ecs.sh`，用于后续普通发布。

## **2. 影响范围**

会执行：

- 拉取 `main` 最新代码。
- 补服务器 `.env` / `backend/.env`。
- 检查 `vm.max_map_count`。
- 重新构建 PHP 镜像。
- 启动 `elasticsearch` / `kibana`。
- 启动 `php` / `scheduler` / `nginx`。
- 执行 Composer install。
- 刷新 Laravel 缓存。
- 创建 ES 索引。
- 从 MySQL 重建 ES 搜索数据。

不会执行：

- 不删除 MySQL 数据。
- 不删除 Docker volume。
- 不执行 `docker compose down -v`。

注意：

- `search:reindex --fresh` 只会删除并重建 ES 的 `nga_threads` / `nga_posts` 索引。
- 不会删除 MySQL 数据。

## **3. 发布前检查**

```bash
cd ~/projects/elegant_nga

git status --short
git branch --show-current
docker compose ps
grep -nE "ELASTICSEARCH|KIBANA" .env backend/.env || true
```

预期：

- 当前分支是 `main`。
- 工作区没有未确认的代码改动。
- 如果有 `backend/.env_bak`，先移动到项目外备份目录。

```bash
mkdir -p ~/deploy-backups/elegant_nga
mv backend/.env_bak ~/deploy-backups/elegant_nga/backend.env_bak.$(date +%Y%m%d%H%M%S) 2>/dev/null || true
git status --short
```

## **4. 拉取 main**

```bash
git fetch origin
git checkout main
git pull --ff-only origin main
git log --oneline -1
```

## **5. 补服务器环境变量**

先备份：

```bash
cp .env .env.bak.$(date +%Y%m%d%H%M%S)
cp backend/.env backend/.env.bak.$(date +%Y%m%d%H%M%S)
```

补根 `.env`：

```bash
grep -q '^ELASTICSEARCH_PORT=' .env || cat >> .env <<'ENVEOF'

ELASTICSEARCH_PORT=9200
KIBANA_PORT=5601
ENVEOF
```

补 `backend/.env`：

```bash
grep -q '^ELASTICSEARCH_HOST=' backend/.env || cat >> backend/.env <<'ENVEOF'

ELASTICSEARCH_HOST=http://elasticsearch:9200
ELASTICSEARCH_THREADS_INDEX=nga_threads
ELASTICSEARCH_POSTS_INDEX=nga_posts
ENVEOF
```

检查：

```bash
grep -nE "ELASTICSEARCH|KIBANA" .env backend/.env
```

说明：

- `.env` 和 `backend/.env` 是服务器本地配置，不提交 Git。
- 修改 `backend/.env` 后必须重新刷新 Laravel 配置缓存。

## **6. 检查 Elasticsearch 主机参数**

```bash
sysctl vm.max_map_count
```

如果小于 `1048576`：

```bash
sudo sysctl -w vm.max_map_count=1048576

grep -q '^vm.max_map_count=' /etc/sysctl.conf \
  && sudo sed -i 's/^vm.max_map_count=.*/vm.max_map_count=1048576/' /etc/sysctl.conf \
  || echo 'vm.max_map_count=1048576' | sudo tee -a /etc/sysctl.conf

sudo sysctl -p
```

说明：

- 这是 Elasticsearch 运行需要的 Linux 内核参数。
- 不会删除数据，不会修改 MySQL，不会修改项目代码。

## **7. 构建并启动容器**

```bash
docker compose config
docker compose build php
docker compose up -d elasticsearch kibana php scheduler nginx
docker compose ps
```

预期：

- `elasticsearch` healthy
- `php` healthy
- `nginx` Up
- `scheduler` Up
- `kibana` Up
- `mysql` Up

确认 PHP 容器内已有 `nc`：

```bash
docker compose exec php sh -lc 'command -v nc && nc -h 2>&1 | head -5 || true'
```

## **8. Laravel 后处理**

```bash
docker compose exec -u www-data php sh -lc \
  'cd /var/www/backend && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader'

docker compose exec -u www-data php sh -lc \
  'cd /var/www/backend && php artisan config:clear && php artisan config:cache'

docker compose exec -u www-data php sh -lc \
  'cd /var/www/backend && php artisan route:clear && php artisan route:cache'

docker compose exec -u www-data php sh -lc \
  'cd /var/www/backend && php artisan view:clear && php artisan view:cache'

docker compose exec -u www-data php sh -lc \
  'cd /var/www/backend && php artisan queue:restart'
```

确认 Laravel 已读取 Elasticsearch 配置：

```bash
docker compose exec php sh -lc 'cd /var/www/backend && php artisan tinker --execute="
dump(config(\"elasticsearch.host\"));
dump(config(\"elasticsearch.indexes\"));
"'
```

预期：

```text
"http://elasticsearch:9200"
threads => "nga_threads"
posts => "nga_posts"
```

## **9. 创建索引并导入数据**

确认命令和路由已注册：

```bash
docker compose exec php sh -lc 'cd /var/www/backend && php artisan list | grep search'
docker compose exec php sh -lc 'cd /var/www/backend && php artisan route:list | grep search'
```

创建索引：

```bash
docker compose exec php sh -lc 'cd /var/www/backend && php artisan search:create-indexes'
```

重建搜索数据：

```bash
docker compose exec php sh -lc 'cd /var/www/backend && php artisan search:reindex --fresh --chunk=500'
```

说明：

- `--fresh` 会删除并重建 Elasticsearch 中的 `nga_threads` / `nga_posts` 索引。
- 不会删除 MySQL 数据。
- 不会删除 Docker volume。
- 服务器 MySQL 数据量如果和本地不同，导入数量不同是正常的。

## **10. 验收**

```bash
docker compose ps

curl -i "http://127.0.0.1:9200"
curl -s "http://127.0.0.1:9200/nga_threads/_count?pretty"
curl -s "http://127.0.0.1:9200/nga_posts/_count?pretty"

curl -i "http://127.0.0.1:8080/api/threads?per_page=1"
curl -i "http://127.0.0.1:8080/api/search?q=救赎&type=threads&per_page=5"
curl -i "http://127.0.0.1:8080/api/search?q=救赎buff&type=posts&per_page=5"
curl -i "http://127.0.0.1:8080/api/search?q=救赎&type=all&per_page=5"
```

预期：

- API 返回 `HTTP/1.1 200 OK`。
- 搜索接口有结果。
- 搜索高亮字段可见 `<mark>`。
- ES count 与服务器 MySQL 当前数据规模对应。

## **11. 故障处理**

ES 不 healthy：

```bash
docker compose logs --tail=120 elasticsearch
sysctl vm.max_map_count
```

PHP 不 healthy：

```bash
docker compose logs --tail=120 php
docker compose exec php sh -lc 'php-fpm -t && command -v nc && nc -z 127.0.0.1 9000'
```

nginx 502：

```bash
docker compose logs --tail=120 nginx php
docker compose up -d php scheduler nginx
```

Laravel 没读到 ES 配置：

```bash
docker compose exec -u www-data php sh -lc \
  'cd /var/www/backend && php artisan config:clear && php artisan config:cache'
```

## **12. 回滚**

停止 ELK：

```bash
docker compose stop elasticsearch kibana
```

重新拉起原核心服务：

```bash
docker compose up -d php scheduler nginx
```

禁止执行：

```bash
docker compose down -v
```

原因：

- `down -v` 会删除 volume。
- 有删除 MySQL / Elasticsearch 数据卷的风险。

## **13. 后续自动化**

首次上线成功后，再增强：

```bash
scripts/deploy-ecs.sh
```

建议后续支持：

- 检测 `docker-compose.yml` / `docker/php/Dockerfile` 变化后自动 build PHP 镜像。
- 自动 `docker compose up -d` 核心服务。
- 检测 Composer 文件变化后自动执行 `composer install`。
- 刷新 Laravel 缓存。
- 增加基础 API 健康检查。
- 不默认执行 `search:reindex --fresh`。
- 如需重建搜索索引，应通过显式参数或单独 oneoff 脚本触发。
