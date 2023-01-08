FROM ghcr.io/benzine-framework/php:cli-8.1
LABEL maintainer="Matthew Baggett <matthew@baggett.me>" \
      org.label-schema.vcs-url="https://github.com/pushtolive/client" \
      org.opencontainers.image.source="https://github.com/pushtolive/client"
COPY . /app
RUN apt-get -qq update && \
    apt-get -yqq install --no-install-recommends \
        xz-utils \
      && \
    apt-get autoremove -yqq && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /var/lib/dpkg/status.old /var/cache/debconf/templates.dat /var/log/dpkg.log /var/log/lastlog /var/log/apt/*.log
RUN composer install && \
    chmod +x /app/client /app/entrypoint.sh && \
    chmod 0777 /tmp
ENTRYPOINT /app/entrypoint.sh