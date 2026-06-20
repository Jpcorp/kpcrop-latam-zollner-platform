# Proyeccion Financiera a 12 Meses — KeepCrop / Synkrop

**Fecha:** 2026-06-20
**Version:** 1.0
**Periodo:** Julio 2026 – Junio 2027
**Moneda:** Pesos Chilenos (CLP)

> Proyeccion para escenario base. Incluye flujo de caja mensual, estado de resultados, punto de equilibrio, VAN y TIR. Todos los valores en pesos chilenos ($).

---

## 1. Supuestos del Modelo

### Inversion Inicial

| Concepto | Monto |
|---|---|
| Desarrollo MVP (60 horas × $25.000/hora, costo de oportunidad) | $1.500.000 |
| Registro SII + marca INAPI (clase 42) | $150.000 |
| Marketing inicial y outreach agencias beta | $200.000 |
| Reserva operacional (2 meses costos fijos) | $150.000 |
| **Total Inversion Inicial** | **$2.000.000** |

### Parametros Financieros

| Parametro | Valor | Fuente |
|---|---|---|
| Tasa de descuento mensual | 1,5% | 18% anual (alta por riesgo startup) |
| Tasa de descuento anual | 18% | Referencia WACC mercado emprendimiento Chile |
| Tipo de cambio referencia | $950 CLP/USD | Referencia junio 2026 |
| Churn mensual estimado | 3% | Conservador para SaaS B2B |
| Impuesto a la renta estimado | 25% | Tasa efectiva persona natural primera categoria |

### Estructura de Precios

| Plan | Precio/mes | Tipo cliente |
|---|---|---|
| Starter | $18.000 | Directo — 1 tienda |
| Growth | $47.000 | Directo — hasta 3 tiendas |
| Agency Standard | $95.000 | Agencia — hasta 15 tiendas |
| Agency Pro | $190.000 | Agencia — ilimitadas + white-label |

### Estructura de Costos

| Concepto | Tipo | Valor |
|---|---|---|
| Infraestructura (Railway + dominio) | Fijo | $76.000/mes |
| Infra variable por tienda | Variable | $475/tienda/mes |
| Stripe fees | Variable | 3% MRR + $285/cliente activo |
| Soporte agencias | Variable | $9.500/mes por agencia (Std o Pro) |

---

## 2. Proyeccion de Clientes (Escenario Base)

Supuesto: 0 clientes pagantes en mes 1 (periodo beta), crecimiento organico desde mes 2 via outreach directo y marketplace Bsale.

| Mes | Starter | Growth | Agency Std | Agency Pro | Total Clientes | MRR |
|-----|---------|--------|------------|------------|----------------|-----|
| 1 | 0 | 0 | 0 | 0 | 0 | $0 |
| 2 | 1 | 0 | 0 | 0 | 1 | $18.000 |
| 3 | 2 | 1 | 0 | 0 | 3 | $83.000 |
| 4 | 3 | 1 | 0 | 1 | 5 | $291.000 |
| 5 | 4 | 2 | 0 | 1 | 7 | $356.000 |
| 6 | 5 | 2 | 1 | 2 | 10 | $659.000 |
| 7 | 6 | 3 | 1 | 2 | 12 | $724.000 |
| 8 | 7 | 3 | 1 | 3 | 14 | $932.000 |
| 9 | 8 | 4 | 2 | 3 | 17 | $1.092.000 |
| 10 | 8 | 4 | 2 | 4 | 18 | $1.282.000 |
| 11 | 9 | 5 | 2 | 4 | 20 | $1.347.000 |
| 12 | 10 | 5 | 3 | 5 | 23 | $1.650.000 |

**MRR calculado:**
- Mes 4: 3×$18.000 + 1×$47.000 + 1×$190.000 = $291.000
- Mes 6: 5×$18.000 + 2×$47.000 + 1×$95.000 + 2×$190.000 = $659.000
- Mes 12: 10×$18.000 + 5×$47.000 + 3×$95.000 + 5×$190.000 = **$1.650.000**

