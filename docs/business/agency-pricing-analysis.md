# Analisis de Pricing — Canal Agencias

**Fecha:** 2026-06-20
**Version:** 1.0
**Contexto:** Pivot a canal agencias confirmado. Este documento consolida el analisis de costos e ingresos para determinar los precios del plan Agency y construir el argumento economico del one-pager (#58).

---

## 1. Costo Real de Servir a una Agencia

Lo que le cuesta a KeepCrop operar el servicio para una agencia con N tiendas activas.

| Concepto | Costo | Notas |
|---|---|---|
| Infraestructura fija (Railway + dominio + monitoreo) | USD 80/mes | Se divide entre todas las agencias activas |
| Railway variable por tienda sincronizando | USD 0.50/tienda/mes | Estimado conservador, validar con test de carga |
| Stripe fee por cobro mensual | ~3% + USD 0.30 | Sobre Agency Standard: ~USD 3.27 / Agency Pro: ~USD 6.10 |
| Soporte estimado | ~USD 10/mes/agencia | Meta: < 15 min por ticket, 80% self-service |

### Ejemplo con 1 agencia de 10 clientes (Agency Pro)

```
Infraestructura fija (asignada):     USD 80.00
Variable: 10 tiendas × USD 0.50:     USD  5.00
Stripe fee:                           USD  6.10
Soporte:                              USD 10.00
─────────────────────────────────────────────
Costo total para KeepCrop:           USD 101.10/mes
```

---

## 2. Precios Recomendados

Basado en el analisis de costos, el mercado comparable (issue #41) y la estrategia definida en `pricing-strategy.md` v2.0.

### Canal Agencias (foco comercial)

| Plan | Precio/mes | Precio/ano (15% dto.) | Limite tiendas | White-label | Sync |
|---|---|---|---|---|---|
| **Agency Standard** | **USD 99** | USD 1.009 | Hasta 15 | No | Cada 15 min |
| **Agency Pro** | **USD 199** | USD 2.030 | Ilimitadas | Si | Webhook (tiempo real) |
| **Beta (primeros 3)** | **Gratis 60 dias** | — | Ilimitadas | Si | Webhook |

### Canal Directo (secundario)

| Plan | Precio/mes | Tiendas | Sync |
|---|---|---|---|
| Starter | USD 19 | 1 | Manual |
| Growth | USD 49 | 3 | Cada 15 min |

---

## 3. Margen de KeepCrop por Plan

### Agency Standard (USD 99/mes, hasta 15 tiendas)

| Escenario | Tiendas activas | Costo KeepCrop | Ingreso | Margen | Margen % |
|---|---|---|---|---|---|
| Agencia pequena | 5 | USD 52 | USD 99 | USD 47 | 47% |
| Agencia media | 10 | USD 99 | USD 99 | USD 0 | 0% |
| Agencia al limite | 15 | USD 147 | USD 99 | -USD 48 | — |

> Agency Standard no es rentable con 15 tiendas. El precio funciona solo si la agencia promedio tiene 5-8 clientes. Para agencias que crecen, el upsell natural a Pro es la solucion.

### Agency Pro (USD 199/mes, tiendas ilimitadas)

| Escenario | Tiendas activas | Costo KeepCrop | Ingreso | Margen | Margen % |
|---|---|---|---|---|---|
| Agencia pequena | 5 | USD 102 | USD 199 | USD 97 | 49% |
| Agencia media | 15 | USD 148 | USD 199 | USD 51 | 26% |
| Agencia grande | 30 | USD 221 | USD 199 | -USD 22 | — |
| Agencia grande | 50 | USD 331 | USD 199 | -USD 132 | — |

> Agency Pro tiene un techo de rentabilidad alrededor de las 20-25 tiendas. Para agencias muy grandes, se necesita un precio por volumen o un tier Enterprise a definir en v2.

**Conclusion practica:** El modelo es rentable con la mayoria de agencias del mercado chileno (tipicamente 3-15 clientes activos). El riesgo de una agencia con 30+ tiendas en Plan Pro es real — considerar un limite de 25 tiendas en Agency Pro o un tier Enterprise desde tienda 26.

---

## 4. La Economia para la Agencia

Este es el argumento del one-pager: Synkrop no es un gasto, es un producto que la agencia revende con margen.

### Escenario Agency Pro (USD 199/mes)

La agencia cobra a cada uno de sus clientes por el servicio de sincronizacion (bajo su marca):

| Precio que cobra la agencia/cliente | 5 clientes | 10 clientes | 15 clientes | 20 clientes |
|---|---|---|---|---|
| $15.000 CLP/mes (~USD 16) | USD 80 | USD 160 | USD 240 | USD 320 |
| $20.000 CLP/mes (~USD 21) | USD 105 | USD 210 | USD 315 | USD 420 |
| $25.000 CLP/mes (~USD 26) | USD 130 | USD 263 | USD 395 | USD 526 |
| $35.000 CLP/mes (~USD 37) | USD 185 | USD 370 | USD 553 | USD 737 |

**Con precio conservador de $20.000 CLP/cliente:**
```
10 clientes × $20.000 CLP = $200.000 CLP ≈ USD 211/mes de ingreso
Menos: USD 199/mes a KeepCrop
═══════════════════════════════════════
Margen de la agencia: +USD 12/mes desde el cliente 1
Cliente 11 en adelante: ~USD 21/mes de utilidad pura
```

**Con precio moderado de $25.000 CLP/cliente:**
```
10 clientes × $25.000 CLP = $250.000 CLP ≈ USD 263/mes de ingreso
Menos: USD 199/mes a KeepCrop
═══════════════════════════════════════
Margen de la agencia: +USD 64/mes
A 20 clientes: +USD 327/mes de margen
```

> El precio que cobra la agencia a su cliente es **decision de la agencia**. KeepCrop no lo limita ni lo controla. Este es el argumento del white-label: la agencia construye su propio margen.

---

## 5. Comparacion con Alternativas para la Agencia

¿Que le costaria a una agencia resolver este problema de otra forma?

| Alternativa | Costo estimado | Problema |
|---|---|---|
| Desarrollo a medida por agencia | USD 3.000 - 8.000 (una vez) + mantenimiento | No escala a varios clientes; requiere dev propio |
| Contratarle a cada cliente su propio plugin | USD 19-49/mes × N clientes | Sin descuento por volumen; sin panel centralizado |
| Exportar/importar CSV manualmente | USD 0 directo, ~4h/semana de tiempo | Costo de oportunidad alto; errores frecuentes |
| Zapier o Make.com | USD 29-99/mes por flujo | Requiere configuracion tecnica; no hay soporte para PrestaShop-Bsale nativo |
| **Synkrop Agency Pro** | **USD 199/mes ilimitado** | **Instalacion en 20 min; panel centralizado; no requiere dev** |

---

## 6. Oferta Beta para Primeras Agencias

Para los primeros 3 deals del canal agencias (issue #61):

| Condicion | Detalle |
|---|---|
| Precio | Gratis durante 60 dias |
| Requisito | Al menos 3 clientes activos con Bsale + PrestaShop |
| Compromiso de la agencia | Llamada de feedback al dia 30 y al dia 60 |
| Que obtiene KeepCrop | Testimonio real, datos de uso, caso de exito para el one-pager |
| Al termino del beta | Precio Agency Pro (USD 199) con 20% de descuento permanente por ser early adopter |

---

## 7. Punto de Equilibrio del Canal Agencias

Cuantas agencias necesita KeepCrop para que el canal sea rentable por si solo.

Asumiendo USD 80/mes de costos fijos compartidos + USD 10/mes de soporte por agencia:

| Agencias activas | MRR Agency Pro | Costos (infra + soporte) | Resultado |
|---|---|---|---|
| 1 | USD 199 | USD 90 | +USD 109 |
| 3 | USD 597 | USD 110 | +USD 487 |
| 5 | USD 995 | USD 130 | +USD 865 |
| 10 | USD 1.990 | USD 180 | +USD 1.810 |

> **El canal agencias es rentable desde la primera agencia pagante.** Una sola agencia en Agency Pro (USD 199/mes) cubre los costos fijos operativos completos del producto.

---

## 8. Preguntas Pendientes de Validar

Antes de lanzar el outreach a agencias (#60), validar:

| Pregunta | Como validar | Urgencia |
|---|---|---|
| ¿USD 99/199 es razonable para el mercado chileno? | Incluir en las calls de discovery del outreach | Alta |
| ¿Cuanto cobra hoy la agencia a su cliente por "mantener el e-commerce"? | Preguntar directamente en la call | Alta |
| ¿La agencia prefiere cobrar fee fijo o % sobre el precio del plugin? | Explorar en discovery | Media |
| ¿Necesitan factura electronica de KeepCrop desde el inicio? | Pregunta de cierre en la call | Alta |
| ¿El modelo Agency Standard (USD 99, 15 tiendas) tiene demanda o todos piden Pro? | Validar con las 3 agencias beta | Media |

---

## Referencias

- [Estrategia de Pricing v2.0](./pricing-strategy.md) — estructura completa de tiers y justificacion
- [Plan Financiero](./financial-plan.md) — costos fijos, variables y proyecciones
- Issue #41 — Validar pricing Agency con 3 agencias reales
- Issue #58 — One-pager y pitch deck para propuesta a agencias
- Issue #61 — Condiciones de la oferta beta

*Tipo de cambio de referencia usado: 1 USD = 950 CLP*
