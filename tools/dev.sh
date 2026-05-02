#!/usr/bin/env bash
# dev.sh — one-stop developer harness for VYN Laravel Backend.
#
# Subcommands:
#   up        — start the local dev server (php artisan serve :8000) in foreground
#   bg        — same, but in background; writes /tmp/vyn-serve.log
#   down      — kill any process bound to :8000
#   migrate   — apply pending migrations
#   fresh     — drop all tables, recreate, seed (DESTRUCTIVE — local only)
#   smoke     — run the API smoke runner against localhost
#   test      — run PHPUnit (composer test)
#   phpstan   — run static analysis at level 5
#   pint      — auto-format with Laravel Pint
#   audit     — run codebase audit against CLAUDE.md rules
#   check     — run all gates: pint --test, phpstan, test, smoke (no writes)
#   tail      — tail storage/logs/laravel.log
#   triage    — gather context for a failing endpoint (e.g. ./tools/dev.sh triage GET /api/staff)
#   baseline  — save current smoke run as a baseline JSON (./tools/dev.sh baseline prod-2026-05-02 --base https://admin.vynhq.com)
#   diff      — compare a saved baseline against a fresh smoke run (./tools/dev.sh diff tools/baselines/local-day3-postfix.json)
#   baselines — list saved baselines
#   doctor    — diagnose env: PHP, DB reachability, .env presence, migrations status
#   help      — show this list

set -e
cd "$(dirname "$0")/.."

export PATH="/opt/homebrew/opt/php@8.3/bin:/opt/homebrew/opt/postgresql@16/bin:$PATH"

if [ -t 1 ]; then
  YELLOW=$'\033[1;33m'; GREEN=$'\033[0;32m'; RED=$'\033[0;31m'; GRAY=$'\033[0;90m'; OFF=$'\033[0m'
else
  YELLOW=""; GREEN=""; RED=""; GRAY=""; OFF=""
fi

cmd="${1:-help}"; shift || true

