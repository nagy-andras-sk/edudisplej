FROM php:8.2-apache

# Install required PHP extensions
RUN docker-php-ext-install pdo pdo_mysql && \
    apt-get update && \
    apt-get install -y \
        libcurl4-openssl-dev \
        supervisor \
        iputils-ping \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Install PHP extensions
RUN docker-php-ext-install curl

# Copy API files
COPY api/ /var/www/api/

# Copy supervisor configuration and scripts
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/upnp-manager.sh /docker/upnp-manager.sh

# Create necessary directories and set permissions
RUN mkdir -p /var/log/supervisor /tmp /docker && \
    chown -R www-data:www-data /var/www/api /tmp && \
    chmod 755 /var/www/api /docker/upnp-manager.sh

# Expose port 80
EXPOSE 80

# Start supervisor (manages Apache and heartbeat service)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
