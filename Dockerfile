FROM php:8.3-apache

ARG GLPI_VERSION=11.0.7

***REMOVED*** System dependencies + PHP extensions required by GLPI
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libldap2-dev \
        libxml2-dev \
        libzip-dev \
        libcurl4-openssl-dev \
        libonig-dev \
        libexif-dev \
        libgd-dev \
        gettext \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        gd \
        intl \
        mbstring \
        mysqli \
        xml \
        dom \
        simplexml \
        zip \
        ldap \
        opcache \
        exif \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

***REMOVED*** PHP tuning for GLPI
RUN { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=4000'; \
        echo 'opcache.revalidate_freq=60'; \
        echo 'opcache.enable_cli=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini \
    && { \
        echo 'upload_max_filesize = 20M'; \
        echo 'post_max_size = 20M'; \
        echo 'max_execution_time = 600'; \
        echo 'memory_limit = 256M'; \
        echo 'session.cookie_httponly = On'; \
    } > /usr/local/etc/php/conf.d/glpi.ini

***REMOVED*** Enable Apache rewrite module + configure VirtualHost
***REMOVED*** GLPI 10+/11+ serves from the public/ subdirectory, NOT the root
RUN a2enmod rewrite \
    && { \
        echo '<VirtualHost *:80>'; \
        echo '    DocumentRoot /var/www/html/glpi/public'; \
        echo '    <Directory /var/www/html/glpi/public>'; \
        echo '        Options -Indexes +FollowSymLinks'; \
        echo '        AllowOverride All'; \
        echo '        Require all granted'; \
        echo '        ***REMOVED*** FallbackResource ensures all non-existent paths go through'; \
        echo '        ***REMOVED*** GLPIs front controller (index.php) regardless of .htaccess'; \
        echo '        FallbackResource /index.php'; \
        echo '    </Directory>'; \
        echo '</VirtualHost>'; \
    } > /etc/apache2/sites-available/000-default.conf \
    ***REMOVED*** Also fix the global apache2.conf AllowOverride that blocks .htaccess in /var/www/
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

***REMOVED*** Download and extract GLPI
RUN curl -fsSL \
        "https://github.com/glpi-project/glpi/releases/download/${GLPI_VERSION}/glpi-${GLPI_VERSION}.tgz" \
        -o /tmp/glpi.tgz \
    && tar -xzf /tmp/glpi.tgz -C /var/www/html/ \
    && rm /tmp/glpi.tgz \
    && chown -R www-data:www-data /var/www/html/glpi \
    && chmod -R 755 /var/www/html/glpi

***REMOVED*** Custom API endpoint
COPY public/custom_api.php /var/www/html/glpi/public/custom_api.php

RUN chown www-data:www-data \
        /var/www/html/glpi/public/custom_api.php

***REMOVED*** Startup scripts
COPY docker/generate-config.php /docker/generate-config.php
COPY docker/entrypoint.sh       /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