---

## 3. Estado de Resultados Proyectado

### Anual Consolidado

| | Monto | % sobre Ingresos |
|---|---|---|
| **Ingresos por suscripcion** | **$8.434.000** | 100% |
| (-) Costo directo del servicio | ($508.000) | 6% |
| &nbsp;&nbsp;&nbsp;— Comisiones Stripe (3% + $285/cliente) | $290.000 | 3,4% |
| &nbsp;&nbsp;&nbsp;— Infraestructura variable ($475/tienda/mes) | $218.000 | 2,6% |
| **= Margen Bruto** | **$7.926.000** | **94%** |
| (-) Gastos de operacion | ($1.264.000) | 15% |
| &nbsp;&nbsp;&nbsp;— Infraestructura fija ($76.000 × 12) | $912.000 | 10,8% |
| &nbsp;&nbsp;&nbsp;— Soporte agencias (acumulado M4-M12) | $352.000 | 4,2% |
| **= Resultado Operacional (EBITDA)** | **$6.662.000** | **79%** |
| (-) Impuesto a la renta estimado (25%) | ($1.666.000) | 19,7% |
| **= Resultado Neto** | **$4.996.000** | **59%** |

### Desglose Trimestral

| | T1 (M1–M4) | T2 (M5–M8) | T3 (M9–M12) | **Año 1** |
|---|---|---|---|---|
| Ingresos | $392.000 | $2.671.000 | $5.371.000 | $8.434.000 |
| Costos totales | $339.000 | $569.000 | $863.000 | $1.772.000 |
| EBITDA | $53.000 | $2.102.000 | $4.508.000 | $6.662.000 |
| Margen EBITDA | 14% | 79% | 84% | 79% |

> El primer trimestre es el mas ajustado: la inversion en beta y el crecimiento lento de clientes genera un margen bajo. Desde el T2 en adelante, la escala de agencias Pro transforma el margen.

### Mensual Detallado

| Mes | Ingresos | COGS | Margen Bruto | Gastos Op. | EBITDA | Mg EBITDA |
|-----|----------|------|--------------|------------|--------|-----------|
| 1 | $0 | $0 | $0 | $76.000 | **-$76.000** | — |
| 2 | $18.000 | $1.000 | $17.000 | $77.000 | **-$60.000** | — |
| 3 | $83.000 | $5.000 | $78.000 | $76.000 | **+$2.000** | 2% |
| 4 | $291.000 | $27.000 | $264.000 | $86.000 | **+$188.000** | 65% |
| 5 | $356.000 | $31.000 | $325.000 | $86.000 | **+$249.000** | 70% |
| 6 | $659.000 | $68.000 | $591.000 | $105.000 | **+$515.000** | 78% |
| 7 | $724.000 | $72.000 | $652.000 | $105.000 | **+$576.000** | 80% |
| 8 | $932.000 | $94.000 | $838.000 | $114.000 | **+$762.000** | 82% |
| 9 | $1.092.000 | $114.000 | $978.000 | $114.000 | **+$902.000** | 83% |
| 10 | $1.282.000 | $134.000 | $1.148.000 | $133.000 | **+$1.072.000** | 84% |
| 11 | $1.347.000 | $138.000 | $1.209.000 | $133.000 | **+$1.133.000** | 84% |
| 12 | $1.650.000 | $175.000 | $1.475.000 | $175.000 | **+$1.399.000** | 85% |
| **Total** | **$8.434.000** | **$859.000** | **$7.575.000** | **$1.280.000** | **$6.662.000** | **79%** |

---

## 4. Flujo de Caja

### Flujo Mensual y Acumulado

