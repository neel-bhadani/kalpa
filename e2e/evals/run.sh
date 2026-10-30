#!/bin/sh
# make eval-story [EVAL_MODELS="qwen3:1.7b qwen3:4b"]: for each model, write every brief in Auto mode
# against kalpa-ollama, sample the model server's RAM meanwhile, and print the report.
set -u
MODELS="${EVAL_MODELS:-qwen3:1.7b}"
cd "$(dirname "$0")/../.."

docker compose --profile ai up -d ollama >/dev/null
for i in $(seq 1 30); do curl -sf http://localhost:11434/api/version >/dev/null && break; sleep 1; done

status=0
for model in $MODELS; do
  echo "== $model"
  docker compose --profile ai exec -T ollama ollama pull "$model" >/dev/null || { echo "Could not pull $model."; status=1; continue; }

  # The model server's memory every 2 s (docker stats), highest kept.
  ram_log=$(mktemp)
  ( while :; do docker stats --no-stream --format '{{.MemUsage}}' kalpa-ollama 2>/dev/null | cut -d/ -f1 >> "$ram_log"; sleep 2; done ) &
  sampler=$!

  docker compose exec -T -e EVAL_MODEL="$model" -e EVAL_ONLY="${EVAL_ONLY:-}" web npx vitest run --config vitest.eval.config.ts || status=1

  kill "$sampler" 2>/dev/null
  ram=$(awk '{ v=$1; n=v+0; if (v ~ /GiB/) n*=1024; else if (v ~ /KiB/) n/=1024; if (n>m) m=n } END { printf "%.0f MiB", m }' "$ram_log")
  limit=$(docker inspect kalpa-ollama --format '{{.HostConfig.Memory}}' | awk '{ printf "%.0f MiB", $1/1048576 }')
  rm -f "$ram_log"

  file="results/$(echo "$model" | sed 's/[^A-Za-z0-9_.-]/_/g').json"
  docker compose exec -T api php artisan eval:story-report "/evals/$file" --ram="$ram of $limit" || status=1
done
exit $status
