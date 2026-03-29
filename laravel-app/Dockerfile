FROM php:8.5-fpm-alpine

RUN apk add --no-cache \
        git \
        curl \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        libpq-dev \
        autoconf \
        gcc \
        g++ \
        make \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        bcmath \
        pcntl \
        intl \
        gd \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf gcc g++ make \
    && rm -rf /tmp/pear

RUN { \
        echo "upload_max_filesize=512M"; \
        echo "post_max_size=600M"; \
        echo "memory_limit=512M"; \
        echo "max_execution_time=120"; \
        echo "max_input_time=240"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
