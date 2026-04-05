#!/bin/sh
set -e

# certifi-Bundle als Basis (wird von aiohttp/edge-tts verwendet)
CERTIFI_BUNDLE=$(python -c "import certifi; print(certifi.where())")

# Custom-Zertifikate (z.B. Zscaler) direkt ins certifi-Bundle UND ins System-Bundle anhängen
if [ -d /custom-certs ]; then
    for cert in /custom-certs/*.pem /custom-certs/*.crt; do
        [ -f "$cert" ] || continue
        echo "  + $(basename "$cert")"
        # An certifi-Bundle anhängen (wird von aiohttp genutzt)
        cat "$cert" >> "$CERTIFI_BUNDLE"
        # An System-Bundle anhängen (wird von ssl.create_default_context genutzt)
        cat "$cert" >> /etc/ssl/certs/ca-certificates.crt
    done
    echo "CA-Bundles aktualisiert (certifi + system)."
fi

# Originalkommando starten
exec python /app/server.py "$@"
