#!/usr/bin/env bash
set -euo pipefail

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
project_dir="${script_dir}/.."
chart_dir="${project_dir}/helm"
rendered="$(mktemp)"
trap 'rm -f "$rendered"' EXIT

grep -Fxq '/sandbox-broker' "${project_dir}/.dockerignore"
if grep -ERq 'SANDBOX_|sandbox-broker' "${project_dir}/.github/workflows/build-and-deploy.yaml" "${project_dir}/docker-compose.yaml" "$chart_dir"; then
    echo 'A production path still references the disabled sandbox.' >&2; exit 1
fi

helm lint "$chart_dir"
helm template wtf-happend-bot "$chart_dir" --namespace wtfhappendbot > "$rendered"
if [[ "$(grep -Fc 'value: "http://wtf-happend-bot-searxng:8080"' "$rendered")" != 3 ]]; then
    echo 'Every application container must use the in-chart SearXNG Service address.' >&2; exit 1
fi
if grep -Fq 'dockerconfigjson-github-com' "$rendered" || grep -Fq 'helm.sh/hook' "$rendered"; then
    echo 'Default chart contains obsolete registry credentials or hooks.' >&2; exit 1
fi
[[ "$(grep -Ec '^kind: ServiceAccount$' "$rendered")" == 1 ]]
[[ "$(grep -Ec '^kind: RoleBinding$' "$rendered")" == 2 ]]
grep -Fq 'name: wtf-happend-bot-token' "$rendered"
grep -Fq 'name: wtf-happend-bot-admin' "$rendered"
grep -Fq 'name: wtf-happend-bot-monitoring-access' "$rendered"
[[ "$(grep -Ec '^kind: Deployment$' "$rendered")" == 2 ]]
grep -Fq 'name: wtf-happend-bot' "$rendered"
grep -Fq 'type: Recreate' "$rendered"
grep -Fq 'automountServiceAccountToken: false' "$rendered"
grep -Fq 'helm.sh/resource-policy: keep' "$rendered"
grep -Fq 'name: bot' "$rendered"
grep -Fq 'name: worker' "$rendered"
grep -Fq 'name: dream-worker' "$rendered"
grep -Fq 'name: migrate-space-v2' "$rendered"
grep -Fq 'RELEASE_INGRESS_GATE' "$rendered"
grep -Fq 'HOST_RELEASE_ID' "$rendered"
grep -Fq 'SPACE_AGENT_TASK_QUEUE' "$rendered"
grep -Fq 'SPACE_DREAM_TASK_QUEUE' "$rendered"
if grep -Fq 'release-controller' "$rendered"; then
    echo 'Default render must not enable the controller.' >&2; exit 1
fi

release_render="$(helm template wtf-happend-bot "$chart_dir" --namespace wtfhappendbot \
  --set-string release.id=abcdef123456 --set-string env.HOST_RELEASE_ID=abcdef123456 \
  --set releaseReconciler.enabled=true --set-string releaseReconciler.autoAuthorizeAfterEpoch=9999999999 \
  --set-string releaseReconciler.previousHelmRevision=94 \
  --set ingress.gated=true)"
grep -Fq 'kind: CronJob' <<< "$release_render"
grep -Fq 'serviceAccountName: wtf-happend-bot-release-controller' <<< "$release_render"
grep -Fq 'automountServiceAccountToken: true' <<< "$release_render"
grep -Fq 'scripts/reconcile-host-release.sh' <<< "$release_render"
grep -Fq 'resources: ["deployments"]' <<< "$release_render"
grep -Fq 'resources: ["replicasets"]' <<< "$release_render"
grep -Fq 'resources: ["pods/log"]' <<< "$release_render"
grep -Fq 'resources: ["cronjobs", "jobs"]' <<< "$release_render"
grep -Fq 'resources: ["configmaps", "secrets", "serviceaccounts", "services"]' <<< "$release_render"
grep -Fq 'helm.sh/resource-policy: keep' <<< "$release_render"
if grep -Eq '^kind: ClusterRole(Binding)?$' <<< "$release_render"; then
    echo 'The namespaced release controller must not require cluster-scoped RBAC.' >&2; exit 1
fi
grep -Fq 'name: wtf-happend-bot-release-reconciler-abcdef123456' <<< "$release_render"
grep -Fq 'alpine/helm@sha256:d899e6316789fec04ee95300a18e454b7942539cbb3d89bde3e0655d6ca2e895' <<< "$release_render"
grep -Fq 'name: RELEASE_PREVIOUS_HELM_REVISION' <<< "$release_render"
grep -Fq 'resources: ["configmaps", "secrets", "serviceaccounts", "services"]' <<< "$release_render"
production_render="$(helm template wtf-happend-bot "$chart_dir" --namespace wtfhappendbot \
  --set-string release.id=abcdef123456 --set-string env.HOST_RELEASE_ID=abcdef123456 \
  --set releaseReconciler.enabled=true --set releaseControllerBootstrap.enabled=true \
  --set-string releaseControllerBootstrap.id=abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd \
  --set-string releaseReconciler.autoAuthorizeAfterEpoch=9999999999 \
  --set-string releaseReconciler.previousHelmRevision=94 \
  --set-string imagePullSecrets[0].name=dockerconfigjson-github-com)"
[[ "$(grep -Fc 'name: dockerconfigjson-github-com' <<< "$production_render")" -ge 3 ]]
next_release_render="$(helm template wtf-happend-bot "$chart_dir" --namespace wtfhappendbot \
  --set-string release.id=fedcba654321 --set-string env.HOST_RELEASE_ID=fedcba654321 \
  --set releaseReconciler.enabled=true --set-string releaseReconciler.autoAuthorizeAfterEpoch=9999999999 \
  --set-string releaseReconciler.previousHelmRevision=94)"
grep -Fq 'name: wtf-happend-bot-release-reconciler-fedcba654321' <<< "$next_release_render"
if grep -Fq 'name: wtf-happend-bot-release-reconciler-abcdef123456' <<< "$next_release_render"; then
    echo 'Release controllers are not uniquely fenced by release identity.' >&2; exit 1
fi
if grep -Fq 'ingress-' <<< "$release_render" || grep -Fq 'preserved-for-cutover' <<< "$release_render"; then
    echo 'Release chart still contains the split/preserved deployment design.' >&2; exit 1
fi

for operation in prepare-release abort-release preflight-workers preflight-ingress authorize-release confirm-ingress-retired reconcile-release release-status; do
    operation_render="$(helm template wtf-happend-bot "$chart_dir" --namespace wtfhappendbot \
      --show-only templates/release-operation-job.yaml --set releaseOperation.enabled=true \
      --set-string releaseOperation.id=abcdef123456 --set-string releaseOperation.operation="$operation")"
    [[ "$(grep -Ec '^kind: Job$' <<< "$operation_render")" == 1 ]]
    grep -Fq -- "- ${operation}" <<< "$operation_render" || grep -Fq -- "\"${operation}\"" <<< "$operation_render"
done

bash -n "${script_dir}/deploy-release.sh" "${script_dir}/run-release-operation.sh" "${script_dir}/reconcile-host-release.sh"
grep -Fq 'cutover_boundary_started=true' "${script_dir}/deploy-release.sh"
grep -Fq 'forward-cutover-confirmation' "${script_dir}/deploy-release.sh"
grep -Fq 'forward-schedule-reconcile' "${script_dir}/deploy-release.sh"
grep -Fq 'abort-release' "${script_dir}/deploy-release.sh"
grep -Fq -- '--take-ownership' "${script_dir}/deploy-release.sh"
grep -Fq 'helm.sh/resource-policy=keep' "${script_dir}/deploy-release.sh"
grep -Fq 'candidate_is_deployed' "${script_dir}/reconcile-host-release.sh"
grep -Fq 'verify_release_admission' "${script_dir}/reconcile-host-release.sh"
grep -Fq 'maybe_suspend_obsolete_controller' "${script_dir}/reconcile-host-release.sh"
grep -Fq 'abort_and_restore_previous_runtime' "${script_dir}/reconcile-host-release.sh"
grep -Fq '"$helm_bin" rollback' "${script_dir}/reconcile-host-release.sh"
grep -Fq 'find_runtime_matching_helm_revision' "${script_dir}/deploy-release.sh"

workflow="${project_dir}/.github/workflows/build-and-deploy.yaml"
if grep -Fq 'permissions: write-all' "$workflow" \
  || [[ "$(grep -Fc 'contents: read' "$workflow")" != 3 ]] \
  || [[ "$(grep -Fc 'packages: write' "$workflow")" != 1 ]] \
  || [[ "$(grep -Fc 'packages: read' "$workflow")" != 1 ]]; then
    echo 'GitHub Actions permissions are broader than contents-read/packages-write.' >&2; exit 1
fi
grep -Fq 'needs: [build, validation]' "$workflow"
grep -Fq 'image_ref="${repository}@${IMAGE_DIGEST}"' "$workflow"
grep -Fq 'org.opencontainers.image.revision=${{ github.sha }}' "$workflow"
grep -Fq 'docker run --rm "${IMAGE_REF}" php vendor/bin/phpunit' "$workflow"
grep -Fq 'Check formatting in release image' "$workflow"
[[ "$(grep -Fc 'version: v3.17.3' "$workflow")" == 2 ]]
[[ "$(grep -Fc "helm version --template '{{ .Version }}'" "$workflow")" == 2 ]]
if grep -Fq 'continue-on-error:' "$workflow"; then
    echo 'Release validation must remain a blocking gate.' >&2; exit 1
fi
bash "${script_dir}/test-deploy-release.sh"
bash "${script_dir}/test-reconcile-host-release.sh"
echo 'helm release safety checks passed'
