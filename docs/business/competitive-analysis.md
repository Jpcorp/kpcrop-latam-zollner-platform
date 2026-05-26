# Análisis Competitivo y Pre-Mortem — kpcrop

**Fecha:** 2026-05-26
**Versión:** 1.0 — análisis inicial basado en investigación de mercado activa
**Metodología:** desk research + análisis de marketplaces (Shopify App Store, Bsale integradores) + pricing de competidores

> **Propósito de este documento:** destruir la idea antes de invertir más tiempo. Si el proyecto no supera este análisis, hay que pivotar. No es un ejercicio académico — es una decisión de capital.

---

## 1. Mapa de Competidores

### 1.1 Competidores Directos — hacen exactamente lo mismo

| Empresa | Plataformas | Precio | Modelo | Antigüedad | Amenaza |
|---|---|---|---|---|---|
| **Sidekick.cl** | PS, WC, Shopify, Jumpseller | ~$19.990 CLP/mes + 2 UF activación | SaaS mensual | 8+ años | **CRÍTICA** |
| **Pixofia SpA** | PS, WC, Shopify | $19.990/$29.990 CLP/mes, $299.990 CLP/año | SaaS mensual/anual | 3-5 años | **CRÍTICA** |
| **Lobo Creaciones** | Shopify (app oficial) | Free / USD 20 / 40 / 60 / mes | SaaS + Shopify App Store | 3+ años | **ALTA** |
| **Pivotech** | Shopify (app "Bsale · connect") | USD 45/mes | SaaS + Shopify App Store | ~2025 | **ALTA** |
| **Codificando.cl** | WC, Shopify, Jumpseller | $220.000–$260.000 CLP único | Pago único | 4+ años | **ALTA** |
| **Middify** | PrestaShop + otros | ~$78.540 CLP/mes | SaaS mensual | 2-3 años | **MEDIA** |
| **Brochure.cl** | PS, WC | No publicado | Módulo/pago único | Desconocida | **MEDIA** |
| **Entienda.cl** | PrestaShop | Desde $1.100.000 CLP único | Proyecto a medida | 5+ años | **BAJA** |
| **pymeecommerce.cl** | PS, Magento | No publicado | Desconocido | Desconocida | **MEDIA** |

### 1.2 El Ecosistema Oficial de Bsale

Bsale mantiene un marketplace de integradores certificados. Los siguientes están listados como partners aprobados:

- Pivotech, Codificando, Amplifica, Lobo Creaciones, Sidekick, Centry, LoadingPlay, Dsarhoya, Fixlabs, Yuju

**Dato crítico:** aparecer en este marketplace requiere aprobación de Bsale. Es el principal canal de distribución para cualquier integrador — y kpcrop no está listado todavía.

**Dato crítico 2:** Bsale ya construyó integración nativa con Tienda Nube (la plataforma de ecommerce más rápida en LATAM). Este es el manual de cómo termina esto para PrestaShop/WooCommerce si escalan en Chile.

### 1.3 El Mayor Competidor: Bsale mismo

Bsale tiene plan Ecommerce nativo. Su estrategia es absorber integraciones cuando la plataforma de destino gana masa crítica. Ya lo hizo con:
- Tienda Nube (integración nativa)
- Mercado Libre (integración nativa)
- Falabella / Paris / Ripley (a través de integradores certificados)

Si PrestaShop o WooCommerce crecen suficientemente en Chile, Bsale tiene tanto el incentivo como la capacidad técnica de construir esto internamente.

### 1.4 Sustitutos Indirectos

| Solución | Descripción | Por qué es amenaza |
|---|---|---|
| Zapier / Make | No tienen conector nativo de Bsale (hoy). | No son amenaza inmediata pero cualquier developer puede construir el conector en semanas |
| Centry / LoadingPlay | Plataformas omnicanal con Bsale integrado | Amenaza en el segmento Agency (multicanal) |
| Agencias a medida | Entienda.cl cobra $1.1M CLP por implementación custom | Para clientes con presupuesto, la solución custom es percibida como más robusta |

---

## 2. Razones por las que este Proyecto va a Fracasar

Ordenadas por severidad. Esto no es pesimismo — es la lista de problemas que hay que resolver antes de escalar.

### CRÍTICO — Matan el negocio si no se resuelven

**C1: El mercado direccionable real es microscópico**

- Bsale tiene ~12.000 empresas activas en Chile
- De esas, ~2.370 son tiendas ecommerce activas (dato propio de Bsale)
- Restando: tiendas con la tienda nativa de Bsale, tiendas en Shopify (ya tienen 3+ soluciones), tiendas que ya usan un integrador
- Universo direccionable estimado: **300–500 empresas en Chile**
- Con 10 competidores peleando ese mismo pool: potencial para kpcrop ≈ 30–50 clientes en escenario optimista
- **Ingreso máximo del mercado chileno: USD 900–1.500 MRR** — no es un negocio, es un ingreso de subsistencia

