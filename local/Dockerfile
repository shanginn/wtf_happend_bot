ARG RR_IMAGE_TAG=2025.1.5

FROM --platform=${TARGETPLATFORM:-linux/amd64} ghcr.io/roadrunner-server/roadrunner:${RR_IMAGE_TAG} AS roadrunner
FROM ghcr.io/shanginn/spiral-docker-image-base:master

WORKDIR /app
VOLUME /app

COPY . /app

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr
