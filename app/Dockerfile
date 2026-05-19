FROM php:8.3-fpm-bookworm

LABEL maintainer="juwon.lee@u-infra.com"
LABEL purpose="Cortex CaaS test workload - intentionally vulnerable"

# nginx + tools an attacker typically reaches for once they get RCE.
# Installing them on purpose so Cortex CaaS has clear spawn targets.
RUN apt-get update && apt-get install -y --no-install-recommends \
      nginx supervisor \
      curl wget netcat-openbsd bash \
      sqlite3 procps net-tools iputils-ping \
      ca-certificates \
  && rm -rf /var/lib/apt/lists/* \
  && mkdir -p /run/php /var/log/supervisor

RUN rm -f /etc/nginx/sites-enabled/default
COPY nginx.conf       /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisor/conf.d/services.conf

RUN mkdir -p /var/www/html /var/www/html/uploads /var/www/html/vuln
COPY index.php  /var/www/html/index.php
COPY vuln/      /var/www/html/vuln/
RUN  chown -R www-data:www-data /var/www \
  && chmod 0777 /var/www/html/uploads

# Decoy "secret" files - obviously fake values; presence is what matters.
RUN mkdir -p /etc/secrets /root/.ssh \
  && printf 'AWS_ACCESS_KEY_ID=AKIA__DECOY_NOT_REAL__\nAWS_SECRET_ACCESS_KEY=decoy0000000000000000000000000000000000\n' \
       > /etc/secrets/aws.env \
  && printf 'cortex-test-decoy-api-key-NOT-REAL\n' > /etc/secrets/api_key.txt \
  && printf -- '-----BEGIN OPENSSH PRIVATE KEY-----\nDECOY_KEY_DO_NOT_USE\n-----END OPENSSH PRIVATE KEY-----\n' \
       > /root/.ssh/id_rsa \
  && chmod 600 /etc/secrets/* /root/.ssh/id_rsa

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD curl -fsS http://localhost/health > /dev/null || exit 1

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/services.conf"]
