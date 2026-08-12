#!/usr/bin/env bash

set -Eeuo pipefail

image_digest="${1:-${IMAGE_DIGEST:-}}"
if [[ -z "$image_digest" ]]; then
    echo 'Usage: deploy-release.sh <sha256:image-digest>' >&2
    exit 64
fi
if [[ ! "$image_digest" =~ ^sha256:[a-f0-9]{64}$ ]]; then
    echo 'The release image must be pinned by a canonical sha256 OCI digest.' >&2
    exit 64
fi

: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${TELEGRAM_BOT_TOKEN:?TELEGRAM_BOT_TOKEN is required}"
: "${DEEPSEEK_API_KEY:?DEEPSEEK_API_KEY is required}"

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
chart_dir="${script_dir}/../helm"
release_name="${HELM_RELEASE:-wtf-happend-bot}"
namespace="${DEPLOY_NAMESPACE:-wtfhappendbot}"
host_release_id="${image_digest#sha256:}"
deployment_name="${DEPLOYMENT_NAME:-wtf-happend-bot}"
helm_bin="${HELM_BIN:-helm}"
kubectl_bin="${KUBECTL_BIN:-kubectl}"
jq_bin="${JQ_BIN:-jq}"
operation_runner="${RELEASE_OPERATION_RUNNER:-${script_dir}/run-release-operation.sh}"
recovery_attempts="${RELEASE_RECOVERY_ATTEMPTS:-3}"
recovery_verify_attempts="${RELEASE_RECOVERY_VERIFY_ATTEMPTS:-3}"
schedule_reconcile_attempts="${DREAM_SCHEDULE_RECONCILE_ATTEMPTS:-3}"
retry_delay_seconds="${RELEASE_RETRY_DELAY_SECONDS:-10}"
auto_authorize_delay_seconds="${RELEASE_AUTO_AUTHORIZE_DELAY_SECONDS:-2700}"
image_pull_secret="${IMAGE_PULL_SECRET:-dockerconfigjson-github-com}"
agent_task_queue="${SPACE_AGENT_TASK_QUEUE_BASE:-space-agent-v1}-${host_release_id}"
dream_task_queue="${SPACE_DREAM_TASK_QUEUE_BASE:-space-dream-v1}-${host_release_id}"

phase='initializing'
helm_upgrade_completed=false
cutover_boundary_started=false
previous_release_exists=false
previous_revision=''
previous_release_status=''
previous_deployment_snapshot=''
previous_release_id=''

require_bounded_integer() {
    local name="$1"
    local value="$2"
    local minimum="$3"
    local maximum="$4"

    if [[ ! "$value" =~ ^[0-9]+$ ]] || ((value < minimum || value > maximum)); then
        echo "${name} must be an integer between ${minimum} and ${maximum}." >&2
        exit 64
    fi
}

require_command() {
    local command_name="$1"

    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "Required release command is unavailable: ${command_name}" >&2
        exit 69
    fi
}

is_first_install_failure() {
    [[ "$previous_release_exists" == false && "$phase" != 'capture-previous-release' ]]
}

runtime_deployments() {
    "$kubectl_bin" --namespace "$namespace" get deployments \
        --selector="app.kubernetes.io/instance=${release_name},service=bot" \
        --output=json
}