| Mes | Flujo del Periodo | Flujo Acumulado | Hito |
|-----|-------------------|-----------------|------|
| 0 (inversion) | -$2.000.000 | -$2.000.000 | Inversion inicial |
| 1 | -$76.000 | -$2.076.000 | Periodo beta |
| 2 | -$60.000 | -$2.136.000 | Primer cliente |
| 3 | +$2.000 | -$2.134.000 | **Break-even mensual** |
| 4 | +$188.000 | -$1.946.000 | Primera agencia Pro pagando |
| 5 | +$249.000 | -$1.697.000 | |
| 6 | +$515.000 | -$1.182.000 | Primera agencia Std + segunda Pro |
| 7 | +$576.000 | -$606.000 | |
| 8 | +$762.000 | **+$156.000** | **Payback total** |
| 9 | +$902.000 | +$1.058.000 | |
| 10 | +$1.072.000 | +$2.130.000 | |
| 11 | +$1.133.000 | +$3.263.000 | |
| 12 | +$1.399.000 | **+$4.662.000** | Cierre ano 1 |

### Lectura del Flujo de Caja

```
Mes 3:  El negocio genera caja positiva por primera vez (+$2.000)
Mes 8:  Se recupera la inversion inicial completa (+$156.000 acumulado)
Mes 12: Caja acumulada positiva de $4.662.000
        → cada $1 invertido retorna $3,33 al final del ano
```

---

## 5. Punto de Equilibrio

### Break-Even Mensual (cash flow operacional positivo)

El negocio genera flujo positivo desde el **Mes 3**, con 3 clientes pagantes (2 Starter + 1 Growth).

```
Costo fijo mensual:                            $76.000
Margen de contribucion promedio ponderado:
  — Starter ($18.000):   costo variable ~$1.000 → margen $17.000
  — Growth ($47.000):    costo variable ~$5.000 → margen $42.000
  — Agency Std ($95.000): costo variable ~$22.000 → margen $73.000
  — Agency Pro ($190.000): costo variable ~$40.000 → margen $150.000
```

**Punto de equilibrio por tipo de cliente (si todos fueran del mismo tipo):**

| Solo este plan | Clientes necesarios | MRR en equilibrio |
|---|---|---|
| Solo Starter | 5 clientes | $90.000 |
| Solo Growth | 2 clientes | $94.000 |
| Solo Agency Std | 2 clientes | $190.000 |
| Solo Agency Pro | 1 cliente | $190.000 |

> Una sola agencia en Agency Pro cubre todos los costos fijos del producto. El canal agencias tiene el punto de equilibrio mas bajo del modelo.

### Break-Even Acumulado (recuperacion de la inversion)

| Concepto | Monto |
|---|---|
| Inversion inicial | $2.000.000 |
| Perdidas operacionales M1–M2 | $136.000 |
| **Total a recuperar** | **$2.136.000** |
| Recuperacion acumulada M3–M7 | $1.530.000 |
| Recuperacion en M8 | +$762.000 → supera el umbral |
| **Mes de payback** | **Mes 8** |

---

## 6. Valor Actual Neto (VAN) y Tasa Interna de Retorno (TIR)

### Metodologia

- **Tasa de descuento:** 1,5% mensual (18% anual) — tasa alta que refleja el riesgo de un startup SaaS en etapa inicial
- **Horizonte:** 12 meses (no incluye valor terminal del negocio en anos 2+)
- **Flujos:** EBITDA mensual descontado al presente

### Calculo VAN

| Mes | Flujo Neto | Factor Descuento (1,5%/mes) | Valor Presente |
|-----|-----------|----------------------------|----------------|
| 0 | -$2.000.000 | 1,0000 | -$2.000.000 |
| 1 | -$76.000 | 0,9852 | -$74.875 |
| 2 | -$60.000 | 0,9707 | -$58.242 |
| 3 | +$2.000 | 0,9563 | +$1.913 |
| 4 | +$188.000 | 0,9422 | +$177.134 |
| 5 | +$249.000 | 0,9283 | +$231.147 |
| 6 | +$515.000 | 0,9145 | +$470.968 |
| 7 | +$576.000 | 0,9010 | +$519.000 |
| 8 | +$762.000 | 0,8877 | +$676.451 |
| 9 | +$902.000 | 0,8746 | +$788.893 |
| 10 | +$1.072.000 | 0,8617 | +$923.742 |
| 11 | +$1.133.000 | 0,8489 | +$961.809 |
| 12 | +$1.399.000 | 0,8364 | +$1.170.114 |
| | | **VAN =** | **$3.788.054** |

