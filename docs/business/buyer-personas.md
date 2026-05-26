# Buyer Personas — kpcrop-latam-zollner-platform

**Fecha:** 2026-05-22
**Mercado primario:** Chile
**Version:** 1.0

---

## Introduccion

Los siguientes perfiles representan los segmentos de clientes reales mas probables para la plataforma en su fase inicial (MVP y primeros 12 meses). Estan construidos a partir del contexto del mercado de PYMEs chilenas con Bsale y tienda online, y son insumo directo para las decisiones de mensajeria, canales y features prioritarios.

Cada persona incluye un "dia tipico" para ilustrar el dolor concreto, no solo el perfil abstracto.

---

## Persona 1 — Rodrigo, el Comerciante de Ropa

### Perfil

| Atributo | Detalle |
|---|---|
| Nombre ficticio | Rodrigo Sanchez |
| Edad | 38 anos |
| Cargo | Dueno y administrador de tienda de ropa |
| Empresa | Tienda de ropa con local fisico en Providencia y tienda online en PrestaShop |
| Empleados | 3 (el mismo + 2 vendedores de tienda) |
| Facturacion mensual | CLP 4-8 millones |
| ERP | Bsale (plan con punto de venta fisico) |
| CMS | PrestaShop 8.x |
| Nivel tecnico | Bajo — instala apps desde el backoffice pero no sabe programar |

### Dia Tipico (Sin el Producto)

Rodrigo llega los lunes a las 8 AM. Antes de abrir la tienda, revisa las novedades de su proveedor de ropa y actualiza los precios en Bsale. A las 9 AM se da cuenta de que la tienda online todavia muestra los precios de la semana pasada. Entra al backoffice de PrestaShop, busca los productos uno por uno y actualiza los precios manualmente. Esto le toma entre 45 minutos y 2 horas dependiendo de cuantos productos cambio el proveedor. Tres veces al mes, un cliente le escribe diciendo que compro algo que no tenia stock — porque PrestaShop no estaba actualizado con Bsale.

### Dolor Principal

"Tengo dos sistemas y tengo que actualizar ambos. Si cambio el precio en Bsale, la tienda online sigue mostrando el precio viejo. Me ha pasado que vendo cosas online que ya vendimos en la tienda."

### Que Busca

Que Bsale y su tienda online "esten conectados" y que no tenga que actualizar los dos por separado. No sabe como se llama tecnicamente — solo quiere que "funcione solo".

### Proceso de Compra

1. Googlea "conectar Bsale con PrestaShop" o pregunta en grupos de Facebook de comerciantes chilenos
2. Encuentra el producto (via SEO o recomendacion en el grupo)
3. Necesita ver que funciona antes de pagar — es escéptico de soluciones tecnicas que prometen mucho
4. Si el trial funciona y sincroniza sus productos en 10 minutos, convierte inmediatamente
5. No involucra a nadie mas en la decision — decide solo

### Willingness to Pay

| Precio | Reaccion esperada |
|---|---|
| USD 10/mes (CLP 9.500) | "Lo pruebo, es menos que un almuerzo" |
| USD 19/mes (CLP 18.050) | "Caro para lo que hace, pero si me ahorra el trabajo de los lunes, vale" |
| USD 49/mes (CLP 46.550) | "Necesito que me convenzan de que vale tanto" |

**Tier objetivo:** Starter (USD 19/mes) con posible upsell a Growth cuando el sync manual ya no le alcance.

### Canal de Adquisicion

- Grupos de Facebook de comerciantes y emprendedores chilenos
- Busqueda organica Google ("conectar Bsale con PrestaShop")
- Recomendacion de otro comerciante
- Marketplace de Bsale

### Mensaje Clave Para Este Persona

> "Rodrigo, cada vez que actualizas Bsale, tu tienda online se actualiza sola. Sin hacer nada."

### Mapa de Empatia — Rodrigo

