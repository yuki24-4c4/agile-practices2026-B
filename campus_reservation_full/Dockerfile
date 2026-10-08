FROM php:8.2-apache
RUN docker-php-ext-install pdo_mysql
RUN a2enmod headers && printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/security-extra.conf && a2enconf security-extra
