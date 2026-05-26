# Plan de Accion — Primeros 90 Dias (Q0 → Q1)

**Fecha:** 2026-05-22
**Version:** 1.0
**Periodo cubierto:** Dias 1 al 90 desde la fecha de inicio del proyecto
**Equipo:** 1 programador (fundador), 1 disenador

---

## Introduccion

Este documento establece el plan de accion concreto para los primeros 90 dias del proyecto. Cubre la priorizacion de tareas (Matriz de Eisenhower), el plan de accion semanal, la checklist de formalizacion legal en Chile y la configuracion de los medios de pago.

Los primeros 90 dias son criticos porque determinan si el MVP puede llegar al mercado con al menos un cliente real antes de agotar la motivacion del equipo y el tiempo disponible. El foco debe estar en validar, no en perfeccionar.

---

## 1. Matriz de Eisenhower

La Matriz de Eisenhower clasifica las tareas segun su urgencia e importancia, para forzar la priorizacion y evitar que lo urgente consuma lo importante.

```
                    URGENTE                     NO URGENTE
              ┌─────────────────────────┬─────────────────────────┐
IMPORTANTE    │  CUADRANTE I            │  CUADRANTE II           │
              │  Hacer ahora            │  Planificar y agendar   │
              │  (Crisis / Prioridad 1) │  (Crecimiento / P2)     │
              ├─────────────────────────┼─────────────────────────┤
NO IMPORTANTE │  CUADRANTE III          │  CUADRANTE IV           │
              │  Delegar o reducir      │  Eliminar               │
              │  (Interrupciones / P3)  │  (Perdida de tiempo)    │
              └─────────────────────────┴─────────────────────────┘
```

### Cuadrante I — Urgente e Importante (Hacer Ahora)

| Tarea | Por que es urgente e importante |
|---|---|
| Terminar el conector PrestaShop-Bsale funcional en produccion | Sin esto no hay MVP, no hay primer cliente, no hay validacion del producto |
| Identificar y contactar al primer cliente beta | El camino critico de la validacion empieza aqui. Sin un cliente real, el producto no existe en el mundo real |
| Iniciar contacto con el equipo de Bsale para el listing en el marketplace | El proceso de aprobacion toma 1-3 meses. Cada semana que no se inicia, se retrasa el canal de adquisicion principal |
| Configurar Stripe y MercadoPago en produccion (no solo en modo test) | Sin cobro real no hay ingresos. La configuracion debe estar lista antes del primer trial |
| Montar la landing page con pricing visible | El cliente debe poder entender el producto y el precio en < 30 segundos. Es la primera impresion y el gate de la conversion |

### Cuadrante II — Importante pero No Urgente (Planificar y Agendar)

| Tarea | Por que es importante aunque no urgente hoy |
|---|---|
| Registrar la marca "kpcrop" en INAPI | Protege el activo mas valioso del negocio (el nombre). No es urgente hoy, pero si se retrasa mas de 3 meses hay riesgo de que alguien mas lo registre |
| Construir el conector WooCommerce (segundo CMS) | Ampliar la cobertura de CMS aumenta el SAM. Debe hacerse en el mes 2-3, no antes — primero validar con PrestaShop |
| Escribir documentacion tecnica de onboarding | Una buena documentacion reduce el CAC y mejora la conversion del trial. No urgente hoy, pero critica antes del lanzamiento publico |
| Definir la estrategia de contenidos SEO | Los articulos SEO tardan 3-6 meses en posicionarse. Empezar a escribir en el mes 2 para tener resultados en el mes 6-8 |
| Postular a fondos SERCOTEC/CORFO | Requiere preparar documentacion. Hacer esto despues de tener el primer cliente pagante (da credibilidad a la postulacion) |
| Contactar 5 agencias digitales para alianza Agency | Importante para el crecimiento, pero no necesario para el lanzamiento. Agendar para el mes 2-3 |

### Cuadrante III — Urgente pero No Importante (Reducir o Delegar)

