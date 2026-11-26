#!/usr/bin/env bash
# FP Dual Conecta A&G: actualizacion controlada de la instalacion 3.6.
set -Eeuo pipefail
umask 077
[[ $EUID -eq 0 ]] || { echo 'Ejecute con sudo.' >&2; exit 1; }
[[ $# -eq 0 ]] || { echo 'Uso: sudo bash update.sh' >&2; exit 1; }
[[ -f /etc/fpdual/config.php && -f /var/www/fpdual/index.php && -d /var/lib/fpdual ]] || { echo 'No hay una instalacion existente. Use install.sh.' >&2; exit 1; }
exec 8>/run/lock/fpdual-update.lock
flock -n 8 || { echo 'Ya hay una actualizacion en curso.' >&2; exit 1; }
if ! command -v git >/dev/null; then
  apt-get update
  apt-get install -y git ca-certificates
fi
WORK=$(mktemp -d /tmp/fpdual-update.XXXXXX)
BACKUP=''
APACHE=0
TIMER=0
MAINT=0
MUTATING=0
cleanup() {
  rc=$?
  trap - EXIT
  set +e
  if (( MAINT )); then
    if (( rc != 0 && MUTATING )); then
      echo "Error en actualizacion. Restaurando archivos de aplicacion desde $BACKUP" >&2
      # Puede haber arrancado Apache antes de fallar la activacion del temporizador.
      if ! systemctl stop fpdual-worker.timer fpdual-worker.service apache2; then
        echo 'No se pudieron detener los servicios; no se sustituira codigo en caliente. Restaure desde la copia en mantenimiento.' >&2
        rm -rf -- "$WORK"
        exit 1
      fi
      tar -xzf "$BACKUP" -C / var/www/fpdual usr/local/lib/fpdual etc/systemd/system/fpdual-worker.service etc/systemd/system/fpdual-worker.timer
      if (( $? != 0 )); then
        echo 'Fallo de restauracion. Servicios detenidos; revise la copia privada.' >&2
        rm -rf -- "$WORK"
        exit 1
      fi
    fi
    systemctl daemon-reload || rc=1
    if (( APACHE )); then systemctl start apache2 || rc=1; fi
    if (( TIMER )); then systemctl start fpdual-worker.timer || rc=1; fi
  fi
  rm -rf -- "$WORK"
  if (( rc != 0 )); then echo "Actualizacion no completada. Copia: ${BACKUP:-no creada}" >&2; fi
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
git clone --depth 1 --branch main https://github.com/atreyu1968/Dualfp.git "$WORK/source"
cd "$WORK/source"
REV=$(git rev-parse HEAD)
FILES=(index.php lib.php auth.php inbox.php prospecting.php mail_composer.php campaigns.php imports.php panels.php portal.php teacher.php app.js style.css favicon.svg seed.json .htaccess)
for f in "${FILES[@]}" ubuntu/worker.php ubuntu/fpdual-worker.service ubuntu/fpdual-worker.timer; do
  [[ -s "$f" && ! -L "$f" ]] || { echo "Archivo ausente o no valido: $f" >&2; exit 1; }
done
for f in *.php ubuntu/*.php; do php -l "$f" >/dev/null; done
apache2ctl configtest
for f in /usr/local/lib/fpdual/worker.php /etc/systemd/system/fpdual-worker.service /etc/systemd/system/fpdual-worker.timer; do
  [[ -f "$f" ]] || { echo "Instalacion previa incompleta: $f" >&2; exit 1; }
done
printf 'Revision descargada: %s\n' "$REV"
if [[ -t 0 ]]; then
  read -r -p 'Se detendra Apache y el trabajador FEOE temporalmente. Continuar (s/N)? ' answer
  [[ "$answer" =~ ^[sS]$ ]] || exit 0
else
  echo 'Se requiere una terminal interactiva para confirmar.' >&2
  exit 1
fi
systemctl is-active --quiet apache2 && APACHE=1
systemctl is-active --quiet fpdual-worker.timer && TIMER=1
MAINT=1
systemctl stop fpdual-worker.timer
# No interrumpir un envio en curso: esperar al trabajador antes de copiar.
for ((i=0; i<60; i++)); do
  state=$(systemctl show -p ActiveState --value fpdual-worker.service)
  [[ "$state" != active && "$state" != activating && "$state" != deactivating ]] && break
  sleep 1
done
if [[ "$state" == active || "$state" == activating || "$state" == deactivating ]]; then
  echo 'El trabajador sigue ocupado; reintente mas tarde.' >&2
  exit 1
fi
systemctl stop apache2
install -d -o root -g root -m 0700 /var/backups/fpdual
BACKUP=$(mktemp /var/backups/fpdual/update-XXXXXXXX.tar.gz)
chmod 0600 "$BACKUP"
tar -czf "$BACKUP" -C / etc/fpdual var/lib/fpdual var/www/fpdual usr/local/lib/fpdual etc/systemd/system/fpdual-worker.service etc/systemd/system/fpdual-worker.timer
tar -tzf "$BACKUP" >/dev/null
echo "Copia privada: $BACKUP"
MUTATING=1
for f in "${FILES[@]}"; do install -o root -g root -m 0644 "$f" /var/www/fpdual/; done
install -o root -g root -m 0644 ubuntu/worker.php /usr/local/lib/fpdual/worker.php
install -o root -g root -m 0644 ubuntu/fpdual-worker.service ubuntu/fpdual-worker.timer /etc/systemd/system/
for f in /var/www/fpdual/*.php /usr/local/lib/fpdual/worker.php; do php -l "$f" >/dev/null; done
apache2ctl configtest
systemctl daemon-reload
# No se modifican datos ni configuracion privada. Se restaura el estado anterior de servicios.
if (( APACHE )); then systemctl start apache2; fi
if (( TIMER )); then systemctl start fpdual-worker.timer; fi
printf '%s\n' "$REV" >/usr/local/lib/fpdual/revision
MUTATING=0
MAINT=0
echo "Actualizacion completada: $REV"
echo 'Compruebe el acceso HTTPS y el temporizador. No se han cambiado credenciales ni datos.'
