FROM ghcr.io/benzine-framework/php:cli-8.1
LABEL maintainer="Matthew Baggett <matthew@baggett.me>" \
      org.label-schema.vcs-url="https://github.com/pushtolive/client" \
      org.opencontainers.image.source="https://github.com/pushtolive/client"
COPY . /app
RUN composer install && \
    chmod +x /app/client /app/entrypoint.sh
ENTRYPOINT /app/entrypoint.sh