# The server on the official PHP image, with a variant of the extension
# baseline installed. Debian (bookworm) rather than Alpine: glibc, so the
# Oracle add-on's Instant Client works.
#
#   docker build -f packaging/docker/Dockerfile --build-arg PHP_VERSION=8.3 \
#     --build-arg VARIANT=standard -t qbix-webserver:php8.3-standard .
#
# VARIANT is mini, lite, standard or full (docs/binaries.md); WITH_ADDONS=1
# adds the Oracle and Firebird drivers and ODBC drivers to standard and full
# (docs/docker.md). Published as ghcr.io/se7enxweb/exponential-velocity.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-bookworm

ARG VARIANT=standard
ARG WITH_ADDONS=auto
ENV QBIX_HOME=/usr/share/qbix-webserver \
    QBIX_VARIANT=${VARIANT}

COPY . /usr/share/qbix-webserver
RUN set -eux; \
    addons="$WITH_ADDONS"; \
    if [ "$addons" = auto ]; then case "$VARIANT" in standard|full) addons=1 ;; *) addons=0 ;; esac; fi; \
    bash /usr/share/qbix-webserver/packaging/docker/install-extensions.sh "$VARIANT" "$addons"; \
    for t in qbixserver qbixctl qbixconsole; do \
      install -m 0755 "/usr/share/qbix-webserver/packaging/bin/$t" "/usr/local/bin/$t"; \
    done; \
    mkdir -p /app /etc/qbix; \
    cp -a /usr/share/qbix-webserver/web/. /app/; \
    # The image's baseline, checked by the engine itself: fails the build if
    # a required extension is missing.
    qbixctl ext:check --variant="$VARIANT"

WORKDIR /app
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["php", "/usr/share/qbix-webserver/packaging/docker/healthcheck.php"]
CMD ["qbixserver", "--root=/app", "--port=8080"]
