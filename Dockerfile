# Dockerfile
FROM php:8.2-apache

# Установка расширений PHP для PostgreSQL и GD
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql pgsql gd

# Включаем mod_rewrite для Apache (опционально)
RUN a2enmod rewrite

# Копируем код в контейнер
COPY . /var/www/html/

# Настройка прав (для разработки)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Указываем рабочую директорию
WORKDIR /var/www/html

EXPOSE 80