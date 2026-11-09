FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
      libzip-dev libpng-dev libonig-dev libcurl4-openssl-dev \
  && docker-php-ext-install pdo_mysql mbstring zip gd curl \
  && printf 'post_max_size=16M\nupload_max_filesize=12M\n' > /usr/local/etc/php/conf.d/uploads.ini \
  && a2enmod rewrite headers \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative

COPY . /var/www/html/

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/000-default.conf \
  && printf '<Directory %s>\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' "$APACHE_DOCUMENT_ROOT" \
       > /etc/apache2/conf-available/lavalust.conf \
  && a2enconf lavalust \
  && mkdir -p /var/www/html/runtime \
  && chown -R www-data:www-data /var/www/html/runtime \
  && chmod -R 755 /var/www/html \
  && chmod -R 775 /var/www/html/runtime

EXPOSE 80
CMD ["sh", "-c", "port=${PORT:-80}; sed -ri \"s/^Listen .*/Listen ${port}/\" /etc/apache2/ports.conf; sed -ri \"s/<VirtualHost \\\\*:[0-9]+>/<VirtualHost *:${port}>/\" /etc/apache2/sites-available/000-default.conf; exec apache2-foreground"]