El TAM no sustenta una empresa SaaS. El producto necesita expandir el ERP (Defontana, Nubox, Loggro) o la geografía (Perú, México) desde el principio, no como plan B.

**C2: Los competidores tienen distribución y tú no**

- Lobo Creaciones: en Shopify App Store — distribución orgánica gratuita de miles de búsquedas
- Codificando.cl: posicionado en Google en los resultados naturales para "Bsale WooCommerce integración" desde hace años
- Sidekick: 8 años de referencias, boca a boca, relaciones con agencias
- Todos los competidores relevantes: listados como integradores certificados en el marketplace de Bsale

**kpcrop no tiene ningún canal de distribución.** El único canal viable es entrar al marketplace de Bsale, lo cual requiere aprobación del mismo vendor del que dependes para la API.

**C3: El modelo de pago único destruye la propuesta de suscripción**

Codificando.cl cobra $260.000 CLP una sola vez. Sin mensualidad. Sin renuncia.

Para una PYME chilena: USD 19/mes × 36 meses = USD 684 total vs. USD 285 para siempre.

La suscripción solo gana si hay valor continuo demostrable: nuevas plataformas, actualizaciones frecuentes, soporte activo. Con 1 desarrollador esa promesa es difícil de mantener de forma creíble ante un cliente escéptico.

**C4: El pricing en USD en un mercado que compra en CLP**

- Pixofia y Sidekick cobran en CLP — precio fijo, factura electrónica con IVA (deducible)
- kpcrop cobra en USD — precio variable según tipo de cambio, sin certeza de factura electrónica chilena
- El USD/CLP fluctuó ±37% en los últimos 24 meses
- Una empresa chilena que gestiona su presupuesto en CLP percibe el precio en USD como un riesgo adicional

### ALTO — Comprometen seriamente la rentabilidad

**A1: Riesgo de plataforma — Bsale puede cerrarte sin previo aviso**

Bsale es una empresa privada chilena. Puede:
- Cobrar por acceso a la API a terceros comerciales
- Exigir revenue share a integradores (modelo común en plataformas maduras)
- Construir la integración nativa (ya lo hizo con Tienda Nube)
- Ser adquirido por un competidor que cambia las reglas

Toda la empresa descansa en un activo que no controlas. Esto no es hipotético.

**A2: El CAC vs LTV no cierra en el plan Starter**

- LTV Starter (USD 19/mes, churn 3-5%/mes): USD 380–633
- CAC estimado sin canal propio: USD 150–300 en escenario optimista
- Payback: 8–16 meses antes de contar infraestructura y soporte
- El plan Starter probablemente no es rentable unitariamente con cualquier costo de adquisición

**A3: PrestaShop está en declive global**

- PrestaShop tiene 0.26% de market share global y cae
- Su dominancia chilena (39%) es una anomalía histórica que convergerá hacia la media
- Shopify crece en Chile; Tienda Nube tiene 180.000 tiendas en LATAM
- Tu MVP está construido sobre el CMS que más riesgo de encogimiento tiene en el horizonte de 3–5 años
- Para Shopify ya hay 3 competidores con app publicada

**A4: Sin switching costs — churn latente es estructuralmente alto**

Un cliente puede desinstalar el plugin de kpcrop e instalar el de Sidekick en un día. No hay datos propietarios, no hay workflow complejo, no hay dependencia real. En este tipo de producto, el churn está determinado por el soporte y la estabilidad — no por el costo de cambio.

**A5: Concentración en un solo ERP**

Si un nuevo ERP chileno (Defontana mejorado, un entrante con VC) captura el 30% del mercado PYME, tu producto no sirve para esos clientes. No hay diversificación de ERP y agregar soporte a otro ERP requiere reescribir el core de integración.

### MEDIO — Generan fricción y costo, pero se pueden resolver

**M1: 1 desarrollador = soporte crítico incompatible con desarrollo**

Una integración ERP-ecommerce es infraestructura crítica. Un bug de stock un viernes por la noche = ventas perdidas = churn. A 50 clientes cualquier incidente de la API de Bsale afecta a todos simultáneamente.

**M2: Willingness to pay en PYME chilena está anclado bajo**

El pricing de Sidekick y Pixofia (~USD 22/mes) ancló la percepción del mercado. El plan Agency de USD 120/mes enfrenta a Middify (USD 86/mes) con más referencias. La sensibilidad al precio en PYME es alta — el churn aumenta significativamente si el cliente siente que puede conseguir lo mismo más barato.

**M3: Riesgo regulatorio y de facturación**

Competidores locales emiten factura electrónica chilena (obligatoria para que la PYME deduzca el gasto). Sin constitución de sociedad y SII activo, kpcrop no puede emitir factura — lo que puede ser un bloqueador en ciclos de venta B2B formales.

