# T-0005 - UBB转HTML与Sanitizer

- status: done
- created: 2026-01-20
- owner: (optional)
- related:
  - architecture: `项目架构规划文档.md`
  - environment: `开发环境设置指南.md`
  - database: `数据库设计说明.md`
  - adr: (optional) `docs/adr/ADR-xxxx-*.md`

## Scope

- In:
  - UBB→HTML 解析（优先覆盖常见标签）
  - HTML 白名单清洗（防 XSS）
  - 危险链接/属性处理（如 `javascript:`）
  - fixtures 覆盖常见边界场景
- Out:
  - 前端展示细节美化（T-0006）

## Acceptance

- [x] 常见 UBB 标签可转换为可展示 HTML
- [x] 清洗后 HTML 不含危险标签/属性
- [x] fixtures 覆盖边界与异常输入

## Plan

1) 定义白名单与测试用例
2) 接入解析与清洗
3) 用 fixtures 回放验证

## Notes

- 输出字段写入 `posts.content_html`
- UBB 标签：`b/i/u/s/del/quote/code/url/img/list`（`color/size` 按纯文本）
- 指纹基于原始内容，HTML 为安全清洗结果
- Sanitizer：`ezyang/htmlpurifier`
- 验收：`php artisan test --filter NgaPostContentProcessorTest`（Docker）
- 验收：`php artisan test --filter NgaLiteCrawlerTest`（Docker）
