#!/usr/bin/env bash

set -euo pipefail

log="${RELEASE_TEST_LOG:?}"
behavior="${RELEASE_TEST_BEHAVIOR:?}"
args=" $* "

if [[ "$args" == *' get deployments '* && "$args" == *' --output=json '* ]]; then
    cat <<'JSON'
{"items":[{"metadata":{"name":"wtf-happend-bot"},"spec":{"replicas":1,"template":{"metadata":{"annotations":{}},"spec":{"containers":[{"name":"bot","image":"registry/app:old"},{"name":"worker","image":"registry/app:old"}]}}}}]}
JSON
    exit 0
fi

if [[ "$args" == *' get configmap/'* && "$args" == *'jsonpath={.data.release-id}'* ]]; then
    printf '%s' abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd
    exit 0
fi

if [[ "$args" == *' create configmap '* && "$args" == *' --dry-run=client '* ]]; then
    printf 'apiVersion: v1\nkind: ConfigMap\nmetadata:\n  name: release-marker\ndata:\n  release-id: abcdef\n'
    exit 0
fi

if [[ "$args" == *' get serviceaccount/'* ]]; then
    if [[ "$args" == *'jsonpath={.metadata.annotations.helm\.sh/resource-policy}'* ]]; then
        printf 'keep'
    fi
    exit 0
fi
if [[ "$args" == *' annotate serviceaccount/'* ]]; then
    printf 'kubectl %s\n' "$*" >> "$log"
    exit 0
fi
if [[ "$args" == *' get configmap/'* || "$args" == *' get secret/'* || "$args" == *' get cronjob/'* ]]; then
    printf 'kubectl dependency %s\n' "$*" >> "$log"
    exit 0
fi

if [[ "$args" == *' apply -f '* ]]; then
    printf 'kubectl apply %s\n' "$*" >> "$log"
    exit 0
fi

if [[ "$args" == *' delete job '* ]]; then
    printf 'kubectl delete job %s\n' "$*" >> "$log"
    exit 0
fi

if [[ "$args" == *' wait '* && "$args" == *'condition=complete'* ]]; then
    printf 'kubectl bootstrap complete %s\n' "$*" >> "$log"
    if [[ "$behavior" == 'bootstrap-failure' ]]; then exit 31; fi
    exit 0
fi

if [[ "$args" == *' rollout status '* ]]; then
    printf 'kubectl rollout %s\n' "$*" >> "$log"
    exit 0
fi

if [[ "$args" == *' wait '* && "$args" == *'condition=Ready'* ]]; then
    echo 'kubectl wait pods-ready' >> "$log"
    exit 0
fi

if [[ "$args" == *' logs job/'* ]]; then
    echo 'Release controller bootstrap verified DB and Kubernetes access.'
    exit 0
fi

if [[ "$args" == *' describe job '* ]]; then
    printf 'kubectl describe %s\n' "$* >> "$log"
    exit 0
fi

if [[ "$args" == *' logs deployment/'* ]]; then
    echo 'Starting durable bot polling with offset 1, limit 100, timeout 30'
    exit 0
fi

echo "unexpected fake kubectl call: $*" >&2
exit 64
