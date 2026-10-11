FROM php:8.2-apache

# Instalar dependencias del sistema y extensiones de PHP requeridas (mysqli, pdo_mysql y soporte mbstring)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libonig-dev \
    && docker-php-ext-install mysqli pdo_mysql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

# Configurar VirtualHost y Apache Front Controller apuntando a public/ con AllowOverride All
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Directivas PHP de produccion recomendadas
RUN printf "variables_order = \"EGPCS\"\ndisplay_errors = Off\nlog_errors = On\n" > /usr/local/etc/php/conf.d/appsalon.ini

WORKDIR /var/www/html
