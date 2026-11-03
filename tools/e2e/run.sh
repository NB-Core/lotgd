#!/bin/bash
# Run the browser suites against a running test game (see README.md).
#
#   tools/e2e/run.sh                     every suite, the skin-dependent ones on Aurora and jade
#   tools/e2e/run.sh town fights         those suites only
#   tools/e2e/run.sh --skins all town    town on all ten skins
#
# --skins: default (Aurora and jade), twig, legacy, all.
# Before each suite, reset.sql puts back what one suite may leave for the
# next. Results go to $LOTGD_E2E_OUT (default tools/e2e/results/): one log
# per run and results.tsv (run, passed/total, exit code, failures).
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

DB_CMD="${LOTGD_E2E_DB_CMD:-docker exec -i lotgd-e2e-db mariadb -N -ulotgd -plotgdpw lotgd}"
GAME="${LOTGD_E2E_GAME:-$(cd ../.. && pwd)}"
OUT="${LOTGD_E2E_OUT:-$DIR/results}"
SKINSET=default
SUITES=()
while [ $# -gt 0 ]; do
  case "$1" in
    --skins) SKINSET="$2"; shift 2 ;;
    --skins=*) SKINSET="${1#--skins=}"; shift ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    *) SUITES+=("$1"); shift ;;
  esac
done

TWIG="twig:aurora twig:aurora_minimal twig:aurora_test twig:modern twig:puritanic twig:puritanic_ai"
LEGACY="legacy:jade.htm legacy:modern.htm legacy:yarbrough.htm legacy:puritanic_ai.htm"
case "$SKINSET" in
  default) SKINS="twig:aurora legacy:jade.htm" ;;
  twig) SKINS="$TWIG" ;;
  legacy) SKINS="$LEGACY" ;;
  all) SKINS="$TWIG $LEGACY" ;;
  *) echo "unknown --skins $SKINSET" >&2; exit 2 ;;
esac

# Suites that take a skin (SKIN=...), the legacy suite (a template name),
# the realtime suites (both transports) and the rest.
PER_SKIN="town fights forms chat indicator notices timeout fmt modules"
TRANSPORT="duel encounters extras"
OTHERS="skins overlay async realtime sse commentary bank grants staletab pvplive status usereditor never xss keys dweditor dwtypes fmtpreview fmtliteral tour e2e"
if [ ${#SUITES[@]} -eq 0 ]; then
  read -r -a SUITES <<< "$PER_SKIN legacy $TRANSPORT $OTHERS"
fi

mkdir -p "$OUT/shots"
RES="$OUT/results.tsv"
: > "$RES"

run() { # label suite env...
  local label=$1 suite=$2
  shift 2
  $DB_CMD < reset.sql
  # The data cache, if the game keeps one there: its entries, never the directory.
  [ -n "$GAME" ] && [ -d "$GAME/data_cache" ] && find "$GAME/data_cache" -mindepth 1 -delete
  local log="$OUT/$(echo "$label" | tr ':/ ' '___').log"
  env OUT="$OUT/shots" LOTGD_E2E_GAME="$GAME" "$@" timeout 900 node "$suite.js" "$OUT/shots" > "$log" 2>&1
  local rc=$?
  local sum
  sum=$(grep -Eo '^[0-9]+/[0-9]+( passed)?$|[0-9]+/[0-9]+ passed' "$log" | tail -1)
  local fails
  fails=$(grep -c '^FAIL' "$log")
  printf "%s\t%s\t%s\t%s\n" "$label" "${sum:-$(grep -c '^PASS' "$log") passed, no total}" "$rc" "$fails" | tee -a "$RES"
}

for suite in "${SUITES[@]}"; do
  if [ ! -f "$suite.js" ]; then
    echo "no suite $suite" >&2
    continue
  fi
  case " $PER_SKIN " in
    *" $suite "*)
      for skin in $SKINS; do run "$suite@$skin" "$suite" SKIN="$skin"; done
      continue ;;
  esac
  case "$suite" in
    legacy)
      for skin in $SKINS; do
        case "$skin" in legacy:*) for t in poll sse; do run "legacy@${skin#legacy:}@$t" legacy SKIN="${skin#legacy:}" TRANSPORT=$t; done ;; esac
      done
      continue ;;
  esac
  case " $TRANSPORT " in
    *" $suite "*)
      for t in sse poll; do run "$suite@$t" "$suite" TRANSPORT=$t; done
      continue ;;
  esac
  run "$suite" "$suite"
done

echo
echo "Failed runs:"
awk -F'\t' '$3 != 0 || $4 != 0 { print "  " $1 "\t" $2 "\t" $4 " FAIL" }' "$RES"
# Non-zero when any run failed, so a script calling this can tell.
awk -F'\t' '$3 != 0 || $4 != 0 { bad = 1 } END { exit bad }' "$RES"
