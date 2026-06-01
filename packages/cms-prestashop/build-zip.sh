#!/bin/bash
# Genera el ZIP instalable del módulo synkrop para PrestaShop.
# Uso: bash build-zip.sh [version]
# Ejemplo: bash build-zip.sh 1.0.1
#
# El ZIP resultante puede instalarse desde:
#   PrestaShop Backoffice > Módulos > Subir un módulo

set -e

VERSION="${1:-1.0.0}"
OUT="synkrop-v${VERSION}.zip"
MODULE="synkrop"

# Leer version desde synkrop.php si no se pasa argumento
if [ -z "$1" ]; then
    VERSION=$(grep "\$this->version" ${MODULE}/synkrop.php | grep -o "'[^']*'" | tr -d "'")
    OUT="synkrop-v${VERSION}.zip"
fi

echo "Generando ${OUT}..."

# Eliminar ZIP anterior si existe
rm -f "${OUT}"

python3 - <<EOF
import zipfile, os

module_dir = '${MODULE}'
zip_path   = '${OUT}'

exclude_paths = [
    os.path.join(module_dir, 'cli'),  # herramienta de dev, no va en distribución
]

with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk(module_dir):
        dirs[:] = [d for d in dirs if d not in ['__pycache__', '.git']]
        for file in files:
            filepath = os.path.join(root, file)
            if any(filepath.startswith(ex) for ex in exclude_paths):
                continue
            zf.write(filepath, filepath)
            print(f'  + {filepath}')

size = os.path.getsize(zip_path)
print(f'\n✅  {zip_path}  ({size/1024:.1f} KB)')
print(f'    Instalar desde: PrestaShop > Módulos > Subir un módulo')
EOF
