# T-0003 - Lite接口打通-列表详情解析入库

- status: done
- created: 2026-01-20
- owner: (optional)
- related:
  - architecture: `项目架构规划文档.md`
  - environment: `开发环境设置指南.md`
  - database: `数据库设计说明.md`
  - adr:
    - `docs/adr/ADR-0001-http-client.md`

## Scope

- In:
  - 接通访客 HTML 列表接口（fid=7），解析主题基础字段
  - 接通详情 HTML 接口，解析楼层数据并写入 `threads`/`posts`
  - content_html 暂存原始内容（HTML/UBB，后续由 T-0005 替换为安全 HTML）
  - 提供最小可运行抓取命令（手动执行一次）
  - 浏览器驱动模式保留但禁用（不再引用）
- Out:
  - 增量策略与游标（T-0004）
  - UBB→HTML 与 Sanitizer（T-0005）

## Acceptance

- [x] fixture 回放列表/详情解析通过（不依赖网络）
- [x] `threads`/`posts` 入库可回放且幂等
- [x] 手动命令可成功执行一次抓取并落库

## Plan

1) 先用 fixtures 打通解析
2) 再接入访客 HTML 接口并落库
3) 最后补齐命令入口与回放测试

## Notes

- 解析字段以 `数据库设计说明.md` 为准
- 验收：`php artisan test --filter NgaLiteCrawlerTest` 通过（Docker）
- 验收：`php artisan nga:crawl-lite --source=fixture` 成功（Docker）
- 验收：`php artisan nga:crawl-lite --source=http` 成功（Docker，访客 HTML 抓取）
