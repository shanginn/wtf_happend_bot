FROM composer:2 AS composer
FROM rust:1.94-bookworm AS rust

FROM trueasync/php-true-async:0.8.4-php8.6 AS temporal-builder

ARG PHP_TEMPORAL_REPOSITORY=https://github.com/shanginn/php-temporal.git
ARG PHP_TEMPORAL_REF=38d114ab958b7d7df7187deab9f5c05eaed60436

ENV CARGO_HOME=/usr/local/cargo \
    RUSTUP_HOME=/usr/local/rustup \
    PATH=/usr/local/cargo/bin:$PATH

COPY --from=rust /usr/local/cargo /usr/local/cargo
COPY --from=rust /usr/local/rustup /usr/local/rustup

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        autoconf \
        ca-certificates \
        g++ \
        git \
        libprotobuf-dev \
        libssl-dev \
        libtool \
        make \
        pkg-config \
        protobuf-compiler \
        zlib1g-dev

RUN git clone --recurse-submodules "$PHP_TEMPORAL_REPOSITORY" /tmp/php-temporal \
    && cd /tmp/php-temporal \
    && git checkout --detach "$PHP_TEMPORAL_REF" \
    && git submodule update --init --recursive

WORKDIR /tmp/php-temporal

RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/tmp/php-temporal/third_party/sdk-rust/target \
    cargo +1.94 build --release -p temporalio-sdk-core-c-bridge --manifest-path third_party/sdk-rust/Cargo.toml \
    && phpize \
    && ./configure --enable-temporal --with-php-config="$(command -v php-config)" \
    && make -j"$(nproc)" \
    && make install \
    && cp third_party/sdk-rust/target/release/libtemporalio_sdk_core_c_bridge.so /usr/local/lib/

FROM trueasync/php-true-async:0.8.4-php8.6 AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates git libssl3 unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=temporal-builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=temporal-builder /usr/local/lib/libtemporalio_sdk_core_c_bridge.so /usr/local/lib/
COPY docker/php/temporal.ini /etc/php.d/20-temporal.ini

RUN ldconfig \
    && php -r "if (PHP_VERSION_ID < 80600 || !extension_loaded('true_async') || !extension_loaded('temporal')) { exit(1); }"

RUN groupadd --gid 1337 bot \
    && useradd --uid 1337 --gid 1337 --create-home --shell /bin/bash bot

WORKDIR /app

FROM runtime AS app

COPY --chown=bot:bot composer.json composer.lock /app/

RUN composer install \
    --prefer-dist \
    --no-scripts \
    --no-progress \
    --no-interaction \
    --ignore-platform-req=php+

COPY --chown=bot:bot . /app

USER bot

STOPSIGNAL SIGTERM
CMD ["php", "src/bot.php"]
