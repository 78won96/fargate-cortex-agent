FROM distributions.traps.paloaltonetworks.com/agent-docker-pull/265efe9809644aa3a742e8786bdde605/method:9.2.0.86 AS cortex_agent

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

# --- Cortex Agent ---
USER root

COPY --from=cortex_agent /opt/traps /opt/traps
COPY --from=cortex_agent /etc/panw-init /etc/panw-init
COPY --from=cortex_agent /var/log/traps-install.log /var/log/traps-install.log
COPY --from=cortex_agent /etc/ssl/certs/ /etc/ssl/certs/
COPY --from=cortex_agent /usr/share/ca-certificates/ /usr/share/ca-certificates/

ENV XDR_CA_CERTS_LOCATION="/etc/ssl/certs/ca-certificates.crt" \
  XDR_INIT_ROOT_DIR="/etc/panw-init" \
  XDR_DISTRIBUTION_ID="265efe9809644aa3a742e8786bdde605" \
  XDR_CONTAINER_MODE="embeddedcontainer" \
  XDR_DISTRIBUTION_SERVER="https://distributions.traps.paloaltonetworks.com"

RUN mkdir -p /usr/lib/ssl/certs && \
  ln -sf /etc/ssl/certs/ca-certificates.crt /usr/lib/ssl/cert.pem && \
  ln -sf /etc/ssl/certs /usr/lib/ssl/certs

RUN ln -sf /opt/traps/bin/initd /initd
RUN mkdir -p /etc/panw && echo '["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/services.conf"]' > /etc/panw/dypd_entry && chmod 666 /etc/panw/dypd_entry

ENTRYPOINT ["/initd"]