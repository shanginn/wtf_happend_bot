#!/usr/bin/env bash
# The release controller is deliberately boring. It owns the small Kubernetes
# permission set, while the app process owns Temporal and the durable DB state.
set -Eeuo pipefail

: "${HOST_RELEASE_ID:?HOST_RELEASE_ID is required}"
: "${RELEASE_AUTO_AUTHORIZE_AFTER:?RELEASE_AUTO_AUTHORIZE_AFTER is required}"
: "${RELEASE_CONTROLLER_CRONJOB:?RELEASE_CONTROLLER_CRONJOB is required}"
: "${RELEASE_HELM_COMMIT_MARKER:?RELEASE_HELM_COMMIT_MARKER is required}"
: "${RELEASE_DEPLOYMENT:?RELEASE_DEPLOYMENT is required}"
: "${RELEASE_NAMESPACE:?RELEASE_NAMESPACE is required}"
: "${RELEASE_PREVIOUS_BOT_IMAGE:?RELEASE_PREVIOUS_BOT_IMAGE is required}"
: "${RELEASE_PREVIOUS_HELM_REVISION:?RELEASE_PREVIOUS_HELM_REVISION is required}"
: "${RELEASE_HELM_NAME:?RELEASE_HELM_NAME is required}"
helm_bin="${HELM_BIN:-helm}"

admin=(php src/space-v2-admin.php)
release=(--release-id="$HOST_RELEASE_ID")

status_for() {
    local release_id="$1"
    set +e
    local output code
    output="$(HOST_RELEASE_ID="$release_id" "${admin[@]}" release-status "--release-id=${release_id}" 2>&1)"
    code=$?
    set -e
    printf '%s\n' "$output" >&2
    if ((code != 0 && code != 3)); then
        return "$code"
    fi

    local parsed
    parsed="$(printf '%s' "$output" | sed -n 's/.*"status"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | tail -n1)"
    if [[ -z "$parsed" ]]; then
        echo "release-status for ${release_id} returned no machine-readable state." >&2
        return 1
    fi
    printf '%s' "$parsed"
}

status() {
    status_for "$HOST_RELEASE_ID"
}

candidate_is_deployed() {
    local actual
    actual="$(kubectl --namespace "$RELEASE_NAMESPACE" get "deployment/${RELEASE_DEPLOYMENT}" \
        --output='jsonpath={.spec.template.metadata.annotations.wtf-happend-bot/release-id}' 2>/dev/null || true)"
    [[ "$actual" = "$HOST_RELEASE_ID" ]]
}

helm_commit_exists() {
    local marker_release_id
    marker_release_id="$(kubectl --namespace "$RELEASE_NAMESPACE" get \
        "configmap/${RELEASE_HELM_COMMIT_MARKER}" \
        --output='jsonpath={.data.release-id}' 2>/dev/null || true)"
    [[ "$marker_release_id" == "$HOST_RELEASE_ID" ]]
}

deployed_release_id() {
    kubectl --namespace "$RELEASE_NAMESPACE" get "deployment/${RELEASE_DEPLOYMENT}" \
        --output='jsonpath={.spec.template.metadata.annotations.wtf-happend-bot/release-id}' 2>/dev/null || true
}

deployed_bot_image() {
    kubectl --namespace "$RELEASE_NAMESPACE" get "deployment/${RELEASE_DEPLOYMENT}" \
        --output='jsonpath={.spec.template.spec.containers[?(@.name=="bot")].image}' 2>/dev/null || true
}

verify_restored_ingress() {
    local restored_id restored_phase restored_image helm_status attempt logs
    helm_status="$("$helm_bin" status "$RELEASE_HELM_NAME" \
        --namespace "$RELEASE_NAMESPACE" --output json)" || return 1
    if ! grep -Eq '"status"[[:space:]]*:[[:space:]]*"deployed"' <<< "$helm_status"; then
        echo 'Full Helm rollback did not end in deployed state.' >&2
        return 1
    fi
    restored_id="$(deployed_release_id)"
    restored_image="$(kubectl --namespace "$RELEASE_NAMESPACE" get "deployment/${RELEASE_DEPLOYMENT}" \
        --output='jsonpath={.spec.template.spec.containers[?(@.name=="bot")].image}')"
    if [[ "$restored_id" == "$HOST_RELEASE_ID" || "$restored_image" != "$RELEASE_PREVIOUS_BOT_IMAGE" ]]; then
        echo 'Kubernetes rollback did not restore the exact previous bot image/template identity.' >&2
        return 1
    fi
    if [[ "$restored_id" =~ ^[a-f0-9]{64}$ ]]; then
        restored_phase="$(status_for "$restored_id")" || return 1
        [[ "$restored_phase" == active ]] || {
            echo "Restored release ${restored_id} is ${restored_phase}, not active." >&2
            return 1
        }
    fi
    for ((attempt = 1; attempt <= 12; attempt++)); do
        if logs="$(kubectl --namespace "$RELEASE_NAMESPACE" logs \
            "deployment/${RELEASE_DEPLOYMENT}" --container=bot --since=10m 2>&1)" \
            && grep -Fq 'Starting durable bot polling with offset' <<< "$logs"; then
            return 0
        fi
        sleep 5
    done
    echo 'Restored bot never proved gate-aware Telegram polling.' >&2
    return 1
}