| Tarea | Por que es urgente pero no importante |
|---|---|
| Responder mensajes de redes sociales sobre el proyecto | Crea sensacion de actividad pero no avanza el producto ni consigue clientes reales. Reservar 15 min/dia maximo |
| Elegir el nombre de dominio definitivo | Genera ansiedad pero cualquier dominio disponible con ".com" funciona para el MVP. Decidir en 30 minutos y seguir adelante |
| Optimizar la infraestructura de Railway antes de tener clientes | Optimizar para escala sin clientes es prematura. Hacerlo solo cuando el costo sea relevante |
| Comparar herramientas de email marketing (Mailchimp vs. Brevo vs. otros) | Irrelevante hasta tener 20+ suscriptores. Usar cualquier herramienta gratuita disponible ahora |

### Cuadrante IV — No Urgente y No Importante (Eliminar)

| Tarea | Por que debe eliminarse |
|---|---|
| Construir el conector para Magento 2 antes de validar PrestaShop | Magento 2 es para grandes empresas. Fuera del SAM del MVP. No hacer hasta el ano 2 como minimo |
| Diseno de la marca corporativa completa (manual de marca, variaciones, etc.) | Para el MVP, un logo simple y colores consistentes son suficientes. Un manual de marca completo es lujo del ano 2 |
| Crear cuentas en todas las redes sociales | La presencia en redes sin comunidad ni contenido es ruido. Foco en 1-2 canales maximos (LinkedIn + un grupo de Facebook) |
| Analizar competidores internacionales (Zapier, Make, Celigo) en profundidad | Ya se sabe que son sustitutos parciales. Analisis profundo no cambia el producto en el mes 1 |
| Construir un sistema de afiliados | Caracteristica de escala. Irrelevante hasta tener 100+ clientes activos |

---

## 2. Plan de Accion Semanal — 90 Dias

### Fase 0 — Preparacion (Semanas 1-2, Dias 1-14)

**Objetivo:** Dejar el MVP listo para el primer cliente real.

| Accion | Responsable | Plazo | Recursos | Estado |
|---|---|---|---|---|
| Finalizar el conector PrestaShop-Bsale: sync de productos, stock y precios | Programador | Dia 7 | Node.js + API Bsale + API PrestaShop | Pendiente |
| Configurar BullMQ con jobs de sync automatico (c/15 min para Growth) | Programador | Dia 7 | BullMQ + Redis | Pendiente |
| Montar base de datos multi-tenant en PostgreSQL | Programador | Dia 5 | PostgreSQL + Railway | Pendiente |
| Configurar Stripe en produccion (suscripciones + trial de 14 dias) | Programador | Dia 10 | Stripe API | Pendiente |
| Configurar MercadoPago en produccion | Programador | Dia 10 | MercadoPago API | Pendiente |
| Diseno de landing page (wireframe + implementacion) | Disenador | Dia 12 | Figma + framework frontend | Pendiente |
| Escribir copy de la landing page con pricing visible | Fundador + Disenador | Dia 12 | Contexto de buyer personas | Pendiente |
| Montar la landing page en produccion con dominio definitivo | Programador | Dia 14 | Railway / Vercel | Pendiente |
| Preparar email de contacto con el equipo de Bsale (partnerships) | Fundador | Dia 3 | Informacion del producto | Pendiente |

### Fase 1 — Primer Cliente Beta (Semanas 3-4, Dias 15-28)

**Objetivo:** Tener al menos 1 comercio real usando el producto en produccion.

| Accion | Responsable | Plazo | Recursos | Estado |
|---|---|---|---|---|
| Enviar propuesta al equipo de Bsale para listing en marketplace | Fundador | Dia 15 | Email + informacion del producto | Pendiente |
| Contactar a 10 comercios conocidos con Bsale + PrestaShop para oferta de beta | Fundador | Dia 17 | Lista de contactos personales / LinkedIn | Pendiente |
| Onboarding personalizado con el primer cliente beta (asistido) | Fundador | Dia 21 | Acceso al sistema + soporte directo | Pendiente |
| Documentar los problemas encontrados en el onboarding del primer cliente | Fundador | Dia 22 | Notas / Notion | Pendiente |
| Corregir los bugs criticos identificados en el primer uso real | Programador | Dia 24 | Acceso al codigo + logs | Pendiente |
| Solicitar feedback estructurado al primer cliente beta (entrevista 20 min) | Fundador | Dia 28 | Formulario de entrevista | Pendiente |

