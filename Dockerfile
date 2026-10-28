FROM php:8.2-apache

# Install PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libzip-dev \
    unzip \
    dos2unix \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd mysqli pdo pdo_mysql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite and set PHP output buffering + upload limits
RUN a2enmod rewrite
RUN echo "output_buffering = On" >> /usr/local/etc/php/php.ini-production \
    && echo "upload_max_filesize = 10M" >> /usr/local/etc/php/php.ini-production \
    && echo "post_max_size = 12M" >> /usr/local/etc/php/php.ini-production \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/php.ini-production \
    && echo "date.timezone = Asia/Manila" >> /usr/local/etc/php/php.ini-production \
    && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# Set working directory
WORKDIR /var/www/html

# Copy all project files
COPY . /var/www/html/

# Write config.php from environment variables at container startup
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN dos2unix /usr/local/bin/docker-entrypoint.sh && chmod +x /usr/local/bin/docker-entrypoint.sh

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# Apache config — set document root to /customer, allow .htaccess overrides
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html/customer\n\
    DirectoryIndex index.php index.html\n\
    Alias /customer/images /var/www/html/customer/images\n\
    Alias /images /var/www/html/images\n\
    Alias /admin /var/www/html/admin\n\
    Alias /includes /var/www/html/includes\n\
    <Directory /var/www/html>\n\
        Options FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    <Directory /var/www/html/customer>\n\
        Options FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    <Directory /var/www/html/customer/images>\n\
        Options FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    <Directory /var/www/html/images>\n\
        Options FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    <Directory /var/www/html/admin>\n\
        Options FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    <Directory /var/www/html/includes>\n\
        Options FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
