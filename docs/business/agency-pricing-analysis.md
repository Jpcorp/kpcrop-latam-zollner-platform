# Analisis de Pricing — Canal Agencias

**Fecha:** 2026-06-20
**Version:** 1.1
**Contexto:** Pivot a canal agencias confirmado. Este documento consolida el analisis de costos e ingresos para determinar los precios del plan Agency y construir el argumento economico del one-pager (#58). Todos los valores en Pesos Chilenos (CLP).

---

## 1. Costo Real de Servir a una Agencia

Lo que le cuesta a KeepCrop operar el servicio para una agencia con N tiendas activas.

| Concepto | Costo | Notas |
|---|---|---|
| Infraestructura fija (Railway + dominio + monitoreo) | $76.000/mes | Se divide entre todas las agencias activas |
| Railway variable por tienda sincronizando | $475/tienda/mes | Estimado conservador, validar con test de carga |
| Stripe fee por cobro mensual | ~3% + $285 | Sobre Agency Standard: ~$2.950 / Agency Pro: ~$5.985 |
| Soporte estimado | ~$9.500/mes/agencia | Meta: < 15 min por ticket, 80% self-service |

### Ejemplo con 1 agencia de 10 clientes (Agency Pro)

```
Infraestructura fija (asignada):     $76.000
Variable: 10 tiendas × $475:          $4.750
Stripe fee:                            $5.985
Soporte:                               $9.500
─────────────────────────────────────────────
Costo total para KeepCrop:            $96.235/mes
```

---

## 2. Precios Recomendados

Basado en el analisis de costos, el mercado comparable (issue #41) y la estrategia definida en `pricing-strategy.md` v2.0.

### Canal Agencias (foco comercial)

| Plan | Precio/mes | Precio/ano (15% dto.) | Limite tiendas | White-label | Sync |
|---|---|---|---|---|---|
| **Agency Standard** | **$95.000** | $969.000 | Hasta 15 | No | Cada 15 min |
| **Agency Pro** | **$190.000** | $1.938.000 | Ilimitadas | Si | Webhook (tiempo real) |
| **Beta (primeros 3)** | **Gratis 60 dias** | — | Ilimitadas | Si | Webhook |

### Canal Directo (secundario)

| Plan | Precio/mes | Tiendas | Sync |
|---|---|---|---|
| Starter | $18.000 | 1 | Manual |
| Growth | $47.000 | 3 | Cada 15 min |

---

## 3. Margen de KeepCrop por Plan

### Agency Standard ($95.000/mes, hasta 15 tiendas)

| Escenario | Tiendas activas | Costo KeepCrop | Ingreso | Margen | Margen % |
|---|---|---|---|---|---|
| Agencia pequena | 5 | $49.400 | $95.000 | $45.600 | 48% |
| Agencia media | 10 | $94.050 | $95.000 | $950 | 1% |
| Agencia al limite | 15 | $139.650 | $95.000 | -$44.650 | — |

> Agency Standard no es rentable con 15 tiendas. El precio funciona solo si la agencia promedio tiene 5-8 clientes. Para agencias que crecen, el upsell natural a Pro es la solucion.

### Agency Pro ($190.000/mes, tiendas ilimitadas)

| Escenario | Tiendas activas | Costo KeepCrop | Ingreso | Margen | Margen % |
|---|---|---|---|---|---|
| Agencia pequena | 5 | $96.985 | $190.000 | $93.015 | 49% |
| Agencia media | 15 | $140.735 | $190.000 | $49.265 | 26% |
| Agencia grande | 30 | $209.985 | $190.000 | -$19.985 | — |
| Agencia grande | 50 | $313.985 | $190.000 | -$123.985 | — |

> Agency Pro tiene un techo de rentabilidad alrededor de las 20-25 tiendas. Para agencias muy grandes, se necesita un precio por volumen o un tier Enterprise a definir en v2.

**Conclusion practica:** El modelo es rentable con la mayoria de agencias del mercado chileno (tipicamente 3-15 clientes activos). El riesgo de una agencia con 30+ tiendas en Plan Pro es real — considerar un limite de 25 tiendas en Agency Pro o un tier Enterprise desde tienda 26.

---

## 4. La Economia para la Agencia

Este es el argumento del one-pager: Synkrop no es un gasto, es un producto que la agencia revende con margen.

### Escenario Agency Pro ($190.000/mes)

La agencia cobra a cada uno de sus clientes por el servicio de sincronizacion (bajo su marca):

| Precio que cobra la agencia/cliente | 5 clientes | 10 clientes | 15 clientes | 20 clientes |
|---|---|---|---|---|
| $15.000/mes | $75.000 | $150.000 | $225.000 | $300.000 |
| $20.000/mes | $100.000 | $200.000 | $300.000 | $400.000 |
| $25.000/mes | $125.000 | $250.000 | $375.000 | $500.000 |
| $35.000/mes | $175.000 | $350.000 | $525.000 | $700.000 |

**Con precio conservador de $20.000/cliente:**
```
10 clientes × $20.000 = $200.000/mes de ingreso para la agencia
Menos: $190.000/mes a KeepCrop
═══════════════════════════════════════
Margen de la agencia: +$10.000/mes desde el cliente 1
Cliente 11 en adelante: ~$20.000/mes de utilidad pura
```

**Con precio moderado de $25.000/cliente:**
```
10 clientes × $25.000 = $250.000/mes de ingreso para la agencia
Menos: $190.000/mes a KeepCrop
═══════════════════════════════════════
Margen de la agencia: +$60.000/mes
A 20 clientes: $500.000 - $190.000 = +$310.000/mes de margen
```

> El precio que cobra la agencia a su cliente es **decision de la agencia**. KeepCrop no lo limita ni lo controla. Este es el argumento del white-label: la agencia construye su propio margen.

---

## 5. Comparacion con Alternativas para la Agencia

¿Que le costaria a una agencia resolver este problema de otra forma?

| Alternativa | Costo estimado | Problema |
|---|---|---|
| Desarrollo a medida por agencia | $2.850.000 - $7.600.000 (una vez) + mantenimiento | No escala a varios clientes; requiere dev propio |
| Contratarle a cada cliente su propio plugin | $18.000-$47.000/mes × N clientes | Sin descuento por volumen; sin panel centralizado |
| Exportar/importar CSV manualmente | $0 directo, ~4h/semana de tiempo | Costo de oportunidad alto; errores frecuentes |
| Zapier o Make.com | $27.500-$94.000/mes por flujo | Requiere configuracion tecnica; no hay soporte para PrestaShop-Bsale nativo |
| **Synkrop Agency Pro** | **$190.000/mes ilimitado** | **Instalacion en 20 min; panel centralizado; no requiere dev** |

---

## 6. Oferta Beta para Primeras Agencias

Para los primeros 3 deals del canal agencias (issue #61):

| Condicion | Detalle |
|---|---|
| Precio | Gratis durante 60 dias |
| Requisito | Al menos 3 clientes activos con Bsale + PrestaShop |
| Compromiso de la agencia | Llamada de feedback al dia 30 y al dia 60 |
| Que obtiene KeepCrop | Testimonio real, datos de uso, caso de exito para el one-pager |
| Al termino del beta | Precio Agency Pro ($190.000) con 20% de descuento permanente por ser early adopter → $152.000/mes |

---

## 7. Punto de Equilibrio del Canal Agencias

Cuantas agencias necesita KeepCrop para que el canal sea rentable por si solo.

Asumiendo $76.000/mes de costos fijos compartidos + $9.500/mes de soporte por agencia:

| Agencias activas | MRR Agency Pro | Costos (infra + soporte) | Resultado |
|---|---|---|---|
| 1 | $190.000 | $85.500 | +$104.500 |
| 3 | $570.000 | $104.500 | +$465.500 |
| 5 | $950.000 | $123.500 | +$826.500 |
| 10 | $1.900.000 | $171.000 | +$1.729.000 |

> **El canal agencias es rentable desde la primera agencia pagante.** Una sola agencia en Agency Pro ($190.000/mes) cubre los costos fijos operativos completos del producto.

---

## 8. Preguntas Pendientes de Validar

Antes de lanzar el outreach a agencias (#60), validar:

| Pregunta | Como validar | Urgencia |
|---|---|---|
| ¿$95.000/$190.000 es razonable para el mercado chileno? | Incluir en las calls de discovery del outreach | Alta |
| ¿Cuanto cobra hoy la agencia a su cliente por "mantener el e-commerce"? | Preguntar directamente en la call | Alta |
| ¿La agencia prefiere cobrar fee fijo o % sobre el precio del plugin? | Explorar en discovery | Media |
| ¿Necesitan factura electronica de KeepCrop desde el inicio? | Pregunta de cierre en la call | Alta |
| ¿El modelo Agency Standard ($95.000, 15 tiendas) tiene demanda o todos piden Pro? | Validar con las 3 agencias beta | Media |

---

## Referencias

- [Estrategia de Pricing v2.0](./pricing-strategy.md) — estructura completa de tiers y justificacion
- [Plan Financiero](./financial-plan.md) — costos fijos, variables y proyecciones
- Issue #41 — Validar pricing Agency con 3 agencias reales
- Issue #58 — One-pager y pitch deck para propuesta a agencias
- Issue #61 — Condiciones de la oferta beta

*Tipo de cambio utilizado para conversion: 1 USD = 950 CLP. Todos los valores en pesos chilenos ($).*
