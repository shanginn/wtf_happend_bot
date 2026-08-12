FROM node:24-bookworm-slim AS gondolin-source

ARG GONDOLIN_COMMIT=10b510625dde73cbfd15ac2fc1ae7b8ef642c62c
ARG GONDOLIN_ARCHIVE_SHA256=f0a5198d9f2749bf0431a573833e835db5bc89d994e15bb3a402d9152203cfba
ADD https://codeload.github.com/shanginn/gondolin-firecracker/tar.gz/${GONDOLIN_COMMIT} /tmp/gondolin.tar.gz
RUN mkdir -p /source \
    && echo "${GONDOLIN_ARCHIVE_SHA256}  /tmp/gondolin.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/gondolin.tar.gz --strip-components=1 -C /source \
    && printf '%s\n' "${GONDOLIN_COMMIT}" > /source/.git-commit

FROM node:24-bookworm-slim AS build

WORKDIR /app
RUN corepack enable
COPY --from=gondolin-source /source /app/vendor/gondolin-firecracker
RUN pnpm --dir /app/vendor/gondolin-firecracker install --frozen-lockfile \
    && pnpm --dir /app/vendor/gondolin-firecracker --filter @earendil-works/gondolin build \
    && pnpm --dir /app/vendor/gondolin-firecracker install --prod --frozen-lockfile
COPY package.json pnpm-lock.yaml tsconfig.json ./
RUN pnpm install --frozen-lockfile
COPY src ./src
RUN pnpm build && pnpm prune --prod

FROM node:24-bookworm-slim AS runtime

ARG FIRECRACKER_VERSION=v1.16.1
ARG FIRECRACKER_SHA256_X86_64=382a02a869e4d6d5cb14c40577f9545e8458021ea8b0b2d3fc10ec14d9c242e6
ARG FIRECRACKER_SHA256_AARCH64=8d0e69f6d6f9a1724551f607f18504052c16c1828ee3d4d7b6e6c73380871e0e

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl iproute2 python3 xz-utils \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 sandbox \
    && useradd --uid 10001 --gid sandbox --home-dir /var/lib/wtf-sandbox --create-home sandbox \
    && mkdir -p /var/lib/wtf-sandbox/capsules /var/lib/wtf-sandbox/state /var/lib/wtf-sandbox/runtime /run/wtf-sandbox \
    && chown -R sandbox:sandbox /var/lib/wtf-sandbox /run/wtf-sandbox

RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in \
      amd64) fc_arch="x86_64"; fc_sha256="$FIRECRACKER_SHA256_X86_64" ;; \
      arm64) fc_arch="aarch64"; fc_sha256="$FIRECRACKER_SHA256_AARCH64" ;; \
      *) echo "unsupported architecture: $arch" >&2; exit 1 ;; \
    esac; \
    url="https://github.com/firecracker-microvm/firecracker/releases/download/${FIRECRACKER_VERSION}/firecracker-${FIRECRACKER_VERSION}-${fc_arch}.tgz"; \
    curl -fsSL "$url" -o /tmp/firecracker.tgz; \
    echo "$fc_sha256  /tmp/firecracker.tgz" | sha256sum -c -; \
    tar -xzf /tmp/firecracker.tgz -C /tmp; \
    install -m 0755 "$(find /tmp -type f -name "firecracker-${FIRECRACKER_VERSION}-${fc_arch}" | head -n 1)" /usr/local/bin/firecracker; \
    rm -rf /tmp/firecracker.tgz /tmp/release-"${FIRECRACKER_VERSION}"-"${fc_arch}"

WORKDIR /app
COPY --from=build --chown=sandbox:sandbox /app/node_modules ./node_modules
COPY --from=build --chown=sandbox:sandbox /app/vendor ./vendor
COPY --from=build --chown=sandbox:sandbox /app/dist ./dist
COPY --from=build --chown=sandbox:sandbox /app/package.json ./package.json

USER sandbox
ENV SANDBOX_CAPSULE_ROOT=/var/lib/wtf-sandbox/capsules \
    SANDBOX_STATE_ROOT=/var/lib/wtf-sandbox/state \
    SANDBOX_RUNTIME_ROOT=/var/lib/wtf-sandbox/runtime \
    GONDOLIN_FIRECRACKER=/usr/local/bin/firecracker \
    GONDOLIN_RUNTIME_DIR=/run/wtf-sandbox/gondolin
EXPOSE 8787
CMD ["node", "dist/server.js"]
