# AI 提出问题和解决方案

## T-0005 - UBB 转 HTML 与 Sanitizer

### 1) 支持的 UBB 标签范围
- 问题：首批要支持哪些常见 UBB 标签？
- 方案：`[b] [i] [u] [s] [del] [quote] [code] [url] [img] [list]`；`[color] [size]` 暂不解析，按纯文本输出。

### 2) URL/IMG 的语法与协议范围
- 问题：`[url]` / `[url=]` / `[img]` 的语法与允许协议？
- 方案：
  - 支持 `[url]https://...[/url]` 与 `[url=https://...]文本[/url]`。
  - 支持 `[img]https://...[/img]`。
  - 仅允许 `http/https`，不允许协议相对 `//`，不允许 `javascript:` / `data:` 等危险协议。

### 3) 换行策略
- 问题：UBB 中的换行如何输出？
- 方案：统一将 `\r\n` / `\r` 归一为 `\n`，再转换为 `<br>`。

### 4) HTML 白名单与属性策略
- 问题：Sanitizer 放行哪些标签/属性，如何处理危险属性？
- 方案：
  - 白名单标签：`a` `br` `blockquote` `pre` `code` `strong` `em` `u` `s` `del` `ul` `ol` `li` `img`。
  - `a` 仅允许 `href`，强制补 `rel="nofollow noopener noreferrer"` 与 `target="_blank"`。
  - `img` 仅允许 `src`/`alt`，强制补 `loading="lazy"` 与 `referrerpolicy="no-referrer"`。
  - 删除所有事件属性与 `style`，危险 scheme 直接移除属性或剔除元素。

### 5) 未知/异常 UBB 的处理
- 问题：遇到未识别标签或不闭合/嵌套异常的 UBB 怎么办？
- 方案：不做猜测，整体按纯文本输出；`[code]` 内不再解析任何 UBB。

### 6) 数据写入口径
- 问题：指纹与展示内容分别基于什么计算？
- 方案：`content_fingerprint_sha256` 基于原始内容；`posts.content_html` 存安全 HTML。

### 7) UBB 与 HTML 的区分
- 问题：如何区分来源是 UBB 还是 HTML？
- 方案：解析层新增 `content_format`（`ubb|html`），处理链路按格式决定“UBB→HTML→清洗”或“直接清洗”。

### 8) Sanitizer 实现方式
- 问题：引入第三方库还是自研？
- 方案：使用 `ezyang/htmlpurifier`，并叠加自定义策略做二次校验。

## T-0008 - 运行报表与稳定性优化

### 1) 是否新增运行审计表
- 问题：运行报表是仅靠日志，还是必须落库追溯？
- 方案：新增 `crawl_runs` + `crawl_run_threads` 两张表，保证 run 与 thread 级可追溯。

### 2) 运行级统计口径与关键字段
- 问题：run 层需要记录哪些统计，是否保存时间窗？
- 方案：记录 `run_started_at/run_finished_at`、`run_trigger_text`、`date_window_start/date_window_end`、`thread_scanned_count/thread_change_detected_count/thread_updated_count/http_request_count`；耗时由开始/结束时间计算。

### 3) 主题级明细需要记录什么
- 问题：thread 层明细要覆盖哪些排查字段？
- 方案：记录变更检测、抓取页数、页上限、楼层新增/更新、错误摘要与 HTTP code、开始/结束时间等关键信息。

### 4) 错误摘要归类口径
- 问题：错误类型如何统一，便于统计与排查？
- 方案：固定枚举口径：`http_429/http_5xx/http_4xx/http_timeout/http_connect_error/guest_blocked/parse_list_failed/parse_thread_failed/db_write_failed/unknown_error`，同时保留 `http_error_code`。

### 5) 失败是否中断与重试策略
- 问题：单主题失败是否中断整次抓取，是否重试？
- 方案：失败记录后继续其他主题；单主题抓取兜底重试 1 次（最多 2 次尝试）。

### 6) 限速与退避策略
- 问题：是否要加限速与指数退避？
- 方案：启用按版块配置的请求限速，并结合指数退避 + jitter，避免集中重试造成雪崩。

### 7) 报表 API 形态
- 问题：报表查询需要哪些接口形态？
- 方案：提供 `GET /api/crawl-runs`、`GET /api/crawl-runs/{id}`、`GET /api/crawl-runs/{id}/threads`，详情接口返回汇总统计与耗时。

### 8) 分页与过滤策略
- 问题：分页默认值与过滤能力如何定？
- 方案：默认 `per_page=20`（允许 1~100）；线程明细支持 `only_failed` 可选过滤；运行列表暂不增加额外过滤条件。