### Resultado VAN

> **VAN = $3.788.000 CLP** (positivo)

Un VAN positivo a una tasa de descuento del 18% anual confirma que el proyecto destruye la tasa de retorno exigida y genera valor economico real. Por cada $1 invertido, el proyecto devuelve $2,89 en valor presente.

### Calculo TIR

La TIR es la tasa de descuento que hace el VAN = 0.

| Tasa mensual | VAN estimado | Interpretacion |
|---|---|---|
| 1,5%/mes (18% anual) | +$3.788.000 | VAN positivo (punto de partida) |
| 10%/mes | +$743.000 | VAN positivo |
| 13%/mes | +$112.000 | VAN positivo (cercano a 0) |
| **14%/mes** | **≈ $0** | **TIR mensual ≈ 14%** |
| 15%/mes | -$168.000 | VAN negativo |

### Resultado TIR

| Metrica | Valor |
|---|---|
| **TIR mensual** | **≈ 14%** |
| **TIR anual equivalente** | **≈ 382%** [(1,14)^12 – 1] |

> Una TIR del 382% anual es caracteristica de negocios de software con baja inversion de capital y alto margen. El indicador refleja que el proyecto es extremadamente rentable en relacion a lo invertido, no que "crezca un 382% al ano". Lo que importa para la decision es que la **TIR (14% mensual) supera con creces la tasa de descuento (1,5% mensual)** → el proyecto aprueba el filtro de inversion.

---

## 7. Analisis de Sensibilidad — 3 Escenarios

Variacion sobre el numero de clientes respecto al escenario base.

| Metrica | Conservador (-40%) | **Base** | Optimista (+60%) |
|---|---|---|---|
| Clientes mes 12 | 14 | **23** | 37 |
| MRR mes 12 | ~$990.000 | **$1.650.000** | ~$2.640.000 |
| Ingreso anual | ~$5.060.000 | **$8.434.000** | ~$13.500.000 |
| EBITDA anual | ~$3.200.000 | **$6.662.000** | ~$11.000.000 |
| Margen EBITDA | ~63% | **79%** | ~81% |
| VAN (18% anual) | ~$1.350.000 | **$3.788.000** | ~$7.200.000 |
| TIR mensual | ~9% | **~14%** | ~18% |
| Payback | Mes 10-11 | **Mes 8** | Mes 6 |
| Break-even mensual | Mes 5 | **Mes 3** | Mes 3 |

### Escenario Conservador

- Crecimiento mas lento: 14 clientes al ano, sin agencias Pro hasta el mes 6
- VAN positivo igualmente: el proyecto es viable incluso con 40% menos clientes
- Riesgo: el payback se extiende a mes 10-11, aumentando la exigencia de caja

### Escenario Optimista

- 1-2 agencias Pro desde el mes 3 (acceso rapido via referido o marketplace Bsale)
- MRR supera $1M CLP en el mes 7 (vs. mes 9 en base)
- Payback en mes 6, VAN de $7.2M

### Factores que desplazan entre escenarios

| Factor | Hacia conservador | Hacia optimista |
|---|---|---|
| Velocidad de onboarding | > 30 dias para primer sync | < 10 dias |
| Canal agencias | Sin deals en 6 meses | Primera agencia en mes 2-3 |
| Churn | > 5% mensual | < 2% mensual |
| Marketplace Bsale | Sin listing en ano 1 | Listing aprobado en mes 3 |

---

## 8. Supuestos Criticos y Riesgos

