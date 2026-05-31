# Configuracion de Produccion

Referencia de todos los valores no-secretos de la infraestructura actual.
Los valores sensibles (API Keys, secrets) estan en `secrets.md` (excluido del repo via .gitignore).

---

## Railway

| Campo | Valor |
|---|---|
| Proyecto | `truthful-essence` |
| Project ID | `b6525894-63c0-47f5-9617-bcbd6ea05b0e` |
| Environment | `production` (ID: `a1941d74-baad-4d7b-81ed-9a8e0f3ed787`) |
| Service bot-miki | ID `83d31e9b-c096-4ca1-bd8e-ba46229e0875` |
| Service Redis | ID `53bebd51-b57e-4246-a88f-de275fd5e714` |
| Service Postgres | ID `c11ed5b3-5f81-404b-ab55-64bc5644fb51` |
| Branch | `master` — autodeploy en push |
| Builder | Dockerfile (`packages/bot-miki/Dockerfile`) |
| Region | San Francisco (SFO) |

## URLs

| Servicio | URL |
|---|---|
| bot-miki (produccion) | `https://kpcrop-latam-zollner-platform-production.up.railway.app` |
| Health | `https://kpcrop-latam-zollner-platform-production.up.railway.app/health` |
| Swagger UI | `https://kpcrop-latam-zollner-platform-production.up.railway.app/docs` |
| Dominio personalizado | `api.espaciobits.com` — CNAME OK, SSL bloqueado (ver deployment/README.md) |

## DNS

| Registro | Tipo | Valor |
|---|---|---|
| `api.espaciobits.com` | CNAME | `yg8d6egj.up.railway.app` |
| Nameservers | NS | `ns13.domaincontrol.com`, `ns14.domaincontrol.com` (GoDaddy) |

## Tenants en produccion

| tenantId | Plan | Stores | Creado | Notas |
|---|---|---|---|---|
| `tienda-zollner-cl` | growth | max 3 | 2026-05-31 | Primer cliente — onboarding pendiente |

## Plugin PrestaShop — Valores de configuracion

| Campo DB | Valor |
|---|---|
| `daemon_api_url` | `https://kpcrop-latam-zollner-platform-production.up.railway.app` |
| `daemon_api_key` | Ver `secrets.md` |
| `bsale_api_token` | Cifrado AES-256-CBC en DB — token real del cliente |
| `bsale_price_list_id` | Segun lista de precios del cliente en Bsale |
| `bsale_office_id` | NULL = todas las sucursales |
