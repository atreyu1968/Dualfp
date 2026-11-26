#!/usr/bin/env bash
# Fotografías de stock Pexels. Se descargan al servidor, no al navegador.
set -euo pipefail
dest=/var/www/fpdual
[[ $EUID -eq 0 && -f "$dest/index.php" ]] || { echo 'Ejecute como root en el contenedor FP Dual.' >&2; exit 1; }
for cmd in curl php; do command -v "$cmd" >/dev/null || { echo "Falta $cmd" >&2; exit 1; }; done
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
valid(){
 [[ -s "$1" ]] && php -r '$i=@getimagesize($argv[1]);exit($i&&$i[2]===IMAGETYPE_JPEG&&$i[0]>=100&&$i[1]>=100&&$i[0]<=10000&&$i[1]<=10000?0:1);' "$1"
}
fetch(){
 local name="$1" id="$2" base url
 if valid "$dest/$name"; then echo "OK: $name ya instalada"; return 0; fi
 base="https://images.pexels.com/photos/$id/pexels-photo-$id.jpeg"
 for url in "$base?auto=compress&cs=tinysrgb&w=1260" "$base?auto=compress&cs=tinysrgb&w=900" "$base?auto=compress&dpr=1&h=750&w=1260" "$base"; do
   rm -f -- "$tmp/$name"
   if curl --proto '=https' --proto-redir '=https' --fail --silent --show-error --location --connect-timeout 5 --max-time 18 --max-filesize 10000000 --retry 1 --retry-delay 1 "$url" -o "$tmp/$name" && valid "$tmp/$name"; then
     install -o root -g root -m 0644 "$tmp/$name" "$dest/$name"
     echo "OK: fotografía instalada $name ($(stat -c %s "$dest/$name") bytes)"
     return 0
   fi
 done
 echo "ERROR: no se pudo instalar $name. Compruebe DNS y salida HTTPS del contenedor. Fuente: $base" >&2
 return 1
}
missing=0
fetch landing-hero.jpg 5439394 || missing=1
fetch landing-empleo.jpg 8424941 || missing=1
if (( missing )); then
 echo 'FOTOGRAFÍAS INCOMPLETAS. La aplicación conserva el diseño de respaldo; vuelva a ejecutar este script cuando haya acceso a Pexels.' >&2
 exit 1
fi
echo 'FOTOGRAFÍAS INSTALADAS: 2/2.'
ls -lh "$dest/landing-hero.jpg" "$dest/landing-empleo.jpg"
