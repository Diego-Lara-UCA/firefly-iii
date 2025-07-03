FROM fireflyiii/core:latest

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar tus archivos modificados de reportes
COPY resources/views/reports/ /var/www/html/resources/views/reports/

# Si tienes modificaciones en el backend (controladores, rutas, etc.)
# COPY app/ /var/www/html/app/
# COPY routes/ /var/www/html/routes/

# Copiar composer.json si has añadido dependencias
# COPY composer.json composer.lock /var/www/html/

# Instalar dependencias PHP adicionales si las tienes
# RUN composer install --no-dev --optimize-autoloader --no-interaction

# Si has modificado assets de frontend
# COPY package.json package-lock.json /var/www/html/
# RUN npm ci --only=production && npm run production

# Establecer permisos correctos
RUN chown -R www-data:www-data /var/www/html/resources/views/
RUN chown -R www-data:www-data /var/www/html/storage/
RUN chown -R www-data:www-data /var/www/html/bootstrap/cache/

# Limpiar caché si es necesario
RUN php artisan config:clear
RUN php artisan route:clear
RUN php artisan view:clear

# Exponer puerto
EXPOSE 8080

# Comando por defecto (heredado de la imagen base)
