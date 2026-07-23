# Documentacion de Negocio — kpcrop-latam-zollner-platform

**Ultima actualizacion:** 2026-05-22
**Estado:** v1.1 — Documentacion de negocio completa con frameworks del ebook "Mi Plan de Negocio" (Consultoria I.S.)

---

## Proposito de Este Directorio

Este directorio contiene el analisis estrategico de negocio del proyecto. Los documentos aqui sirven como base para decisiones de producto, pricing, canales y crecimiento. No son documentos inmutables — deben actualizarse a medida que se validan hipotesis con clientes reales.

---

## Documentos

### Analisis estrategico y mercado

| Documento | Descripcion | Estado |
|---|---|---|
| [Estudio de Mercado](./market-study.md) | TAM/SAM/SOM para Chile y LATAM, analisis competitivo, propuesta de valor, canales de adquisicion | Borrador v1.0 |
| [Estrategia de Pricing](./pricing-strategy.md) | Modelo de negocio recomendado, tiers con precios, proyeccion de ingresos a 12 meses | v2.0 — Pivot agencias |
| [Analisis Pricing Canal Agencias](./agency-pricing-analysis.md) | Costos reales por agencia, margen por plan, economia para la agencia, oferta beta | v1.1 — CLP |
| [Proyeccion Financiera 12 Meses](./financial-projection-12m.md) | Flujo de caja mensual, estado de resultados, VAN, TIR, punto de equilibrio, 3 escenarios | v1.0 — 2026-06-20 |
| [One-Pager Agencias](./agency-onepager.md) | Problema, solución, pricing, economía para la agencia y CTA — listo para enviar | v1.0 — 2026-06-20 |
| [Pitch Deck Agencias](./agency-pitch-deck.md) | 5 slides para call de 20 min con director de agencia, con guía de conversación y objeciones | v1.0 — 2026-06-20 |
| [Templates Outreach Agencias](./agency-outreach-templates.md) | 4 templates LinkedIn/email (frío, follow-up, post-conexión) y guía de calificación | v1.0 — 2026-06-20 |
| [Plantilla Mapeo 20 Agencias](./agency-mapping-tracker.csv) + [guía](./agency-mapping-guide.md) | Tracker CSV de 20 filas para calificar y hacer seguimiento del outreach (#59) | v1.0 — 2026-07-23 |
| [Pitch Deck (.pptx)](./agency-pitch-deck.pptx) | Versión visual editable del guión de `agency-pitch-deck.md`, lista para presentar | v1.0 — 2026-07-23 |
| [Buyer Personas](./buyer-personas.md) | 4 perfiles de cliente con dolores, canales, willingness to pay y mapa de empatia | v1.1 — con Mapas de Empatia |
| [Plan Go-to-Market](./go-to-market.md) | Canales, mensajes, secuencia de lanzamiento, metricas de exito | Borrador v1.0 |

### Frameworks del Plan de Negocio (Consultoria I.S.)

| Documento | Descripcion | Estado |
|---|---|---|
| [Resumen Ejecutivo](./executive-summary.md) | 8 secciones listas para presentar a SERCOTEC/CORFO o inversionista angel | v1.0 |
| [Analisis PESTEL y FODA](./foda.md) | PESTEL completo, tabla FODA y FODA cruzado con estrategias FO/FA/DO/DA | v1.0 |
| [Plan Financiero](./financial-plan.md) | Inversion inicial, costos, punto de equilibrio, proyecciones a 3 anos y semaforo mensual | v1.0 |
| [Business Model Canvas](./canvas.md) | 9 bloques del canvas + canvas de validacion con 3 hipotesis criticas | v1.0 |
| [KPIs del Negocio](./kpis.md) | 8 KPIs con metodologia SMART, formulas, metas y semaforo Verde/Amarillo/Rojo | v1.0 |
| [Plan de Accion 90 Dias](./action-plan.md) | Matriz de Eisenhower, plan semanal, checklist de formalizacion en Chile | v1.0 |

---

## Decisiones Clave de Negocio

Las siguientes decisiones estan documentadas en estos archivos y representan la posicion actual del analisis. Las marcadas con (*) fueron agregadas en v1.1.

### Modelo de Negocio

**Decision: SaaS de suscripcion mensual con 3 tiers.**

El mercado de integraciones para e-commerce PYME convergio en este modelo. Es predecible para el negocio y comprensible para el cliente. Ver justificacion completa en [pricing-strategy.md](./pricing-strategy.md#1-evaluacion-de-modelos-de-negocio).

### Pricing

**Decision: Starter USD 19 / Growth USD 49 / Agency USD 120 — con trial de 14 dias sin tarjeta.**

| Plan | Precio/mes | Para quien |
|---|---|---|
| Starter | USD 19 | 1 tienda, 1 CMS, sync manual, hasta 1.000 productos |
| Growth | USD 49 | 3 tiendas, todos los CMS, sync automatico, hasta 10.000 productos |
| Agency | USD 120 | Tiendas ilimitadas, dashboard multi-cliente, sync en tiempo real |

Ver tabla completa y justificacion en [pricing-strategy.md](./pricing-strategy.md#2-modelo-de-pricing-recomendado).

### Mercado Objetivo

**Decision: Chile como mercado primario, LATAM como expansion post-50 clientes.**

El SAM estimado en Chile es ~USD 1.215.000/año. El objetivo a 12 meses (SOM) es capturar 50 clientes (~USD 1.650 MRR en escenario base). Ver calculos en [market-study.md](./market-study.md#2-tamano-de-mercado-tam--sam--som).

### Canal Prioritario

**Decision: Marketplace de Bsale como canal 1, comunidades online como canal 2, agencias como canal 3.**

El marketplace de Bsale es el canal de mayor leverage porque el trafico ya esta pre-calificado. Ver detalle en [go-to-market.md](./go-to-market.md#2-canales-de-adquisicion-y-tacticas).

### Segmento Inicial

**Decision: Comercios individuales con PrestaShop o WooCommerce y catalogo de 50-1.000 productos (Rodrigo y Catalina) como segmento inicial; PYME mediana (Valeria) y agencias (Sebastian) como segmentos de crecimiento.**

Los comercios individuales tienen el tiempo de decision mas corto y el onboarding mas simple. Son el mejor segmento para los primeros 10 clientes. Ver perfiles completos en [buyer-personas.md](./buyer-personas.md).

### (*) Punto de Equilibrio

**Decision: El punto de equilibrio del modelo SaaS es de solo 2 clientes pagantes — esto es una ventaja competitiva estrategica del modelo.**

Con costos fijos de USD 80/mes y un ARPU ponderado de USD 44, solo se necesitan 2 clientes para cubrir todos los costos operativos. Ver calculo completo en [financial-plan.md](./financial-plan.md#6-punto-de-equilibrio).

### (*) Formalizacion Legal

**Decision: Iniciar actividades en el SII como persona natural con giro de servicios de tecnologia en el mes 1, evaluar constitucion de SpA cuando el MRR supere USD 1.000/mes.**

El registro de marca en INAPI (nombre "kpcrop", clase 42) debe hacerse en el mes 2-3, antes del lanzamiento publico. Ver checklist completo en [action-plan.md](./action-plan.md#3-checklist-de-formalizacion-en-chile).

### (*) KPIs Criticos de Monitoreo

**Decision: Los 3 KPIs mas criticos para el MVP son Trial-to-Paid Conversion Rate (meta: > 35%), Churn Rate (meta: < 3% mensual) y Time to First Sync (meta: < 10 minutos en P50).**

Si el Trial-to-Paid cae bajo el 20%, el problema esta en el onboarding o en la propuesta de valor. Si el Churn supera el 5%, el problema esta en el value delivery post-conversion. Ver definicion completa de todos los KPIs en [kpis.md](./kpis.md).

---

## Hipotesis Pendientes de Validacion

Las siguientes hipotesis son centrales al modelo de negocio y deben validarse con clientes reales antes del mes 6:

| Hipotesis | Como validar | Urgencia |
|---|---|---|
| Un comercio paga USD 19/mes por sync de productos | Cerrar 5 ventas en ese precio | Alta |
| El trial de 14 dias genera conversion > 25% | Medir en los primeros 20 trials | Alta |
| El marketplace de Bsale genera trafico calificado | Medir clientes por canal en los primeros 3 meses | Alta |
| El dolor de sync manual es suficientemente urgente para pagar | Si el churn es < 5% al mes 3, la hipotesis es verdadera | Alta |
| Una agencia adopta el plan Agency para sus clientes | Cerrar 1 agencia en los primeros 6 meses | Media |

---

## Metricas de Negocio Objetivo

| Metrica | Mes 3 | Mes 6 | Mes 12 |
|---|---|---|---|
| MRR | USD 350 | USD 850 | USD 1.650 |
| Clientes activos | 10 | 25 | 50 |
| Churn mensual | < 8% | < 5% | < 4% |
| NPS | > 7 | > 8 | > 8 |
| CAC promedio | < USD 50 | < USD 40 | < USD 35 |

---

## Preguntas Criticas para el Fundador

Consolidadas de todos los documentos, las siguientes preguntas son las de mayor impacto en la estrategia:

1. **Primer cliente:** ¿Quien es el primer comercio real que va a usar el producto antes del lanzamiento oficial? Sin un primer cliente con datos reales, el MVP no esta listo para lanzar.

2. **Relacion con Bsale:** ¿Se ha iniciado contacto con el equipo comercial de Bsale para explorar el listing en su marketplace? El proceso puede tomar 1-3 meses.

3. **Validacion de precio:** ¿Se ha conversado el precio de USD 19/mes con al menos 3 comercios potenciales? Una sesion de discovery de 20 minutos con 5 prospectos es el analisis de mercado mas valioso posible.

4. **Metrica de exito personal:** ¿Que MRR a 12 meses justifica continuar invirtiendo tiempo en el producto vs. explorar otras oportunidades?

5. **Capacidad de ventas:** ¿Cuantas horas semanales puede dedicar el fundador a actividades de ventas y marketing en los primeros 3 meses? La velocidad de adquisicion de los primeros 10 clientes depende directamente de esto.

---

## Proximos Pasos Recomendados

En orden de prioridad:

1. Contactar al equipo de Bsale para explorar el listing en su marketplace (esta semana)
2. Identificar y contactar al primer cliente beta potencial (esta semana)
3. Preparar la landing page con el pricing definido antes del launch (en paralelo al desarrollo del MVP)
4. Validar el precio de USD 19/mes con 3-5 conversaciones con prospectos reales (antes del mes 2)
5. Identificar 5 agencias digitales en Chile para propuesta de alianza (mes 2)