### Fase 2 — Lanzamiento y Primeros Pagos (Semanas 5-8, Dias 29-56)

**Objetivo:** 5 clientes pagantes antes del dia 56.

| Accion | Responsable | Plazo | Recursos | Estado |
|---|---|---|---|---|
| Publicar el primer articulo de blog SEO ("Como conectar Bsale con PrestaShop") | Fundador | Dia 32 | Blog en la landing page | Pendiente |
| Publicar en grupos de Facebook de comerciantes chilenos (con caso de exito del beta) | Fundador | Dia 35 | Texto + captura de pantalla del sync funcionando | Pendiente |
| Activar el trial autoservicio (sin asistencia del fundador) | Programador | Dia 30 | Wizard de onboarding en la app | Pendiente |
| Medir Time to First Sync de los primeros 5 trials | Programador | Dia 40 | Logs de la aplicacion | Pendiente |
| Identificar y contactar 10 agencias digitales en Chile para alianza | Fundador | Dia 40 | LinkedIn + listado de agencias | Pendiente |
| Confirmar el estado del listing en el marketplace de Bsale | Fundador | Dia 42 | Seguimiento al contacto de Bsale | Pendiente |
| Tener 3 clientes pagantes activos | Equipo | Dia 45 | Trials activos + conversion | Pendiente |
| Publicar segundo articulo SEO ("Como sincronizar catalogo Bsale con WooCommerce") | Fundador | Dia 50 | Blog | Pendiente |
| Meta: 5 clientes pagantes | Equipo | Dia 56 | Canal organico + marketplace | Pendiente |

### Fase 3 — Crecimiento Temprano (Semanas 9-12, Dias 57-90)

**Objetivo:** 15 clientes pagantes y al menos 1 alianza con agencia.

| Accion | Responsable | Plazo | Recursos | Estado |
|---|---|---|---|---|
| Lanzar el conector WooCommerce en produccion | Programador | Dia 65 | Desarrollo + testing | Pendiente |
| Enviar encuesta NPS a todos los clientes activos (primer NPS formal) | Fundador | Dia 60 | Email transaccional | Pendiente |
| Cerrar la primera alianza con una agencia digital (plan Agency) | Fundador | Dia 75 | Propuesta comercial | Pendiente |
| Revisar metricas de los primeros 2 meses y ajustar proyecciones | Fundador | Dia 65 | Dashboard de KPIs | Pendiente |
| Iniciar tramite de registro de marca en INAPI | Fundador | Dia 70 | INAPI online + pago | Pendiente |
| Medir churn del primer mes completo de clientes pagantes | Fundador | Dia 70 | Stripe + registro interno | Pendiente |
| Publicar tercer articulo SEO y documentacion tecnica de onboarding publica | Fundador | Dia 75 | Blog | Pendiente |
| Hacer revision de costos operativos reales vs. estimados | Fundador | Dia 80 | Railway Dashboard + Stripe | Pendiente |
| Meta: 15 clientes pagantes | Equipo | Dia 90 | Todos los canales activos | Pendiente |
| Retrospectiva de 90 dias — revisar FODA y ajustar estrategia | Fundador | Dia 90 | Este documento + KPIs reales | Pendiente |

---

## 3. Checklist de Formalizacion en Chile

La formalizacion legal permite emitir facturas electronicas, abrir cuenta bancaria empresarial y acceder a fondos publicos. Para un SaaS digital, la estructura recomendada es Sociedad por Acciones (SpA) o inicio de actividades como persona natural con giro de servicios de tecnologia.

### 3.1 Registro de la Empresa

