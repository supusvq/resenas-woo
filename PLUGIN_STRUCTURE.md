# Reseñas Woo — Documentación de Estructura del Plugin

> **Versión:** 2.11.11 | **Autor:** Juan Gallardo | **PHP mínimo:** 7.4 | **WP mínimo:** 6.0
> **Text Domain:** `mis-resenas-de-google` | **Prefijo constantes:** `MRG_` | **Namespace raíz:** `MRG\`

---

## 📁 Árbol de Archivos

```
mis-resenas-de-google/
├── mis-resenas-de-google.php       ← Punto de entrada (v2.11.11)
├── uninstall.php                   ← Desinstalador completo (limpia BD + opciones)
├── readme.txt                      ← Metadatos WordPress.org
│
├── assets/
│   ├── css/
│   │   ├── frontend.css            ← Estilos del widget público
│   │   └── admin.css               ← Estilos admin
│   └── js/
│       ├── frontend.js             ← JS público
│       └── admin.js                ← JS admin
│
├── includes/
│   ├── Autoloader.php              ← Carga automática de clases MRG\
│   ├── Activator.php               ← Hook de activación y defaults de ajustes
│   ├── Deactivator.php             ← Hook de desactivación (sin cambios permanentes)
│   ├── Database.php                ← Gestión de BD con sistema de upgrade
│   ├── Helpers.php                 ← Funciones de utilidad
│   │
│   ├── Admin/
│   │   ├── Menu.php                ← Menú principal y submenús
│   │   ├── Settings.php            ← Configuración general, importación y rendimiento
│   │   ├── ReviewsPage.php         ← Gestión de reseñas almacenadas
│   │   ├── EmailsPage.php          ← Configuración de correos HTML
│   │   ├── LogsPage.php            ← Historial de envíos del cron
│   │   └── InvitationsPage.php     ← Nueva página de invitaciones manuales
│   │
│   ├── API/
│   │   ├── PlacesClient.php        ← Google Places (vía wp_remote_get)
│   │   └── BusinessProfileClient.php ← Google Business Profile
│   │
│   ├── Reviews/
│   │   ├── ReviewRepository.php    ← CRUD en BD local
│   │   ├── ReviewStats.php         ← Estadísticas calculadas
│   │   └── ReviewSyncService.php   ← Lógica de sincronización
│   │
│   ├── Emails/
│   │   ├── EmailTemplate.php       ← Procesador de variables {nombre_cliente}...
│   │   ├── EmailSender.php         ← Envío real de correos HTML
│   │   ├── EmailScheduler.php      ← Programación y envío manual
│   │   └── EmailLogRepository.php  ← Registro de intentos y errores
│   │
│   ├── Frontend/
│   │   ├── Shortcode.php           ← Registro de [mis_resenas_google]
│   │   └── Renderer.php            ← Renderizado del widget CSS/HTML
│   │
│   └── WooCommerce/
│       └── OrderHooks.php          ← Captura de eventos completion_order
│
├── languages/                      ← Preparado para internacionalización
│
└── templates/
    ├── review-card.php             ← Placeholder para templates externos
    └── reviews-wrapper.php         ← Placeholder para templates externos
```

---

## 🚀 Punto de Entrada — `mis-resenas-de-google.php`

Define las constantes globales y arranca todos los módulos.

| Constante | Valor |
|---|---|
| `MRG_VERSION` | `'2.11.11'` |
| `MRG_FILE` | Ruta física al archivo |

**Lógica principal:**
1. Carga del Autoloader.
2. Hook de activación (Database + Default Options).
3. Carga del dominio de texto (`load_plugin_textdomain`).
4. Instanciación modular (Admin, Frontend, WooCommerce).
5. Hook de Cron: `mrg_send_scheduled_email`.

---

## 🗄️ Base de Datos e Integración

### Tablas Personalizadas
*   `{prefix}mrg_reviews`: Almacena reseñas locales (nombre, foto, rating, texto, fecha).
*   `{prefix}mrg_email_logs`: Registro de envío/errores de invitaciones.

### Sistema de Actualización (`Database::maybe_upgrade`)
Se ejecuta al cargar el admin y compara `mrg_version` en la base de datos con `MRG_VERSION` del código. Si es menor, aplica `dbDelta` y corre comprobaciones de columnas robustas para evitar errores de actualización.

---

## ⚙️ Opciones de WordPress (`mrg_settings`)
Todo se almacena en un array único serializado:
- **Google/importación:** `maps_url`, `place_id`, `scraper_service_url`, `service_site_token`, `remote_sync_consent`.
- **Diseño:** `theme`, `default_stars`, `only_text_reviews`, `reviews_limit`, `slider_mode`, `google_stars_header`, `google_reviews_total`.
- **Rendimiento:** `hide_review_avatars` controla si se imprimen avatares externos de Google. Por defecto vale `1` para no cargar `lh3.googleusercontent.com` en frontend.
- **Emails:** `enable_review_requests`, `send_delay_days`, `email_subject`, `from_name`, `email_template`, `email_review_url`, `email_company_name`.
- **Privacidad:** `footer_privacy_email`, `footer_privacy_url`.

### Rendimiento de avatares
Desde `2.11.11`, la opción **Ocultar avatares de reseñas** está activada por defecto.
Cuando `hide_review_avatars = 1`, `Frontend\Renderer::render_avatar()` no imprime ningún `<img>` ni fallback visual, por lo que no se solicita la URL externa de Google. No se oculta por CSS.

Si en el futuro se implementa caché local de avatares, debe hacerse como fase separada:
1. Descargar una vez desde `author_photo`.
2. Redimensionar a máximo `48x48`.
3. Convertir a WebP si `imagewebp()` está disponible.
4. Guardar en `wp-content/uploads/reviews-avatars/`.
5. Servir solo URL local y añadir acción admin para regenerar/limpiar caché.

---

## 📩 Sistema de Invitaciones

### Automático
Disparado por `woocommerce_order_status_completed`. Si hay un retardo (`send_delay_days`), se programa un evento en el Cron de WordPress.

### Manual (`InvitationsPage`)
Permite navegar por los últimos pedidos de WooCommerce, buscar clientes específicos y forzar el envío inmediato de la invitación. Permite sincronizar metadatos antiguos con registros nuevos del historial.

---

## 🏗️ Estado de Desarrollo

### ✅ Completado v2.11.11
- [x] Arquitectura modular completa.
- [x] Sistema de desinstalación (`uninstall.php`).
- [x] Integración con WooCommerce y WP-Cron.
- [x] Correos premium en formato HTML.
- [x] Panel de invitaciones manuales.
- [x] Widget frontend dinámico.
- [x] Opción de rendimiento para no imprimir avatares externos de Google.

### 🔲 Pendiente
- [ ] Caché local/WebP de avatares si PageSpeed lo requiere.
- [ ] Archivos de idioma `.mo/.po`.
- [ ] Separación de HTML de Renderer a archivos en `templates/`.
