# elegant_nga

NGA「艾泽拉斯议事厅」（fid=7）抓取与浏览站点。

## 规则与进度

- TDD 工作流：`docs/rules/TDD工作流.md`
- 文件化任务/进度追踪：`docs/rules/文件化进度追踪.md`
- 任务列表：`docs/progress/tasks/`
- 每日日志：`docs/progress/logs/`
- ADR：`docs/adr/`

## 抓取规则与口径（详细）

### 0) 抓取入口（手动命令）

本项目当前通过 Artisan 命令手动触发抓取：

- 版块抓取：`nga:crawl-lite`
- 单主题抓取：`nga:crawl-thread`
- 缺楼层审计/修补：`nga:audit-missing-floors`

Docker 环境下推荐这样执行（在宿主机上）：

```bash
docker compose exec php php artisan nga:crawl-lite --fid=7 --recent-days=3

# 缺楼层审计（可选修补）
docker compose exec php php artisan nga:audit-missing-floors --thread-ids=46101568 --repair
```

本地非 Docker 方式（直接运行后端）：

```bash
php backend/artisan nga:crawl-lite --fid=7 --recent-days=3
```

### 1) 列表排序规则（order_by=postdatedesc）

- 列表抓取请求会强制带 `order_by=postdatedesc`，保证列表按“主题创建时间（postdate）倒序”返回。
- 作用：窗口过滤与停止翻页判断依赖“页码越大，主题创建时间整体越早（越旧）”这个前提；如果排序不稳定，就可能翻页停不准或漏抓窗口内的主题。

例子：

- 当你执行 `--recent-days=3`，第一页基本是“近 3 天”最新创建的主题；往后翻页会逐渐看到更早创建的主题（这里按创建时间排序，不保证“最活跃”，活跃度通常应看最后回复时间）。

### 2) 时间窗口口径（按主题创建时间）

窗口口径是“主题创建时间（thread_created_at）”，不是 `last_reply_at`。

- 默认窗口：最近 3 天自然日（Asia/Shanghai）
  - 例如现在是 2026-01-23 任意时刻，窗口就是 2026-01-21 00:00:00 ～ 2026-01-23 23:59:59。
- 覆盖方式：
  - `--recent-days=3`：使用“最近 N 天自然日”
  - `--start-at`/`--end-at`：指定窗口边界（支持只给一边）

例子：

```bash
# 最近 3 天（自然日口径）
php backend/artisan nga:crawl-lite --fid=7 --recent-days=3

# 手动指定窗口（更精准）
php backend/artisan nga:crawl-lite --fid=7 --start-at="2026-01-21 00:00:00" --end-at="2026-01-23 23:59:59"

# 关闭窗口过滤（仅抓单页列表；此时不会自动翻页）
php backend/artisan nga:crawl-lite --fid=7 --recent-days=0 --list-page=1
```

### 3) 自动翻页与停止条件（窗口抓取时）

当启用窗口（`--recent-days>0` 或传了 `--start-at/--end-at`）时，会自动从 `--list-page` 开始持续翻页，直到满足停止条件（`--list-page` 是列表起始页码，1-based，默认 1，可用于从指定页开始/断点续抓）。

停止条件（业务语义）：

- 当前页里“所有非置顶主题”的创建时间都已经超出窗口（通常是早于窗口起始时间）时，停止继续翻页。
- 置顶主题会被忽略：置顶可能非常久远，但仍会出现在每一页顶部，如果拿它判断会导致永远停不下来。

例子：

- `--recent-days=3` 从第 1 页开始抓：当翻到某一页发现该页普通主题全是 4 天前/更早创建的主题，则认为后续页也只会更旧，直接停止。

### 4) 详情抓取规则（回复抓取“抓到末页”）

只要主题在窗口内，就会进入“抓完整回复”的策略：

- 窗口内主题：抓到最后一页（直到详情页返回的 `page_total`）
- 窗口外主题：仅更新主题列表信息与审计记录，不抓回复详情（不发详情页请求）
- 未启用窗口时：回退到 `--max-post-pages` 的单次抓取上限（默认 5 页），后续可通过重复运行自动分段补齐到末页（除非触发 >1000 页跳过保护）。

保护策略（避免超大主题拖垮运行/对源站压力过大）：

- 若主题详情探测到 `page_total > 1000`，则直接跳过回复抓取，并在数据库中标记该主题“因超页数跳过”。
  - 注意：这里的阈值是“主题总页数”，不是“回复条数”。（两者相关但不完全等价）

例子（可预期行为）：

- 近 3 天新建的小主题：会把回复抓到末页并落库到 posts。
- 近 3 天新建但页数超过 1000 的巨型主题：不会抓任何回复（posts 不更新），只会更新 threads 并留下跳过标记与审计记录。
- 4 天前创建的主题（即使今天有人回复）：由于窗口按创建时间判断，该主题不会抓回复详情。

### 5) 增量策略（不重复抓取没改变的数据）

窗口内主题“需要抓全”与“增量不重复”并不冲突：完整性是针对“最终结果”，增量是针对“重复运行的成本”。

增量抓取开关：

