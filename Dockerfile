FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
    && docker-php-ext-install pdo pdo_mysql mysqli zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Разрешить .htaccess (ЧПУ /technical_documentation и т.п.)
RUN sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/finbuild-entrypoint
RUN chmod +x /usr/local/bin/finbuild-entrypoint

EXPOSE 80

ENTRYPOINT ["finbuild-entrypoint"]
CMD ["apache2-foreground"]
