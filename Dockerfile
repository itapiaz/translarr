FROM php:8.2-cli-alpine

LABEL org.opencontainers.image.title="translarr" \
      org.opencontainers.image.description="AI-powered web UI to auto-translate subtitles from English to Spanish using DeepSeek API." \
      org.opencontainers.image.source="https://github.com/itapiaz/translarr"

# Dependencias: SQLite + crond (incluido en busybox-suid de Alpine)
RUN apk add --no-cache sqlite-dev busybox-suid
RUN docker-php-ext-install pdo pdo_sqlite

# Configuramos el directorio de trabajo
WORKDIR /app/www/public

# Copiamos el código de la aplicación DENTRO de la imagen
# (html/scratch/ queda excluido vía .dockerignore, se recrea vacío)
COPY html/ /app/www/public/
RUN mkdir -p /app/www/public/scratch

# Copiamos el script de entrada
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Exponemos el puerto 80
EXPOSE 80

# Usamos el entrypoint que lanza worker + PHP server
CMD ["/entrypoint.sh"]