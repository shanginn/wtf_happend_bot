#!/usr/bin/env bash
set -euo pipefail

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
target="${script_dir}/reconcile-host-release.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

fail() { echo "reconcile controller test failed: $*" >&2; exit 1; }
has() { grep -Fq -- "$1" "$2" || fail "$2 lacks $1"; }
missing() { ! grep -Fq -- "$1" "$2" || fail "$2 unexpectedly has $1"; }

setup() {
    dir="$tmp/$1"; log="$dir/log"; mkdir -p "$dir/bin"; : > "$log"
    cat > "$dir/bin/php" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
log="${RECONCILE_TEST_LOG:?}"; command="$2"
echo "php $command" >> "$log"
case "$command" in
  release-status)
    status="${RECONCILE_STATUS:?}"
    if [[ -n "${RECONCILE_CANDIDATE_ID:-}" && "${HOST_RELEASE_ID:-}" != "$RECONCILE_CANDIDATE_ID" ]]; then
      status="${RECONCILE_OTHER_STATUS:-$status}"
    fi
    if [[ -f "${log}.aborted" && "${HOST_RELEASE_ID:-}" == "${RECONCILE_CANDIDATE_ID:-}" ]]; then status=aborted; fi
    printf '{"status":"%s"}\n' "$status"
    [[ "$status" = active ]] && exit 0 || exit 3
    ;;
  preflight-workers|preflight-ingress)
    if [[ "${RECONCILE_FAIL_PREFLIGHT:-}" = "$command" ]]; then exit 9; fi
    ;;
  abort-release)
    printf '{"status":"%s"}\n' "${RECONCILE_ABORT_STATUS:-aborted}"
    if [[ "${RECONCILE_ABORT_STATUS:-aborted}" = aborted || "${RECONCILE_ABORT_STATUS:-aborted}" = missing ]]; then
      : > "${log}.aborted"
      exit 0
    fi
    exit 4
    ;;
  prepare-release|authorize-release|confirm-ingress-retired|reconcile-release) ;;
  *) exit 64 ;;
esac
EOF
    cat > "$dir/bin/kubectl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
log="${RECONCILE_TEST_LOG:?}"; args=" $* "
echo "kubectl $*" >> "$log"
if [[ "$args" == *' get deployment/'* ]]; then
  if [[ "$args" == *'containers[?(@.name=="bot")].image'* ]]; then
    if [[ -f "${log}.helm-restored" ]]; then printf '%s' "${RELEASE_PREVIOUS_BOT_IMAGE}"; else printf '%s' registry/app:candidate; fi
    exit 0
  fi
  if [[ -f "${log}.helm-restored" ]]; then
    if [[ "${RECONCILE_LEGACY_PREVIOUS:-false}" != true ]]; then
      printf '%s' "${RECONCILE_PREVIOUS_RELEASE_ID:-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb}"
    fi
  elif [[ "${RECONCILE_TEMPLATE_MATCH:-true}" = true ]]; then
    printf '%s' "${RECONCILE_DEPLOYED_RELEASE_ID:-${RECONCILE_CANDIDATE_ID:-$HOST_RELEASE_ID}}"
  fi
  exit 0
fi
if [[ "$args" == *' get configmap/'* ]]; then
  [[ "${RECONCILE_HELM_MARKER:-true}" = true ]] && printf '%s' "${HOST_RELEASE_ID}"
  exit 0
fi
case "$args" in
  *' rollout status '*)
    if [[ "${RECONCILE_FAIL_CANDIDATE_ROLLOUT:-false}" = true ]]; then exit 8; fi
    exit 0
    ;;
  *' logs deployment/'*) echo 'Starting durable bot polling with offset 1, limit 100, timeout 30'; exit 0;;
  *' patch cronjob/'*) exit 0;;
  *) exit 64;;
esac
EOF
    cat > "$dir/bin/helm" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
log="${RECONCILE_TEST_LOG:?}"; command="$1"; shift
echo "helm $command $*" >> "$log"
case "$command" in
  rollback)
    attempts_file="${log}.helm-attempts"; attempts=0
    [[ -f "$attempts_file" ]] && attempts="$(< "$attempts_file")"
    attempts=$((attempts + 1)); echo "$attempts" > "$attempts_file"
    if [[ "${RECONCILE_FAIL_HELM_ROLLBACK:-false}" = true ]]; then exit 12; fi
    if [[ "${RECONCILE_FAIL_FIRST_HELM_ROLLBACK:-false}" = true && "$attempts" -eq 1 ]]; then exit 12; fi
    : > "${log}.helm-restored"
    ;;
  status) printf '{"info":{"status":"deployed"}}\n' ;;
  *) exit 64 ;;
