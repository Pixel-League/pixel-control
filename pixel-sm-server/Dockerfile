# Keep Ubuntu 20.04 to stay on PHP 7.4-compatible runtime for ManiaControl.
FROM ubuntu:20.04

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        default-mysql-client \
        gettext-base \
        php \
        php-curl \
        php-mbstring \
        php-mysql \
        php-xml \
        php-zip \
        php-xmlrpc \
        xmlstarlet \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/pixel-sm

COPY scripts /opt/pixel-sm/scripts
COPY templates /opt/pixel-sm/templates

RUN chmod +x /opt/pixel-sm/scripts/*.sh

CMD ["/opt/pixel-sm/scripts/bootstrap.sh"]
