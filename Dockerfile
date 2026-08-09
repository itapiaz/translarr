FROM php:8.2-cli-alpine

# Dependencias: SQLite + crond (incluido en busybox-suid de Alpine)
RUN apk add --no-cache sqlite-dev busybox-suid
RUN docker-php-ext-install pdo pdo_sqlite

# Configuramos el directorio de trabajo
WORKDIR /app/www/public

# Copiamos el script de entrada
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Exponemos el puerto 80
EXPOSE 80

# Usamos el entrypoint que lanza crond + PHP server
CMD ["/entrypoint.sh"]