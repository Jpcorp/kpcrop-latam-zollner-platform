# kpcrop-latam-zollner-platform
Conecta cualquier CMS (WP, Presta, Shopify, etc.) con Bsale. Componentes: plugins por CMS (sync manual) + servicio central (demonio) para sync automático, cola, reintentos y licencias.

## Estructura
- `/packages/bot-miki` - Sincronizador automático (demonio)
- `/packages/cms-*` - Plugins para cada CMS
- `/packages/shared` - Código y modelos comunes
- `/docs` - Documentación global (arquitectura, API, ADRs)

kpcrop-latam-zollner-platform/
├── .github/
│ └── workflows/ # CI/CD condicional
├── docs/
│ ├── architecture/ # Diagramas y decisiones técnicas
│ ├── api-contracts/ # Contratos OpenAPI del demonio
│ └── licensing/ # Gestión de licencias y flujos
├── packages/
│ ├── bot-miki/ # Sincronizador automático (demonio)
│ ├── cms-wordpress/
│ ├── cms-prestashop/
│ ├── cms-shopify/
│ ├── cms-woocommerce/
│ ├── cms-magento/
│ ├── cms-jumpseller/
│ └── shared/ # Código común (validación, modelos)
├── docker-compose.yml # Entorno de desarrollo integrado
├── README.md
└── LICENSE

## Primeros pasos
Ver [documentación](/docs) para desarrollo local.


