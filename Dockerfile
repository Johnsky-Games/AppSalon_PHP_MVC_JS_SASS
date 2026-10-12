FROM php:8.2-apache

# Instalar dependencias del sistema y extensiones de PHP requeridas (mysqli, pdo_mysql y soporte mbstring)
# HTTPS evita bloqueos de repositorios HTTP sin desactivar la verificación de firmas.
RUN find /etc/apt -type f \( -name '*.list' -o -name '*.sources' \) \
        -exec sed -i 's|http://deb.debian.org|https://deb.debian.org|g;s|http://security.debian.org|https://security.debian.org|g' {} + \
    && apt-get update && apt-get install -y --no-install-recommends \
        libonig-dev \
    && docker-php-ext-install mysqli pdo_mysql mbstring \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

# Configurar VirtualHost y Apache Front Controller apuntando a public/ con AllowOverride All
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Directivas PHP de produccion recomendadas
RUN printf "variables_order = \"EGPCS\"\ndisplay_errors = Off\nlog_errors = On\n" > /usr/local/etc/php/conf.d/appsalon.ini

WORKDIR /var/www/html
