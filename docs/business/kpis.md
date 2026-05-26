# KPIs del Negocio — kpcrop-latam-zollner-platform

**Fecha:** 2026-05-22
**Version:** 1.0
**Metodologia:** KPIs SMART con semaforo de gestion (Verde / Amarillo / Rojo)

---

## Introduccion

Los KPIs (Key Performance Indicators) son las metricas que permiten evaluar si el negocio avanza en la direccion correcta. Para un SaaS B2B en etapa MVP, las metricas financieras (MRR, churn) y de producto (sync error rate, time to first sync) son igualmente importantes.

Cada KPI en este documento sigue la metodologia SMART:
- **S**pecific (Especifico): el KPI mide una sola cosa, no un concepto vago
- **M**easurable (Medible): tiene una formula clara y un dato fuente
- **A**chievable (Alcanzable): la meta es realista dado el equipo y la etapa
- **R**elevant (Relevante): impacta directamente en la viabilidad del negocio
- **T**ime-bound (Acotado en el tiempo): tiene una frecuencia de medicion definida

---

## Resumen de KPIs — Vista Rapida

| KPI | Meta Optima (Verde) | Tolerable (Amarillo) | Deficiente (Rojo) | Frecuencia |
|---|---|---|---|---|
| MRR | >= proyeccion base del mes | Entre conservador y base | Por debajo del conservador | Mensual |
| Churn Rate | < 3% mensual | 3-5% mensual | > 5% mensual | Mensual |
| CAC | < USD 35 | USD 35-60 | > USD 60 | Mensual |
| LTV | > USD 490 (10 meses) | USD 300-490 | < USD 300 | Trimestral |
| Trial-to-Paid Conversion | > 35% | 20-35% | < 20% | Semanal |
| Sync Error Rate | < 1% | 1-3% | > 3% | Diaria |
| NPS | > 40 | 20-40 | < 20 | Mensual |
| Time to First Sync | < 10 minutos | 10-20 minutos | > 20 minutos | Por evento |

---

## 1. MRR — Monthly Recurring Revenue

**Definicion:** El total de ingresos recurrentes mensuales provenientes de todas las suscripciones activas pagadas. Es la metrica central de salud financiera de un SaaS.

**Formula:**
```
MRR = SUM(precio_mensual de cada suscripcion activa)

MRR Nuevo = clientes_nuevos * ARPU promedio
MRR Expansion = upgrades de tier en el mes
MRR Contraccion = downgrades de tier en el mes
MRR Perdido = cancelaciones * ARPU promedio

MRR Neto del mes = MRR anterior + MRR Nuevo + MRR Expansion - MRR Contraccion - MRR Perdido
```

**Unidad de medida:** USD/mes

**Fuente de datos:** Stripe Dashboard / MercadoPago Dashboard

| Criterio | Meta | Valor |
|---|---|---|
| Verde (Optimo) | >= proyeccion del escenario base para ese mes | MRR mes 6: >= USD 968 |
| Amarillo (Tolerable) | Entre escenario conservador y base | MRR mes 6: USD 520-968 |
| Rojo (Deficiente) | Por debajo del escenario conservador | MRR mes 6: < USD 520 |

**Frecuencia de medicion:** Mensual (el dia 1 de cada mes, con datos del mes anterior)

**Meta a 12 meses (escenario base):** USD 2.332/mes

**Accion si es Rojo:** Revisar tasa de conversion del trial y fuentes de adquisicion. Si el MRR es rojo dos meses consecutivos, hacer entrevistas de descubrimiento con 5 prospectos que NO convirtieron.

---

## 2. Churn Rate — Tasa de Cancelacion Mensual

**Definicion:** El porcentaje de clientes pagantes que cancelan su suscripcion en un mes dado. Es el principal indicador de la retencion del producto — si el churn es alto, el crecimiento es ilusorio.

**Formula:**
```
Churn Rate (%) = (Clientes que cancelaron en el mes / Clientes activos al inicio del mes) * 100

Ejemplo: 3 cancelaciones sobre 50 clientes activos = 6% churn mensual
```

**Unidad de medida:** Porcentaje (%)

**Fuente de datos:** Stripe cancelaciones + registro interno en base de datos

| Criterio | Meta | Interpretacion |
|---|---|---|
| Verde (Optimo) | < 3% mensual | < 3% de los clientes cancelan por mes. LTV alto. |
| Amarillo (Tolerable) | 3-5% mensual | Hay desercion significativa. Investigar motivos. |
| Rojo (Deficiente) | > 5% mensual | El negocio pierde clientes mas rapido de lo que puede crecer. Crisis. |

**Frecuencia de medicion:** Mensual

**Relacion con LTV:** A mayor churn, menor LTV. Un churn de 3% implica un LTV promedio de ~33 meses; un churn de 5% implica ~20 meses.

**Accion si es Rojo:** Encuestar a todos los clientes que cancelaron en el ultimo mes (exit survey de 3 preguntas). Identificar el patron de cancelacion — si cancelan antes del dia 30, el problema es onboarding; si cancelan entre el dia 31 y 90, el problema es value delivery.

---

## 3. CAC — Costo de Adquisicion de Cliente

**Definicion:** El costo total promedio de adquirir un nuevo cliente pagante, incluyendo todos los gastos de marketing y ventas del periodo.

**Formula:**
```
CAC = Total de gastos de marketing y ventas del mes / Numero de nuevos clientes pagantes en el mes

Ejemplo: USD 200 gastados en marketing + 6 horas de tiempo del fundador a USD 50/hora = USD 500 total.
         Si se adquirieron 5 clientes: CAC = USD 500 / 5 = USD 100
```

**Unidad de medida:** USD por cliente

**Fuente de datos:** Gastos reales de marketing (publicidad, herramientas) + tiempo dedicado a ventas (valorado a tarifa de mercado)

| Criterio | Meta | Interpretacion |
|---|---|---|
| Verde (Optimo) | < USD 35 por cliente | CAC recuperado en < 1 mes al precio Starter (USD 19). Muy eficiente. |
| Amarillo (Tolerable) | USD 35-60 por cliente | CAC recuperado en 1-3 meses. Aceptable si el churn es bajo. |
| Rojo (Deficiente) | > USD 60 por cliente | CAC > 3 meses de ingreso. Insostenible sin financiamiento. |

**Frecuencia de medicion:** Mensual

**Relacion critica — CAC vs. LTV:** El ratio LTV/CAC debe ser >= 3x. Si el LTV es USD 490 y el CAC es USD 35, el ratio es 14x — excelente. Si el CAC sube a USD 200, el ratio cae a 2.5x — zona de riesgo.

**Nota para el MVP:** En los primeros meses, el CAC incluira tiempo del fundador en ventas directas y onboarding. A medida que el canal de marketplace de Bsale madura, el CAC deberia caer por debajo de USD 20.

---

## 4. LTV — Lifetime Value del Cliente

**Definicion:** El ingreso total esperado de un cliente durante toda su relacion con el producto. Permite justificar el CAC y proyectar el valor del portafolio de clientes.

**Formula:**
```
LTV = ARPU mensual / Churn Rate mensual

Ejemplo con churn del 3% y ARPU USD 44:
LTV = USD 44 / 0.03 = USD 1.467

Ejemplo con churn del 5% y ARPU USD 44:
LTV = USD 44 / 0.05 = USD 880
```

**Unidad de medida:** USD por cliente (valor esperado total de vida)

**Fuente de datos:** ARPU real de la base de clientes + churn rate medido

| Criterio | Meta | Interpretacion |
|---|---|---|
| Verde (Optimo) | > USD 490 (equivalente a > 10 meses de ARPU promedio) | Clientes leales y con bajo churn. |
| Amarillo (Tolerable) | USD 300-490 | LTV de 6-10 meses. Aceptable pero mejorable. |
| Rojo (Deficiente) | < USD 300 | < 6 meses de vida promedio. El churn esta destruyendo valor. |

**Frecuencia de medicion:** Trimestral (requiere datos acumulados para ser estadisticamente valido)

**Accion si es Rojo:** El LTV bajo es sintoma de un problema mas profundo — usualmente churn elevado o ARPU bajo por exceso de clientes en el tier Starter. Revisar la estrategia de upsell.

---

## 5. Trial-to-Paid Conversion Rate — Tasa de Conversion de Trial a Pago

**Definicion:** El porcentaje de usuarios que iniciaron un trial de 14 dias y terminaron suscribiendose a un plan pago al finalizar el periodo.