| Dimension | Detalle |
|---|---|
| **Que piensa y siente** | Siente que tiene dos trabajos: administrar la tienda fisica y mantener la tienda online. Piensa que "deberia haber una forma mas facil de hacer esto". Le frustra cometer errores de stock. En el fondo, teme que un cliente enojado deje una mala resena en Google por un error de stock que no fue su culpa. |
| **Que oye** | Escucha a otros comerciantes en grupos de Facebook quejarse del mismo problema. Oye a su proveedor anunciar cambios de precios frecuentes. Escucha a sus vendedores decirle que la tienda online muestra precios distintos a los del local. |
| **Que ve** | Ve su backoffice de Bsale y su backoffice de PrestaShop como dos mundos separados. Ve a su competencia con tiendas online que "se ven mas profesionales". Ve tutoriales en YouTube sobre automatizacion pero le parecen demasiado tecnicos. |
| **Que dice y hace** | Dice que "alguna vez lo va a solucionar". Hace la actualizacion manual los lunes con resignacion. Busca en Google "como sincronizar Bsale con PrestaShop" y no encuentra una solucion simple. Pregunta en grupos de Facebook si alguien conoce alguna herramienta. |
| **Dolores** | Tiempo perdido cada semana actualizando dos sistemas. Ventas fallidas por stock incorrecto en la tienda online. Verguenza ante clientes que le reclaman por precios distintos entre el local y la web. Miedo a seguir creciendo online si el problema se va a multiplicar. |
| **Ganancias (aspiraciones)** | Quiere que su tienda online "funcione sola". Quiere confiar en que los precios estan siempre correctos. Quiere mas tiempo para atender clientes y menos tiempo frente al computador haciendo actualizaciones. Quiere vender mas online sin que eso signifique mas trabajo administrativo. |

---

## Persona 2 — Valeria, la PYME con Catalogo Grande

### Perfil

| Atributo | Detalle |
|---|---|
| Nombre ficticio | Valeria Morales |
| Edad | 44 anos |
| Cargo | Gerente de operaciones / socia |
| Empresa | Distribuidora de insumos de oficina con canal B2B y tienda online en WooCommerce |
| Empleados | 12 |
| Facturacion mensual | CLP 20-50 millones |
| ERP | Bsale (plan distribuidor) |
| CMS | WooCommerce sobre WordPress |
| Nivel tecnico | Medio — tiene una persona de TI part-time |
| Catalogo | ~3.000 SKUs activos |

### Dia Tipico (Sin el Producto)

Valeria tiene una persona de TI part-time (Felipe, 20 horas/semana) cuya tarea principal es mantener el catalogo de WooCommerce sincronizado con Bsale. Felipe tiene un Excel con una macro que exporta los productos de Bsale y los importa en WooCommerce via plugin de importacion masiva. El proceso toma 3-4 horas cada lunes y cada vez que hay cambios importantes de precios. Cuando Bsale cambia algun formato de exportacion, la macro de Felipe deja de funcionar y hay que repararla. Valeria estima que Felipe dedica 30-40% de su tiempo a este proceso.

### Dolor Principal

"Tenemos a un programador dedicando un dia entero a la semana a mantener la sincronizacion. Eso es caro y fragil — si Felipe se enferma o se va, nos quedamos sin proceso."

### Que Busca

Eliminar la dependencia de un proceso manual fragil. Quiere un servicio confiable que no requiera mantencion interna. Busca SLA, soporte y evidencia de que el producto escala a 3.000+ productos.

### Proceso de Compra

1. Felipe o Valeria buscan en Google o en el marketplace de Bsale
2. Valeria necesita ver una demo o caso de exito con catalogo grande antes de comprometerse
3. El proceso de decision incluye al menos 2 personas (Valeria + Felipe)
4. Puede tomar 2-4 semanas desde el descubrimiento hasta la firma
5. Necesita factura electronica para la contabilidad de la empresa

### Willingness to Pay

| Precio | Reaccion esperada |
|---|---|
| USD 19/mes | "Muy barato para lo que necesitamos — me preocupa que no soporte nuestro volumen" |
| USD 49/mes (Growth) | "Tiene sentido — Felipe cobra mas que eso en las horas que dedica a esto" |
| USD 120/mes (Agency) | "Solo si el dashboard multi-tienda nos da algo que no tenemos" |

**Tier objetivo:** Growth (USD 49/mes). El sync automatico y el soporte a 10.000 productos son los features que justifican el precio para este perfil.

### Canal de Adquisicion

- Busqueda organica o pagada (Google Ads)
- Marketplace de Bsale (busqueda activa de solucion)
- Recomendacion de otra empresa del sector
- Alianza con agencias WordPress que les implementaron WooCommerce

### Mensaje Clave Para Este Persona

> "Valeria, tu catalogo de 3.000 SKUs sincronizado en Bsale y WooCommerce automaticamente, sin que Felipe tenga que tocarlo cada semana."

### Mapa de Empatia — Valeria

