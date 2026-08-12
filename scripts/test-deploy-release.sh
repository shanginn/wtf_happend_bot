#!/usr/bin/env bash

set -euo pipefail

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
deploy_script="${script_dir}/deploy-release.sh"
temporary_root="$(mktemp -d)"
trap 'rm -rf "$temporary_root"' EXIT

fail() { echo "deploy-release test failed: $*" >&2; exit 1; }
has() { grep -Fq -- "$1" "$2" || fail "$2 does not contain $1"; }
missing() { ! grep -Fq -- "$1" "$2" || fail "$2 unexpectedly contains $1"; }
before() {
    local first second file first_line second_line
    first="$1"; second="$2"; file="$3"
    first_line="$(grep -Fn -- "$first" "$file" | head -n1 | cut -d: -f1)"
    second_line="$(grep -Fn -- "$second" "$file" | head -n1 | cut -d: -f1)"
    [[ -n "$first_line" && -n "$second_line" && "$first_line" -lt "$second_line" ]] \
        || fail "${first} must occur before ${second} in ${file}"
}

prepare() {
    scenario_dir="${temporary_root}/$1"; scenario_log="${scenario_dir}/calls.log"
    mkdir -p "${scenario_dir}/bin"; : > "$scenario_log"
    unset HELM_TEST_ALIGNED
    cp "${script_dir}/test-fixtures/fake-release-helm.sh" "${scenario_dir}/bin/helm"
    cp "${script_dir}/test-fixtures/fake-release-kubectl.sh" "${scenario_dir}/bin/kubectl"
    cp "${script_dir}/test-fixtures/fake-release-operation.sh" "${scenario_dir}/operation"
    chmod +x "${scenario_dir}/bin/"* "${scenario_dir}/operation"
}

run() {
    PATH="${scenario_dir}/bin:${PATH}" RELEASE_TEST_LOG="$scenario_log" RELEASE_TEST_BEHAVIOR="$1" \
    RELEASE_TEST_STATE_DIR="$scenario_dir" RELEASE_OPERATION_RUNNER="${scenario_dir}/operation" \
    RELEASE_RETRY_DELAY_SECONDS=0 RELEASE_RECOVERY_ATTEMPTS=1 RELEASE_RECOVERY_VERIFY_ATTEMPTS=1 \
    DREAM_SCHEDULE_RECONCILE_ATTEMPTS="${RELEASE_WAIT_ATTEMPTS:-1}" DB_PASSWORD=x TELEGRAM_BOT_TOKEN=x DEEPSEEK_API_KEY=x \
    bash "$deploy_script" "sha256:abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd" > "$2" 2>&1
}

prepare preflight_failure; out="${scenario_dir}/out"
if run preflight-failure "$out"; then fail 'preflight failure succeeded'; fi
has 'kubectl bootstrap complete' "$scenario_log"; has 'helm upgrade stable' "$scenario_log"; has 'operation prepare-release' "$scenario_log"
before 'kubectl bootstrap complete' 'helm upgrade stable' "$scenario_log"
has '--set-string imagePullSecrets[0].name=dockerconfigjson-github-com' "$scenario_log"
has '--take-ownership' "$scenario_log"
has 'annotate serviceaccount/wtf-happend-bot helm.sh/resource-policy=keep --overwrite' "$scenario_log"
sa_protect_line="$(grep -nF 'annotate serviceaccount/wtf-happend-bot helm.sh/resource-policy=keep --overwrite' "$scenario_log" | head -1 | cut -d: -f1)"
upgrade_line="$(grep -nF 'helm upgrade stable' "$scenario_log" | head -1 | cut -d: -f1)"
[[ -n "$sa_protect_line" && -n "$upgrade_line" && "$sa_protect_line" -lt "$upgrade_line" ]] \
  || fail 'legacy ServiceAccount was not protected before the first Helm mutation'
has 'operation preflight-workers' "$scenario_log"; has 'operation abort-release' "$scenario_log"
has 'operation release-status' "$scenario_log"
has 'helm rollback wtf-happend-bot 7' "$scenario_log"; missing 'operation authorize-release' "$scenario_log"

# Loss/failure while exercising the already-applied in-cluster controller must
# happen before Helm is allowed to patch the Recreate application Deployment.
prepare bootstrap_failure; out="${scenario_dir}/out"
if run bootstrap-failure "$out"; then fail 'bootstrap failure succeeded'; fi
has 'helm template wtf-happend-bot' "$scenario_log"; has 'kubectl bootstrap complete' "$scenario_log"
missing 'helm upgrade stable' "$scenario_log"; missing 'operation prepare-release' "$scenario_log"

# A failed or ambiguous durable abort makes rollback unsafe. It must stop
# before Helm can revive the previous image.
prepare abort_failure; out="${scenario_dir}/out"
if run abort-failure "$out"; then fail 'abort failure succeeded'; fi
has 'operation abort-release' "$scenario_log"; missing 'helm rollback' "$scenario_log"
has 'refusing Kubernetes/Helm rollback' "$out"

prepare consecutive_after_controller_recovery; out="${scenario_dir}/out"
if run runtime-drift-preflight-failure "$out"; then fail 'drifted preflight failure succeeded'; fi
has 'Detected controller-restored runtime beneath Helm revision 8' "$out"
has 'Helm/runtime state aligned at new deployed revision 9' "$out"
before 'helm rollback wtf-happend-bot 7' 'helm upgrade stable' "$scenario_log"
has 'helm rollback wtf-happend-bot 9' "$scenario_log"
missing 'helm rollback wtf-happend-bot 8' "$scenario_log"

prepare authorize_failure; out="${scenario_dir}/out"
if run authorize-failure "$out"; then fail 'authorize failure succeeded'; fi
has 'operation preflight-workers' "$scenario_log"; has 'operation preflight-ingress' "$scenario_log"
has 'operation authorize-release' "$scenario_log"; missing 'helm rollback' "$scenario_log"
has 'refusing to roll back' "$out"

prepare success; out="${scenario_dir}/out"; run success "$out"
has 'kubectl rollout' "$scenario_log"; has 'operation prepare-release' "$scenario_log"
has 'operation authorize-release' "$scenario_log"; has 'operation release-status' "$scenario_log"
missing 'helm rollback' "$scenario_log"

# `release-status` exits nonzero until phase=active. This proves CI cannot
# treat ingress-retired as completion while the gate is still closed.
prepare waits_for_active; out="${scenario_dir}/out"; RELEASE_WAIT_ATTEMPTS=2 run not-active-then-active "$out"
[[ "$(grep -Fc 'operation release-status' "$scenario_log")" == 2 ]] || fail 'CI did not wait for exact active status'
missing 'helm rollback' "$scenario_log"

echo 'deploy-release recovery tests passed'
