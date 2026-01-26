# elegant_nga

NGA「艾泽拉斯议事厅」（fid=7）抓取与浏览站点。

## 规则与进度

- TDD 工作流：`docs/rules/TDD工作流.md`
- 文件化任务/进度追踪：`docs/rules/文件化进度追踪.md`
- 任务列表：`docs/progress/tasks/`
- 每日日志：`docs/progress/logs/`
- ADR：`docs/adr/`

## 开发环境（Docker，Mac M2）

环境说明见：`开发环境设置指南.md`

### 1) 启动基础服务（nginx/php/mysql）

```bash
cp .env.example .env
docker compose up -d --build
```

默认访问：

- Nginx：`http://localhost:8080`（可用 `NGINX_PORT` 调整）
- MySQL：`127.0.0.1:3306`（可用 `MYSQL_PORT` 调整）

### 2) 初始化后端（Laravel 12）

```bash
docker compose exec php bash
composer install
cp -n .env.example .env
php artisan key:generate
php artisan migrate
```

说明：后端不再依赖 Node（不包含 backend/package.json/Vite）。Node 仅用于 frontend。

### 3) 初始化前端（Vue 3 + Vite）

Docker 方式：

```bash
docker compose up -d frontend
```

默认访问：`http://localhost:5173`（可用 `FRONTEND_PORT` 调整）

页面入口：
- 运行报表列表：`http://localhost:5173/crawl-runs`
- 运行报表详情：`http://localhost:5173/crawl-runs/{runId}`

API 说明（运行报表）：
- API 基址（后端）：`http://localhost:8080/api`
- 前端开发代理：`http://localhost:5173/api`（Vite 代理到 nginx）
- 运行列表：`GET /api/crawl-runs?page=1&per_page=20`
- 运行详情：`GET /api/crawl-runs/{runId}`
- 主题明细：`GET /api/crawl-runs/{runId}/threads?only_failed=1`

本地方式：

```bash
cd frontend
npm i
npm run dev
```

### 4) 前端测试（Vitest）

```bash
cd frontend
npm test
```

> Node 版本：`20.19`（见 `.nvmrc`）。

### 5) CI（GitHub Actions）

- 工作流：`.github/workflows/ci.yml`
- 后端：`composer install` → `php artisan migrate` → `php artisan test`（SQLite）
- 前端：`npm ci` → `npm test` → `npm run build`
