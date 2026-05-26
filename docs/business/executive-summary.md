# Resumen Ejecutivo — kpcrop-latam-zollner-platform

**Fecha:** 2026-05-22
**Version:** 1.0
**Destinatario:** SERCOTEC / CORFO / Inversionista Angel
**Tipo de cambio:** 1 USD = 950 CLP

---

## 1. Idea de Negocio

kpcrop es una plataforma SaaS B2B que sincroniza automaticamente el catalogo de productos, stock y precios desde Bsale ERP hacia el CMS de e-commerce del comercio (PrestaShop, WooCommerce, Shopify, entre otros). El comercio instala un plugin en su tienda online, ingresa su token de API de Bsale y desde ese momento cada cambio en Bsale se refleja automaticamente en la tienda — eliminando el trabajo manual de actualizar dos sistemas en paralelo.

---

## 2. Propuesta de Valor — Ventaja Competitiva

**Problema que resuelve:** El 60% de los comercios chilenos que usan Bsale como ERP/POS tambien tienen una tienda online. Estos comercios actualizan precios, stock y catalogo manualmente en dos sistemas separados — un proceso que consume entre 45 minutos y 8 horas semanales, genera errores de stock que resultan en ventas fallidas, y en el caso de empresas medianas requiere personal dedicado (costo estimado: CLP 200.000-400.000/mes en horas de trabajo).

**Solucion:** Sincronizacion automatica de una via (Bsale como fuente de verdad → CMS como destino). El comercio configura la conexion una vez y el sistema hace el trabajo de forma continua en segundo plano.

**Diferenciadores competitivos:**

| Diferenciador | Descripcion |
|---|---|
| Especificidad de nicho | La unica solucion SaaS identificada exclusivamente para la integracion Bsale-CMS en Chile. No es una integracion generica (Zapier) ni un desarrollo custom de agencia. |
| Multi-CMS desde el inicio | Compatible con PrestaShop, WooCommerce, Shopify, Magento 2 y Jumpseller — cubre el 90% del mercado de CMS de e-commerce en Chile. |
| Onboarding en < 10 minutos | Configuracion autoservicio sin necesidad de programadores ni soporte tecnico. |
| Idioma y soporte local | Documentacion y soporte 100% en espanol, con conocimiento del mercado chileno. |
| Sin competidor SaaS directo | No existe una solucion equivalente consolidada en el mercado. La ventana de ser primero esta abierta. |

---

## 3. Clientes y Mercado

### Segmentos de Clientes

| Segmento | Perfil | Tier objetivo | Urgencia del dolor |
|---|---|---|---|
| Comerciante individual | Dueno de tienda con Bsale + PrestaShop o WooCommerce. Actualiza precios manualmente cada semana. | Starter (USD 19/mes) | Alta |
| PYME con catalogo grande | Distribuidor o retail con 1.000-10.000 SKUs. Tiene un programador part-time haciendo sync manual. | Growth (USD 49/mes) | Alta |
| Agencia digital | Gestiona 10-25 clientes con Bsale. Quiere ofrecer sync como servicio de valor agregado. | Agency (USD 120/mes) | Media-Alta |
| Emprendedora digital | Negocio 100% online, catalogo en crecimiento, quiere automatizar antes de que el problema escale. | Starter (USD 19/mes) | Media |

### Tamano del Mercado — Chile

| Nivel | Definicion | Estimacion |
|---|---|---|
| TAM (Mercado Total Disponible) | Total de empresas en Chile con Bsale y tienda online | ~27.000 empresas |
| SAM (Mercado Alcanzable) | Empresas en Chile tecnicamente compatibles y con perfil de pago | ~4.050 empresas |
| SOM (Mercado Objetivo — ano 1) | Meta de captura en los primeros 12 meses | 50 clientes / ~USD 1.650 MRR |

**Proyeccion de mercado en LATAM (expansion post-50 clientes):** Bsale opera tambien en Mexico y Colombia con base de usuarios creciente. El SAM combinado de los tres paises supera las 15.000 empresas alcanzables.

---

## 4. Modelo de Ingresos

El modelo es SaaS de suscripcion mensual con tres tiers de precio, sin contrato anual obligatorio y con trial de 14 dias sin tarjeta de credito.

| Plan | USD/mes | CLP/mes | Para quien | Features clave |
|---|---|---|---|---|
| Starter | USD 19 | CLP 18.050 | 1 tienda, catalogo pequeno | 1 CMS, sync manual, hasta 1.000 productos |
| Growth | USD 49 | CLP 46.550 | Comercio en crecimiento | 3 tiendas, sync automatico c/15 min, hasta 10.000 productos |
| Agency | USD 120 | CLP 114.000 | Agencias digitales | Tiendas ilimitadas, dashboard multi-cliente, sync en tiempo real |

**Procesamiento de pagos:**
- Stripe: clientes que pagan en USD (internacional o preferencia)
- MercadoPago: clientes que pagan en CLP (chile, mayor adoption rate local)

**Punto de equilibrio:** Solo 2 clientes pagantes cubren todos los costos fijos mensuales (USD 80). Esta caracteristica del modelo SaaS minimiza el riesgo financiero.

---

## 5. Estrategia Comercial

La estrategia de go-to-market prioriza canales de bajo costo con trafico pre-calificado:

**Canal 1 — Marketplace de Bsale (prioridad maxima):**
Los usuarios del marketplace de Bsale ya tienen el ERP instalado y estan buscando integraciones. El trafico es 100% calificado. El proceso de aprobacion tarda 1-3 meses — iniciar inmediatamente.

