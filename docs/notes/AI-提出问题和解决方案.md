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
