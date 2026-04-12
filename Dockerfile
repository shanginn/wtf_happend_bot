ARG RR_IMAGE_TAG=2025.1.5

FROM --platform=${TARGETPLATFORM:-linux/amd64} ghcr.io/roadrunner-server/roadrunner:${RR_IMAGE_TAG} AS roadrunner
FROM ghcr.io/shanginn/spiral-docker-image-base:master

WORKDIR /app
VOLUME /app

COPY --chown=som:som composer.json composer.lock /app/

RUN set -xe && \
    composer install \
        --prefer-dist --no-scripts \
        --no-progress --no-interaction

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

RUN chown som:som /app /opt

COPY --chown=som:som . /app

USER som
