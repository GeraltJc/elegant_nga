#!/usr/bin/env bash
set -euo pipefail

date_str="${1:-$(date +%F)}"
template="docs/progress/templates/LOG.md"
out="docs/progress/logs/${date_str}.md"

if [[ -e "${out}" ]]; then
  echo "Log already exists: ${out}"
  exit 1
fi

sed -e "s/{{DATE}}/${date_str}/g" "${template}" > "${out}"
echo "Created: ${out}"

