FROM php:8.2-apache

# Activer mod_rewrite pour Apache
RUN a2enmod rewrite

# Installer les extensions PHP nécessaires
RUN docker-php-ext-install pdo pdo_mysql

# Configurer le DocumentRoot vers /var/www/html/public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copier le projet dans le conteneur
COPY . /var/www/html

# Définir les permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Installer les dépendances PHP
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader

# Exposer le port 80
EXPOSE 80

# Démarrer Apache
CMD ["apache2-foreground"]