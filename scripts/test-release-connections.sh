#!/usr/bin/env bash
set -euo pipefail
root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
export REAL_HELM="$(command -v helm)"
export CONNECTION_PROFILE="$work/values.json"
export MOCK_LOG="$work/kubectl.log" MOCK_APPLIED="$work/applied.yaml"
cat > "$CONNECTION_PROFILE" <<'JSON'
{
  "extraEnv": [
    {"name":"DB_HOST","value":"fixture-postgres.database"},
    {"name":"DB_PASSWORD","valueFrom":{"secretKeyRef":{"name":"fixture-db-secret","key":"password"}}},
    {"name":"PGSSLMODE","value":"verify-full"}
  ],
  "extraVolumeMounts":[{"name":"fixture-ca","mountPath":"/var/run/test-pg","readOnly":true}],
  "extraVolumes":[{"name":"fixture-ca","configMap":{"name":"fixture-ca"}}]
}
JSON

check_template() {
    local template="$1" consumers="$2"
    shift 2
    "$REAL_HELM" template wtf-happend-bot "$root/helm" --namespace wtfhappendbot \
        --values "$CONNECTION_PROFILE" --show-only "templates/$template" "$@" > "$work/render.yaml"
    test "$(grep -Fc 'name: PGSSLMODE' "$work/render.yaml")" = "$consumers"
    test "$(grep -Fc 'name: fixture-db-secret' "$work/render.yaml")" = "$consumers"
    test "$(grep -Fc 'mountPath: /var/run/test-pg' "$work/render.yaml")" = "$consumers"
    grep -Fq 'value: verify-full' "$work/render.yaml"
}
release_id="$(printf 'a%.0s' {1..64})"
check_template deployment.yaml 4
check_template release-operation-job.yaml 1 --set releaseOperation.enabled=true \
    --set-string releaseOperation.id="$release_id" --set releaseOperation.operation=preflight-workers
controller=(--set releaseReconciler.enabled=true --set-string release.id="$release_id" \
    --set-string releaseReconciler.autoAuthorizeAfterEpoch=9999999999 \
    --set-string releaseReconciler.previousHelmRevision=1)
check_template release-controller-bootstrap-job.yaml 2 "${controller[@]}" \
    --set releaseControllerBootstrap.enabled=true --set-string releaseControllerBootstrap.id="$release_id"
check_template release-reconciler.yaml 1 "${controller[@]}"

for name in HOST_RELEASE_ID SPACE_AGENT_TASK_QUEUE SPACE_DREAM_TASK_QUEUE RELEASE_INGRESS_GATE SKIP_MIGRATIONS WORKER_PACKAGE; do
    if "$REAL_HELM" template wtf-happend-bot "$root/helm" \
        --set-json "extraEnv=[{\"name\":\"$name\",\"value\":\"invalid\"}]" > /dev/null 2> "$work/refusal"; then
        echo "Connection overrides admitted release control $name" >&2; exit 1
    fi
    grep -Fq 'extraEnv cannot override a release control' "$work/refusal"
done
if "$REAL_HELM" template wtf-happend-bot "$root/helm" \
    --set-json 'extraEnv=[{"name":"DB_HOST","value":"a"},{"name":"DB_HOST","value":"b"}]' > /dev/null 2> "$work/refusal"; then
    echo 'Duplicate connection variables were admitted' >&2; exit 1
fi
grep -Fq 'duplicate DB_HOST' "$work/refusal"

mkdir "$work/bin"
cat > "$work/bin/helm" <<'BASH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "$1" = get && "$2" = values ]]; then
    [[ "${FAIL_VALUES:-0}" = 0 ]] || exit 31
    cat "$CONNECTION_PROFILE"
else
    exec "$REAL_HELM" "$@"
fi
BASH
cat > "$work/bin/kubectl" <<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$MOCK_LOG"
if [[ "$3" = apply ]]; then cp "$5" "$MOCK_APPLIED"; fi
if [[ "$3" = logs ]]; then printf '{"status":"active"}\n'; fi
BASH
chmod 700 "$work/bin/helm" "$work/bin/kubectl"
PATH="$work/bin:$PATH" bash "$root/scripts/run-release-operation.sh" release-status "$release_id" > "$work/result"
grep -Fq 'fixture-postgres.database' "$MOCK_APPLIED"
grep -Fq 'mountPath: /var/run/test-pg' "$MOCK_APPLIED"
rm "$MOCK_LOG"
if PATH="$work/bin:$PATH" FAIL_VALUES=1 bash "$root/scripts/run-release-operation.sh" release-status "$release_id" > "$work/result" 2>&1; then
    echo 'Missing stored Helm connection values were ignored' >&2; exit 1
fi
test ! -e "$MOCK_LOG"
echo 'Release connection preservation and pre-effect refusal checks passed'
