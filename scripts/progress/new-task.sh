#!/usr/bin/env bash
set -euo pipefail

title="${1:-}"
if [[ -z "${title}" ]]; then
  echo "Usage: scripts/progress/new-task.sh \"任务标题\""
  exit 1
fi

tasks_dir="docs/progress/tasks"
template="docs/progress/templates/TASK.md"

max_id="$(
  ls -1 "${tasks_dir}" 2>/dev/null \
    | sed -nE 's/^T-([0-9]{4}).*\\.md$/\1/p' \
    | sort -n \
    | tail -n 1
)"
max_id="${max_id:-0000}"
next_id="$(printf "%04d" "$((10#${max_id} + 1))")"
task_id="T-${next_id}"

safe_title="$(printf "%s" "${title}" | sed -E 's/[[:space:]]+/-/g; s#[/:]#-#g')"
date_str="$(date +%F)"

out="${tasks_dir}/${task_id}-${safe_title}.md"
if [[ -e "${out}" ]]; then
  echo "Task already exists: ${out}"
  exit 1
fi

sed -e "s/{{TASK_ID}}/${task_id}/g" \
    -e "s/{{TITLE}}/${title}/g" \
    -e "s/{{DATE}}/${date_str}/g" \
    "${template}" > "${out}"

echo "Created: ${out}"
