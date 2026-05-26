# Business Model Canvas — kpcrop-latam-zollner-platform

**Fecha:** 2026-05-22
**Version:** 1.0
**Metodologia:** Business Model Canvas (Osterwalder & Pigneur)

---

## Introduccion

El Business Model Canvas es una herramienta estrategica que describe el modelo de negocio en nueve bloques interconectados. Este documento refleja el modelo actual del proyecto en fase MVP y debe revisarse cada vez que se valide o refute una hipotesis critica.

El canvas esta construido desde la perspectiva del mercado primario (Chile) pero con la arquitectura pensada para escalar a LATAM.

---

## Canvas — Vista Completa

| **Asociaciones Clave** | **Actividades Clave** | **Propuesta de Valor** | **Relacion con Clientes** | **Segmentos de Clientes** |
|---|---|---|---|---|
| - Bsale (API y marketplace) | - Desarrollo y mantenimiento de integraciones Bsale-CMS | **Para comercios con Bsale y tienda online:** | - Trial autoservicio de 14 dias sin tarjeta | - Comercios individuales con Bsale + PrestaShop/WooCommerce/Shopify (Chile) |
| - Agencias digitales (revendedores) | - Sincronizacion automatica de catalogo, stock y precios | Eliminar la actualizacion manual de productos entre Bsale y el CMS de e-commerce mediante sincronizacion automatica, reduciendo errores de stock y tiempo perdido. | - Onboarding self-service (<10 min) | - PYMEs con catalogo grande (1.000-10.000 SKUs) |
| - PrestaShop, WooCommerce, Shopify (ecosistemas de plugins) | - Onboarding automatizado para nuevos tenants | | - Documentacion en espanol | - Agencias digitales que gestionan multiples clientes con Bsale |
| - Stripe y MercadoPago (procesamiento de pagos) | - Soporte tecnico al cliente | **Beneficio clave:** El comercio configura la conexion una vez y desde ese momento Bsale es la fuente de verdad — cualquier cambio en Bsale se refleja automaticamente en el CMS. | - Soporte por email o ticket (sin telefono en MVP) | - Emprendedores digitales nativos con Bsale basico |
| - SERCOTEC/CORFO (potencial) | - Marketing de contenidos y SEO | | - Comunidad de usuarios (Discord/grupo privado — fase 2) | |
| | - Gestion de billing y suscripciones | | | |

| **Recursos Clave** | | | **Canales** | |
|---|---|---|---|---|
| - Programador full-stack (Node.js/TypeScript) | | | - Marketplace de Bsale (canal 1 — trafico pre-calificado) | |
| - Disenador | | | - SEO organico ("conectar Bsale con [CMS]") | |
| - Infraestructura Railway (bot-miki, PostgreSQL, Redis) | | | - Grupos de Facebook y comunidades de comerciantes chilenos | |
| - Codigo fuente de las integraciones (activo tecnico clave) | | | - LinkedIn (para agencias y PYMEs medianas) | |
| - API de Bsale (acceso) | | | - Referidos de clientes existentes | |
| - Cuenta Stripe y MercadoPago activas | | | - Agencias digitales como canal de reventas (plan Agency) | |

| **Estructura de Costos** | | | **Fuentes de Ingreso** |
|---|---|---|---|
| **Costos fijos:** Railway (~USD 25-50/mes), dominio/SSL (~USD 5/mes), email transaccional (~USD 5/mes), monitoreo (~USD 10/mes) | | | **Suscripcion mensual recurrente (MRR):** |
| **Costos variables:** Fees de Stripe (2.9% + USD 0.30 por tx) y MercadoPago (3.49% por tx), soporte al cliente (tiempo) | | | - Starter: USD 19/mes (CLP 18.050) — 1 tienda, sync manual, hasta 1.000 productos |
| **Inversion inicial:** Registro de marca INAPI (~USD 315), buffer 3 meses (~USD 240), dominio inicial (~USD 15) = **USD 570 total** | | | - Growth: USD 49/mes (CLP 46.550) — 3 tiendas, sync automatico c/15min, hasta 10.000 productos |
| **Costo marginal por cliente adicional:** ~USD 0.50-2.00/mes | | | - Agency: USD 120/mes (CLP 114.000) — tiendas ilimitadas, dashboard multi-cliente |
| **Punto de equilibrio:** 2 clientes activos (USD 80 / USD 44 ARPU promedio) | | | - Trial de 14 dias sin tarjeta de credito |

---

## Los 9 Bloques — Descripcion Detallada

### Bloque 1: Segmentos de Clientes

El producto sirve a cuatro perfiles diferenciados con necesidades similares pero contextos diferentes:

| Segmento | Descripcion | Tier objetivo | Urgencia |
|---|---|---|---|
| Comerciante individual (Rodrigo) | Dueno de tienda con Bsale + PrestaShop/WooCommerce. Actualiza precios manualmente. | Starter | Alta |
| PYME con catalogo grande (Valeria) | Distribuidor o retail con 1.000-10.000 SKUs. Tiene proceso manual fragil. | Growth | Alta |
| Agencia digital (Sebastian) | Gestiona 10-25 clientes con Bsale. Quiere ofrecer sync como servicio. | Agency | Media-Alta |
| Emprendedora digital (Catalina) | Negocio 100% online, Bsale basico, ve el problema crecer. | Starter | Media |

### Bloque 2: Propuesta de Valor

La propuesta de valor central es:

> "Configura la conexion entre Bsale y tu CMS una vez. Desde ese momento, cada cambio en Bsale — precio, stock, nuevo producto — se refleja automaticamente en tu tienda online, sin trabajo manual."

**Evidencia del dolor que resuelve:**
- El comercio tipo Rodrigo dedica 45-120 minutos cada semana a actualizar dos sistemas
- La empresa tipo Valeria tiene un programador dedicando 30-40% de su tiempo a este proceso
- Los errores de stock generan ventas fallidas y danos a la reputacion

**Diferenciadores:**
- Unica solucion SaaS especifica para Bsale + multiples CMS (no agencia custom, no integracion generica)
- Configuracion en menos de 10 minutos sin soporte
- Trial 14 dias sin tarjeta de credito
- Documentacion y soporte 100% en espanol

### Bloque 3: Canales

| Canal | Etapa del funnel | Costo | Prioridad |
|---|---|---|---|
| Marketplace de Bsale | Descubrimiento + consideracion | Bajo (costo de aprobacion, sin CPC) | 1 |
| SEO organico ("conectar Bsale con PrestaShop") | Descubrimiento | Tiempo de contenido | 2 |
| Grupos Facebook/comunidades | Descubrimiento | Bajo (tiempo de participacion) | 3 |
| Agencias digitales (reventas) | Descubrimiento + cierre | Comercial — requiere outreach | 4 |
| LinkedIn | Descubrimiento (agencias y PYMEs medianas) | Tiempo | 5 |

### Bloque 4: Relacion con Clientes

El modelo de relacion es **self-service con soporte disponible**, no soporte proactivo en el MVP.

| Fase | Mecanismo | Meta |
|---|---|---|
| Adquisicion | Trial autoservicio, sin tarjeta | < 5 min para iniciar trial |
| Onboarding | Wizard guiado en la app | < 10 min para primer sync exitoso |
| Retencion | Alertas automaticas de errores, email mensual de resumen | Churn < 3% mensual |
| Soporte | Email/ticket (48h SLA en MVP) | > 80% de casos resueltos sin llamada |
| Expansion | Upsell automatico al llegar al limite del tier | ARPU creciente con el tiempo |

### Bloque 5: Fuentes de Ingreso

| Fuente | Modelo | Precio | Frecuencia |
|---|---|---|---|
| Suscripcion Starter | Recurrente mensual | USD 19 / CLP 18.050 | Mensual |
| Suscripcion Growth | Recurrente mensual | USD 49 / CLP 46.550 | Mensual |
| Suscripcion Agency | Recurrente mensual | USD 120 / CLP 114.000 | Mensual |

No hay ingresos por instalacion, setup, ni servicios profesionales en el MVP. El modelo es 100% suscripcion recurrente.

### Bloque 6: Recursos Clave

| Recurso | Tipo | Criticidad |
|---|---|---|
| Programador full-stack (fundador) | Humano | Alta — es el unico recurso tecnico |
| Disenador | Humano | Media — necesario para landing y UX |
| API de Bsale | Externo | Alta — sin esta API el producto no existe |
| Infraestructura Railway | Tecnologico | Alta — hosting del servicio |
| Codigo fuente de integraciones | Intelectual | Alta — activo diferenciador |
| Stripe y MercadoPago | Externo | Media — necesario para cobrar |

### Bloque 7: Actividades Clave

| Actividad | Descripcion | Frecuencia |
|---|---|---|
| Desarrollo de integraciones | Mantener y mejorar conectores para cada CMS | Continua |
| Sincronizacion de datos | BullMQ procesa jobs de sync automatico para cada tenant | Cada 15 minutos (Growth) / manual (Starter) |
| Onboarding de nuevos tenants | Proceso automatizado de configuracion inicial | Por evento |
| Soporte al cliente | Responder tickets y resolver errores | Diaria |
| Marketing de contenidos | Blog, SEO, participacion en comunidades | Semanal |
| Monitoreo de la API de Bsale | Detectar cambios o roturas en la API | Diaria (automatizada) |
| Gestion de billing | Procesar pagos, gestionar upgrades/downgrades/cancelaciones | Por evento |

### Bloque 8: Asociaciones Clave