**Formula:**
```
Trial-to-Paid (%) = (Usuarios que convirtieron a pago / Total de usuarios que iniciaron trial en el mismo periodo) * 100

Ejemplo: 8 conversiones sobre 25 trials iniciados en el mes = 32% conversion
```

**Unidad de medida:** Porcentaje (%)

**Fuente de datos:** Stripe (suscripciones nuevas) vs. registro de trials iniciados en base de datos

| Criterio | Meta | Interpretacion |
|---|---|---|
| Verde (Optimo) | > 35% | Mas de 1 de cada 3 trials se convierte. El producto convence durante el trial. |
| Amarillo (Tolerable) | 20-35% | Conversion media. Hay espacio para mejorar el onboarding o el follow-up. |
| Rojo (Deficiente) | < 20% | Menos de 1 de cada 5 trials convierte. El trial no esta demostrando valor suficiente. |

**Frecuencia de medicion:** Semanal (dado el ciclo de 14 dias del trial)

**Embudo de conversion del trial:**

```mermaid
flowchart LR
    A[Visita landing] --> B[Inicia trial]
    B --> C[Completa onboarding]
    C --> D[Primer sync exitoso]
    D --> E[Usa el producto > 3 veces]
    E --> F[Convierte a pago]

    A -->|100%| B
    B -->|~60%| C
    C -->|~80%| D
    D -->|~70%| E
    E -->|~60%| F
```

*El cuello de botella mas comun en SaaS de integraciones es entre "inicia trial" y "completa onboarding". Monitorear este paso especificamente.*

**Accion si es Rojo:** Revisar el paso en que mas usuarios abandonan el trial. Implementar un email de activacion al dia 3 del trial dirigido a usuarios que no completaron el onboarding.

---

## 6. Sync Error Rate — Tasa de Error en Sincronizaciones

**Definicion:** El porcentaje de jobs de sincronizacion que fallan con error (no se completan exitosamente) sobre el total de jobs ejecutados en el periodo. Es el KPI de calidad tecnica del servicio.

**Formula:**
```
Sync Error Rate (%) = (Jobs de sync fallidos / Total de jobs de sync ejecutados) * 100

Ejemplo: 15 jobs fallidos sobre 1.500 jobs ejecutados en el dia = 1% error rate
```

**Unidad de medida:** Porcentaje (%)

**Fuente de datos:** BullMQ job queue — jobs en estado "failed" vs. jobs en estado "completed"

| Criterio | Meta | Interpretacion |
|---|---|---|
| Verde (Optimo) | < 1% | Alta confiabilidad. Los errores son casos excepcionales. |
| Amarillo (Tolerable) | 1-3% | Hay problemas intermitentes. Monitorear y buscar patron de falla. |
| Rojo (Deficiente) | > 3% | El servicio es poco confiable. Los clientes estan sufriendo errores frecuentes. |

**Frecuencia de medicion:** Diaria (dashboard de monitoreo automatico)

**Alertas automaticas:** Configurar alerta si el error rate supera el 2% en una ventana de 1 hora — indica un problema activo, no solo ruido estadistico.

**Causas comunes de error:**
- Cambios en la API de Bsale (sin aviso previo)
- Timeout en el CMS del cliente (servidor lento o caido)
- Token de API de Bsale expirado o revocado
- Limite de rate de la API alcanzado (429 Too Many Requests)

---

## 7. NPS / CSAT — Satisfaccion del Cliente

**Definicion — NPS (Net Promoter Score):** Mide la probabilidad de que un cliente recomiende el producto a otros, en una escala de 0 a 10.

**Formula NPS:**
```
NPS = % Promotores (9-10) - % Detractores (0-6)

Escala de referencia:
- NPS > 50: Excelente
- NPS 30-50: Bueno
- NPS 0-30: Aceptable
- NPS < 0: Critico
```

**Formula CSAT (Customer Satisfaction Score):**
```
CSAT (%) = (Respuestas positivas / Total de respuestas) * 100

Se pregunta: "Como calificarias tu experiencia hoy?" — escala del 1 al 5
Positivas = calificaciones 4 o 5
```

**Unidad de medida:** NPS = numero entero (-100 a 100). CSAT = porcentaje (%)

