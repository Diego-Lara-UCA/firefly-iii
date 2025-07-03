#!/bin/bash

# Configurar puerto dinámico de Render
export PORT=${PORT:-8080}

# Ejecutar migraciones
php artisan migrate --force

# Iniciar servidor
php artisan serve --host=0.0.0.0 --port=$PORT