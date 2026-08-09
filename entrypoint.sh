#!/bin/sh
# entrypoint.sh — Inicia worker loop (background) + PHP server (foreground)

# Iniciar worker en background como loop infinito
php /app/www/public/worker.php >> /config/worker.log 2>&1 &

# Iniciar el servidor PHP en foreground (mantiene el contenedor vivo)
exec php -S 0.0.0.0:80 -t /app/www/public
