# Guia de Instalacion — Modulo Bsale Sync para PrestaShop

Tiempo estimado: **8–10 minutos**
Requisitos: PrestaShop 1.7.x · Cuenta Bsale activa · API Key de licencia kpcrop

---

## Antes de comenzar

Necesitas tener a mano:

1. **Tu token de acceso de Bsale**
   - Entra a Bsale → haz clic en tu nombre (arriba a la derecha) → **Mi cuenta**
   - Ve a la seccion **Integraciones**
   - Copia el campo **Access Token** (es una cadena larga de letras y numeros)

2. **Tu API Key de licencia kpcrop**
   - Te la entrego por correo al activar tu suscripcion
   - Tiene el formato `kp_...`

---

## Paso 1 — Descargar el modulo

Descarga el archivo `bsalesync-v1.0.0.zip` que te enviamos.
No descomprimas el archivo — PrestaShop lo necesita en formato ZIP.

---

## Paso 2 — Subir e instalar el modulo

1. Entra al backoffice de tu PrestaShop
   (normalmente: `tutienda.cl/admin` o la URL que te dio tu proveedor de hosting)

2. En el menu lateral, haz clic en **Modulos**

3. Haz clic en el boton **Subir un modulo** (arriba a la derecha)

4. Arrastra el archivo `bsalesync-v1.0.0.zip` o haz clic en el area para seleccionarlo

5. PrestaShop instalara el modulo automaticamente en unos segundos.
   Veras el mensaje **"El modulo se ha instalado correctamente"**.

---

## Paso 3 — Abrir el panel de Bsale Sync

1. En el menu lateral, haz clic en **Catalogo**

2. Aparece una nueva opcion: **Bsale Sync** — haz clic ahi

3. Veras el panel principal con un formulario de configuracion.

---

## Paso 4 — Configurar tus credenciales

El panel mostrara dos campos:

**Token de acceso Bsale**
- Pega el Access Token que copiaste en el Paso 0
- Haz clic en **Verificar** — deberia aparecer `✓ Conectado — [tu correo de Bsale]`
  Si aparece un error, revisa que el token este completo y sin espacios.

**API Key de licencia kpcrop**
- Pega la API Key que te entregamos (`kp_...`)
- Haz clic en **Verificar** — deberia aparecer `✓ Licencia activa`
  Si aparece un error, contactanos a soporte@kpcrop.com

Cuando ambos campos muestren el visto bueno verde, haz clic en **Guardar configuracion**.

El panel se recargara y mostrara la barra verde **"Conexion configurada"**.

---

## Paso 5 — Configurar lista de precios y sucursal

Haz clic en el enlace **Configuracion avanzada** (arriba a la derecha del panel).

Aparece la pagina de configuracion del modulo con estos campos adicionales:

**ID de lista de precios Bsale**
- En Bsale, ve a **Inventario → Listas de precios** y anota el numero ID de la lista
  que quieres usar en tu tienda (generalmente la lista base o minorista)
- Ingresa ese numero en el campo

**ID de sucursal (stock)**
- Si tienes varias sucursales en Bsale y quieres mostrar el stock de una en particular,
  ingresa su ID (lo encuentras en **Bsale → Configuracion → Sucursales**)
- Si lo dejas en blanco, se sumara el stock de todas las sucursales

Haz clic en **Guardar**.

---

## Paso 6 — Primera sincronizacion

1. Vuelve al panel **Catalogo → Bsale Sync**

2. Haz clic en el boton **Productos**

3. Veras una barra de progreso con el tiempo transcurrido.
   El tiempo depende de cuantos productos tengas en Bsale:
   - Hasta 500 productos: ~1–2 minutos
   - Hasta 2000 productos: ~5–8 minutos
   - Mas de 5000 productos: ~15–25 minutos

4. Al terminar, veras el resultado:
   `N productos actualizados, X errores`

   Si hay errores, aparece la lista con el codigo SKU y el motivo.
   Los errores mas comunes son productos sin codigo SKU en Bsale —
   asigna un codigo en Bsale y vuelve a sincronizar.

---

## Paso 7 — Verificar en tu tienda

1. Ve al frontend de tu tienda
2. Busca uno de tus productos de Bsale — deberia aparecer con nombre, precio y stock

Si los productos no aparecen publicados, puede ser porque en Bsale estan marcados
como inactivos. El modulo respeta el estado del producto en Bsale.

---

## Paso 8 — Sincronizaciones futuras

Puedes volver a sincronizar en cualquier momento desde **Catalogo → Bsale Sync**.
La sincronizacion es **idempotente**: si un producto ya existe, lo actualiza.
Si es nuevo, lo crea. Nunca genera duplicados.

Para mantener precios y stock actualizados recomendamos sincronizar:
- **Precios**: cada vez que hagas un cambio de lista de precios en Bsale
- **Stock**: diariamente o antes de una campana de ventas
- **Productos**: cuando agregues productos nuevos en Bsale

---

## Solucion de problemas comunes

### El boton "Verificar" de Bsale muestra error

- Revisa que el token no tenga espacios al inicio o al final
- Confirma que tu cuenta Bsale este activa
- Prueba generando un nuevo token en Bsale (Integraciones → Regenerar token)

### La sincronizacion muestra "Error al validar licencia"

- Verifica que la API Key kpcrop empiece con `kp_`
- Si tu suscripcion vencio, contactanos para renovarla

### Los productos no aparecen en la tienda despues del sync

- Revisa en PS backoffice → Catalogo → Productos que los productos existan
- Confirma que la categoria de los productos no este oculta en PS
- Los productos importados de Bsale quedan en estado "activo" segun el campo `state` de Bsale

### El sync tarda mucho / no termina

- Catalogs grandes (>5000 productos) pueden tardar 20+ minutos — es normal
- No cierres el navegador durante el sync
- Si el sync falla a mitad, puedes volver a iniciarlo — retoma desde donde quedo (idempotente)

---

## Soporte

- **Email**: soporte@kpcrop.com
- **Tiempo de respuesta**: 24–48 horas habiles

Para reportar un error, incluye:
- Tu version de PrestaShop (aparece abajo del backoffice)
- El mensaje de error exacto
- Un pantallazo del historial de sincronizaciones del panel
