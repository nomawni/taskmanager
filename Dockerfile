# Dockerfile

FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libicu-dev \
    libpq-dev \
    libonig-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install intl pdo pdo_mysql pdo_pgsql mbstring zip

# Install Symfony CLI
RUN curl -sS https://get.symfony.com/cli/installer | bash

# Move Symfony binary to /usr/local/bin for global access
RUN mv /root/.symfony5/bin/symfony /usr/local/bin/symfony

# Install Xdebug (if not installed already)
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Create and configure xdebug.ini
RUN echo "[xdebug]" >> /usr/local/etc/php/conf.d/xdebug.ini && \
    echo "zend_extension=$(find /usr/local/lib/php/extensions/ -name xdebug.so)" >> /usr/local/etc/php/conf.d/xdebug.ini && \
    echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/xdebug.ini && \
    echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/xdebug.ini && \
    echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/xdebug.ini && \
    echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/xdebug.ini

# Install Sodium extension
RUN apt-get update && \
    apt-get install -y libsodium-dev && \
    docker-php-ext-install sodium


# Ensure Symfony is executable
RUN chmod +x /usr/local/bin/symfony

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy existing application directory contents
COPY . /var/www

# Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/var /var/www/vendor

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]

