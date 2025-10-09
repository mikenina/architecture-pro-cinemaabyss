FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    nginx supervisor \
    librdkafka-dev \
    libzstd-dev \
    libssl-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install rdkafka \
    && docker-php-ext-enable rdkafka

COPY ./dockerfiles/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY ./dockerfiles/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

COPY ./public /var/www/public

WORKDIR /var/www/public

RUN php -m | grep rdkafka || (echo "Ошибка: rdkafka не установлен" && exit 1)
RUN test -f /var/www/public/index.php || (echo "❌ index.php missing!" && exit 1)

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

