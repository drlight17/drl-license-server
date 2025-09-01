FROM php:8.1-apache

# Enable mod_rewrite and mod_session
RUN a2enmod rewrite session

# Install tzdata for timezone support and dependencies for Composer
RUN apt-get update && apt-get install -y \
    tzdata \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set timezone in PHP
RUN echo "date.timezone = \${TZ}" >> /usr/local/etc/php/conf.d/timezone.ini

# --- Изменено: Создаем отдельную директорию для установки зависимостей ---
# Создаем директорию вне /var/www/html, которая не будет перезаписана томом
RUN mkdir -p /var/www/deps

COPY src/.htaccess /var/www/deps/

# Копируем composer файлы во временную директорию
COPY src/composer.json src/composer.lock* /var/www/deps/

# Устанавливаем зависимости во временную директорию
WORKDIR /var/www/deps
RUN composer install --no-dev --prefer-dist --optimize-autoloader

# --- Изменено: Копируем зависимости в правильное место ПОСЛЕ установки ---
# Переключаемся обратно в /var/www/html
WORKDIR /var/www/html

# Создаем директории для data и logs
RUN mkdir -p /var/www/data /var/www/logs

# Создаем файлы если они не существуют
RUN touch /var/www/data/keys.json
RUN touch /var/www/logs/license.log

# Устанавливаем правильные права доступа
RUN chmod -R 755 /var/www/html/
RUN chmod -R 755 /var/www/data/
RUN chmod -R 755 /var/www/logs/
RUN chmod 666 /var/www/data/keys.json
RUN chmod 666 /var/www/logs/license.log
RUN chown -R www-data:www-data /var/www/
RUN chown -R www-data:www-data /var/www/data
RUN chown -R www-data:www-data /var/www/logs

# Expose port
EXPOSE 80

# --- Изменено: Копируем исходники и зависимости в entrypoint script ---
# Создаем скрипт, который будет копировать зависимости и исходники при запуске контейнера
RUN echo '#!/bin/bash\n\
echo "Copying source files..."\n\
cp -r /var/www/html_source/* /var/www/html/ 2>/dev/null || true\n\
echo "Copying dependencies..."\n\
cp -r /var/www/deps/vendor /var/www/html/ 2>/dev/null || true\n\
cp -r /var/www/deps/.htaccess /var/www/html/ 2>/dev/null || true\n\
echo "Setting permissions..."\n\
chown -R www-data:www-data /var/www/html/\n\
chmod -R 755 /var/www/html/\n\
chown -R www-data:www-data /var/www/data/\n\
chown -R www-data:www-data /var/www/logs/\n\
echo "Starting Apache..."\n\
apache2-foreground\n\
' > /entrypoint.sh && chmod +x /entrypoint.sh

# Копируем исходники в отдельную директорию внутри образа
COPY src/ /var/www/html_source/

# Устанавливаем entrypoint script как команду по умолчанию
CMD ["/entrypoint.sh"]