abort_and_restore_previous_runtime() {
    local abort_output abort_status candidate_phase
    abort_output="$("${admin[@]}" abort-release "${release[@]}")" || return 1
    printf '%s\n' "$abort_output" >&2
    abort_status="$(printf '%s' "$abort_output" | sed -n 's/.*"status"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | tail -n1)"
    [[ "$abort_status" == aborted || "$abort_status" == missing ]] || return 1
    candidate_phase="$(status)" || return 1
    case "$candidate_phase" in aborted|missing|stale|retired) ;; *) return 1 ;; esac

    # Restore the complete prior Helm manifest, not merely the Deployment.
    # ConfigMaps, Secrets, RBAC and auxiliary workloads must match the old
    # image before its ingress can be considered recovered.
    restore_previous_helm_until_verified
}

restore_previous_helm_until_verified() {
    "$helm_bin" rollback "$RELEASE_HELM_NAME" "$RELEASE_PREVIOUS_HELM_REVISION" \
        --namespace "$RELEASE_NAMESPACE" --cleanup-on-fail --wait --timeout=10m
    verify_restored_ingress
}

verify_release_admission() {
    local deployed_id deployed_phase
    deployed_id="$(deployed_release_id)"
    if [[ ! "$deployed_id" =~ ^[a-f0-9]{64}$ || "$deployed_id" == "$HOST_RELEASE_ID" ]]; then
        return 0
    fi

    deployed_phase="$(status_for "$deployed_id")" || return 1
    case "$deployed_phase" in
        prepared|authorized|ingress-retired)
            echo "Release ${deployed_id} is still ${deployed_phase}; its release-qualified controller must converge before another Deployment mutation." >&2
            return 1
            ;;
        active|missing|stale|retired) return 0 ;;
        *)
            echo "Deployed release ${deployed_id} has unknown durable phase ${deployed_phase}." >&2
            return 1
            ;;
    esac
}

maybe_suspend_obsolete_controller() {
    local deployed_id deployed_phase
    if (( $(date +%s) < RELEASE_AUTO_AUTHORIZE_AFTER )); then
        return 1
    fi
    deployed_id="$(deployed_release_id)"
    if [[ ! "$deployed_id" =~ ^[a-f0-9]{64}$ || "$deployed_id" == "$HOST_RELEASE_ID" ]]; then
        return 1
    fi
    deployed_phase="$(status_for "$deployed_id")" || return 1
    if [[ "$deployed_phase" != active ]]; then
        return 1
    fi

    kubectl --namespace "$RELEASE_NAMESPACE" patch \
        "cronjob/${RELEASE_CONTROLLER_CRONJOB}" \
        --type=merge --patch '{"spec":{"suspend":true}}'
    echo "Obsolete release controller ${RELEASE_CONTROLLER_CRONJOB} suspended after ${deployed_id} became active." >&2
    return 0
}

kubectl --namespace "$RELEASE_NAMESPACE" get "deployment/${RELEASE_DEPLOYMENT}" >/dev/null
phase="$(status)"
if [[ "${RELEASE_BOOTSTRAP_ONLY:-false}" == true ]]; then
    verify_release_admission
    case "$phase" in
        aborted|missing|stale|retired|prepared|authorized|ingress-retired|active)
            echo 'Release controller bootstrap verified DB and Kubernetes access.' >&2
            exit 0
            ;;
        *)
            echo "Release controller bootstrap observed unknown state ${phase}." >&2
            exit 1
            ;;
    esac
