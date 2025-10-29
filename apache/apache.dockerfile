FROM --platform=linux/amd64 php:8.3-apache-bullseye

RUN apt-get update && apt-get install -y \
    vim \
    unzip \
    iputils-ping \
    libaio1 \
    libaio-dev \
    libzip-dev \
 && rm -rf /var/lib/apt/lists/*



COPY oracle/instantclient_23_7 /opt/oracle/instantclient

# Install Oracle Instant Client
RUN echo "/opt/oracle/instantclient" > /etc/ld.so.conf.d/oracle-instantclient.conf && \
    ldconfig

# Set Oracle environment variables
ENV LD_LIBRARY_PATH=/opt/oracle/instantclient
ENV ORACLE_HOME=/opt/oracle/instantclient
ENV PATH=$ORACLE_HOME:$PATH

RUN docker-php-ext-configure oci8 --with-oci8=instantclient,/opt/oracle/instantclient \
    && docker-php-ext-configure pdo_oci --with-pdo-oci=instantclient,/opt/oracle/instantclient \
    && docker-php-ext-install oci8 pdo_oci

RUN docker-php-ext-install pdo pdo_mysql mysqli

# ---- XDEBUG -----------------------------------------------------------------
RUN pecl install xdebug \
 && docker-php-ext-enable xdebug


# Enable Apache modules
COPY conf/app.conf /etc/apache2/sites-available/app.conf

# Enable site and SSL
RUN a2ensite app \
 && a2enmod ssl && a2enmod rewrite

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Increase PHP memory limit
# ---- PHP CONFIG --------------------------------------------------------------
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory-limit.ini
COPY conf/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini
