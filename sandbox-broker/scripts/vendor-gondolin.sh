#!/bin/sh
set -eu

commit="10b510625dde73cbfd15ac2fc1ae7b8ef642c62c"
archive_sha256="f0a5198d9f2749bf0431a573833e835db5bc89d994e15bb3a402d9152203cfba"
target="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)/vendor/gondolin-firecracker"
temporary="$(mktemp -d)"
trap 'rm -rf "$temporary"' EXIT HUP INT TERM

curl -fsSL \
  "https://codeload.github.com/shanginn/gondolin-firecracker/tar.gz/${commit}" \
  -o "$temporary/source.tar.gz"
actual="$(shasum -a 256 "$temporary/source.tar.gz" | awk '{print $1}')"
if [ "$actual" != "$archive_sha256" ]; then
  echo "Gondolin archive checksum mismatch: expected $archive_sha256, got $actual" >&2
  exit 1
fi

rm -rf "$target"
mkdir -p "$target"
tar -xzf "$temporary/source.tar.gz" --strip-components=1 -C "$target"
printf '%s\n' "$commit" > "$target/.git-commit"
