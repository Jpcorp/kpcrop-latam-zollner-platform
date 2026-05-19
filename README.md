# kpcrop-latam-zollner-platform
Conecta cualquier CMS (WP, Presta, Shopify, etc.) con Bsale. Componentes: plugins por CMS (sync manual) + servicio central (demonio) para sync automático, cola, reintentos y licencias.

## Estructura
- `/packages/bot-miki` - Sincronizador automático (demonio)
- `/packages/cms-*` - Plugins para cada CMS
- `/packages/shared` - Código y modelos comunes
- `/docs` - Documentación global (arquitectura, API, ADRs)

kpcrop-latam-zollner-platform/
├── .github/               # workflows CI/CD condicionales
├── docs/                  # Documentación global (arquitectura, API demonio, ADRs)
│   ├── architecture/
│   ├── api-contracts/
│   └── licensing/
├── packages/
│   ├── bot-miki/           # El sincronizador automático (Node/Go/Python)
│   ├── cms-wordpress/
│   ├── cms-prestashop/
│   ├── cms-shopify/
│   ├── cms-woocommerce/
│   ├── cms-magento/
│   ├── cms-jumpseller/
│   └── shared/            # Código común (validación de licencias, modelos, etc.)
└── docker-compose.yml     # Entorno de desarrollo integrado

## Primeros pasos
Ver [documentación](/docs) para desarrollo local.