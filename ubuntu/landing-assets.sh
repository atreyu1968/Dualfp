#!/usr/bin/env bash
# Fotografías de stock Pexels. Descarga opcional durante el despliegue; nunca desde el navegador.
set -euo pipefail
dest=/var/www/fpdual
[[ $EUID -eq 0 && -d "$dest" ]] || exit 1
command -v curl >/dev/null || { echo 'No hay curl: se conserva el diseño sin fotografías.'; exit 0; }
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
fetch(){
 local name="$1" url="$2"
 if [[ -s "$dest/$name" ]] && php -r '$i=@getimagesize($argv[1]);exit($i&&$i[2]===IMAGETYPE_JPEG?0:1);' "$dest/$name"; then return 0; fi
 if curl --proto '=https' --proto-redir '=https' --fail --silent --show-error --location --connect-timeout 4 --max-time 12 --max-filesize 3000000 "$url" -o "$tmp/$name" && php -r '$i=@getimagesize($argv[1]);exit($i&&$i[2]===IMAGETYPE_JPEG&&$i[0]>100&&$i[1]>100&&$i[0]<=6000&&$i[1]<=6000?0:1);' "$tmp/$name"; then
   install -o root -g root -m 0644 "$tmp/$name" "$dest/$name"
   echo "Fotografía instalada: $name"
 else
   echo "Aviso: no se pudo descargar $name; se conserva el diseño de respaldo." >&2
 fi
}
fetch landing-hero.jpg 'https://images.pexels.com/photos/5439394/pexels-photo-5439394.jpeg?auto=compress&dpr=1&h=750&w=1260'
fetch landing-empleo.jpg 'https://images.pexels.com/photos/8424941/pexels-photo-8424941.jpeg?auto=compress&dpr=1&h=750&w=1260'
