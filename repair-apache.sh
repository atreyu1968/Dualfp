#!/usr/bin/env bash
# Reparación de la selección del sitio Apache. No modifica datos ni TLS.
set -Eeuo pipefail
[[ $EUID -eq 0 ]] || { echo 'Ejecute como root.' >&2; exit 1; }
[[ -f /var/www/fpdual/index.php && -f /etc/fpdual/config.php && -f /etc/apache2/sites-available/fpdual.conf ]] || { echo 'Instalación incompleta. Compruebe los archivos y termine la instalación.' >&2; exit 1; }
apache2ctl configtest
enabled=/etc/apache2/sites-enabled
had_default=0
had_fpdual=0
[[ -e "$enabled/000-default.conf" ]] && had_default=1
[[ -e "$enabled/fpdual.conf" ]] && had_fpdual=1
other=0
shopt -s nullglob
for f in "$enabled"/*.conf; do
  case "$(basename "$f")" in
    000-default.conf|fpdual.conf|fpdual-le-ssl.conf) ;;
    *) other=1;;
  esac
done
rollback(){
  rc=$?
  trap - EXIT
  if (( rc != 0 )); then
    echo 'Error: se intenta recuperar la selección anterior de sitios.' >&2
    set +e
    if (( had_default )); then a2ensite 000-default.conf; else a2dissite 000-default.conf; fi
    if (( had_fpdual )); then a2ensite fpdual.conf; else a2dissite fpdual.conf; fi
    if apache2ctl configtest && systemctl is-active --quiet apache2; then systemctl reload apache2; fi
  fi
  exit "$rc"
}
trap rollback EXIT
a2ensite fpdual.conf
if (( had_default && !other )); then
  # Solo deshabilitar la página estándar de Ubuntu, no un sitio personalizado.
  default=/etc/apache2/sites-available/000-default.conf
  if grep -Eq '^[[:space:]]*DocumentRoot[[:space:]]+/var/www/html[[:space:]]*$' "$default" && ! grep -Eiq '^[[:space:]]*(ServerName|ServerAlias|ProxyPass|Alias|Redirect|RewriteRule)[[:space:]]' "$default"; then
    a2dissite 000-default.conf
  else
    echo 'El sitio predeterminado parece personalizado; se conserva. Acceda por el dominio configurado.'
  fi
elif (( other )); then
  echo 'Hay otros sitios habilitados: se conservan. Acceda por el dominio configurado para FP Dual.'
fi
apache2ctl configtest
systemctl enable --now apache2
systemctl reload apache2
trap - EXIT
apache2ctl -S
echo 'Selección de sitio completada. Acceda por HTTPS al dominio configurado.'
echo 'Una respuesta HTTP «Se requiere HTTPS» de FP Dual confirma que el virtual host sirve la aplicación; no desactive HTTPS.'
