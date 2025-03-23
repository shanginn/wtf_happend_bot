FROM ghcr.io/shanginn/spiral-docker-image-base:master

WORKDIR /app
VOLUME /app

COPY --chown=som:som composer.json composer.lock /app/

RUN set -xe && \
    composer install \
        --prefer-dist --no-scripts \
        --no-progress --no-interaction

RUN chown som:som /app /opt

COPY --chown=som:som . /app

USER som

ENTRYPOINT ["php", "src/bot.php"]