| Supuesto | Riesgo si falla | Mitigacion |
|---|---|---|
| Primera agencia Pro paga en mes 4 | Payback se extiende 2-3 meses | Outreach a 10 agencias en mes 1-2 |
| Churn ≤ 3% mensual | MRR cae, VAN baja | NPS continuo, soporte activo |
| Stripe procesa sin fricciones | Retraso en ingresos | Cobro mensual automatico desde dia 1 |
| Infra se mantiene en $76.000/mes | Costos escalan con volumen | Revisar Railway usage mensualmente |
| 1 USD = $950 CLP | Devaluacion aumenta costo infra | Reserva en USD para infra (Railway) |

---

## 9. Proyeccion de Indicadores Clave

| KPI | Mes 3 | Mes 6 | Mes 9 | Mes 12 |
|---|---|---|---|---|
| MRR | $83.000 | $659.000 | $1.092.000 | $1.650.000 |
| ARR (×12) | $996.000 | $7.908.000 | $13.104.000 | $19.800.000 |
| Clientes activos | 3 | 10 | 17 | 23 |
| ARPU promedio | $27.700 | $65.900 | $64.200 | $71.700 |
| Margen EBITDA | 2% | 78% | 83% | 85% |
| Flujo acumulado (sin inv.) | -$134.000 | -$47.000 | +$1.905.000 | +$6.662.000 |
| Flujo acumulado (con inv.) | -$2.134.000 | -$1.047.000 | +$905.000* | +$4.662.000 |

*Flujo acumulado con inversion torna positivo en **Mes 8**, no en Mes 9, porque M8 suma $762.000 de golpe.

---

## 10. Resumen Ejecutivo

| Indicador | Resultado |
|---|---|
| Inversion requerida | $2.000.000 CLP |
| Ingresos proyectados ano 1 | $8.434.000 CLP |
| EBITDA ano 1 | $6.662.000 CLP (79% de margen) |
| Resultado neto ano 1 (post impuesto) | ~$4.996.000 CLP |
| MRR al cierre del ano 1 | $1.650.000 CLP |
| Break-even mensual | **Mes 3** |
| Payback total de la inversion | **Mes 8** |
| VAN (18% anual) | **$3.788.000 CLP** |
| TIR mensual / anual equiv. | **14% / 382%** |
| Multiplo sobre inversion (ano 1) | **3,3x** |

### Conclusion

El modelo financiero muestra un proyecto viable en todos los escenarios analizados:

1. **El riesgo de caja es minimo:** la inversion inicial de $2M CLP se recupera en el mes 8. Los primeros 2 meses son los unicos con flujo negativo operacional.

2. **El margen es excepcional:** 79% EBITDA anual es comun en SaaS con baja infraestructura, pero debe protegerse monitoreando el crecimiento de agencias grandes (>25 tiendas en Pro).

3. **El VAN positivo a 18% anual confirma la decision de invertir.** Incluso en escenario conservador (40% menos clientes), el VAN es positivo (~$1.35M CLP).

4. **El canal agencias es el multiplicador critico:** una sola agencia Pro ($190.000/mes) cubre los costos fijos completos. Si el outreach a agencias falla en el primer semestre, el modelo cae al escenario conservador.

5. **La proyeccion NO incluye el valor terminal del negocio** (lo que vale el MRR recurrente a partir del mes 13). Con $1.65M de MRR mensual y churn bajo, el valor de la empresa al cierre del ano 1 — usando multiplo conservador de 3x ARR — seria de ~$59.400.000 CLP (~USD 62.500), lo que hace la inversion inicial de $2M CLP absolutamente justificada.

---

## Referencias

- [Analisis Pricing Canal Agencias](./agency-pricing-analysis.md) — estructura de costos y precios
- [Plan Financiero](./financial-plan.md) — supuestos base del modelo financiero
- [Estrategia de Pricing v2.0](./pricing-strategy.md) — tiers y justificacion de precios
- Issue #58 — One-pager y pitch deck para agencias (este documento es el respaldo financiero)

*Tipo de cambio: 1 USD = $950 CLP. Proyeccion elaborada en junio 2026 con supuestos de mercado chileno.*
