#!/usr/bin/env bash

set -euo pipefail

if (($# < 2 || $# > 5)); then
    echo 'Usage: run-release-operation.sh <operation> <image-tag> [release] [namespace] [chart-dir]' >&2
    exit 64
fi

operation="$1"
image_tag="$2"
release_name="${3:-wtf-happend-bot}"
namespace="${4:-wtfhappendbot}"
script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
chart_dir="${5:-${script_dir}/../helm}"
image_digest="${RELEASE_IMAGE_DIGEST:-}"

case "$operation" in
    prepare-release | abort-release | preflight-workers | preflight-ingress | migrate-legacy-commands | authorize-release | release-status | confirm-ingress-retired | reconcile-release | cutover | install-dream-schedule) ;;
    *)
        echo "Unsupported release operation: ${operation}" >&2
        exit 64
        ;;
esac

manifest="$(mktemp)"
trap 'rm -f "$manifest"' EXIT

helm_args=(
    template "$release_name" "$chart_dir"
    --namespace "$namespace"
    --show-only templates/release-operation-job.yaml
    --set releaseOperation.enabled=true
    --set-string releaseOperation.id="$image_tag"
    --set-string releaseOperation.operation="$operation"
    --set-string image.tag="$image_tag"
)
if [[ -n "$image_digest" ]]; then
    if [[ ! "$image_digest" =~ ^sha256:[a-f0-9]{64}$ ]]; then
        echo 'RELEASE_IMAGE_DIGEST must be a canonical sha256 OCI digest.' >&2
        exit 64
    fi
    helm_args+=(--set-string image.digest="$image_digest")
fi

helm "${helm_args[@]}" > "$manifest"

job_count="$(grep -Ec '^kind: Job$' "$manifest")"
job_name="$(awk '/^kind: Job$/ { in_job=1; next } in_job && /^metadata:$/ { in_metadata=1; next } in_metadata && /^  name: / { print $2; exit }' "$manifest")"
if [[ "$job_count" != "1" || -z "$job_name" ]]; then
    echo "Release operation ${operation} did not render exactly one named Job." >&2
    exit 1
fi

kubectl --namespace "$namespace" delete job "$job_name" --ignore-not-found --wait=true
kubectl --namespace "$namespace" apply -f "$manifest"
if ! kubectl --namespace "$namespace" wait \
    --for=condition=complete \
    --timeout=6m \
    "job/${job_name}"; then
    kubectl --namespace "$namespace" logs "job/${job_name}" --all-containers=true || true
    kubectl --namespace "$namespace" describe job "$job_name" || true
    exit 1
fi
job_logs="$(kubectl --namespace "$namespace" logs "job/${job_name}" --all-containers=true)"
printf '%s\n' "$job_logs"

if [[ "$operation" == release-status ]]; then
    status="$(printf '%s' "$job_logs" | sed -n 's/.*"status"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | tail -n1)"
    case "$status" in
        active) exit 0 ;;
        aborted|missing|stale|retired|prepared|authorized|ingress-retired) exit 3 ;;
        *)
            echo 'release-status Job returned no recognized durable release state.' >&2
            exit 1
            ;;
    esac
fi
