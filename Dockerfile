FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && if ! getent group 1000 >/dev/null; then groupadd --gid 1000 render-secrets; fi \
    && usermod --append --groups 1000 www-data

WORKDIR /var/www/html
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html/runtime \
    && find /var/www/html/runtime -type d -exec chmod 775 {} \; \
    && find /var/www/html/runtime -type f -exec chmod 664 {} \;

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