**M4: Arquitectura centralizada como desventaja de ventas**

La dependencia del bot Node.js como hub central significa que el cliente depende de la infraestructura de kpcrop. Codificando.cl vende su argumento opuesto: "plugin standalone, no depende de terceros". Para clientes técnicos o agencias, la independencia de infraestructura es un argumento poderoso.

---

## 3. Debilidades Estructurales del Modelo

| Debilidad | Descripción | ¿Resoluble? |
|---|---|---|
| Asimetría de poder con Bsale | La empresa vale lo que Bsale permite. No hay palanca de negociación. | Parcialmente: diversificar ERPs |
| Sin network effects | Más clientes no hacen el producto mejor para otros clientes | No inherente al modelo actual |
| Sin switching costs | Churn latente permanentemente alto | Parcialmente: integrar más profundo en el workflow del cliente |
| Sin moat tecnológico | La API es pública, cualquier dev puede replicarlo en 4-8 semanas | No en el corto plazo |
| TAM chileno insuficiente | ~300-500 clientes potenciales no sostienen una empresa SaaS | Requiere expansión de ERP o geografía |

---

## 4. Veredicto

**El proyecto tal como está planteado tiene probabilidad de fracaso alta.**

No porque el problema no exista — existe. No porque no haya willingness to pay — lo hay. Sino porque:

1. Se llega a un mercado ya servido por 9+ competidores
2. Sin canal de distribución propio
3. Sin ventaja diferencial articulada
4. Con TAM chileno de ~USD 1.500 MRR máximo alcanzable
5. Con el competidor más barato ofreciendo pago único que destruye el modelo de suscripción

### Los números no cierran (escenario optimista):

```
TAM chileno creíble:       ~500 empresas objetivo
Captura realista (10%):    ~50 clientes
ARPU promedio:             ~USD 30/mes
MRR techo chileno:         ~USD 1.500/mes
ARR techo chileno:         ~USD 18.000/año

Costo mínimo de operación: USD 80/mes infra + tiempo del fundador
Punto de equilibrio real:  inviable como negocio escalable
```

---

## 5. Opciones de Pivot — Ordenadas por Viabilidad

### Pivot 1 (Recomendado): Expansión multi-ERP

**En lugar de:** "integración Bsale con PrestaShop/WooCommerce"
**Ser:** "la plataforma de sincronización para ERPs chilenos/LATAM con ecommerce"

- Agregar Defontana, Nubox, Loggro, Siigo, Aspel
- TAM se multiplica 3–5x
- Ningún competidor actual puede igualarlo sin reescribir su producto
- Requiere ~3-4 meses de trabajo técnico adicional
- Crea un moat real basado en cobertura de ERPs

### Pivot 2: Herramienta blanca para agencias

**Target:** las 200–300 agencias de desarrollo web en Chile, no el comerciante final

- Precio: USD 199–299/mes, clientes ilimitados, white-label
- CAC baja drásticamente — el canal son las agencias, que ya tienen relación con los comerciantes
- Riesgo: convencer a una agencia que cambie de Sidekick/Codificando sin prueba social

### Pivot 3: Segmento B2B (distribuidores/mayoristas)

- Distribuidores en Chile necesitan: precios por segmento de cliente, descuentos por volumen, gestión de reps de ventas — que la integración estándar Bsale-WooCommerce no maneja bien
- Precio: USD 199–499/mes
- Menor TAM, mayor ARPU, menor churn
- Compite en funcionalidades específicas, no en precio

### Pivot 4: LATAM — Bsale Perú y México

- Bsale tiene 2.700+ clientes en Perú y presencia creciente en México
- En esos mercados no hay competidores consolidados equivalentes a Sidekick/Pixofia/Codificando
- Llegar al mismo mercado en geografías con menos competencia
- Riesgo: las plataformas de ecommerce populares en Perú/México difieren de Chile

---

## 6. Thesis Breaker — La Prueba de los 90 Días

**Si en 90 días no se logra al menos uno de estos dos hitos, hay que pivotar o salir:**

1. **Ser listado como integrador certificado en el marketplace de Bsale** — valida que el canal de distribución principal existe y es accesible
2. **Cerrar 5 clientes pagantes** — valida que hay demanda y que la propuesta de valor supera a los competidores sin el canal de Bsale

Si ninguno de los dos ocurre en 90 días, la distribución no existe para este producto en este mercado. Ese es el único dato que importa.

---

*Fuentes consultadas: Sidekick.cl, Pixofia.com, Codificando.cl, Middify.com, Shopify App Store (búsquedas "Bsale"), Bsale.cl/sheet/integraciones, Bsale.cl/sheet/integradores-bsale, BuiltWith Chile ecommerce data, Gestión Perú (Bsale expansión).*
