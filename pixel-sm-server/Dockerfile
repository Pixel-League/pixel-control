# Keep Ubuntu 20.04 to stay on PHP 7.4-compatible runtime for ManiaControl.
FROM ubuntu:20.04

ARG DEBIAN_FRONTEND=noninteractive

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

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
        tini \
        xmlstarlet \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/pixel-sm

COPY scripts /opt/pixel-sm/scripts
COPY templates /opt/pixel-sm/templates

RUN chmod +x /opt/pixel-sm/scripts/*.sh

RUN test -x /opt/pixel-sm/scripts/bootstrap.sh \
    && test -x /opt/pixel-sm/scripts/healthcheck.sh

ENTRYPOINT ["/usr/bin/tini", "--"]

CMD ["/opt/pixel-sm/scripts/bootstrap.sh"]