case "$cmd" in
  up)
    echo "${YELLOW}Starting Laravel dev server on :8000${OFF}"
    php artisan serve --port=8000
    ;;

  bg)
    if lsof -ti:8000 >/dev/null 2>&1; then
      echo "${YELLOW}A process is already on :8000. Run './tools/dev.sh down' first.${OFF}"
      exit 1
    fi
    nohup php artisan serve --port=8000 > /tmp/vyn-serve.log 2>&1 &
    sleep 1
    echo "${GREEN}Server up on http://localhost:8000${OFF} (logs: /tmp/vyn-serve.log)"
    curl -s -o /dev/null -w "  /up returns HTTP %{http_code}\n" http://localhost:8000/up
    ;;

  down)
    pids=$(lsof -ti:8000 2>/dev/null || true)
    if [ -z "$pids" ]; then
      echo "${GRAY}Nothing on :8000${OFF}"
    else
      echo "${YELLOW}Killing PIDs:${OFF} $pids"
      echo $pids | xargs kill 2>/dev/null || true
    fi
    ;;

  migrate)
    php artisan migrate --force
    ;;

  fresh)
    echo "${RED}This DROPS the local database and re-seeds.${OFF}"
    read -p "Continue? (y/N) " ans
    [ "$ans" = "y" ] || exit 0
    php artisan migrate:fresh --seed --force
    ;;

  smoke)
    python3 tools/api-smoke-test.py "$@"
    ;;

  test)
    composer test
    ;;

  phpstan)
    composer phpstan "$@"
    ;;

  pint)
    if [ "$1" = "--test" ]; then
      vendor/bin/pint --test
    else
      vendor/bin/pint "$@"
    fi
    ;;

  audit)
    python3 tools/audit.py "$@"
    ;;

  check)
    echo "${YELLOW}=== gate 1: pint --test ===${OFF}"
    vendor/bin/pint --test
    echo "${YELLOW}=== gate 2: phpstan ===${OFF}"
    composer phpstan
    echo "${YELLOW}=== gate 3: phpunit ===${OFF}"
    composer test
    echo "${YELLOW}=== gate 4: smoke (read-only) ===${OFF}"
    python3 tools/api-smoke-test.py
    echo "${GREEN}All gates green.${OFF}"
    ;;

  tail)
    tail -f storage/logs/laravel.log
    ;;

  triage)
    if [ -z "$1" ] || [ -z "$2" ]; then
      echo "Usage: ./tools/dev.sh triage <METHOD> <PATH>"
      echo "  e.g. ./tools/dev.sh triage GET /api/staff"
      exit 1
    fi
    python3 tools/triage.py "$1" "$2"
    ;;

  baseline)
    # Save current run as a named baseline
    name="${1:-baseline-$(date +%Y%m%d-%H%M%S)}"
    shift || true
    mkdir -p tools/baselines
    out="tools/baselines/${name}.json"
    python3 tools/api-smoke-test.py --json "$@" > "$out"
    echo "${GREEN}Saved baseline:${OFF} $out"
    python3 -c "import json,sys; d=json.load(open('$out')); print(f'  PASS={d[\"pass\"]} FAIL={d[\"fail\"]} SKIP={d[\"skip\"]} TOTAL={d[\"total\"]} target={d[\"target\"]} role={d[\"role\"]}')"
    ;;

  diff)
    # Compare a baseline against a fresh run
    if [ -z "$1" ]; then
      echo "Usage: ./tools/dev.sh diff <baseline.json> [extra smoke flags]"
      echo "  e.g. ./tools/dev.sh diff tools/baselines/local-day3-postfix.json"
      echo "       ./tools/dev.sh diff tools/baselines/prod-day3.json --base https://admin.vynhq.com"
      exit 1
    fi
    baseline="$1"; shift
    [ -f "$baseline" ] || { echo "${RED}Baseline not found:${OFF} $baseline"; exit 1; }
    tmp="/tmp/smoke-current-$$.json"
    python3 tools/api-smoke-test.py --json "$@" > "$tmp"
    python3 tools/diff-smoke.py "$baseline" "$tmp"
    rc=$?
    rm -f "$tmp"
    exit $rc
    ;;

  baselines)
    # List saved baselines
    if [ -d tools/baselines ]; then
      echo "${YELLOW}Saved baselines:${OFF}"
      for f in tools/baselines/*.json; do
        [ -f "$f" ] || continue
        python3 -c "import json,sys,os; d=json.load(open('$f')); print(f'  {os.path.basename(\"$f\"):40} pass={d[\"pass\"]:3d} fail={d[\"fail\"]} target={d[\"target\"]}')"
      done
    else
      echo "${GRAY}No baselines yet. Save one with: ./tools/dev.sh baseline <name>${OFF}"
    fi
    ;;

  doctor)
    echo "${YELLOW}=== Environment doctor ===${OFF}"
    echo -n "  PHP version:      "; php -v 2>/dev/null | head -1 || echo "${RED}NOT FOUND${OFF}"
    echo -n "  Composer:         "; composer --version 2>/dev/null | head -1 || echo "${RED}NOT FOUND${OFF}"
    echo -n "  artisan:          "; php artisan --version 2>/dev/null | head -1 || echo "${RED}NOT FOUND${OFF}"
    echo -n "  .env present:     "; [ -f .env ] && echo "${GREEN}yes${OFF}" || echo "${RED}NO${OFF}"
    echo -n "  APP_KEY set:      "; grep -q "^APP_KEY=base64:" .env 2>/dev/null && echo "${GREEN}yes${OFF}" || echo "${RED}NO${OFF}"
    echo -n "  DB reachable:     "; psql -h "$(grep ^DB_HOST .env|cut -d= -f2)" -U "$(grep ^DB_USERNAME .env|cut -d= -f2)" -d "$(grep ^DB_DATABASE .env|cut -d= -f2)" -c "select 1" -tA 2>/dev/null | grep -q 1 && echo "${GREEN}yes${OFF}" || echo "${YELLOW}no (or sqlite)${OFF}"
    echo -n "  Pending migrations:"; php artisan migrate:status 2>/dev/null | grep -c "Pending" || echo "${GRAY}n/a${OFF}"
    echo -n "  Server on :8000:  "; lsof -ti:8000 >/dev/null 2>&1 && echo "${GREEN}up${OFF}" || echo "${GRAY}down${OFF}"
    echo -n "  Vendor installed: "; [ -d vendor ] && echo "${GREEN}yes${OFF}" || echo "${RED}NO (run composer install)${OFF}"
    ;;

  help|--help|-h|"")
    awk '/^# /{print substr($0,3)}' "$0" | head -25
    ;;

  *)
    echo "${RED}Unknown command:${OFF} $cmd"
    echo "Run './tools/dev.sh help' for the list."
    exit 1
    ;;
esac
