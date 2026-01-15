FROM php:apache

RUN apt-get update && apt-get install -y unzip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

EXPOSE 80
