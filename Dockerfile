FROM php:apache

RUN apt-get update && apt-get install -y unzip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader

WORKDIR /var/www/html

EXPOSE 80
