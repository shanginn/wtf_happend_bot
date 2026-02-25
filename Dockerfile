FROM ghcr.io/shanginn/spiral-docker-image-base:master

WORKDIR /app
VOLUME /app

COPY --chown=som:som composer.json composer.lock /app/

RUN set -xe && \
    composer install \
        --prefer-dist --no-scripts \
        --no-progress --no-interaction

# Install RoadRunner
RUN curl -L https://github.com/roadrunner-server/roadrunner/releases/download/v2025.1.6/roadrunner-2025.1.6-linux-arm64.tar.gz | tar -xz -C /usr/local/bin

RUN chown som:som /app /opt

COPY --chown=som:som . /app

USER som
