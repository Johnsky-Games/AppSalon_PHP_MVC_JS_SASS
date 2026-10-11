FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo_mysql \
    && a2enmod rewrite headers \
    && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf "variables_order = \"EGPCS\"\ndisplay_errors = Off\nlog_errors = On\n" > /usr/local/etc/php/conf.d/appsalon.ini

WORKDIR /var/www/html
