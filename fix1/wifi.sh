#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "Avvio con privilegi amministrativi..."
    exec sudo sh "$0" "$@"
fi

echo "========================================"
echo " TubeTV - Riparazione Wi-Fi Debian 13"
echo "========================================"

if [ ! -r /etc/os-release ]; then
    echo "ERRORE: sistema Linux non riconosciuto."
    exit 1
fi

. /etc/os-release
if [ "${ID:-}" != "debian" ]; then
    echo "ERRORE: questo fix e' destinato a Debian."
    exit 1
fi

echo "[1/5] Disabilito le vecchie sorgenti DVD..."
if [ -f /etc/apt/sources.list ]; then
    cp -a /etc/apt/sources.list /etc/apt/sources.list.tubetv-backup
    sed -i '/cdrom:/d' /etc/apt/sources.list
fi

for source_file in /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources; do
    [ -f "$source_file" ] || continue
    case "$source_file" in
        */tubetv-online.sources) continue ;;
    esac
    if grep -qi 'cdrom:' "$source_file"; then
        mv "$source_file" "$source_file.tubetv-disabled"
    fi
done

echo "[2/5] Configuro i repository ufficiali Debian 13..."
install -d -m 0755 /etc/apt/sources.list.d
cat > /etc/apt/sources.list.d/tubetv-online.sources <<'EOF'
Types: deb
URIs: https://deb.debian.org/debian
Suites: trixie trixie-updates
Components: main non-free-firmware
Signed-By: /usr/share/keyrings/debian-archive-keyring.gpg

Types: deb
URIs: https://security.debian.org/debian-security
Suites: trixie-security
Components: main non-free-firmware
Signed-By: /usr/share/keyrings/debian-archive-keyring.gpg
EOF

echo "[3/5] Aggiorno l'elenco dei pacchetti..."
apt-get update

echo "[4/5] Installo firmware Realtek, rete e accesso SSH..."
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    firmware-realtek \
    rfkill \
    network-manager \
    network-manager-gnome \
    wpasupplicant \
    openssh-server \
    ca-certificates \
    curl

systemctl enable NetworkManager ssh
rfkill unblock all || true
nmcli radio wifi on || true
systemctl restart NetworkManager || true

echo "[5/5] Fix completato. Il computer si riavvia tra 10 secondi."
echo "Lascia collegati SSD e iPhone durante il riavvio."
sync
sleep 10
systemctl reboot