esac
EOF
    chmod +x "$dir/bin/php" "$dir/bin/kubectl" "$dir/bin/helm"
}

run() {
    local status="$1" grace="$2"; shift 2
    env PATH="$dir/bin:$PATH" RECONCILE_TEST_LOG="$log" RECONCILE_STATUS="$status" \
      RECONCILE_OTHER_STATUS=active \
      HOST_RELEASE_ID=abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd \
      RECONCILE_CANDIDATE_ID=abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd \
      RELEASE_DEPLOYMENT=wtf-happend-bot RELEASE_NAMESPACE=wtfhappendbot \
      RELEASE_CONTROLLER_CRONJOB=wtf-happend-bot-release-reconciler-abcdefabcdef \
      RELEASE_HELM_COMMIT_MARKER=wtf-happend-bot-helm-committed-abcdefabcdef \
      RELEASE_PREVIOUS_BOT_IMAGE=registry/app:old \
      RELEASE_PREVIOUS_HELM_REVISION=94 RELEASE_HELM_NAME=wtf-happend-bot \
      RELEASE_AUTO_AUTHORIZE_AFTER="$grace" "$@" bash "$target" > "$dir/out" 2>&1 \
      || { cat "$dir/out" >&2; return 1; }
}

future=$(( $(date +%s) + 3600 )); past=$(( $(date +%s) - 1 ))

setup missing_grace; run missing "$future"; has 'php release-status' "$log"; missing 'php prepare-release' "$log"; missing 'kubectl rollout' "$log"

setup missing_recovery; run missing "$past"; has 'kubectl --namespace wtfhappendbot rollout status' "$log"; has 'php preflight-workers' "$log"; has 'php prepare-release' "$log"; has 'php authorize-release' "$log"; has 'php confirm-ingress-retired' "$log"; has 'php reconcile-release' "$log"

# Runner loss during Helm has no marker. The controller must never authorize;
# it aborts any prepared state, restores one prior ReplicaSet, and proves the
# old bot crossed its own durable ingress gate.
setup no_helm_marker
if run missing "$past" RECONCILE_HELM_MARKER=false RECONCILE_OTHER_STATUS=active; then fail 'missing marker recovery succeeded'; fi
has 'php abort-release' "$log"; has 'helm rollback wtf-happend-bot 94' "$log"; has 'logs deployment/wtf-happend-bot --container=bot' "$log"
missing 'php prepare-release' "$log"; missing 'php authorize-release' "$log"

# Helm may die before replacing the Deployment while shared resources are
# already partial. Missing marker still forces the exact full prior revision.
setup no_marker_old_deployment
if run missing "$past" RECONCILE_HELM_MARKER=false RECONCILE_TEMPLATE_MATCH=false; then fail 'partial Helm recovery succeeded'; fi
has 'php abort-release' "$log"; has 'helm rollback wtf-happend-bot 94' "$log"; has 'patch cronjob/wtf-happend-bot-release-reconciler-abcdefabcdef' "$log"; missing 'php prepare-release' "$log"

# First adoption rolls back to the legacy production template, which has no
# release annotation. Exact previous image + observed polling are sufficient;
# a fabricated durable release identity is neither expected nor accepted.
setup first_upgrade_legacy_rollback
if run missing "$past" RECONCILE_HELM_MARKER=false RECONCILE_LEGACY_PREVIOUS=true; then fail 'legacy recovery succeeded'; fi
has 'helm rollback wtf-happend-bot 94' "$log"; has 'logs deployment/wtf-happend-bot --container=bot' "$log"
missing 'not restore the exact previous bot image' "$dir/out"

setup failed_candidate_rollout
if run missing "$past" RECONCILE_FAIL_CANDIDATE_ROLLOUT=true RECONCILE_OTHER_STATUS=active; then fail 'failed rollout recovery succeeded'; fi
has 'php abort-release' "$log"; has 'helm rollback wtf-happend-bot 94' "$log"; missing 'php authorize-release' "$log"

setup bounded_rollback
if run missing "$past" RECONCILE_FAIL_CANDIDATE_ROLLOUT=true RECONCILE_FAIL_HELM_ROLLBACK=true RECONCILE_OTHER_STATUS=active; then fail 'failed rollback status succeeded'; fi
[[ "$(grep -Fc 'helm rollback wtf-happend-bot 94' "$log")" == 1 ]] || fail 'full Helm rollback was retried recursively'

