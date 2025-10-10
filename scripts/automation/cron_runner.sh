#!/usr/bin/env bash
# cron_runner.sh
# Unified entry-point for running SimpleFeedMaker maintenance agents from cron.
# Usage examples:
#   scripts/automation/cron_runner.sh hourly
#   scripts/automation/cron_runner.sh daily
#   scripts/automation/cron_runner.sh weekly
#   scripts/automation/cron_runner.sh quarterly
#
# Optional environment:
#   SFM_ALERT_EMAIL, SFM_ALERT_EMAILS, SFM_BACKUPS_DIR, SFM_CHECKSUM_FILE,
#   PHP_BIN (defaults to php)

set -euo pipefail
IFS=$' \n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SECURE_DIR="${ROOT_DIR}/secure"
ENV_FILE="${SECURE_DIR}/cron.env"

if [[ -f "${ENV_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${ENV_FILE}"
fi

if [[ -n "${SFM_ALERT_EMAIL:-}" ]]; then
  export SFM_ALERT_EMAIL
fi
export PHP_BIN="${PHP_BIN:-php}"

log() {
  printf '[%s] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"
}

run_php() {
  local script_path="$1"
  shift
  log "Running ${script_path} $*"
  "${PHP_BIN}" "${ROOT_DIR}/${script_path}" "$@"
}

task=${1:-}
shift || true
extra_args=("$@")

if [[ -z "${task}" ]]; then
  echo "Usage: $0 <hourly|daily|weekly|quarterly> [extra args]" >&2
  exit 2
fi

case "${task}" in
  hourly)
    run_php secure/scripts/rate_limit_inspector.php --threshold=150 --top=10 --block --notify "${extra_args[@]}"
    ;;
  daily)
    run_php secure/scripts/cleanup_feeds.php --max-age=3d --quiet "${extra_args[@]}"
    ;;
  weekly)
    run_php secure/scripts/log_sanitizer.php --notify "${extra_args[@]}"
    ;;
  quarterly)
    extra_opts=()
    if [[ -n "${SFM_BACKUPS_DIR:-}" ]]; then
      extra_opts+=("--backups=${SFM_BACKUPS_DIR}")
    fi
    if [[ -n "${SFM_CHECKSUM_FILE:-}" ]]; then
      extra_opts+=("--checksum-file=${SFM_CHECKSUM_FILE}")
    fi
    run_php secure/scripts/disaster_drill.php "${extra_opts[@]}" "${extra_args[@]}"
    ;;
  monitor)
    run_php secure/scripts/monitor_health.php --quiet --warn-only "${extra_args[@]}"
    ;;
  *)
    echo "Unknown task: ${task}" >&2
    exit 2
    ;;
 esac

log "${task^} automation complete."
