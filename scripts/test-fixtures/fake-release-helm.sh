#!/usr/bin/env bash

set -euo pipefail

log="${RELEASE_TEST_LOG:?}"
behavior="${RELEASE_TEST_BEHAVIOR:?}"
state_dir="${RELEASE_TEST_STATE_DIR:?}"
command="${1:-}"
shift || true

case "$command" in
    template)
        printf 'helm template %s\n' "$*" >> "$log"
        args=" $* "
        if [[ "$args" == *'release-controller-bootstrap-job.yaml'* ]]; then
            cat <<'YAML'
apiVersion: batch/v1
kind: Job
metadata:
  name: wtf-happend-bot-release-bootstrap-abcdefabcdef
YAML
        elif [[ "$args" == *'release-reconciler.yaml'* ]]; then
            printf 'apiVersion: batch/v1\nkind: CronJob\nmetadata:\n  name: wtf-happend-bot-release-reconciler\n'
        else
            printf 'apiVersion: v1\nkind: ServiceAccount\nmetadata:\n  name: wtf-happend-bot-release-controller\n'
        fi
        ;;
    list)
        if [[ "$behavior" == runtime-drift-* ]]; then revision=8; else revision=7; fi
        printf '[{"name":"wtf-happend-bot","namespace":"wtfhappendbot","revision":"%s","status":"deployed"}]\n' "$revision"
        ;;
    status)
        if [[ -f "${state_dir}/helm-aligned" ]]; then revision=9
        elif [[ "$behavior" == runtime-drift-* && " $* " == *' --revision 8 '* ]]; then revision=8
        else revision=7
        fi
        printf '{"version":%s,"info":{"status":"deployed"}}\n' "$revision"
        ;;
    get)
        [[ "${1:-}" == values ]] || exit 64
        args=" $* "
        if [[ "$behavior" == runtime-drift-* && "$args" == *' --revision 8 '* ]]; then
            echo '{"image":{"repository":"registry/app","digest":"sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","tag":"latest"},"release":{"id":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"}}'
        else
            echo '{"image":{"repository":"registry/app","digest":"","tag":"old"},"release":{"id":"local"}}'
        fi
        ;;
    history)
        echo '[{"revision":7,"status":"superseded"},{"revision":8,"status":"deployed"}]'
        ;;
    upgrade)
        printf 'helm upgrade stable %s\n' "$*" >> "$log"
        if [[ "$behavior" == 'upgrade-failure' ]]; then exit 23; fi
        ;;
    rollback)
        printf 'helm rollback %s\n' "$*" >> "$log"
        if [[ "$behavior" == runtime-drift-* && " $* " == *' 7 '* ]]; then : > "${state_dir}/helm-aligned"; fi
        ;;
    uninstall)
        printf 'helm uninstall %s\n' "$*" >> "$log"
        ;;
    *)
        echo "unexpected fake helm command: ${command}" >&2
        exit 64
        ;;
esac

: > "${state_dir}/helm-called"