# The durable aborted digest is terminal. Even if its Cron races before Helm
# rollback, it self-suspends and cannot prepare or authorize the candidate.
setup aborted_interleaving
run aborted "$past"
has 'patch cronjob/wtf-happend-bot-release-reconciler-abcdefabcdef' "$log"
missing 'php prepare-release' "$log"; missing 'php authorize-release' "$log"

# Durable abort means "rollback pending" until the complete old Helm revision
# is verified. A transient failure on one Cron tick is retried by the next.
setup aborted_retry
if run aborted "$past" RECONCILE_FAIL_FIRST_HELM_ROLLBACK=true; then fail 'first transient rollback succeeded'; fi
run aborted "$past" RECONCILE_FAIL_FIRST_HELM_ROLLBACK=true
[[ "$(grep -Fc 'helm rollback wtf-happend-bot 94' "$log")" == 2 ]] || fail 'aborted recovery did not retry exact revision'
has 'patch cronjob/wtf-happend-bot-release-reconciler-abcdefabcdef' "$log"

setup missing_failure; if run missing "$past" RECONCILE_FAIL_PREFLIGHT=preflight-workers RECONCILE_OTHER_STATUS=active; then fail 'missing preflight failure succeeded'; fi; has 'php abort-release' "$log"; has 'helm rollback wtf-happend-bot 94' "$log"; missing 'php authorize-release' "$log"; has 'detectable Helm drift' "$dir/out"

setup prepared_failure; if run prepared "$past" RECONCILE_FAIL_PREFLIGHT=preflight-ingress RECONCILE_OTHER_STATUS=active; then fail 'prepared preflight failure succeeded'; fi; has 'php abort-release' "$log"; has 'helm rollback wtf-happend-bot 94' "$log"; missing 'php authorize-release' "$log"; has 'restoring the previous runtime' "$dir/out"

setup authorized; run authorized "$past" RECONCILE_ABORT_STATUS=authorized; has 'php confirm-ingress-retired' "$log"; has 'php reconcile-release' "$log"; missing 'rollout undo' "$log"

setup stale_mismatch; if run stale "$past" RECONCILE_TEMPLATE_MATCH=false; then fail 'committed deployment mismatch succeeded'; fi; has 'php release-status' "$log"; has 'helm rollback wtf-happend-bot 94' "$log"; missing 'php prepare-release' "$log"; missing 'kubectl rollout' "$log"

setup active; run active "$past"; has 'php reconcile-release' "$log"; missing 'php authorize-release' "$log"; missing 'rollout undo' "$log"; missing 'patch cronjob' "$log"

# Once B is desired, A remains the rollback target and reports retired. A's
# kept controller must never abort A or roll Helm back while B is prepared or
# authorized. It may only self-suspend after B is durably active.
for successor_phase in prepared authorized; do
  setup "retired_while_successor_${successor_phase}"
  run retired "$past" \
    RECONCILE_DEPLOYED_RELEASE_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
    RECONCILE_OTHER_STATUS="$successor_phase"
  missing 'php abort-release' "$log"; missing 'helm rollback' "$log"
  missing 'php prepare-release' "$log"; missing 'patch cronjob' "$log"
done

setup retired_after_successor_active
run retired "$past" \
  RECONCILE_DEPLOYED_RELEASE_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
  RECONCILE_OTHER_STATUS=active
has 'patch cronjob/wtf-happend-bot-release-reconciler-abcdefabcdef' "$log"
missing 'php abort-release' "$log"; missing 'helm rollback' "$log"; missing 'php prepare-release' "$log"

setup obsolete_controller
run stale "$past" \
  RECONCILE_DEPLOYED_RELEASE_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
  RECONCILE_OTHER_STATUS=active
has 'patch cronjob/wtf-happend-bot-release-reconciler-abcdefabcdef' "$log"
missing 'php prepare-release' "$log"

# A new release's one-shot bootstrap must reject admission while the release
# currently installed in the stable Deployment is already mid-cutover. Its
# separately named controller remains untouched and can converge forward.
setup bootstrap_busy
if run stale "$future" RELEASE_BOOTSTRAP_ONLY=true \
  RECONCILE_DEPLOYED_RELEASE_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
  RECONCILE_OTHER_STATUS=authorized; then fail 'busy release admission succeeded'; fi
has 'php release-status' "$log"; has 'must converge before another Deployment mutation' "$dir/out"
missing 'php prepare-release' "$log"; missing 'patch cronjob' "$log"

echo 'release controller recovery tests passed'
