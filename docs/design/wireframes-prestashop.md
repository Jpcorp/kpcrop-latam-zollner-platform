# Wireframes — Plugin PrestaShop (Synkrop)

Especificacion de UI para el backoffice de PrestaShop. El diseñador usa esto como base para los mockups finales.

---

## Pantalla 1: Configuracion del Modulo

Ruta: `Admin > Modulos > Synkrop > Configurar`

```
┌─────────────────────────────────────────────────────────────────┐
│  🔵 Synkrop                               Modulos > Synkrop  │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─── Conexion Bsale ────────────────────────────────────────┐  │
│  │                                                             │  │
│  │  Token de acceso Bsale *                                    │  │
│  │  ┌─────────────────────────────────────┐  [ Verificar ]    │  │
│  │  │ ••••••••••••••••••••••••••••••••••• │                   │  │
│  │  └─────────────────────────────────────┘                   │  │
│  │  ℹ Obtén tu token en: Bsale > Mi cuenta > Integraciones    │  │
│  │                                                             │  │
│  │  ✅ Conectado a: "Tienda Ejemplo Ltda." (cpnId: 1234)       │  │ ← estado post-verificacion
│  │                                                             │  │
│  │  Lista de precios a sincronizar *                           │  │
│  │  ┌──────────────────────────────────┐                      │  │
│  │  │ Precio Público (Lista #1)      ▼ │                      │  │
│  │  └──────────────────────────────────┘                      │  │
│  │                                                             │  │
│  │  Sucursal para stock *                                      │  │
│  │  ┌──────────────────────────────────┐                      │  │
│  │  │ Todas las sucursales (sumado)  ▼ │                      │  │
│  │  └──────────────────────────────────┘                      │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                                                                   │
│  ┌─── Licencia kpcrop ───────────────────────────────────────┐  │
│  │                                                             │  │
│  │  API Key de licencia *                                      │  │
│  │  ┌─────────────────────────────────────┐  [ Validar ]      │  │
│  │  │ kp_••••••••••••••••••••••••••••••  │                   │  │
│  │  └─────────────────────────────────────┘                   │  │
│  │                                                             │  │
│  │  ✅ Plan: Growth  |  Tiendas: 1/3  |  Vence: 2026-12-31    │  │ ← estado post-validacion
│  │  Features activos: sync_manual, sync_auto                  │  │
│  │                                                             │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                                                                   │
│  ┌─── Opciones de sincronizacion ────────────────────────────┐  │
│  │                                                             │  │
│  │  Sincronizar:  ☑ Productos   ☑ Precios   ☑ Stock          │  │
│  │                ☐ Clientes    ☐ Imagenes (lento)            │  │
│  │                                                             │  │
│  │  Al sincronizar productos inactivos en Bsale:              │  │
│  │  ● Desactivar en PrestaShop   ○ Eliminar   ○ Ignorar      │  │
│  │                                                             │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                                                                   │
│                              [ Guardar configuración ]            │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### Estados del campo "Token de acceso Bsale"

```
Sin verificar:
┌─────────────────────────────────────┐  [ Verificar ]
│ Ingresa tu token de Bsale          │
└─────────────────────────────────────┘

Verificando:
┌─────────────────────────────────────┐  [ ⏳ Verificando... ]
│ ••••••••••••••••••••••••••••••••••• │
└─────────────────────────────────────┘

Exito:
✅ Conectado a: "Tienda Ejemplo Ltda." (cpnId: 1234)

