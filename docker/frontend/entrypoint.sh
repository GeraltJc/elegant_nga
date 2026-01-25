#!/bin/sh
set -e

cd /var/www/frontend

# 关键规则：node_modules 空目录时也要安装依赖（避免 vite 缺失）
if [ ! -x node_modules/.bin/vite ]; then
  if [ -f package-lock.json ]; then
    npm ci
  else
    npm install
  fi
fi

exec npm run dev -- --host 0.0.0.0 --port "${VITE_DEV_PORT:-5173}"