| Dimension | Detalle |
|---|---|
| **Que piensa y siente** | Se siente responsable de que el proceso de sync funcione — si falla, el problema llega a su escritorio. Piensa que el proceso actual es "un parche, no una solucion". Tiene ansiedad cuando Felipe toma vacaciones porque sabe que nadie mas puede mantener la macro de Excel. Siente que su empresa deberia tener soluciones mas profesionales para su tamano. |
| **Que oye** | Escucha a Felipe decir que "la macro se rompio otra vez" con mas frecuencia de la que le gustaria. Oye a su jefe o socia preguntar por que los precios en la web no estan actualizados. Escucha en eventos de negocios que "la automatizacion es el futuro" pero no sabe como aplicarlo a su problema especifico. |
| **Que ve** | Ve que Felipe dedica 30-40% de su tiempo a mantener un proceso que deberia ser automatico. Ve los tickets de soporte del equipo comercial cuando la web muestra precios incorrectos. Ve herramientas de automatizacion en Google pero no sabe cual sirve especificamente para Bsale y WooCommerce. |
| **Que dice y hace** | Dice que "necesitamos una solucion que no dependa de Felipe". Pide demos antes de comprometerse a cualquier herramienta. Involucra a Felipe en la evaluacion tecnica. Busca referencias de otras empresas que ya usen la herramienta antes de aprobar la compra. |
| **Dolores** | Dependencia critica de una sola persona (Felipe) para mantener el proceso. Fragilidad del proceso — cualquier cambio en Bsale rompe la macro. Costo oculto del tiempo de Felipe dedicado a algo que deberia ser automatico. Riesgo reputacional cuando los precios en la web son incorrectos frente a clientes B2B que hacen pedidos basandose en la web. |
| **Ganancias (aspiraciones)** | Quiere que el catalogo de WooCommerce se actualice sin intervension humana. Quiere que Felipe pueda dedicar su tiempo a proyectos de mayor valor. Quiere tener confianza de que si Bsale cambia algo, la web se actualizara igual. Busca una solucion que tenga SLA y soporte — no algo que "funcione hasta que no funcione". |

---

## Persona 3 — Sebastian, el Gerente de Agencia Digital

### Perfil

| Atributo | Detalle |
|---|---|
| Nombre ficticio | Sebastian Torres |
| Edad | 32 anos |
| Cargo | Director / socio de agencia digital |
| Empresa | Agencia de e-commerce con 15-25 clientes activos en Chile |
| Empleados | 8 (desarrolladores, disenadores, project managers) |
| Clientes con Bsale | 8-12 clientes |
| CMS que implementan | PrestaShop, WooCommerce, Shopify |
| Nivel tecnico | Alto — tiene equipo tecnico propio |

### Dia Tipico (Sin el Producto)

Sebastian recibe cada semana 3-5 tickets de soporte de clientes con Bsale que preguntan por que la tienda online no refleja los cambios que hicieron en Bsale. Cada ticket lo atiende un desarrollador junior que toma 1-2 horas en diagnosticar, actualizar manualmente y cerrar el caso. Para los clientes con contrato de mantencion, este trabajo esta incluido sin cargo adicional — es un costo oculto para la agencia. Para los clientes sin contrato de mantencion, facturan por hora, lo que genera fricciones de "me cobran por esto?". Sebastian quiere dejar de hacer este trabajo artesanal y ofrecer sync automatico como servicio de valor agregado.

### Dolor Principal

"Perdemos tiempo de desarrolladores en actualizar catalogo manualmente para clientes. Es trabajo que no escala y que nos hace ver mal ante el cliente porque 'por que no esta sincronizado automaticamente'."

### Que Busca

Una solucion que pueda vender a sus clientes como parte de su servicio mensual de mantencion, idealmente con dashboard para gestionar N clientes desde un solo lugar. Valora poder hacer markup sobre el precio (compra en USD 120 y vende a sus clientes en CLP 30.000-40.000 por tienda).

### Proceso de Compra

1. Sebastian busca activamente — probablemente ya googleo esto antes
2. El proceso de decision es rapido si el precio es razonable y el producto tiene API o dashboard de agencias
3. Quiere probar primero con 1-2 clientes antes de escalar a toda la cartera
4. Necesita que el onboarding sea simple para que sus clientes lo hagan solos o con minima intervencion de su equipo

### Willingness to Pay

| Precio | Reaccion esperada |
|---|---|
| USD 49/mes por tienda | "Caro si tengo 10 clientes — serian USD 490/mes" |
| USD 120/mes (Agency, N tiendas) | "Si puedo hacer markup y lo resuelve para 10 clientes, es una ganga" |
| USD 200/mes (Agency premium) | "Evaluaria si tiene dashboard de agencias completo y SLA" |

