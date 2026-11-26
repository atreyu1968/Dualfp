#!/usr/bin/env bash
# Confía en el protocolo exterior exclusivamente desde un conector autorizado.
set -Eeuo pipefail
umask 077
[[ $EUID -eq 0 ]] || { echo 'Ejecute como root.' >&2; exit 1; }
[[ $# -eq 2 ]] || { echo 'Uso: bash configure-tunnel.sh DOMINIO IP_REAL_DEL_CONECTOR' >&2; exit 1; }
host="$1"; ip="$2"
[[ "$host" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*\.[a-zA-Z0-9]+$ ]] || { echo 'Dominio inválido.' >&2; exit 1; }
php -r 'exit(filter_var($argv[1],FILTER_VALIDATE_IP)?0:1);' "$ip" || { echo 'Indique una IP exacta, no una red ni un rango.' >&2; exit 1; }
[[ -f /etc/fpdual/config.php && -f /var/www/fpdual/index.php ]] || { echo 'Instalación FP Dual no encontrada.' >&2; exit 1; }
exec 8>/run/lock/fpdual-update.lock
flock -n 8 || { echo 'Hay otra operación de mantenimiento.' >&2; exit 1; }
apache2ctl configtest
systemctl is-active --quiet apache2 || { echo 'Apache no está activo.' >&2; exit 1; }
conf=/etc/apache2/conf-available/fpdual-trusted-tunnel.conf
tmp=$(mktemp -d)
old=0; enabled=0
if [[ -f "$conf" ]]; then cp -a "$conf" "$tmp/old"; old=1; fi
if [[ -e /etc/apache2/conf-enabled/fpdual-trusted-tunnel.conf ]]; then enabled=1; fi
cleanup(){
 rc=$?
 trap - EXIT
 if (( rc != 0 )); then
   set +e
   if (( old )); then cp -a "$tmp/old" "$conf"; else rm -f "$conf"; fi
   if (( enabled )); then a2enconf fpdual-trusted-tunnel; else a2disconf fpdual-trusted-tunnel; fi
   if apache2ctl configtest; then systemctl reload apache2; fi
 fi
 rm -rf "$tmp"
 exit "$rc"
}
trap cleanup EXIT
cat > "$tmp/new" <<EOF
# FP Dual: solo el peer TCP autorizado puede indicar HTTPS exterior.
# No confiar en X-Forwarded-Proto desde clientes directos.
SetEnvIfExpr "%{CONN_REMOTE_ADDR} == '$ip' && req('X-Forwarded-Proto') == 'https' && req('Host') == '$host'" HTTPS=on
EOF
install -m 0644 "$tmp/new" "$conf"
a2enmod setenvif
a2enconf fpdual-trusted-tunnel
apache2ctl configtest
systemctl reload apache2
echo "Configuración aplicada: https://$host (conector $ip)."
echo 'En Cloudflare active la redirección HTTP a HTTPS. Mantenga restringido el acceso al origen.'
echo 'Si el conector está en otro equipo, proteja también el tramo con TLS o una red privada aislada.'
