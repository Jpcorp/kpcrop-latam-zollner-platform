# Pitch Deck — Synkrop para Agencias

> 5 slides para call de 20 minutos con director o dueño de agencia digital.
> Formato: conversacional. No leer slides — usarlos como guía de conversación.
> Duración sugerida por slide: ~4 minutos.

---

## SLIDE 1 — El problema de tus clientes (y el tuyo)

**Título visual:** *"Tus clientes sincronizan Bsale con PrestaShop a mano. Cada semana."*

**Contenido:**
- Bsale es el ERP líder en Chile: precios, stock y catálogo viven ahí
- PrestaShop es el CMS de e-commerce que muchos de tus clientes usan
- El puente entre los dos no existe de forma nativa
- Resultado: CSV manual, errores de stock, precios desactualizados, clientes insatisfechos

**Dato de impacto:**
> Promedio de 3-4 horas por semana por tienda dedicadas a sincronización manual.
> Con 10 clientes: **30-40 horas semanales perdidas** en tu cartera.

**Pregunta de apertura para el director:**
*"¿Cuántos de tus clientes actuales usan Bsale y tienen PrestaShop?"*

---

## SLIDE 2 — La solución: sincronización automática bajo tu marca

**Título visual:** *"Plugin instalado en 20 minutos. Sincronización automática. Tu logo."*

**Contenido:**
```
Bsale  ──→  Synkrop  ──→  PrestaShop
               ↑
        Panel de tu agencia
        con tu nombre y logo
```

- Instala el plugin en el sitio del cliente en 20 minutos
- Desde ese momento: precios, stock y productos se sincronizan solos
- Tú ves todos tus clientes en un panel centralizado
- El cliente ve el nombre de **tu agencia**, no el nuestro

**Lo que se sincroniza:**
- Stock disponible en tiempo real
- Precios (lista de precios de Bsale)
- Productos nuevos y actualizaciones de catálogo
- Variantes (tallas, colores, etc.)

**Pregunta para el director:**
*"¿Cuánto tiempo te toma hoy hacer esto para un cliente nuevo?"*

---

## SLIDE 3 — Cómo funciona el negocio para tu agencia

**Título visual:** *"No es un gasto. Es una línea de ingresos recurrente."*

**Tabla de economía:**

| Tú pagas a KeepCrop | Tú cobras a cada cliente | Con 10 clientes |
|---|---|---|
| $190.000/mes (Agency Pro) | $20.000/mes | Ingreso $200.000 → **Margen $10.000** |
| $190.000/mes (Agency Pro) | $25.000/mes | Ingreso $250.000 → **Margen $60.000** |
| $190.000/mes (Agency Pro) | $35.000/mes | Ingreso $350.000 → **Margen $160.000** |

**El argumento clave:**
> Tú defines el precio que le cobras a tu cliente. Nosotros no lo limitamos.
> Puedes ofrecerlo como parte de tu paquete de mantención mensual o como servicio adicional separado.

**Punto de equilibrio:**
- Con 10 clientes pagando $20.000: cubierto desde el primer mes
- Desde el cliente 11: **utilidad pura**

**Pregunta para el director:**
*"¿Cuánto cobras hoy a tus clientes por mantención mensual del e-commerce?"*

---

## SLIDE 4 — Qué incluye el plan y cómo se activa

**Título visual:** *"Agency Pro: tiendas ilimitadas, tu marca, soporte prioritario."*

**Contenido del plan Agency Pro ($190.000/mes):**

| Característica | Detalle |
|---|---|
| Tiendas | Ilimitadas |
| White-label | Logo y nombre de tu agencia |
| Sincronización | Tiempo real (webhooks automáticos) |
| Panel multi-cliente | Dashboard centralizado con estado de cada tienda |
| Instalación | 20 minutos por tienda con guía paso a paso |
| Soporte técnico | Canal prioritario para la agencia (no el cliente final) |
| Compatibilidad | PrestaShop 1.7 y 8.x + Bsale API v1/v2 |

**Proceso de activación:**
1. Agencia crea cuenta en KeepCrop → obtiene API key de administrador
2. Descarga el plugin `.zip` desde el panel
3. Instala en PrestaShop del cliente → configura URL del daemon + secret + token Bsale
4. Primer sync manual para validar → activa webhooks automáticos
5. Cliente queda sincronizado en tiempo real

**Pregunta para el director:**
*"¿Tienes 3 clientes activos con Bsale + PrestaShop con quienes podríamos hacer el piloto?"*

---

## SLIDE 5 — Oferta beta y próximos pasos

**Título visual:** *"60 días gratis. Sin compromisos. Con descuento permanente al terminar."*

**Condiciones del beta:**

| | |
|---|---|
| Precio | **$0 durante 60 días** |
| Requisito | Mínimo 3 clientes activos con Bsale + PrestaShop |
| Compromiso | Llamada de feedback al día 30 y al día 60 |
| Qué obtienes | Panel Agency Pro completo, white-label, tiendas ilimitadas |
| Al terminar | **20% de descuento permanente** → $152.000/mes para siempre |

**Por qué hacemos esto:**
> Somos una empresa nueva en el canal agencias. Necesitamos casos de éxito reales con agencias que nos den feedback honesto. A cambio, te damos el servicio completo gratis y un descuento permanente por ser early adopter.

**Próximos pasos concretos:**
1. Esta semana: confirmar los 3 clientes piloto
2. Semana siguiente: instalación asistida (nosotros guiamos, tú instalas)
3. Día 30: llamada de revisión y ajustes
4. Día 60: decisión de continuar con descuento permanente

---

## Guía de conversación — Preguntas clave por etapa

### Apertura (2 min)
- *"¿Cuántos clientes tienes hoy con Bsale + PrestaShop?"*
- *"¿Cómo resuelven hoy la sincronización de productos?"*

### Discovery (8 min)
- *"¿Cuánto tiempo invierte tu equipo o el cliente en eso por semana?"*
- *"¿Cuánto cobras mensualmente por mantención de e-commerce?"*
- *"¿Has perdido algún cliente por problemas de stock o precios desactualizados?"*

### Propuesta (6 min)
- Mostrar slides 2, 3 y 4
- Calcular el margen en vivo con los números reales del director

### Cierre (4 min)
- *"¿Tienes 3 clientes donde podríamos hacer el piloto?"*
- *"¿Cuándo podríamos tener una llamada técnica para revisar los sitios piloto?"*

### Objeciones frecuentes

| Objeción | Respuesta |
|---|---|
| *"¿Y si Bsale cambia su API?"* | Nosotros mantenemos la integración. Es nuestro producto, no el tuyo. |
| *"¿Qué pasa si el plugin falla?"* | Tú tienes soporte prioritario. El cliente final no sabe que existimos. |
| *"Mis clientes ya tienen otra solución"* | ¿Cuánto les cuesta y qué incluye? (comparar en vivo con la tabla del slide 3) |
| *"No tenemos presupuesto ahora"* | Son 60 días gratis. El costo es tiempo, no dinero. |
| *"¿Funciona con PrestaShop X versión?"* | PS 1.7 y 8.x. Preguntar versión y confirmar en el momento. |

---

*KeepCrop — Pitch interno de ventas para directores de agencia — Junio 2026*