**Tier objetivo:** Agency (USD 120/mes). El modelo de negocio de Sebastian depende de que el precio sea por cuenta de agencia, no por tienda de cliente.

### Canal de Adquisicion

- Contacto directo del fundador (canal de ventas B2B)
- LinkedIn (busqueda de directores de agencias digitales en Chile)
- Eventos de e-commerce en Chile (eCommerce Day, etc.)
- Referencia de un cliente mutuo

### Mensaje Clave Para Este Persona

> "Sebastian, gestiona todos tus clientes con Bsale desde un dashboard, hace el markup que quieras y deja de perder horas de desarrollador en sincronizaciones manuales."

### Mapa de Empatia — Sebastian

| Dimension | Detalle |
|---|---|
| **Que piensa y siente** | Siente que el tiempo de sus desarrolladores vale demasiado para desperdiciarlo en actualizaciones manuales de catalogo. Piensa que deberia tener una herramienta para esto — no desarrollar una el mismo. Le incomoda cuando un cliente le pregunta por que la tienda no esta actualizada porque "eso no deberia pasar". Tiene ambicion de hacer crecer la agencia y sabe que los procesos manuales son el cuello de botella. |
| **Que oye** | Escucha a sus desarrolladores quejarse de los tickets repetitivos de "el catalogo no esta actualizado". Oye a otros directores de agencia hablar de herramientas de automatizacion que "cambiaron su operacion". Escucha a sus clientes preguntar si hay una forma de que todo este mas automatizado. |
| **Que ve** | Ve 3-5 tickets identicos por semana de clientes con Bsale reportando desincronizacion. Ve el tiempo de sus desarrolladores consumido por trabajo de bajo valor. Ve una oportunidad de negocio: si tuviera una herramienta para esto, podria ofrecerla a sus clientes y aumentar sus margenes de mantencion. |
| **Que dice y hace** | Dice que "necesito estandarizar esto". Busca activamente herramientas — probablemente ya probo Zapier o Make y descubrio que no sirven para Bsale. Habla con otros directores de agencia para ver como resolvieron el problema. Hace calculos de cuanto le cuesta el problema en horas de desarrollador. |
| **Dolores** | Costo oculto de tener desarrolladores haciendo trabajo que no escala. Dano reputacional ante los clientes por la desincronizacion. Cada cliente nuevo con Bsale agrega friccion operativa en lugar de margen. No tiene una solucion estandar para ofrecer — cada cliente es un caso especial. |
| **Ganancias (aspiraciones)** | Quiere poder ofrecer "integracion Bsale-CMS" como servicio estandar con precio fijo en sus contratos de mantencion. Quiere liberar a sus desarrolladores para proyectos de mayor valor (features nuevas, mejoras de UX). Quiere un dashboard donde pueda ver el estado de todos sus clientes sin preguntar uno por uno. Aspira a que el plan Agency le permita hacer markup y convertir un costo en un ingreso adicional. |

---

## Persona 4 — Catalina, la Emprendedora que Escala

### Perfil

| Atributo | Detalle |
|---|---|
| Nombre ficticio | Catalina Reyes |
| Edad | 29 anos |
| Cargo | Fundadora y unica empleada |
| Empresa | Tienda de cosmeticos naturales, 100% online, Shopify |
| Facturacion mensual | CLP 2-4 millones (en crecimiento) |
| ERP | Bsale (plan basico — empezo hace 6 meses) |
| CMS | Shopify (migracion desde Jumpseller hace 3 meses) |
| Nivel tecnico | Medio — usa apps de Shopify, lee blogs de e-commerce |

### Dia Tipico (Sin el Producto)

Catalina actualizo todos sus precios en Bsale despues de una reunion con su proveedor. Abrio Shopify para hacer lo mismo y se dio cuenta de que tiene 80 productos — van a ser 2 horas de trabajo. Piensa "cuando tenga mas productos voy a necesitar una solucion para esto". No es su problema urgente numero 1 hoy, pero lo ve venir.

### Dolor Principal

"Aun no es un problema critico porque tengo 80 productos, pero si sigo creciendo se va a convertir en un problema. Quiero resolverlo antes de que me explote."

### Que Busca

Una solucion simple, bien documentada y con buen soporte. Valora mucho las opiniones de otros emprendedores y que el producto sea confiable. No quiere contratar a un programador.

### Proceso de Compra

1. Descubre el producto via redes sociales o grupos de emprendedoras
2. Revisa reviews, casos de uso y que "no parezca hecho en 2015"
3. Prueba el trial
4. Convierte si el onboarding es simple y el sync funciona sin errores en su primer intento

