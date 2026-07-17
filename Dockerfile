# Используем официальный образ PHP с Apache
FROM php:8.2-apache AS base

# Установка системных зависимостей и расширений
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        gd \
        exif \
        zip \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Включаем модуль rewrite и заголовки
RUN a2enmod rewrite headers

# Копируем конфигурацию PHP (opcache и настройки)
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Настраиваем Apache (выключаем листинг директорий, включаем сжатие)
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && echo "Options -Indexes" >> /etc/apache2/conf-available/security.conf \
    && a2enconf security

# Копируем код приложения
COPY . /var/www/html/

# Права доступа (безопасность)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/storage || true

# Устанавливаем рабочую директорию
WORKDIR /var/www/html

# Переменные окружения для PHP (используются в core.php)
ENV DB_HOST=db \
    DB_PORT=5432 \
    DB_NAME=repro \
    DB_USER=app_user \
    DB_PASSWORD=secret

EXPOSE 80

# Healthcheck (проверка доступности приложения)
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/index.php/documents || exit 1

# Запуск Apache в foreground
CMD ["apache2-foreground"]