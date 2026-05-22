# kpcrop-latam-zollner-platform

> Conecta todos tus CMS con Bsale. Sincronización manual, automática y hasta _dropshipping_ entre tiendas.

---

## 💼 ¿Qué problema resolvemos?

Si usas **Bsale** (ERP/POS líder en Chile y Latinoamérica) y vendes en más de un CMS (WordPress, Shopify, PrestaShop, WooCommerce, Magento o Jumpseller), seguro has sufrido:

- **Doble ingreso de datos** → errores y pérdida de tiempo.
- **Stock inconsistente** → ventas que no puedes cumplir.
- **Sincronización manual** cada vez que alguien compra en un canal.
- **Falta de control** sobre licencias y actualizaciones cuando gestionas varios clientes.

---

## 🚀 Nuestra solución: una plataforma dual

| Componente | Función |
|------------|---------|
| **Plugins CMS (6 motores)** | Sincronización **manual** con un clic: productos, precios, stock, clientes y guías. |
| **Demonio `bot-miki`** | Sincronización **automática** programable, cola de tareas, reinteligencia ante fallos de Bsale y gestión centralizada de licencias. |
| **Plugin CMS servidor** | Configura y disponibiliza los productos de tu tienda para tus consumidores o distribuidores. Permite seleccionar productos para consignar de forma virtual. |
| **Plugin CMS cliente** | Sincronización programable con el plugin servidor. Actúa como un sistema de **dropshipping** entre dos CMS de comercio electrónico. |

> 🔁 **Manual + Automático + Dropshipping**: Tú decides cómo y cuándo sincronizar. Sin sorpresas.

---

## 🎯 ¿Para quién es?

- **Comercios** que venden en múltiples tiendas online y quieren operar sin fricción.
- **Agencias digitales** que administran decenas de clientes y necesitan un mismo estándar de integración.
- **Retailers** que crecen y no pueden permitirse desincronización entre Bsale y sus canales.
- **Distribuidores / mayoristas** que quieren ofrecer catálogos a sus clientes finales mediante dropshipping automatizado.

---

## 💰 Modelo de negocio

- **Licenciamiento por volumen** – Pagas según transacciones o número de tiendas conectadas.
- **Suscripción mensual/anual** – Incluye soporte y actualizaciones.
- **Servicios de implementación** – Personalización para integraciones complejas.

---

## 🏆 Ventajas competitivas

| Ventaja | Beneficio |
|---------|------------|
| **Multi-CMS** | Cobertura total de los 6 motores más usados en la región. |
| **Arquitectura híbrida** | Manual + automático = flexibilidad total. |
| **Licencias centralizadas** | Control absoluto desde el demonio (ideal para agencias). |
| **Resiliencia** | Demonio con cola y reintentos ante fallos de red o API de Bsale. |
| **Dropshipping nativo** | Sincroniza entre dos CMS como si fueran un solo ecosistema. |

---

## 📈 ¿Listo para vender sin límites?

> 👉 **Únete a la primera plataforma de sincronización Bsale que unifica todos tus canales de venta.**

¿Quieres verlo en acción? **Contáctanos** para una demo o prueba gratuita.

📧 `comercial@kpcrop.com` (ejemplo)  
🌐 [www.kpcrop.com/bsale-sync](https://ejemplo.com)

---

## 📚 Documentación del proyecto

- [Arquitectura global](/docs/architecture/README.md)
- [Contrato API del demonio](/docs/api/demonio-openapi.yaml)
- [ADR (Decisiones técnicas)](/docs/adr/)
- [Guía de desarrollo de plugins](/docs/plugin-development.md)

---

## 🧱 Estructura del proyecto
kpcrop-latam-zollner-platform/
├── .github/workflows/ # CI/CD condicional
├── docs/
│ ├── architecture/ # Diagramas y doc
│ ├── api-contracts/ # OpenAPI del demonio
│ └── licensing/ # Flujos de licencias
├── packages/
│ ├── bot-miki/ # Demonio sincronizador
│ ├── app-servi-dropi/
│ ├── app-client-dropi/
│ ├── cms-wordpress/
│ ├── cms-prestashop/
│ ├── cms-shopify/
│ ├── cms-woocommerce/
│ ├── cms-magento/
│ ├── cms-jumpseller/
│ └── shared/ # Código común
├── docker-compose.yml
├── README.md
└── LICENSE