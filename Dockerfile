FROM ghcr.io/shanginn/spiral-docker-image-base:master

WORKDIR /app
VOLUME /app

COPY . /app

ENTRYPOINT ["php", "src/bot.php"]
