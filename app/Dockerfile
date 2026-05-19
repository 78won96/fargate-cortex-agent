FROM distributions.traps.paloaltonetworks.com/agent-docker-pull/805924c49d064c4eb08adb734ac4d116/method:9.2.0.86 AS cortex_agent

FROM nginx:1.27

LABEL maintainer="juwon.lee@u-infra.com"

RUN rm /etc/nginx/conf.d/default.conf
COPY nginx.conf /etc/nginx/conf.d/default.conf

RUN rm -rf /usr/share/nginx/html/*
COPY index.html /usr/share/nginx/html/index.html

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
  XDR_DISTRIBUTION_ID="805924c49d064c4eb08adb734ac4d116" \
  XDR_CONTAINER_MODE="embeddedcontainer" \
  XDR_DISTRIBUTION_SERVER="https://distributions.traps.paloaltonetworks.com"

RUN mkdir -p /usr/lib/ssl/certs && \
  ln -sf /etc/ssl/certs/ca-certificates.crt /usr/lib/ssl/cert.pem && \
  ln -sf /etc/ssl/certs /usr/lib/ssl/certs

RUN ln -sf /opt/traps/bin/initd /initd
RUN mkdir -p /etc/panw && echo '["nginx", "-g", "daemon off;"]' > /etc/panw/dypd_entry && chmod 666 /etc/panw/dypd_entry

ENTRYPOINT ["/initd"]