### Willingness to Pay

| Precio | Reaccion esperada |
|---|---|
| USD 10/mes | "Lo pruebo" |
| USD 19/mes | "Si me ahorra tiempo, ok" |
| USD 49/mes | "Mucho para donde estoy ahora — quizas en 6 meses" |

**Tier objetivo:** Starter (USD 19/mes), con potencial upsell a Growth cuando el catalogo crezca o cuando quiera sync automatico.

### Canal de Adquisicion

- Instagram / TikTok (contenido sobre automatizacion para emprendedoras)
- Grupos de Facebook de emprendedoras chilenas
- Recomendacion de otra emprendedora
- Marketplace de Bsale

### Mensaje Clave Para Este Persona

> "Catalina, configura la conexion una vez en 10 minutos. Desde ese momento, cuando actualizas en Bsale, Shopify se actualiza solo."

### Mapa de Empatia — Catalina

| Dimension | Detalle |
|---|---|
| **Que piensa y siente** | Se siente orgullosa de su negocio y quiere que "se vea profesional". Piensa que "esto no puede seguir siendo manual cuando tenga 500 productos". Le da algo de verguenza reconocer que no sabe como resolver esto tecnicamente. Siente entusiasmo por automatizar — es nativa digital y le gustan las herramientas tecnologicas. |
| **Que oye** | Escucha en podcasts de emprendimiento sobre "automatizar los procesos repetitivos". Oye a otras emprendedoras en grupos de Instagram recomendando herramientas de gestion. Escucha a su proveedor anunciar cambios de precios y piensa "ahora tengo que actualizar Shopify tambien". |
| **Que ve** | Ve a otras tiendas de cosmeticos online que "parecen mas automatizadas". Ve tutoriales de YouTube sobre Shopify pero no encuentra nada especifico para la integracion con Bsale. Ve su catalogo crecer mes a mes y sabe que el problema va a escalar. |
| **Que dice y hace** | Dice "cuando tenga mas productos voy a necesitar una solucion para esto". Busca en el App Store de Shopify una integracion con Bsale y no encuentra nada. Pregunta en grupos de emprendedoras si alguien usa algo para sincronizar Bsale con Shopify. Lee resenas antes de instalar cualquier app. |
| **Dolores** | El problema no es critico hoy (80 productos) pero lo ve crecer. Actualizacion manual de precios en dos sistemas despues de cada reunion con el proveedor. No sabe programar y no puede pagar un desarrollador. Teme instalar una herramienta que "rompa algo" en su tienda. |
| **Ganancias (aspiraciones)** | Quiere que su tienda online sea un negocio que "corre solo" mientras ella se enfoca en el producto y el marketing. Quiere la seguridad de que cuando actualiza un precio en Bsale, Shopify lo refleja automaticamente. Aspira a escalar a 500-1.000 productos sin que el trabajo administrativo escale proporcional. Quiere sentirse "profesional" — tener sistemas que funcionen como los de empresas grandes. |

---

## Resumen Comparativo de Personas

| Dimension | Rodrigo | Valeria | Sebastian | Catalina |
|---|---|---|---|---|
| Tamano empresa | Micropyme | PYME mediana | Agencia | Emprendedora |
| CMS | PrestaShop | WooCommerce | Multiple | Shopify |
| Urgencia del dolor | Alta | Alta | Media-Alta | Media |
| Tier objetivo | Starter | Growth | Agency | Starter |
| LTV potencial | Bajo-Medio | Medio | Alto | Bajo |
| Tiempo de decision | 1-3 dias | 2-4 semanas | 1-2 semanas | 1-7 dias |
| Canal principal | Facebook / Google | Google / Bsale MP | LinkedIn / directo | Instagram / grupos |
| Necesita demo | No | Si | Si | No |

---

## Implicaciones para el Producto (MVP)

Los buyers personas revelan las siguientes prioridades de feature para el MVP:

1. **Onboarding en menos de 10 minutos sin soporte:** Rodrigo y Catalina deciden solos en el momento. Si el onboarding es complejo, abandonan.

2. **Funcionar con catalogo grande sin degradacion:** Valeria es el mejor cliente en valor monetario y requiere soporte a 3.000+ SKUs sin timeout.

3. **Dashboard multi-tienda:** Sebastian no puede escalar sin esto. Es el gate del tier Agency.

4. **Documentacion clara en espanol:** Los cuatro personas valoran no necesitar soporte tecnico para la instalacion basica.

5. **Prueba social:** Reviews y casos de exito publicados son el principal desbloqueo para Valeria y Sebastian.
