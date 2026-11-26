#!/usr/bin/env bash
# Comprueba los recursos incluidos en Git. No necesita acceso a bancos de imágenes.
set -euo pipefail
source_dir=$(cd "$(dirname "$0")/.." && pwd)
case "$1" in
  --check-source) dir="$source_dir";;
  --check-installed) dir=/var/www/fpdual;;
  *) echo 'Uso: landing-assets.sh --check-source | --check-installed' >&2; exit 2;;
esac
count=0
for file in landing-hero-311.jpg landing-formacion-311.jpg landing-empleo-311.jpg landing-ayudas-311.jpg; do
  [[ -s "$dir/$file" && ! -L "$dir/$file" ]] || { echo "ERROR: falta $dir/$file" >&2; exit 1; }
  php -r '$i=@getimagesize($argv[1]);if(!$i||$i[2]!==IMAGETYPE_JPEG||$i[0]<100||$i[1]<100){fwrite(STDERR,"JPEG inválido: ".$argv[1].PHP_EOL);exit(1);}printf("OK: %s (%dx%d)\n",basename($argv[1]),$i[0],$i[1]);' "$dir/$file"
  if [[ "$dir" == /var/www/fpdual ]]; then cmp -s "$source_dir/$file" "$dir/$file" || { echo "ERROR: fotografía instalada distinta de la incluida en Git: $file" >&2; exit 1; }; fi
  count=$((count+1))
done
echo "FOTOGRAFÍAS VERIFICADAS: $count/4"