| Paso | Organismo | Plazo estimado | Costo estimado | Estado |
|---|---|---|---|---|
| Decidir entre persona natural o SpA | Contador o abogado | 1 semana | Sin costo (decision interna) | Pendiente |
| Constituir SpA via el Registro de Empresas y Sociedades (RES) en linea | Ministerio de Economia — portal empresasenundia.cl | 1 dia habil | USD 0 (constitucion gratuita en linea) | Pendiente |
| Obtener RUT de la empresa | SII | Automatico post-constitucion | USD 0 | Pendiente |

**Nota:** Si el fundador opera como persona natural, puede iniciar actividades directamente sin constituir empresa. La SpA es recomendada si se planea recibir inversion o tener socios.

### 3.2 Inicio de Actividades en el SII

| Paso | Organismo | Plazo estimado | Costo estimado | Estado |
|---|---|---|---|---|
| Iniciar actividades en SII (giro: "Servicios de informatica y tecnologia") | Servicio de Impuestos Internos — sii.cl | 1 dia habil | USD 0 | Pendiente |
| Solicitar timbraje de facturas electronicas (o contratar software de facturacion) | SII + proveedor de facturacion electronica | 1 semana | CLP 0-15.000/mes segun proveedor | Pendiente |
| Verificar si el giro aplica IVA a servicios digitales exportados (clientes fuera de Chile) | SII o contador | 1 semana | Tiempo de consulta | Pendiente |

**Giro recomendado:** "Servicios de tecnologia de la informacion" (Codigo SII 620000 — Actividades de tecnologia de la informacion y servicios informaticos). Permite emitir facturas electronicas para servicios SaaS.

### 3.3 Patente Municipal

| Paso | Organismo | Plazo estimado | Costo estimado | Estado |
|---|---|---|---|---|
| Verificar si aplica exencion de patente para microempresa o inicio de actividades | Municipalidad correspondiente al domicilio del negocio | 1 semana | Sin costo (consulta) | Pendiente |
| Solicitar patente comercial si se requiere (generalmente para empresas con local fisico) | Municipalidad | 2-4 semanas | Variable segun municipio — tipicamente CLP 20.000-100.000/ano | Pendiente |

**Nota practica:** Para una empresa 100% digital que opera desde el domicilio del fundador sin atencion de publico, en la mayoria de los municipios de Chile no se requiere patente comercial en los primeros anos o el costo es minimo. Confirmar con la municipalidad correspondiente.

### 3.4 Cuenta Bancaria Empresarial

| Paso | Organismo | Plazo estimado | Costo estimado | Estado |
|---|---|---|---|---|
| Abrir cuenta corriente o cuenta vista empresarial | Banco o fintech (Banco BCI, Scotiabank, Tenpo, Khipu, Fintoc) | 1-2 semanas | Variable — muchas fintechs ofrecen cuenta empresarial gratuita | Pendiente |
| Alternativamente: usar cuenta personal del fundador con separacion contable clara | Fundador | Inmediato | Sin costo (riesgo legal menor en fase inicial) | Pendiente |

**Recomendacion:** Para una empresa digital en etapa inicial, una cuenta de Tenpo Empresas o similar (sin costo de apertura) es suficiente. Stripe requiere una cuenta bancaria en Chile para transferir los fondos recaudados.

### 3.5 Registro de Marca en INAPI

| Paso | Organismo | Plazo estimado | Costo estimado | Estado |
|---|---|---|---|---|
| Buscar si el nombre "kpcrop" ya esta registrado en INAPI | INAPI — inapi.cl (busqueda gratuita) | 1 hora | USD 0 | Pendiente |
| Presentar solicitud de registro de marca (clase 42 — Servicios de tecnologia y software) | INAPI — tramite online | 1 dia habil | ~CLP 300.000 (~USD 315) | Pendiente |
| Seguimiento del proceso de registro | INAPI | 12-18 meses (proceso completo) | Sin costo adicional | Pendiente |

**Nota:** El registro de marca en INAPI no es obligatorio para operar, pero protege el nombre ante terceros. Se recomienda hacer la solicitud en el mes 2-3 del proyecto, antes del lanzamiento publico.

