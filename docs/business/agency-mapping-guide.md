# Guía de uso — Plantilla de Mapeo de 20 Agencias (#59)

> Acompaña a [`agency-mapping-tracker.csv`](./agency-mapping-tracker.csv) — 20 filas listas
> para completar. Abrir en Google Sheets/Excel. Criterios de calificación tomados de
> [`agency-outreach-templates.md`](./agency-outreach-templates.md#señales-de-calificación-positiva-priorizar-estas-agencias).

## Columnas

| Columna | Qué poner |
|---|---|
| `agencia` | Nombre de la agencia |
| `sitio_web` | URL |
| `nombre_contacto` | Director/dueño o quien decide — no soporte técnico |
| `cargo_contacto` | Director, socio, PM, etc. |
| `linkedin_contacto` | URL del perfil |
| `email` / `telefono_whatsapp` | Si están disponibles públicamente |
| `num_empleados_aprox` | Desde LinkedIn — ideal 3-15 (ver fit score) |
| `menciona_prestashop` | Sí/No — buscar en el sitio o portafolio |
| `tiene_casos_ecommerce` | Sí/No — casos de estudio visibles |
| `menciona_bsale` | Sí/No — `site:agencia.cl bsale` en Google |
| `sector_clientes_principal` | Retail, moda, ferretería, distribución, alimentos, etc. (catálogos grandes = mejor fit) |
| `fit_score_1_5` | Ver criterio abajo |
| `canal_contacto` | LinkedIn / Email / Referido |
| `fecha_primer_contacto` | Fecha de envío del template de outreach |
| `estado` | Por contactar → Enviado → Sin respuesta → Follow-up enviado → Respondió → Llamada agendada → En negociación → Beta activo → Descartado |
| `fecha_proximo_paso` | Cuándo hacer follow-up o la próxima acción |
| `notas` | Cualquier dato relevante de la llamada/investigación |

## Cómo calcular `fit_score_1_5`

Sumar 1 punto por cada señal positiva presente (máximo 5):

1. Menciona PrestaShop en sitio o portafolio
2. Tiene casos de estudio de e-commerce
3. Clientes en sectores con catálogos grandes (retail, moda, ferretería, distribución, alimentos)
4. Tamaño 3-15 empleados
5. El director/dueño tiene LinkedIn activo (posteos recientes, no solo perfil)

**Descalificar directo (no agregar a la lista o marcar `Descartado`)** si la agencia es 100% diseño/
branding sin desarrollo, o solo trabaja con WooCommerce/Shopify sin PrestaShop.

## Orden de trabajo sugerido

1. Completar las 20 filas con investigación (sitio web, LinkedIn, Google) — sin contactar todavía.
2. Ordenar por `fit_score_1_5` descendente.
3. Empezar el outreach (`agency-outreach-templates.md`) por las de score 4-5 — son las 10 de la
   "primera oleada" que pide el issue #60.
4. Actualizar `estado` y `fecha_proximo_paso` después de cada interacción — es lo que impide
   perder el hilo cuando hay 20 conversaciones en paralelo.
