FROM mcr.microsoft.com/devcontainers/php:1-8.3-bookworm

RUN apt-get update \
    && export DEBIAN_FRONTEND=noninteractive \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        zip \
        sqlite3 \
        postgresql-client \
        php8.3-bcmath \
        php8.3-curl \
        php8.3-intl \
        php8.3-mbstring \
        php8.3-pgsql \
        php8.3-sqlite3 \
        php8.3-xml \
        php8.3-zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*