fi
case "$phase" in
    aborted)
        restore_previous_helm_until_verified
        kubectl --namespace "$RELEASE_NAMESPACE" patch \
            "cronjob/${RELEASE_CONTROLLER_CRONJOB}" \
            --type=merge --patch '{"spec":{"suspend":true}}'
        echo 'Durably aborted release cannot be prepared or authorized again; controller suspended.' >&2
        exit 0
        ;;
    active)
        # Active is a harmless schedule-healing pass. Stale CronJobs never
        # change a newer desired release.
        [[ "$phase" = active ]] && "${admin[@]}" reconcile-release "${release[@]}"
        exit 0
        ;;
    retired)
        # This release is the last known-good rollback target while a
        # successor is crossing the durable cutover boundary. Its kept Cron
        # must not roll the successor back merely because its own Deployment
        # is no longer current.
        if maybe_suspend_obsolete_controller; then
            exit 0
        fi
        echo 'Retired release controller is waiting for the desired successor to converge.' >&2
        exit 0
        ;;
    missing|stale)
        if maybe_suspend_obsolete_controller; then
            exit 0
        fi
        if (( $(date +%s) < RELEASE_AUTO_AUTHORIZE_AFTER )); then
            echo 'No prepared release yet; retaining CI recovery grace period.' >&2
            exit 0
        fi
        installed_release_id="$(deployed_release_id)"
        if ! candidate_is_deployed \
            && [[ "$installed_release_id" =~ ^[a-f0-9]{64}$ ]] \
            && [[ "$(deployed_bot_image)" != "$RELEASE_PREVIOUS_BOT_IMAGE" ]]; then
            # A different candidate is installed. This kept controller is
            # obsolete, not evidence that its own rollout was interrupted.
            echo 'Another release is installed; stale controller no-ops until it can self-suspend.' >&2
            exit 0
        fi
        if ! helm_commit_exists; then
            echo 'Helm did not durably commit this candidate; restoring the full previous revision even if Deployment mutation was incomplete.' >&2
            if candidate_is_deployed; then
                abort_and_restore_previous_runtime
            else
                abort_output="$("${admin[@]}" abort-release "${release[@]}")"
                printf '%s\n' "$abort_output" >&2
                [[ "$(printf '%s' "$abort_output" | sed -n 's/.*"status"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | tail -n1)" == aborted ]]
                restore_previous_helm_until_verified
                kubectl --namespace "$RELEASE_NAMESPACE" patch \
                    "cronjob/${RELEASE_CONTROLLER_CRONJOB}" \
                    --type=merge --patch '{"spec":{"suspend":true}}'
            fi
            exit 1
        fi
        if ! candidate_is_deployed; then
            echo 'Deployment is not this committed candidate; restoring the full previous revision.' >&2
            restore_previous_helm_until_verified
            exit 1
        fi
        if ! kubectl --namespace "$RELEASE_NAMESPACE" rollout status \
            "deployment/${RELEASE_DEPLOYMENT}" --timeout=8m; then
            echo 'Candidate rollout did not become healthy; restoring the previous runtime.' >&2
            abort_and_restore_previous_runtime
            exit 1
        fi
        if ! "${admin[@]}" preflight-workers "${release[@]}" \
            || ! "${admin[@]}" preflight-ingress; then
            echo 'Candidate failed autonomous pre-prepare preflight; restoring the last runtime and leaving detectable Helm drift.' >&2
            abort_and_restore_previous_runtime
            exit 1
        fi
        "${admin[@]}" prepare-release "${release[@]}"
        phase=prepared
        ;;
    prepared)
        if (( $(date +%s) < RELEASE_AUTO_AUTHORIZE_AFTER )); then
            echo 'Prepared release is waiting for its CI/recovery grace period.' >&2
            exit 0
        fi
        if ! helm_commit_exists; then
            echo 'Prepared release has no durable Helm commit marker; restoring the previous runtime.' >&2
            abort_and_restore_previous_runtime
            exit 1
        fi
        ;;
    authorized|ingress-retired)
        "${admin[@]}" confirm-ingress-retired "${release[@]}"
        "${admin[@]}" reconcile-release "${release[@]}"
        exit 0
        ;;
    *)
        echo "Unknown host release phase: ${phase}" >&2
        exit 1
        ;;
esac

# A runner that died after Helm has no special privileges: it repeats exactly
# the same DB, Telegram and Temporal worker checks before authorizing.
if ! "${admin[@]}" preflight-workers "${release[@]}" \
    || ! "${admin[@]}" preflight-ingress; then
    echo 'Prepared candidate failed autonomous preflight; aborting and restoring the previous runtime.' >&2
    abort_and_restore_previous_runtime
    exit 1
fi

"${admin[@]}" authorize-release "${release[@]}"
"${admin[@]}" confirm-ingress-retired "${release[@]}"
"${admin[@]}" reconcile-release "${release[@]}"
