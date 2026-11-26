#!/usr/bin/env bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Ejecute con sudo.'; exit 1; }
SEED=""
if (( $# )); then
  [[ $# -eq 2 && "$1" == --seed && -f "$2" ]] || { echo 'Uso: sudo bash ubuntu/install.sh [--seed /ruta/directorio.json]'; exit 1; }
  SEED="$(realpath -- "$2")"
fi
exec 8>/run/lock/fpdual-update.lock
flock -n 8 || { echo 'Ya hay una instalacion o actualizacion en curso.' >&2; exit 1; }
if [[ -f /etc/fpdual/config.php && -f /var/www/fpdual/index.php && -f /usr/local/lib/fpdual/worker.php && -f /etc/systemd/system/fpdual-worker.service && -f /etc/systemd/system/fpdual-worker.timer && -f /etc/apache2/sites-available/fpdual.conf ]]; then
  echo 'Instalacion existente: use el actualizador update.sh.' >&2; exit 1
fi
cd "$(dirname "$0")/.."
apt-get update
apt-get install -y apache2 libapache2-mod-php php-cli php-curl php-common ca-certificates unzip openssl
for f in *.php ubuntu/*.php; do php -l "$f" >/dev/null; done
php -r 'foreach (["curl","fileinfo","iconv","openssl","session"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR,"Extension PHP ausente: $ext\n"); exit(1); } }'
install -d -m 0755 /var/www/fpdual
install -d -o www-data -g www-data -m 0700 /var/lib/fpdual
install -d -o root -g www-data -m 0750 /etc/fpdual
if [[ -e /etc/fpdual/config.php ]]; then
  install -d -o root -g root -m 0700 /var/backups/fpdual
  if [[ ! -e /var/lib/fpdual/lock ]]; then
    runuser -u www-data -- touch /var/lib/fpdual/lock
  fi
  exec 9<>/var/lib/fpdual/lock
  flock -x -w 60 9 || { echo 'No se ha podido obtener el bloqueo de datos para la copia de seguridad.' >&2; exit 1; }
  BACKUP=$(mktemp /var/backups/fpdual/pre-actualizacion-XXXXXXXX.tar.gz)
  chmod 0600 "$BACKUP"
  tar -czf "$BACKUP" -C / etc/fpdual var/lib/fpdual
  tar -tzf "$BACKUP" >/dev/null
  flock -u 9
  exec 9>&-
  printf 'Copia privada de seguridad: %s\n' "$BACKUP"
fi
if [[ -n "$SEED" ]]; then
  if [[ -e /var/lib/fpdual/data.json || -e /var/lib/fpdual/seed.json ]]; then
    echo 'Ya existen datos o una carga privada; no se sustituirán. Use el importador del panel para añadir empresas.'
  else
    php ubuntu/validate-seed.php "$SEED"
    install -o www-data -g www-data -m 0600 "$SEED" /var/lib/fpdual/seed.json
  fi
fi
if [[ ! -e /etc/fpdual/config.php ]]; then
  echo 'Creación de la cuenta inicial de administrador (no se enviará correo todavía).'
  read -r -p 'Correo del administrador: ' ADMIN_EMAIL </dev/tty
  while true; do
    read -rs -p 'Contraseña (14–72 bytes): ' PW </dev/tty; echo
    read -rs -p 'Repita la contraseña: ' PW2 </dev/tty; echo
    if [[ "$PW" == "$PW2" ]] && (( $(printf '%s' "$PW" | wc -c) >= 14 && $(printf '%s' "$PW" | wc -c) <= 72 )); then break; fi
    echo 'Las contraseñas no coinciden o la longitud es incorrecta.'
  done
  printf '%s\n%s\n' "$ADMIN_EMAIL" "$PW" | php ubuntu/bootstrap.php
  unset PW PW2 ADMIN_EMAIL
else
  echo 'Instalación existente: se mantienen la cuenta, las credenciales y los datos.'
fi
for file in index.php lib.php auth.php inbox.php prospecting.php mail_composer.php campaigns.php imports.php panels.php portal.php teacher.php app.js style.css favicon.svg seed.json .htaccess; do install -o root -g root -m 0644 "$file" /var/www/fpdual/; done
if [[ ! -e /etc/apache2/sites-available/fpdual.conf ]]; then
  read -r -p 'Dominio del virtual host (sin https://): ' HOST </dev/tty
  [[ "$HOST" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*[A-Za-z0-9]$ ]] || { echo 'Dominio inválido. Ejecute de nuevo el instalador.'; exit 1; }
  sed "s/empresas.example.invalid/$HOST/g" ubuntu/fpdual.conf > /etc/apache2/sites-available/fpdual.conf
  chmod 0644 /etc/apache2/sites-available/fpdual.conf
else
  echo 'Se mantiene la configuración Apache existente.'
  HOST=$(awk '$1=="ServerName" {print $2; exit}' /etc/apache2/sites-available/fpdual.conf)
fi
# En un servidor dedicado, FP Dual sustituye la página de bienvenida.
# Si hay otros sitios o una configuración personalizada, se conservan.
bash repair-apache.sh
if [[ ! -e /etc/apache2/sites-available/fpdual-le-ssl.conf && -n "$HOST" ]]; then
  read -r -p '¿Configurar ahora HTTPS con Let’s Encrypt? (s/N): ' TLS </dev/tty
  if [[ "$TLS" =~ ^[sS]$ ]]; then
    apt-get install -y certbot python3-certbot-apache
    certbot --apache -d "$HOST" --redirect
  fi
fi
install -d -m 0755 /usr/local/lib/fpdual
install -m 0644 ubuntu/worker.php /usr/local/lib/fpdual/worker.php
install -m 0644 ubuntu/fpdual-worker.service ubuntu/fpdual-worker.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now fpdual-worker.timer
cat <<'EOF' 
Instalación de archivos completada. No se han borrado datos existentes.
El acceso web exige HTTPS; si no se ha configurado aún, prepare certificado o proxy de confianza (consulte README.md).
Entre con la cuenta inicial y abra Configuración para indicar URL pública, Resend, identidad, ayudas y privacidad.
No es necesario editar config.php para la configuración ordinaria de la aplicación.
EOF
