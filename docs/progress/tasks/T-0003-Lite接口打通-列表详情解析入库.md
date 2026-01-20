# T-0003 - Lite接口打通-列表详情解析入库

- status: todo
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
  - 接通 `lite=js` 列表接口（fid=7），解析主题基础字段
  - 接通详情接口，解析楼层数据并写入 `threads`/`posts`
  - content_html 暂存原始 UBB（后续由 T-0005 替换为安全 HTML）
  - 提供最小可运行抓取命令（手动执行一次）
- Out:
  - 增量策略与游标（T-0004）
  - UBB→HTML 与 Sanitizer（T-0005）

## Acceptance

- [ ] fixture 回放列表/详情解析通过（不依赖网络）
- [ ] `threads`/`posts` 入库可回放且幂等
- [ ] 手动命令可成功执行一次抓取并落库

## Plan

1) 先用 fixtures 打通解析
2) 再接入真实接口并落库
3) 最后补齐命令入口与回放测试

## Notes

- 解析字段以 `数据库设计说明.md` 为准