| Asociado | Rol | Tipo de relacion |
|---|---|---|
| Bsale | Proveedor de la API y canal de distribucion (marketplace) | Critico — sin Bsale el producto no existe |
| Agencias digitales | Revendedores y canal de acceso a PYMEs medianas | Estrategico — plan Agency diseñado para este canal |
| Railway | Infraestructura cloud del servicio | Proveedor — puede sustituirse por AWS/ECS si es necesario |
| Stripe | Procesamiento de pagos en USD/internacionales | Proveedor — critico para clientes fuera de Chile |
| MercadoPago | Procesamiento de pagos en CLP (Chile) | Proveedor — preferido por clientes locales |
| SERCOTEC/CORFO | Potencial fuente de financiamiento para marketing | Oportunidad — no dependiente |

### Bloque 9: Estructura de Costos

El modelo es **value-driven con costos fijos bajos**, caracteristico del SaaS de nicho:

| Categoria | Costo | Naturaleza |
|---|---|---|
| Railway (infraestructura) | USD 25-50/mes | Fijo + variable |
| Email transaccional | USD 5/mes | Fijo |
| Monitoreo (BetterStack o similar) | USD 10/mes | Fijo |
| Dominio y SSL | USD 5/mes | Fijo |
| Fees de pago (Stripe/MP) | 2.9-3.49% por transaccion | Variable |
| Tiempo del fundador (marketing, soporte, desarrollo) | Costo de oportunidad | Variable |
| **Total fijo mensual** | **~USD 80** | |

---

## Canvas de Validacion — Hipotesis Criticas

Antes de escalar (invertir en marketing pagado, contratar, o expandir a LATAM), las siguientes tres hipotesis deben ser validadas con datos reales.

### Hipotesis 1 — El dolor es lo suficientemente urgente para pagar

**Hipotesis:** Los comercios con Bsale y CMS perciben el problema de sincronizacion manual como suficientemente doloroso como para pagar USD 19-49/mes por una solucion automatica.

| Elemento | Detalle |
|---|---|
| Supuesto | El comercio tipo Rodrigo actualiza dos sistemas manualmente al menos 1 vez por semana y considera que el tiempo perdido vale mas de USD 19/mes |
| Como validar | Cerrar 5 clientes pagantes en el plan Starter antes del mes 3 sin descuentos |
| Indicador de verdad | 5 clientes pagando en precio de lista en el mes 3 |
| Indicador de falsedad | Ninguna conversion en los primeros 20 trials, o los clientes piden precio < USD 10 |
| Urgencia | Alta — si esta hipotesis es falsa, el modelo de negocio no existe |

### Hipotesis 2 — El marketplace de Bsale genera trafico calificado suficiente

**Hipotesis:** El listing en el marketplace de Bsale genera al menos 30 visitas mensuales a la landing page y 5 trials por mes despues de los primeros 60 dias de estar listado.

| Elemento | Detalle |
|---|---|
| Supuesto | Los usuarios de Bsale buscan activamente soluciones en su marketplace antes de googlear |
| Como validar | Instrumentar UTM en el link del marketplace y medir visitas y trials con origen "bsale-marketplace" |
| Indicador de verdad | >= 30 visitas/mes y >= 5 trials/mes desde el marketplace en el mes 2 post-listado |
| Indicador de falsedad | < 10 visitas/mes desde el marketplace a los 60 dias del listado |
| Urgencia | Alta — este es el canal 1 de adquisicion |

### Hipotesis 3 — El onboarding autoservicio funciona sin soporte

**Hipotesis:** El 80% de los usuarios que inician un trial logran completar el primer sync exitoso en menos de 10 minutos, sin necesidad de contactar al equipo de soporte.

| Elemento | Detalle |
|---|---|
| Supuesto | La documentacion y el wizard de onboarding son suficientemente claros para un usuario con nivel tecnico medio-bajo |
| Como validar | Medir en los primeros 20 trials: tiempo desde creacion de cuenta hasta primer sync exitoso, y cuantos abrieron un ticket de soporte en las primeras 24 horas |
| Indicador de verdad | >= 80% completan el primer sync en < 10 min, < 20% abren ticket de soporte |
| Indicador de falsedad | > 40% de los usuarios en trial no completan el primer sync o contactan soporte |
| Urgencia | Alta — si el onboarding falla, el trial no convierte y el CAC sube dramaticamente |

---

## Preguntas Pendientes de Validar

1. **Potencial del canal de agencias:** Las agencias digitales chilenas estan dispuestas a pagar USD 120/mes por el plan Agency y hacer markup sobre ese precio para sus clientes? Esto requiere al menos 3 conversaciones con directores de agencias antes de invertir tiempo en un dashboard multi-cliente completo.

2. **Sostenibilidad del precio Starter a USD 19:** Dado que el segmento Starter tiene el mayor riesgo de churn (comercios individuales, menor urgencia del dolor), vale la pena invertir recursos en retenerlos o conviene enfocarse en conseguir clientes Growth desde el inicio? Evaluar el LTV de cada segmento despues de 6 meses de datos reales.

3. **Riesgo de dependencia de Bsale como canal:** Si el marketplace de Bsale rechaza la aplicacion o tarda mas de 3 meses en aprobarla, cual es el canal alternativo para los primeros 20 clientes? Definir un plan B de adquisicion antes de iniciar el proceso de aprobacion.
