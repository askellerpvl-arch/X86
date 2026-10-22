# Runtime for isolated regression only; never deploy to the stock server.
FROM php:8.2-cli
RUN --mount=type=secret,id=proxy_ca \
    apt-get -o Acquire::https::CaInfo=/run/secrets/proxy_ca update && \
    apt-get -o Acquire::https::CaInfo=/run/secrets/proxy_ca install -y --no-install-recommends libonig-dev && \
    docker-php-ext-install mysqli mbstring && rm -rf /var/lib/apt/lists/*
WORKDIR /tests
