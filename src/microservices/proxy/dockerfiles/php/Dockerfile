FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    curl

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/public

CMD ["php-fpm"]

EXPOSE 9000