- 以 `last_reply_at` 变化作为增量开关：主题的最后回复时间有变化时，才会触发详情抓取（或继续分段补齐）。
- 若 `last_reply_at` 没变化且已补齐完成：本次只更新列表字段与审计，不抓详情。

楼层去重与变更检测：

- 新增楼层模式：使用楼层游标（最大 floor / 最大 pid）跳过已抓楼层，避免重复写入与唯一约束冲突。
- 变更检测模式：当 `last_reply_at` 变化时，会复查已抓楼层的内容指纹/删除/折叠标记，必要时写入历史版本（PostRevision）。

例子：

- 你连续跑两次 `--recent-days=3`：
  - 第一次会把窗口内主题补齐到末页（<=1000 页的前提下）。
  - 第二次只会对 `last_reply_at` 发生变化的主题再次请求详情；其余主题不重复抓取。

### 6) 抓取频率（对 NGA 压力）与配置口径

抓取频率是“单进程限速”，由 forums 表字段 `request_rate_limit_per_sec` 控制：

- `1.0`：平均 1 秒 1 次请求（约 1 req/s）
- `0.5`：平均 2 秒 1 次请求（约 0.5 req/s）

同时还有失败重试与退避：

- 对 408/429/5xx 会重试并指数退避（带 jitter），429 会优先遵循 Retry-After。
- 作用：当源站压力大/限流时自动降速，避免集中重试造成更大压力。

建议：

- 你现在是 1 路执行，`0.5 ~ 1.0 req/s` 属于相对保守的范围；自动翻页只增加总请求量，不提高瞬时频率。

## 抓取效果（最终落库长相）

### 主题（threads）

- 只要列表页解析到主题，就会 upsert 到 threads（包含标题、作者、创建时间、最后回复时间、置顶/精华等列表字段）。
- 对于窗口外主题：
  - threads 会更新（用于“看得到主题”与审计可追溯）
  - 不会抓取回复详情（posts 不会因该主题而新增/更新）
- 对于窗口内主题：
  - 会抓取回复详情直到末页（<=1000 页前提）
  - 若 `page_total > 1000`：设置 `is_skipped_by_page_total_limit=1` 并跳过回复抓取（posts 不更新）
- 当抓取过主题详情后，会优先用详情页口径刷新 `threads.reply_count_display`（列表页的 `reply_count_display` 可能不准）。
  - 口径说明：回复数不包含楼主 0 楼；详情页若提供“总楼层/总行数”，会按“总楼层 - 1”写回。
  - 兜底规则：详情页拿不到总楼层时，只有在“已抓到最后一页”的情况下，才用最大楼层号回填回复数。
  - 若未抓到末页且无法解析总楼层：保持列表页的 `reply_count_display`，等待后续补齐或再次抓取纠正。

### 回复（posts）

- 窗口内主题的回复会写入 posts。
- 重复运行时：
  - 未变化的楼层会被游标跳过（不重复抓、不重复写）
  - 若检测到内容指纹/删除/折叠状态变化，会更新 posts，并可写入 post_revisions 作为历史记录（用于追溯）

### 审计（crawl_runs / crawl_run_threads）

- 每次运行会写入一条 crawl_runs 汇总（时间窗口、耗时、请求数、成功/失败等）。
- 每个主题处理会写入 crawl_run_threads 明细（成功/失败、抓取页数、是否触发页上限、错误摘要等）。

你可以通过前端页面验证抓取效果：

- 运行报表列表：`http://localhost:5173/crawl-runs`
- 运行报表详情：`http://localhost:5173/crawl-runs/{runId}`
- 缺楼层审计列表：`http://localhost:5173/floor-audit-runs`
- 缺楼层审计详情：`http://localhost:5173/floor-audit-runs/{runId}`
- 缺楼层楼层明细：`http://localhost:5173/floor-audit-threads/{auditThreadId}`

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
php artisan config:clear
```

说明：后端不再依赖 Node（不包含 backend/package.json/Vite）。Node 仅用于 frontend。

#### HTMLPurifier 缓存刷新（规则变更时）

当你修改 HTML 清洗规则（白名单/自定义属性）时，需要刷新 HTMLPurifier 的定义缓存，完整步骤如下：

1) 更新环境变量（递增版本号）：

```bash
# backend/.env
NGA_HTMLPURIFIER_DEFINITION_REV=2
```

2) 清理或重建配置缓存：

Docker 方式：

```bash
docker compose exec php php artisan config:clear
```

3) 可选：删除 HTMLPurifier 定义缓存文件（确保立即生效）：

Docker 方式：

```bash
docker compose exec php rm -rf storage/framework/cache/htmlpurifier
```

#### ECS 一键部署脚本

```bash
bash scripts/deploy-ecs.sh
```

说明：脚本依赖 `flock`（通常由 util-linux 提供）实现部署互斥锁；缺少时会直接退出。

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

补充说明（测试环境）：
- `phpunit.xml` 已指定 SQLite + `APP_CONFIG_CACHE` 临时路径，测试不会命中 `bootstrap/cache/config.php`，无需再手动 `config:clear`。

### MySQL 日志（general_log）
为便于定位误操作，已在 `docker/mysql/my.cnf` 开启 general_log（写入 `/var/lib/mysql/general.log`）。

- 注意：general_log 会持续增长，建议在问题排查后关闭或做日志轮转。