**Fuente de datos:** Encuesta automatica por email en:
- Dia 7 del trial (NPS del onboarding)
- Dia 30 de suscripcion paga (NPS del servicio)
- Despues de cada interaccion de soporte (CSAT del soporte)

| Criterio | NPS Meta | CSAT Meta |
|---|---|---|
| Verde (Optimo) | > 40 | > 85% |
| Amarillo (Tolerable) | 20-40 | 70-85% |
| Rojo (Deficiente) | < 20 | < 70% |

**Frecuencia de medicion:** Mensual (consolidado de todas las respuestas del mes)

**Accion si es Rojo:** El NPS bajo indica que hay clientes insatisfechos que pueden hacer churn o dejar reviews negativas. Contactar personalmente a todos los detractores (NPS 0-6) para entender el problema especifico.

---

## 8. Time to First Sync — Tiempo hasta el Primer Sync Exitoso

**Definicion:** El tiempo transcurrido desde que un usuario crea su cuenta hasta que el primer job de sincronizacion se completa exitosamente. Mide la calidad del onboarding — cuanto demora el cliente en recibir el primer valor real del producto.

**Formula:**
```
Time to First Sync = timestamp(primer sync exitoso) - timestamp(creacion de cuenta)

Unidad: minutos

Medir percentiles:
- P50 (mediana): el 50% de los usuarios logran el primer sync en X minutos o menos
- P90: el 90% de los usuarios logran el primer sync en Y minutos o menos
```

**Unidad de medida:** Minutos

**Fuente de datos:** Logs de la aplicacion — timestamp de creacion de cuenta (registro en DB) vs. timestamp del primer job de sync completado (BullMQ)

| Criterio | P50 Meta | P90 Meta |
|---|---|---|
| Verde (Optimo) | < 10 minutos | < 20 minutos |
| Amarillo (Tolerable) | 10-20 minutos | 20-40 minutos |
| Rojo (Deficiente) | > 20 minutos | > 40 minutos |

**Frecuencia de medicion:** Por evento (cada nuevo usuario), consolidar semanalmente para ver tendencia

**Por que importa:** El primer sync exitoso es el "momento aha" del producto — cuando el cliente entiende realmente que funciona. Si este momento tarda mas de 10 minutos, la probabilidad de completar el trial cae drasticamente. El Time to First Sync alto es el sintoma principal de un onboarding deficiente.

**Accion si es Rojo:** Grabar sesiones de onboarding de usuarios en trial (con consentimiento, herramienta como Hotjar). Identificar en que paso se demoran mas y simplificar ese paso especificamente.

---

## Dashboard de Seguimiento — Plantilla Mensual

| KPI | Meta (Verde) | Mes 1 | Mes 2 | Mes 3 | Mes 6 | Mes 12 |
|---|---|---|---|---|---|---|
| MRR (USD) | >= proyeccion base | | | | | |
| Clientes activos | >= proyeccion base | | | | | |
| Churn Rate (%) | < 3% | | | | | |
| CAC (USD) | < USD 35 | | | | | |
| LTV (USD) | > USD 490 | | | | | |
| Trial-to-Paid (%) | > 35% | | | | | |
| Sync Error Rate (%) | < 1% | | | | | |
| NPS | > 40 | | | | | |
| Time to First Sync (min) | < 10 min (P50) | | | | | |

*Completar con los datos reales cada mes. Si un KPI cae en Rojo dos meses seguidos, es una accion prioritaria para la semana siguiente.*

---

## Preguntas Pendientes de Validar

1. **Volumen de jobs de sync por cliente:** El Sync Error Rate depende del volumen total de jobs. Con pocos clientes y pocos productos, 1 error puede representar el 5% — un numero que distorsiona la metrica. Definir un minimo de 100 jobs ejecutados antes de calcular el error rate como metrica de gestion.

2. **Metodo de encuesta NPS:** Las PYMEs chilenas con un perfil tecnico medio-bajo tienen tasas de respuesta bajas en encuestas por email. Considerar si el NPS debe medirse en-app (durante la sesion) en lugar de por email para mejorar la tasa de respuesta y obtener datos validos antes del mes 3.

3. **Definicion de "sync fallido" para el error rate:** Hay distintos tipos de falla — timeout, error de autenticacion, error de datos del producto. Es util medir el error rate total o conviene segmentarlo por tipo de error? Definir la taxonomia de errores antes de construir el dashboard tecnico.
