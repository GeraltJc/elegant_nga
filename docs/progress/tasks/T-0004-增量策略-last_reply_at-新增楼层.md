# T-0004 - 增量策略-last_reply_at-新增楼层

- status: todo
- created: 2026-01-20
- owner: (optional)
- related:
  - architecture: `项目架构规划文档.md`
  - environment: `开发环境设置指南.md`
  - database: `数据库设计说明.md`
  - adr: (optional) `docs/adr/ADR-xxxx-*.md`

## Scope

- In:
  - 基于 `last_reply_at` 判定主题是否需要更新
  - 仅抓新增楼层（游标：最大 pid/楼层号）
  - 处理 5 页上限截断与标记
  - 幂等更新：重复运行不产生重复楼层
- Out:
  - UBB→HTML 与 Sanitizer（T-0005）

## Acceptance

- [ ] `last_reply_at` 变化触发更新，未变化不更新
- [ ] 只抓新增楼层且游标可推进
- [ ] 超过 5 页截断且可在主题中标记
- [ ] 重复运行结果一致

## Plan

1) 增量判定与游标存储
2) 新增楼层抓取与幂等
3) 截断策略与标记

## Notes

- 依赖 T-0003 的基础入库能力
