
FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql

# Apacheのバージョン情報を非表示にする
RUN sed -i \
    -e 's/^[[:space:]]*ServerTokens OS/ServerTokens Prod/' \
    -e 's/^[[:space:]]*ServerSignature On/ServerSignature Off/' \
    /etc/apache2/conf-available/security.conf

# Apacheの警告を抑制
RUN printf 'ServerName localhost\n' \
    > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername
