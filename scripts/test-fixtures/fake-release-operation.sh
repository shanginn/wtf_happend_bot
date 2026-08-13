#!/usr/bin/env bash

set -euo pipefail

log="${RELEASE_TEST_LOG:?}"
behavior="${RELEASE_TEST_BEHAVIOR:?}"
state_dir="${RELEASE_TEST_STATE_DIR:?}"
operation="$1"
state_file="${state_dir}/release-state"

echo "operation ${operation}" >> "$log"

if [[ ( "$behavior" == 'preflight-failure' || "$behavior" == 'abort-failure' || "$behavior" == 'runtime-drift-preflight-failure' ) && "$operation" == 'preflight-workers' ]]; then
    exit 17
fi
if [[ "$behavior" == 'authorize-failure' && "$operation" == 'authorize-release' ]]; then
    exit 19
fi
if [[ "$behavior" == 'not-active-then-active' && "$operation" == 'release-status' ]]; then
    attempts_file="${state_dir}/release-status-attempts"
    attempts=0
    if [[ -f "$attempts_file" ]]; then attempts="$(< "$attempts_file")"; fi
    attempts=$((attempts + 1))
    echo "$attempts" > "$attempts_file"
    # A Job failure for ingress-retired must not masquerade as active.
    if ((attempts >= 2)); then
        echo '{"status":"active"}'
        exit 0
    fi
    echo '{"status":"ingress-retired"}'
    exit 3
fi

case "$operation" in
    prepare-release)
        echo prepared > "$state_file"
        echo '{"status":"prepared"}'
        ;;
    abort-release)
        if [[ "$behavior" == 'abort-failure' ]]; then exit 29; fi
        echo aborted > "$state_file"
        echo '{"status":"aborted"}'
        ;;
    release-status)
        state=active
        if [[ -f "$state_file" ]]; then state="$(< "$state_file")"; fi
        printf '{"status":"%s"}\n' "$state"
        [[ "$state" == active ]] || exit 3
        ;;
    migrate-legacy-commands)
        echo '{"mode":"applied","migratedSpaces":1}'
        ;;
    authorize-release)
        echo authorized > "$state_file"
        echo '{"status":"authorized"}'
        ;;
    confirm-ingress-retired)
        echo ingress-retired > "$state_file"
        echo '{"status":"ingress-retired"}'
        ;;
    reconcile-release)
        echo active > "$state_file"
        echo '{"status":"active"}'
        ;;
esac