Error:
❌ Token inválido. Verifica que copiaste el token completo desde Bsale.
```

---

## Pantalla 2: Panel de Sincronizacion

Ruta: `Admin > Catálogo > Synkrop` (tab en el menu lateral)

```
┌─────────────────────────────────────────────────────────────────┐
│  🔵 Synkrop                       Admin > Catálogo > Bsale   │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─── Estado de la conexion ─────────────────────────────────┐  │
│  │  🟢 Bsale conectado   |   🟢 Licencia activa (Growth)     │  │
│  │  Ultimo sync: hace 2 horas  |  1.247 productos en Bsale   │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                                                                   │
│  ┌─── Sincronizacion manual ─────────────────────────────────┐  │
│  │                                                             │  │
│  │   Sincronizar:                                              │  │
│  │   [  Todo  ]  [ Productos ]  [ Precios ]  [ Stock ]        │  │
│  │                                                             │  │
│  │                    ┌────────────────────────────────┐      │  │
│  │                    │  🔄 Sincronizar ahora          │      │  │
│  │                    └────────────────────────────────┘      │  │
│  │                                                             │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                                                                   │
│  ─── En progreso ───────────────────────────────────────────────  │
│                                                                   │
│  Sincronizando productos...  Pagina 3 de 25                      │  ← estado durante sync
│  ████████░░░░░░░░░░░░░░░░░░░░░░  142 / 1.247 productos          │
│  [ Cancelar ]                                                     │
│                                                                   │
│  ─── Historial de sincronizaciones ─────────────────────────────  │
│                                                                   │
│  Fecha              Tipo     Entidad     Estado    Registros      │
│  ─────────────────────────────────────────────────────────────    │
│  2026-05-22 14:35   Manual   Productos   ✅ OK       1.247        │
│  2026-05-22 14:35   Manual   Precios     ✅ OK       1.247        │
│  2026-05-22 14:35   Manual   Stock       ✅ OK         891        │
│  2026-05-22 08:00   Auto     Productos   ✅ OK          12        │
│  2026-05-21 20:00   Auto     Stock       ⚠️ Parcial    340        │
│  2026-05-21 14:00   Manual   Todo        ❌ Error        0        │
│                                                                   │
│  [ Ver mas ]                                                      │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### Estados posibles del panel

```
Estado: Idle (listo para sincronizar)
  → Boton activo, historial visible

Estado: Sincronizando
  → Progress bar animada
  → Boton cambia a "Cancelar"
  → Historial congelado

Estado: Exito
  ✅ Sincronizacion completada
  1.247 productos actualizados · 0 errores · Duracion: 3m 42s
  [ Sincronizar de nuevo ]

Estado: Error parcial
  ⚠️ Sincronizacion completada con advertencias
  1.230 productos actualizados · 17 errores
  Ver detalles ↓
  ┌────────────────────────────────────────────────┐
  │ SKU-001: Imagen no disponible (URL invalida)   │
  │ SKU-045: Categoria no encontrada en PrestaShop │
  │ ... y 15 más                                   │
  └────────────────────────────────────────────────┘

Estado: Error critico
  ❌ Error de conexion con Bsale
  No se pudo conectar a la API de Bsale. Verifica tu token en Configuracion.
  [ Reintentar ]   [ Ir a Configuracion ]

Estado: Licencia expirada
  🔴 Licencia inactiva
  Tu plan kpcrop ha vencido. Renueva en kpcrop.com/billing para continuar.
  [ Renovar licencia ]
```

---

## Pantalla 3: Detalle de un Sync (modal)

Al hacer clic en una fila del historial:

```
┌────────────────────────────────────────────────────────────┐
│  Detalle de sincronizacion                              ✕   │
├────────────────────────────────────────────────────────────┤
│  Fecha:      2026-05-21 20:00                              │
│  Tipo:       Automatico (cron)                             │
│  Entidad:    Stock                                         │
│  Estado:     ⚠️ Parcial                                    │
│  Duracion:   45s                                           │
│  Actualizados: 340 / 412                                   │
│                                                            │
│  ─── Errores ────────────────────────────────────────────  │
│  SKU-101  Variante no encontrada en PrestaShop             │
│  SKU-204  Stock negativo ignorado (allowNegative=false)    │
│  ... 70 más                                                │
│                                                            │
│                                    [ Cerrar ]              │
└────────────────────────────────────────────────────────────┘
```

---

## Guia de Estilo para el Diseñador

| Elemento | Especificacion |
|---|---|
| Framework CSS | Bootstrap 4 (incluido en PrestaShop 1.7/8) |
| Iconos | FontAwesome 5 (incluido en PrestaShop) |
| Color primario | Azul PrestaShop `#25B9D7` |
| Color exito | Verde `#70B580` |
| Color error | Rojo `#E08F95` |
| Color advertencia | Amarillo `#F9C86E` |
| Tipografia | System font stack (PrestaShop default) |
| Formularios | Clases Bootstrap: `form-group`, `form-control`, `btn btn-primary` |
| Progress bar | `<div class="progress"><div class="progress-bar progress-bar-striped progress-bar-animated">` |

### Componentes PrestaShop a reutilizar
- `HelperForm` para el formulario de configuracion
- `HelperList` para el historial de syncs
- `AdminController::displayConfirmation()` para mensajes de exito
- `AdminController::displayWarning()` para advertencias
- `AdminController::displayError()` para errores