### 3.6 Registro en el Marketplace de Bsale

| Paso | Organismo | Plazo estimado | Costo estimado | Estado |
|---|---|---|---|---|
| Contactar al equipo de Bsale (partnerships@bsale.app o formulario del marketplace) | Bsale | Esta semana (Dia 1-3) | Sin costo | Pendiente |
| Preparar ficha del producto: descripcion, screenshots, pricing, documentacion tecnica | Fundador + Disenador | Dia 5-10 | Tiempo del equipo | Pendiente |
| Enviar solicitud de listing con toda la documentacion preparada | Fundador | Dia 14 maximo | Sin costo (a confirmar) | Pendiente |
| Aprobacion del listing por parte de Bsale | Bsale | 1-3 meses | Sin costo (a confirmar) | Pendiente |
| Publicacion del listing en el marketplace de Bsale | Bsale | Post-aprobacion | Sin costo (a confirmar) | Pendiente |

### 3.7 Formalizacion de Stripe y MercadoPago como Empresa

| Paso | Organismo | Plazo estimado | Costo estimado | Estado |
|---|---|---|---|---|
| Actualizar cuenta de Stripe de personal a cuenta empresarial (con RUT de empresa) | Stripe — dashboard.stripe.com | 1 dia habil | Sin costo | Pendiente |
| Completar el proceso KYB (Know Your Business) de Stripe con documentos de la empresa | Stripe | 1-3 dias habiles | Sin costo | Pendiente |
| Crear cuenta de MercadoPago a nombre de la empresa | MercadoPago — mercadopago.cl | 1 dia habil | Sin costo | Pendiente |
| Verificar los limites de transferencia de MercadoPago para cuentas empresariales | MercadoPago | 1 semana | Sin costo | Pendiente |
| Configurar los webhooks de Stripe para eventos de suscripcion (creacion, pago, cancelacion) | Programador | Dia 8-10 | Tiempo de desarrollo | Pendiente |
| Probar el flujo completo de pago en produccion con una suscripcion real | Programador | Dia 12 | Tarjeta real del fundador | Pendiente |

---

## 4. Resumen de Inversion de Tiempo — 90 Dias

| Actividad | Responsable | Horas estimadas | Porcentaje del total |
|---|---|---|---|
| Desarrollo del conector PrestaShop + infraestructura | Programador | 120 horas | 45% |
| Desarrollo del conector WooCommerce | Programador | 40 horas | 15% |
| Marketing, ventas y adquisicion de clientes | Fundador | 40 horas | 15% |
| Formalizacion legal y administrativa | Fundador | 10 horas | 4% |
| Disenador — landing page y UX | Disenador | 30 horas | 11% |
| Soporte a clientes beta | Fundador | 20 horas | 7% |
| Documentacion y contenido SEO | Fundador | 8 horas | 3% |
| **Total 90 dias** | | **~268 horas** | **100%** |

---

## Preguntas Pendientes de Validar

1. **Estructura legal optima — persona natural vs. SpA:** Para recibir pagos de Stripe y MercadoPago en Chile, emitir facturas electronicas a clientes empresariales y eventualmente postular a fondos CORFO, conviene mas operar como persona natural con inicio de actividades o constituir una SpA desde el inicio? Consultar con un contador antes del dia 14.

2. **Requisitos de KYB de Stripe para empresas en Chile:** El proceso de verificacion de identidad empresarial de Stripe para cuentas en Chile puede demorar mas de lo esperado y requerir documentacion especifica (escritura de la empresa, RUT, etc.). Confirmar los requisitos exactos en el panel de Stripe antes de comprometerse a una fecha de lanzamiento.

3. **Costo real del registro en el marketplace de Bsale:** La informacion disponible sugiere que el listing es gratuito, pero esto no ha sido confirmado directamente con el equipo de Bsale. Si hay un costo de registro (fee anual o comision sobre ventas), esto cambia el analisis financiero del canal 1 y debe incluirse en el plan financiero.
