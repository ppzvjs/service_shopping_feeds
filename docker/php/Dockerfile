FROM php:8.2-fpm

# 1. System-Abhängigkeiten installieren (Alles in einem apt-get Schritt)
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libc6-dev \
    libxml2-dev \
    libicu-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libaio1t64 \
    libnsl2 \
    && rm -rf /var/lib/apt/lists/*

# 2. Symlink für libaio reparieren (Debian 12 / Bookworm)
RUN ln -s /usr/lib/$(uname -m)-linux-gnu/libaio.so.1t64 /usr/lib/$(uname -m)-linux-gnu/libaio.so.1 || true

# 3. Oracle Instant Client herunterladen & entpacken
RUN set -ex; \
    ARCH=$(uname -m); \
    if [ "$ARCH" = "x86_64" ]; then \
        BASE_URL="https://download.oracle.com/otn_software/linux/instantclient/211000/instantclient"; \
        FILES="basic-linux.x64-21.10.0.0.0dbru.zip sdk-linux.x64-21.10.0.0.0dbru.zip"; \
    else \
        BASE_URL="https://download.oracle.com/otn_software/linux/instantclient/1919000/instantclient"; \
        FILES="basic-linux.arm64-19.19.0.0.0dbru.zip sdk-linux.arm64-19.19.0.0.0dbru.zip"; \
    fi; \
    mkdir -p /opt/oracle; \
    for FILE in $FILES; do \
        curl -L -o "/opt/oracle/$FILE" "$BASE_URL-$FILE"; \
        unzip "/opt/oracle/$FILE" -d /opt/oracle; \
        rm "/opt/oracle/$FILE"; \
    done; \
    mv /opt/oracle/instantclient_* /opt/oracle/instantclient

# 4. System für Oracle konfigurieren
RUN echo "/opt/oracle/instantclient" > /etc/ld.so.conf.d/oracle-instantclient.conf && ldconfig

# 5. Umgebungsvariablen setzen
ENV LD_LIBRARY_PATH=/opt/oracle/instantclient
ENV ORACLE_HOME=/opt/oracle/instantclient

# 6. PHP-Standard-Erweiterungen konfigurieren & installieren
RUN docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql xml intl gd zip opcache

# 7. OCI8 via PECL installieren & aktivieren
RUN echo "instantclient,/opt/oracle/instantclient" | pecl install oci8 \
    && docker-php-ext-enable oci8

# 8. PDO_OCI (über PHP Core Extensions) konfigurieren & installieren
RUN docker-php-ext-configure pdo_oci --with-pdo-oci=instantclient,/opt/oracle/instantclient \
    && docker-php-ext-install pdo_oci

# PHP Speicherlimit erhöhen
RUN echo "memory_limit=1024M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Berechtigungen & Arbeitsverzeichnis
RUN chown -R www-data:www-data /var/www/html
WORKDIR /var/www/html
