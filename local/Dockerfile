FROM ghcr.io/shanginn/spiral-docker-image-base:master

WORKDIR /app
VOLUME /app

COPY . /app

# Install RoadRunner
RUN curl -L https://github.com/roadrunner-server/roadrunner/releases/download/v2025.1.6/roadrunner-2025.1.6-linux-arm64.tar.gz | tar -xz -C /usr/local/bin