**Canal 2 — SEO organico:**
Posicionamiento para busquedas de alta intencion: "conectar Bsale con PrestaShop", "sincronizar Bsale WooCommerce", "integracion Bsale Shopify". Contenido educativo en blog y documentacion publica.

**Canal 3 — Comunidades de comerciantes:**
Grupos de Facebook, Discord y comunidades de emprendedores chilenos donde el segmento objetivo discute problemas operativos. Presencia con valor, no solo publicidad.

**Canal 4 — Agencias digitales (canal de reventas):**
Outreach directo a 10-15 agencias de e-commerce en Chile para propuesta de alianza en el plan Agency. Una agencia con 10 clientes equivale a USD 1.200/mes de MRR.

**Mecanismo de conversion:** Trial de 14 dias sin tarjeta → primer sync exitoso en < 10 minutos → conversion automatica via Stripe/MercadoPago al finalizar el trial.

---

## 6. Equipo y Recursos

| Rol | Persona | Dedicacion | Responsabilidad |
|---|---|---|---|
| Fundador / Programador Full-Stack | 1 persona | Full-time | Desarrollo del producto, infraestructura, soporte tecnico, ventas iniciales |
| Disenador | 1 persona | Part-time | Landing page, UX del producto, assets de marketing |

**Stack tecnologico:**
- Hub de integracion: Node.js 22 + TypeScript + Fastify + BullMQ + PostgreSQL + Redis
- Hosting: Railway (inicio) → AWS ECS Fargate (escala)
- Billing: Stripe + MercadoPago

**Ventaja del equipo:** El stack tecnologico es moderno, el programador tiene experiencia en el dominio de las APIs involucradas y el mercado objetivo es conocido — el equipo entiende el problema desde adentro.

**Necesidad de escalar el equipo:** No se requiere contratacion en los primeros 12 meses. Si el MRR supera USD 3.000/mes, evaluar contratacion de soporte o desarrollo.

---

## 7. Proyecciones Financieras — Ano 1 (Escenario Conservador)

| Mes | Clientes Activos | MRR (USD) | MRR (CLP) | Resultado Mensual (USD) |
|---|---|---|---|---|
| Mes 1 | 2 | USD 80 | CLP 76.000 | USD 0 (break-even) |
| Mes 3 | 6 | USD 240 | CLP 228.000 | +USD 160 |
| Mes 6 | 13 | USD 520 | CLP 494.000 | +USD 440 |
| Mes 9 | 22 | USD 880 | CLP 836.000 | +USD 780 |
| Mes 12 | 34 | USD 1.360 | CLP 1.292.000 | +USD 1.255 |

**Inversion inicial total:** USD 570 (CLP 541.500) — autofinanciada

**Retorno de inversion inicial:** Recuperado entre el mes 1 y el mes 3 (break-even desde el primer cliente pagante en escenario conservador)

**ARR proyectado fin de ano 1 (conservador):** USD 16.320 / CLP 15.504.000

**ARR proyectado fin de ano 2 (conservador):** USD 38.400 / CLP 36.480.000

**Margen bruto estimado:** > 90% (caracteristico del modelo SaaS con costos de infraestructura bajos)

---

## 8. Objetivo Principal a 12 Meses

**Meta primaria:** 50 clientes activos pagantes con MRR >= USD 1.650 y churn mensual < 4%.

**Hitos criticos del camino:**

| Hito | Plazo | Indicador de logro |
|---|---|---|
| MVP funcional con PrestaShop | Mes 1 | 1 cliente beta sincronizando en produccion |
| Aprobacion en marketplace de Bsale | Mes 2-3 | Listing publicado y visible en el marketplace |
| 10 clientes pagantes | Mes 4 | MRR >= USD 400 |
| Cobertura de 3 CMS (PrestaShop + WooCommerce + Shopify) | Mes 6 | Los 3 conectores en produccion |
| 1 alianza con agencia digital | Mes 6 | Primer cliente Agency activo |
| 50 clientes pagantes | Mes 12 | MRR >= USD 1.650 |
| Expansion a Mexico o Colombia | Post mes 12 | MRR Chile >= USD 2.000 sostenido por 2 meses |

**Por que este objetivo es alcanzable:** El mercado en Chile tiene mas de 4.000 empresas potenciales. La meta de 50 representa el 1.2% del SAM. El punto de equilibrio es de solo 2 clientes. El canal del marketplace de Bsale provee trafico calificado sin costo publicitario. El equipo tiene el conocimiento tecnico para ejecutar el MVP en el plazo definido.

---

## Preguntas Pendientes de Validar

1. **Primer cliente beta:** El proyecto necesita al menos un comercio real usando el producto en produccion antes del lanzamiento publico para generar el primer caso de exito. Identificar y contactar a ese primer cliente es la accion mas urgente del mes 1.

2. **Proceso de aprobacion de Bsale:** El tiempo exacto y los requisitos especificos para el listing en el marketplace de Bsale no estan confirmados. Iniciar el contacto con el equipo de partnerships de Bsale esta semana para determinar el camino critico.

3. **Postura de SERCOTEC/CORFO ante SaaS bootstrapped:** Los fondos concursables de SERCOTEC y CORFO generalmente prefieren proyectos con traccion inicial (al menos un cliente pagante) y proyeccion de empleo. Evaluar si el perfil del proyecto califica para algun instrumento especifico antes de invertir tiempo en una postulacion.
