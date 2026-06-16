# T-0009 - ELK 搜索 API

- status: done
- created: 2026-06-16
- owner: jc
- related:
  - architecture: `项目架构规划文档.md`
  - environment: `开发环境设置指南.md`
  - database: `数据库设计说明.md`
  - adr: (optional)

## Scope

- In:
  - 在 Docker Compose 中接入 Elasticsearch 8.19.0 与 Kibana 8.19.0。
  - 为 Laravel 后端安装 `elasticsearch/elasticsearch` 客户端。
  - 新增 Elasticsearch 配置文件 `backend/config/elasticsearch.php`。
  - 新增 `nga_threads` 与 `nga_posts` 两类索引。
  - 新增 `search:create-indexes` 命令，用于创建主题与回复索引。
  - 新增 `search:reindex` 命令，用于从 MySQL 重建主题与回复搜索数据。
  - 新增 `/api/search` 搜索接口，支持 `threads`、`posts`、`all` 三种搜索类型。
  - 为 PHP 容器安装 `netcat-openbsd`。
  - 为 PHP 服务增加 healthcheck，检查 php-fpm 配置与 9000 端口。
  - 修改 nginx 依赖关系，使 nginx 等待 php healthy 后启动。
  - 为 nginx 增加 `restart: unless-stopped`，降低启动竞态导致服务不可用的概率。

- Out:
  - 暂不接入 Logstash。
  - 暂不做前端搜索入口和搜索结果页。
  - 暂不引入 IK / smartcn 等中文分词插件。
  - 暂不做增量实时同步，仅提供手动重建索引命令。

## Acceptance

- [x] `docker compose config` 通过。
- [x] Elasticsearch 容器启动并处于 healthy 状态。
- [x] PHP 容器启动并处于 healthy 状态。
- [x] nginx 容器正常启动。
- [x] `search:create-indexes` 命令已注册。
- [x] `search:reindex` 命令已注册。
- [x] `/api/search` 路由已注册。
- [x] `/api/threads?per_page=1` 返回 `HTTP/1.1 200 OK`。
- [x] `/api/search?q=救赎&type=threads&per_page=5` 返回 `HTTP/1.1 200 OK`。
- [x] `/api/search?q=救赎buff&type=posts&per_page=5` 返回 `HTTP/1.1 200 OK`。
- [x] `/api/search?q=救赎&type=all&per_page=5` 返回 `HTTP/1.1 200 OK`。
- [x] `nga_threads` 与 MySQL threads 数量对齐：819。
- [x] `nga_posts` 与 MySQL posts 数量对齐：7741。

## Plan

1) 接入 Elasticsearch / Kibana 容器。

2) 安装 PHP Elasticsearch 客户端并增加 Laravel 配置。

3) 设计主题索引与回复索引字段。

4) 编写索引创建命令 `search:create-indexes`。

5) 编写重建索引命令 `search:reindex`。

6) 导入 MySQL 中已有主题与回复数据。

7) 编写搜索服务与 `/api/search` 接口。

8) 修复 nginx / php-fpm 启动竞态问题：
   - PHP 镜像安装 `netcat-openbsd`。
   - PHP 服务增加 healthcheck。
   - nginx 等待 php healthy 后启动。
   - nginx 增加 `restart: unless-stopped`。

9) 验收旧接口、主题搜索、回复搜索、all 搜索。

## Notes

- `search:reindex --fresh` 会删除并重建 Elasticsearch 中的 `nga_threads` / `nga_posts` 索引，但不会删除 MySQL 数据、不会删除 Docker volume、不会删除代码。
- 当前中文搜索为基础可用：能够命中并高亮中文内容，但“救赎”会被基础 analyzer 拆成单字，高级中文分词后续再优化。
- 当前没有 Logstash，MySQL 到 Elasticsearch 的同步由 Laravel Artisan 命令完成。
- 本次曾遇到 nginx 启动时报错：`host not found in upstream "php"`。
- 根因是同时重启 php 与 nginx 时，nginx 启动阶段解析不到 `php` 服务名。
- 规范修复方式是：php healthcheck + nginx depends_on service_healthy + nginx restart 策略。
- 后续推荐使用 `docker compose up -d php scheduler nginx`，不要优先使用 `docker compose restart php nginx`。
