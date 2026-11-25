#!/usr/bin/env bash
# Instalación desde el repositorio oficial de FP Dual Conecta A&G.
set -Eeuo pipefail
[[ $EUID -eq 0 ]] || { echo 'Ejecute con sudo.'; exit 1; }
SEED_ARGS=()
if (( $# )); then
  [[ $# -eq 2 && "$1" == --seed && -f "$2" ]] || { echo 'Uso: sudo bash install.sh [--seed /ruta/directorio.json]'; exit 1; }
  SEED_ARGS=(--seed "$(realpath -- "$2")")
fi
command -v git >/dev/null || { apt-get update; apt-get install -y git ca-certificates; }
umask 077
WORK=$(mktemp -d /tmp/fpdual-install.XXXXXX)
trap 'rm -rf -- "$WORK"' EXIT
git clone --depth 1 --branch main https://github.com/atreyu1968/Dualfp.git "$WORK/source"
cd "$WORK/source"
for f in index.php lib.php auth.php campaigns.php imports.php panels.php portal.php teacher.php app.js style.css seed.json ubuntu/install.sh ubuntu/validate-seed.php update.sh; do
  [[ -s "$f" ]] || { echo "Publicación incompleta: $f" >&2; exit 1; }
done
printf 'Instalando revisión %s\n' "$(git rev-parse HEAD)"
if [[ -e /etc/fpdual/config.php ]]; then
  if (( ${#SEED_ARGS[@]} )); then
    echo 'Instalacion existente: importe empresas desde el panel; no use --seed.' >&2
    exit 1
  fi
  echo 'Instalacion existente: utilice update.sh para actualizar con copia y mantenimiento.' >&2
  exit 1
fi
bash ubuntu/install.sh "${SEED_ARGS[@]}"
printf '%s\n' "$(git rev-parse HEAD)" >/usr/local/lib/fpdual/revision
