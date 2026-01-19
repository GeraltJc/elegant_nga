# T-0002 - 开发环境 Docker 脚手架

- status: done
- created: 2026-01-19
- owner: jc
- related:
  - environment: `开发环境设置指南.md`
  - rules:
    - `docs/rules/TDD工作流.md`
    - `docs/rules/文件化进度追踪.md`

## Scope

- In:
  - 落地 `docker-compose.yml`（nginx/php-fpm/mysql）
  - 提供 Nginx/PHP/MySQL 基础配置（时区、字符集、Laravel 入口约定）
  - 提供 `.env.example`（Docker Compose 用）
  - 提供项目启动说明（README）
- Out:
  - 生成实际 Laravel/Vue 项目代码（需要网络，由开发者本地执行脚手架命令）

## Acceptance

- [x] `docker compose up -d --build` 可正常启动 3 个服务
- [x] Nginx 能转发到 php-fpm（`/` 返回 200）
- [x] MySQL 可通过容器内 `mysql` 连接（Laravel 可跑迁移）
- [x] README 中包含初始化后端/前端的步骤

## Notes

- 由于仓库不自带 `backend/`、`frontend/` 的依赖与代码，本任务的“可验证性”依赖本地网络完成脚手架生成。
