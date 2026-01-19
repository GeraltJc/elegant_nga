# ADR-0001 - 后端 HTTP Client 选择（抓取用）

- status: accepted
- date: 2026-01-19
- related tasks: `docs/progress/tasks/T-0001-项目脚手架与基础链路.md`

## Context

- 本项目需要抓取 NGA 的 `lite=js` 接口，并实现限速、退避、可观测日志。
- 需求强调可测试性（TDD）：抓取与解析应支持 fixtures 回放，HTTP 交互应易于 Mock。

## Decision

- 使用 **Laravel HTTP Client（`Illuminate\Support\Facades\Http`）** 作为默认 HTTP 访问层。

## Consequences

- Pros:
  - 内置 Fake/Mock 机制，便于 TDD 与回归测试
  - 与 Laravel 生态一致，配置与中间件扩展更顺滑
- Cons / Trade-offs:
  - 底层仍基于 Guzzle，部分高级能力需要下探配置

## Alternatives Considered

- 直接使用 Guzzle：更底层可控，但 Mock/测试与项目一致性成本更高

