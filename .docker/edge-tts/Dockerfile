FROM travisvn/openai-edge-tts

# certifi installieren (CA-Bundle für Python-Bibliotheken)
RUN pip install --no-cache-dir certifi

# System-CA-Bundle aus certifi initialisieren
RUN python -c "import certifi; import shutil; shutil.copy(certifi.where(), '/etc/ssl/certs/ca-certificates.crt')"

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt
ENV REQUESTS_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt

ENTRYPOINT ["/entrypoint.sh"]