deployment_snapshot() {
    runtime_deployments | "$jq_bin" -ceS '
        [.items[] | {
            name: .metadata.name,
            replicas: (.spec.replicas // 1),
            releaseId: (.spec.template.metadata.annotations["wtf-happend-bot/release-id"] // ""),
            containers: ([.spec.template.spec.containers[] | {
                name: .name,
                image: .image
            }] | sort_by(.name))
        }] | sort_by(.name)
    '
}

release_inventory() {
    "$helm_bin" list --namespace "$namespace" --all --output json
}

helm_release_exists() {
    local inventory
    inventory="$(release_inventory)"
    [[ "$(matching_release_count "$inventory")" == '1' ]]
}

matching_release_count() {
    local inventory="$1"

    "$jq_bin" -er --arg release "$release_name" \
        '[.[] | select(.name == $release)] | length' <<< "$inventory"
}

capture_previous_release() {
    phase='capture-previous-release'

    local inventory
    local match_count
    local status_snapshot
    local listed_revision

    inventory="$(release_inventory)"
    match_count="$(matching_release_count "$inventory")"
    if [[ "$match_count" == '0' ]]; then
        local unmanaged_deployments
        unmanaged_deployments="$(deployment_snapshot)"
        if [[ "$unmanaged_deployments" != '[]' ]]; then
            echo "Runtime Deployments exist without Helm release ${release_name}; refusing an unsafe install." >&2
            return 1
        fi

        echo "No previous Helm release named ${release_name}; pre-cutover recovery will restore the absent state."
        return 0
    fi
    if [[ "$match_count" != '1' ]]; then
        echo "Expected at most one Helm release named ${release_name}, found ${match_count}." >&2
        return 1
    fi

    previous_release_exists=true
    listed_revision="$(
        "$jq_bin" -er --arg release "$release_name" \
            '[.[] | select(.name == $release)][0].revision | tonumber | tostring' <<< "$inventory"
    )"
    status_snapshot="$(
        "$helm_bin" status "$release_name" \
            --namespace "$namespace" \
            --revision "$listed_revision" \
            --output json
    )"
    previous_revision="$("$jq_bin" -er '.version | tonumber | tostring' <<< "$status_snapshot")"
    previous_release_status="$("$jq_bin" -er '.info.status' <<< "$status_snapshot")"

    if [[ "$previous_revision" != "$listed_revision" ]]; then
        echo "Helm release revision changed while its recovery snapshot was being captured." >&2
        return 1
    fi
    previous_deployment_snapshot="$(deployment_snapshot)"
    if [[ "$previous_deployment_snapshot" == '[]' ]]; then
        echo "The deployed release has no runtime Deployment snapshot." >&2
        return 1
    fi
    previous_release_id="$(
        "$jq_bin" -er '
            [.[] | .releaseId | select(test("^[a-f0-9]{64}$"))] | unique
            | if length == 1 then .[0] else "" end
        ' <<< "$previous_deployment_snapshot"
    )"

    if [[ "$previous_release_status" != 'deployed' ]]; then
        local interrupted_match
        interrupted_match="$(find_runtime_matching_helm_revision "$previous_revision")"
        if [[ -z "$interrupted_match" ]]; then
            echo "Helm release ${release_name} is ${previous_release_status}, and its current runtime has not yet converged to a retained revision." >&2
            return 1
        fi
        echo "Recovering interrupted Helm ${previous_release_status} state to runtime-matching revision ${interrupted_match}." >&2
        heal_helm_to_runtime "$interrupted_match" || return 1
    fi

    if ! runtime_matches_helm_revision "$previous_revision"; then
        local recovered_revision
        recovered_revision="$(find_runtime_matching_helm_revision "$previous_revision")"
        if [[ -z "$recovered_revision" ]]; then
            echo 'Helm/runtime drift exists, but no retained Helm revision matches the running release.' >&2
            return 1
        fi
        echo "Detected controller-restored runtime beneath Helm revision ${previous_revision}; recovery will target matching revision ${recovered_revision}." >&2
        heal_helm_to_runtime "$recovered_revision" || return 1
    fi

    echo "Captured Helm release ${release_name} revision ${previous_revision} (${previous_release_status})."
}

heal_helm_to_runtime() {
    local matching_revision="$1"
    local healed_status
    "$helm_bin" rollback "$release_name" "$matching_revision" \
        --namespace "$namespace" --cleanup-on-fail --wait --timeout=10m
    healed_status="$("$helm_bin" status "$release_name" --namespace "$namespace" --output json)" || return 1
    previous_revision="$("$jq_bin" -er '.version | tonumber | tostring' <<< "$healed_status")" || return 1
    previous_release_status="$("$jq_bin" -er '.info.status' <<< "$healed_status")" || return 1
    if [[ "$previous_release_status" != deployed ]] || ! runtime_matches_helm_revision "$previous_revision"; then
        echo 'Helm drift recovery did not create a deployed revision matching the running release.' >&2
        return 1
    fi
    echo "Helm/runtime state aligned at new deployed revision ${previous_revision}." >&2
}

