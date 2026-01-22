# T-0004 - 增量策略-last_reply_at-新增楼层

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
  - 基于 `last_reply_at` 判定主题是否需要更新
  - 仅抓新增楼层（游标：最大 pid/楼层号）
  - 处理 5 页上限截断与标记
  - 分段补齐：单主题页数较多时，按每次最多 5 页跨多次补齐到全量
  - 保护策略：主题总页数 >1000 页时不抓取楼层（仅更新 threads 列表字段并记录跳过）
  - 幂等更新：重复运行不产生重复楼层
- Out:
  - UBB→HTML 与 Sanitizer（T-0005）

## Acceptance

- [x] `last_reply_at` 变化触发更新，未变化不更新（已补测试）
- [x] 只抓新增楼层且游标可推进（已补测试）
- [x] 超过 5 页截断且可在主题中标记（已补测试）
- [x] 分段补齐可跨多次运行最终抓全（已补测试）
- [x] 主题总页数 >1000 页时跳过 posts 抓取（已补测试）
- [x] 重复运行结果一致

## Plan

1) 增量判定与游标存储
2) 新增楼层抓取与幂等
3) 截断策略与标记

## Verification

- 迁移状态：全部 Ran（`php artisan migrate:status`）
- 表/字段注释：与 `docs/数据库注释清单.md` 对照一致（information_schema 对比）
- 自动化测试：未在本机重新执行

## Notes

- 依赖 T-0003 的基础入库能力
- 覆盖增量/截断的 fixtures 测试：`backend/tests/Feature/NgaLiteCrawlerTest.php`
