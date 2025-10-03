FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    curl

RUN apt-get update && apt-get install -y nginx supervisor && rm -rf /var/lib/apt/lists/*

COPY ./dockerfiles/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY ./dockerfiles/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/public

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