runtime_matches_helm_revision() {
    local revision="$1"
    local values expected_image expected_release_id
    values="$("$helm_bin" get values "$release_name" --namespace "$namespace" \
        --revision "$revision" --all --output json)" || return 1
    expected_image="$("$jq_bin" -er '
        (.image.repository // "") as $repository
        | (.image.digest // "") as $digest
        | (.image.tag // "") as $tag
        | if $repository == "" then error("missing image repository")
          elif $digest != "" then $repository + "@" + $digest
          elif $tag != "" then $repository + ":" + $tag
          else error("missing image identity") end
    ' <<< "$values")" || return 1
    expected_release_id="$("$jq_bin" -r '.release.id // ""' <<< "$values")" || return 1

    "$jq_bin" -e --arg image "$expected_image" --arg releaseId "$expected_release_id" '
        length > 0 and all(.[];
            (all(.containers[]; .image == $image))
            and (.releaseId == "" or $releaseId == "" or .releaseId == $releaseId)
        )
    ' <<< "$previous_deployment_snapshot" >/dev/null
}

find_runtime_matching_helm_revision() {
    local current_revision="$1"
    local history revision
    history="$("$helm_bin" history "$release_name" --namespace "$namespace" --max 50 --output json)" || return 1
    while IFS= read -r revision; do
        [[ "$revision" != "$current_revision" ]] || continue
        if runtime_matches_helm_revision "$revision"; then
            printf '%s' "$revision"
            return 0
        fi
    done < <("$jq_bin" -r 'sort_by(.revision | tonumber) | reverse[] | .revision | tostring' <<< "$history")

    return 1
}

sleep_before_retry() {
    if ((retry_delay_seconds > 0)); then
        sleep "$retry_delay_seconds"
    fi
}

verify_previous_release_restored() {
    local status_snapshot
    local restored_status
    local restored_snapshot
    local deployment

    status_snapshot="$(
        "$helm_bin" status "$release_name" \
            --namespace "$namespace" \
            --output json
    )" || return 1
    restored_status="$("$jq_bin" -er '.info.status' <<< "$status_snapshot")" || return 1
    if [[ "$restored_status" != 'deployed' ]]; then
        echo "Restored Helm release is ${restored_status}, not deployed." >&2
        return 1
    fi

    restored_snapshot="$(deployment_snapshot)" || return 1
    if [[ "$restored_snapshot" != "$previous_deployment_snapshot" ]]; then
        echo 'Restored runtime Deployments do not match the pre-upgrade image/replica snapshot.' >&2
        echo "Expected:" >&2
        echo "$previous_deployment_snapshot" >&2
        echo "Actual:" >&2
        echo "$restored_snapshot" >&2
        return 1
    fi

    while IFS= read -r deployment; do
        "$kubectl_bin" --namespace "$namespace" rollout status \
            "deployment/${deployment}" \
            --timeout=5m || return 1
    done < <("$jq_bin" -r '.[].name' <<< "$previous_deployment_snapshot")

    "$kubectl_bin" --namespace "$namespace" wait \
        --for=condition=Ready \
        --timeout=5m \
        --selector="app.kubernetes.io/instance=${release_name},service=bot" \
        pod || return 1

    if [[ -n "$previous_release_id" ]]; then
        if ! run_release_operation_for release-status "$previous_release_id"; then
            echo "Restored release ${previous_release_id} is not the active durable DB release." >&2
            return 1
        fi
    fi

    while IFS= read -r deployment; do
        local ingress_logs
        if ! ingress_logs="$("$kubectl_bin" --namespace "$namespace" logs \
            "deployment/${deployment}" --container=bot --since=10m 2>&1)" \
            || ! grep -Fq 'Starting durable bot polling with offset' <<< "$ingress_logs"; then
            echo "Restored Deployment ${deployment} is Ready but has not proved Telegram polling passed its release gate." >&2
            return 1
        fi
    done < <("$jq_bin" -r '.[].name' <<< "$previous_deployment_snapshot")
}

restore_previous_release() {
    local attempt
    local rollback_completed=false

    for ((attempt = 1; attempt <= recovery_attempts; attempt++)); do
        echo "Restoring Helm release ${release_name} to revision ${previous_revision} (attempt ${attempt}/${recovery_attempts})." >&2
        if "$helm_bin" rollback "$release_name" "$previous_revision" \
            --namespace "$namespace" \
            --cleanup-on-fail \
            --wait \
            --timeout=10m; then
            rollback_completed=true
            break
        fi
        sleep_before_retry
    done

    if [[ "$rollback_completed" != true ]]; then
        echo "Helm rollback did not complete after ${recovery_attempts} attempts." >&2
        return 1
    fi

    for ((attempt = 1; attempt <= recovery_verify_attempts; attempt++)); do
        echo "Verifying restored release and worker containers (attempt ${attempt}/${recovery_verify_attempts})." >&2
        if verify_previous_release_restored; then
            echo "Previous release revision ${previous_revision} restored; all prior runtime images are Ready." >&2
            return 0
        fi
        sleep_before_retry
    done

    echo "Restored release failed verification after ${recovery_verify_attempts} attempts." >&2
    return 1
}

verify_absent_release_restored() {
    local inventory
    local match_count
    local deployments

    inventory="$(release_inventory)" || return 1
    match_count="$(matching_release_count "$inventory")" || return 1
    if [[ "$match_count" != '0' ]]; then
        return 1
    fi

    deployments="$(deployment_snapshot)" || return 1
    [[ "$deployments" == '[]' ]]
}

restore_absent_release() {
    local attempt

    for ((attempt = 1; attempt <= recovery_attempts; attempt++)); do
        echo "Removing first-install release ${release_name} (attempt ${attempt}/${recovery_attempts})." >&2
        if "$helm_bin" uninstall "$release_name" \
            --namespace "$namespace" \
            --wait \
            --timeout=10m \
            && verify_absent_release_restored; then
            echo 'Pre-cutover failure restored the previously absent release state.' >&2
            return 0
        fi
        sleep_before_retry
    done

    echo "First-install cleanup did not complete after ${recovery_attempts} attempts." >&2
    return 1
}

handle_failure() {
    local original_exit_code="$1"
    local failure_line="$2"
    local recovery_exit_code=0

    trap - ERR INT TERM
    set +e

    echo "Release ${host_release_id} failed in phase ${phase} at line ${failure_line} (exit ${original_exit_code})." >&2
    if [[ "$helm_upgrade_completed" == true && "$cutover_boundary_started" == false ]]; then
        # It is safe only before authorization. Helm rollback is forbidden
        # unless DB compensation succeeds and an independent status read proves
        # the candidate is no longer prepared or active.
        if ! abort_candidate_release; then
            echo 'CRITICAL: durable candidate abort could not be verified; refusing Kubernetes/Helm rollback.' >&2
            exit "$original_exit_code"
        fi
        if [[ "$previous_release_exists" == true ]]; then
            restore_previous_release
            recovery_exit_code=$?
        else
            restore_absent_release
            recovery_exit_code=$?
        fi

        if ((recovery_exit_code != 0)); then
            echo "CRITICAL: automatic pre-cutover recovery failed with exit ${recovery_exit_code}; original release exit remains ${original_exit_code}." >&2
        fi
    elif [[ "$cutover_boundary_started" == true ]]; then
        echo 'The cutover boundary has started; refusing to roll back to an image whose Temporal workflows may have been terminated. Applied Kubernetes controllers keep reconciling.' >&2
    elif [[ "$phase" == 'workers-only-helm-upgrade' ]]; then
        echo 'Helm --atomic owns rollback for this failed upgrade; no external cutover was attempted.' >&2
    fi

    exit "$original_exit_code"
}

run_release_operation() {
    local operation="$1"

    run_release_operation_for "$operation" "$host_release_id"
}

run_release_operation_for() {
    local operation="$1"
    local release_id="$2"

    RELEASE_IMAGE_DIGEST="$image_digest" bash "$operation_runner" \
        "$operation" \
        "$release_id" \
        "$release_name" \
        "$namespace" \
        "$chart_dir"
}

release_status_from_output() {
    sed -n 's/.*"status"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | tail -n1
}

abort_candidate_release() {
    local abort_output abort_status verify_output verify_status verify_code

    abort_output="$(run_release_operation abort-release)" || return 1
    printf '%s\n' "$abort_output"
    abort_status="$(release_status_from_output <<< "$abort_output")"
    if [[ "$abort_status" != aborted && "$abort_status" != missing ]]; then
        echo "abort-release returned unsafe state ${abort_status:-unknown}." >&2
        return 1
    fi

    if verify_output="$(run_release_operation release-status)"; then
        verify_code=0
    else
        verify_code=$?
    fi
    printf '%s\n' "$verify_output"
    verify_status="$(release_status_from_output <<< "$verify_output")"
    if ((verify_code != 3)) \
        || [[ "$verify_status" != aborted && "$verify_status" != missing && "$verify_status" != stale && "$verify_status" != retired ]]; then
        echo "Candidate abort verification observed ${verify_status:-unknown} (exit ${verify_code})." >&2
        return 1
    fi
}

render_bootstrap_resource() {
    local template="$1"
    local destination="$2"
    local auto_authorize_after_epoch="$3"

    local previous_bot_image
    previous_bot_image="$("$jq_bin" -er '[.[].containers[] | select(.name == "bot") | .image] | unique | if length == 1 then .[0] else "" end' <<< "$previous_deployment_snapshot")"
    "$helm_bin" template "$release_name" "$chart_dir" \
        --namespace "$namespace" \
        --show-only "$template" \
        --set releaseReconciler.enabled=true \
        --set releaseControllerBootstrap.enabled=true \
        --set-string releaseControllerBootstrap.id="$host_release_id" \
        --set-string releaseReconciler.autoAuthorizeAfterEpoch="$auto_authorize_after_epoch" \
        --set-string releaseReconciler.previousBotImage="$previous_bot_image" \
        --set-string releaseReconciler.previousHelmRevision="$previous_revision" \
        --set-string release.id="$host_release_id" \
        --set-string env.HOST_RELEASE_ID="$host_release_id" \
        --set-string env.SPACE_AGENT_TASK_QUEUE="$agent_task_queue" \
        --set-string env.SPACE_DREAM_TASK_QUEUE="$dream_task_queue" \
        --set-string imagePullSecrets[0].name="$image_pull_secret" \
        --set-string image.digest="$image_digest" > "$destination"
}

bootstrap_release_controller() {
    local auto_authorize_after_epoch="$1"
    local temporary_dir rbac_manifest cron_manifest job_manifest cron_name job_name
    temporary_dir="$(mktemp -d)"
    rbac_manifest="${temporary_dir}/rbac.yaml"
    cron_manifest="${temporary_dir}/cron.yaml"
    job_manifest="${temporary_dir}/job.yaml"

    # Bootstrap deliberately consumes the current release's stable DB/Telegram
    # references. Updating these objects does not belong before the controller
    # has proved it can recover independently of the CI runner.
    "$kubectl_bin" --namespace "$namespace" get "configmap/${deployment_name}-env" >/dev/null
    "$kubectl_bin" --namespace "$namespace" get "secret/${deployment_name}-secret" >/dev/null

    # The legacy ServiceAccount was created by a pre-install hook and is not in
    # the rollback manifest. Protect the live object before *any* candidate
    # Helm mutation so even an early atomic failure cannot delete the identity
    # required by the restored Deployment. A true first install may not have
    # the account yet; the candidate chart creates it with the same policy.
    if "$kubectl_bin" --namespace "$namespace" get \
        "serviceaccount/${deployment_name}" >/dev/null 2>&1; then
        "$kubectl_bin" --namespace "$namespace" annotate \
            "serviceaccount/${deployment_name}" \
            helm.sh/resource-policy=keep --overwrite
        [[ "$("$kubectl_bin" --namespace "$namespace" get \
            "serviceaccount/${deployment_name}" \
            --output='jsonpath={.metadata.annotations.helm\.sh/resource-policy}')" == keep ]]
    fi

    render_bootstrap_resource templates/release-controller-rbac.yaml "$rbac_manifest" "$auto_authorize_after_epoch"
    "$kubectl_bin" --namespace "$namespace" apply -f "$rbac_manifest"

    render_bootstrap_resource templates/release-reconciler.yaml "$cron_manifest" "$auto_authorize_after_epoch"
    "$kubectl_bin" --namespace "$namespace" apply -f "$cron_manifest"
    cron_name="$(awk '/^kind: CronJob$/ { in_cron=1; next } in_cron && /^metadata:$/ { in_metadata=1; next } in_metadata && /^  name: / { print $2; exit }' "$cron_manifest")"
    if [[ -z "$cron_name" ]]; then
        echo 'Release controller bootstrap did not render one named CronJob.' >&2
        return 1
    fi
    "$kubectl_bin" --namespace "$namespace" get "cronjob/${cron_name}" >/dev/null

    render_bootstrap_resource templates/release-controller-bootstrap-job.yaml "$job_manifest" "$auto_authorize_after_epoch"
    job_name="$(awk '/^kind: Job$/ { in_job=1; next } in_job && /^metadata:$/ { in_metadata=1; next } in_metadata && /^  name: / { print $2; exit }' "$job_manifest")"
    if [[ -z "$job_name" ]]; then
        echo 'Release controller bootstrap did not render one named Job.' >&2
        return 1
    fi
    "$kubectl_bin" --namespace "$namespace" delete job "$job_name" --ignore-not-found --wait=true
    "$kubectl_bin" --namespace "$namespace" apply -f "$job_manifest"
    if ! "$kubectl_bin" --namespace "$namespace" wait \
        --for=condition=complete --timeout=12m "job/${job_name}"; then
        "$kubectl_bin" --namespace "$namespace" logs "job/${job_name}" --all-containers=true
        "$kubectl_bin" --namespace "$namespace" describe job "$job_name"
        return 1
    fi
    "$kubectl_bin" --namespace "$namespace" logs "job/${job_name}" --all-containers=true
    rm -rf "$temporary_dir"
}

record_helm_commit() {
    local marker_name marker_manifest
    marker_name="$(printf '%s-helm-committed-%s' "${deployment_name:0:34}" "${host_release_id:0:12}")"
    marker_manifest="$(mktemp)"
    "$kubectl_bin" --namespace "$namespace" create configmap "$marker_name" \
        --from-literal="release-id=${host_release_id}" \
        --dry-run=client --output=yaml > "$marker_manifest"
    "$kubectl_bin" --namespace "$namespace" apply -f "$marker_manifest"
    rm -f "$marker_manifest"
    [[ "$("$kubectl_bin" --namespace "$namespace" get "configmap/${marker_name}" \
        --output='jsonpath={.data.release-id}')" == "$host_release_id" ]]
}

wait_for_active_release() {
    local attempt
    local operation_exit_code=1

    for ((attempt = 1; attempt <= schedule_reconcile_attempts; attempt++)); do
        echo "Waiting for autonomous release activation (attempt ${attempt}/${schedule_reconcile_attempts})."
        if run_release_operation release-status; then
            return 0
        else
            operation_exit_code=$?
        fi

        if ((attempt < schedule_reconcile_attempts)); then
            echo "Release is not active yet (exit ${operation_exit_code}); the in-cluster reconciler remains active." >&2
            sleep_before_retry
        fi
    done

    echo "Release activation wait exhausted ${schedule_reconcile_attempts} CI attempts (last exit ${operation_exit_code}); the in-cluster reconciler remains active." >&2
    return "$operation_exit_code"
}

main() {
    require_bounded_integer RELEASE_RECOVERY_ATTEMPTS "$recovery_attempts" 1 5
    require_bounded_integer RELEASE_RECOVERY_VERIFY_ATTEMPTS "$recovery_verify_attempts" 1 5
    require_bounded_integer DREAM_SCHEDULE_RECONCILE_ATTEMPTS "$schedule_reconcile_attempts" 1 5
    require_bounded_integer RELEASE_RETRY_DELAY_SECONDS "$retry_delay_seconds" 0 300
    require_bounded_integer RELEASE_AUTO_AUTHORIZE_DELAY_SECONDS "$auto_authorize_delay_seconds" 1800 7200
    require_command "$helm_bin"
    require_command "$kubectl_bin"
    require_command "$jq_bin"
    if [[ ! -f "$operation_runner" ]]; then
        echo "Release operation runner is unavailable: ${operation_runner}" >&2
        exit 69
    fi

    capture_previous_release

    trap 'handle_failure $? $LINENO' ERR
    trap 'handle_failure 130 $LINENO' INT
    trap 'handle_failure 143 $LINENO' TERM

    phase='gated-stable-helm-upgrade'
    local upgrade_exit_code=0
    local auto_authorize_after_epoch=$(( $(date +%s) + auto_authorize_delay_seconds ))
    phase='release-controller-bootstrap'
    bootstrap_release_controller "$auto_authorize_after_epoch"

    # Only the separately applied and successfully exercised controller makes
    # it safe for Helm to replace the combined bot/worker Deployment.
    phase='gated-stable-helm-upgrade'
    "$helm_bin" upgrade "$release_name" "$chart_dir" \
            --install \
            --atomic \
            --take-ownership \
            --create-namespace \
            --namespace "$namespace" \
            --wait \
            --timeout=10m \
            --set ingress.gated=true \
            --set-string release.id="$host_release_id" \
            --set releaseReconciler.enabled=true \
            --set-string releaseReconciler.autoAuthorizeAfterEpoch="$auto_authorize_after_epoch" \
            --set-string releaseReconciler.previousBotImage="$("$jq_bin" -er '[.[].containers[] | select(.name == "bot") | .image] | unique | if length == 1 then .[0] else "" end' <<< "$previous_deployment_snapshot")" \
            --set-string releaseReconciler.previousHelmRevision="$previous_revision" \
            --set-string env.HOST_RELEASE_ID="$host_release_id" \
            --set-string env.SPACE_AGENT_TASK_QUEUE="$agent_task_queue" \
            --set-string env.SPACE_DREAM_TASK_QUEUE="$dream_task_queue" \
            --set-string imagePullSecrets[0].name="$image_pull_secret" \
            --set-string image.digest="$image_digest" \
            --set-string envSecrets.DB_PASSWORD="$DB_PASSWORD" \
            --set-string envSecrets.TELEGRAM_BOT_TOKEN="$TELEGRAM_BOT_TOKEN" \
            --set-string envSecrets.DEEPSEEK_API_KEY="$DEEPSEEK_API_KEY" \
        || upgrade_exit_code=$?
    if ((upgrade_exit_code != 0)); then
        if is_first_install_failure; then
            restore_absent_release || true
        fi
        return "$upgrade_exit_code"
    fi
    helm_upgrade_completed=true

    phase='helm-commit-marker'
    record_helm_commit

    phase='prepare-release'
    run_release_operation prepare-release

    phase='gated-stable-rollout'
    "$kubectl_bin" --namespace "$namespace" rollout status \
        "deployment/${deployment_name}" \
        --timeout=5m

    phase='worker-preflight'
    run_release_operation preflight-workers

    phase='ingress-dependency-preflight'
    run_release_operation preflight-ingress

    # Authorizing durable release state is the irreversible boundary. The
    # in-cluster reconciler is already running and converges without CI.
    phase='release-authorization'
    cutover_boundary_started=true
    run_release_operation authorize-release

    phase='forward-cutover-confirmation'
    run_release_operation confirm-ingress-retired

    phase='forward-schedule-reconcile'
    run_release_operation reconcile-release

    phase='release-activation-wait'
    wait_for_active_release

    phase='complete'
    trap - ERR INT TERM
    echo "Release ${host_release_id} completed with worker/ingress preflights and durable forward reconciliation of cutover, Telegram ingress, and the Dream schedule."
}

main "$@"
