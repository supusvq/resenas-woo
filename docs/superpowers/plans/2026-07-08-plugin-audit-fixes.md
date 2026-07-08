# Corrección de auditoría "Reseñas Woo" v2.11.11 — Plan de implementación

> **Para ejecución agéntica:** SUB-SKILL REQUERIDA: usar `superpowers:subagent-driven-development` (recomendado) o `superpowers:executing-plans` para ejecutar tarea por tarea. Los pasos usan `- [ ]` para seguimiento.

**Objetivo:** Corregir los 4 problemas críticos, 8 importantes y 3 mejoras accionables detectados en `INFORME-AUDITORIA-resenas_woo.md` (2026-07-08), en el orden de riesgo que marca el propio informe (Fase 1 → Fase 4).

**Arquitectura:** Sin cambios estructurales. Todas las correcciones son quirúrgicas dentro de las clases existentes (`EmailScheduler`, `EmailLogRepository`, `InvitationsPage`, `LogsPage`, `EmailsPage`, `Settings`, `Shortcode`, `Renderer`, `uninstall.php`). No se introducen dependencias nuevas.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, WooCommerce (con y sin HPOS). Sin framework de tests automatizados en el repo — la verificación de cada tarea es manual (WP-CLI + staging), no hay carpeta `tests/`. Por eso los pasos "TDD" clásicos (test que falla → código → test que pasa) se sustituyen por "cambio de código → verificación manual reproducible".

**Importante (CLAUDE.md del proyecto):** "No hagas cambios automáticamente sin pedir confirmación." Este plan se ejecuta fase por fase, con aprobación explícita del usuario antes de cada fase, y probando en staging (Mailpit/MailHog o plugin "Stop Emails") antes de tocar producción.

**Control de versiones:** el repo (`resenas_woo/`) ya existía con historial propio y remoto `origin` en GitHub (`git@github.com:supusvq/resenas-woo.git`). El trabajo de este plan se hace en la rama `fix/auditoria-emails`, creada desde `main`. No se hace push a `origin` salvo que el usuario lo pida explícitamente.

---

## FASE 1 — Frenar duplicados (crítico: C1, C2, C4)

Objetivo: que sea **imposible** que un cliente reciba el email dos veces, y que el cron nunca envíe si el admin desactivó los envíos automáticos.

### Task 1: `EmailLogRepository::set_sent()` desprograma el cron pendiente del pedido

**Por qué:** cuando se envía manualmente (botón "Enviar ahora" o proceso masivo), el evento cron `mrg_send_scheduled_email` para ese pedido sigue programado. Si nadie lo cancela, dispara igualmente más tarde → email duplicado (C1).

**Files:**
- Modify: `resenas_woo/includes/Emails/EmailLogRepository.php:190-216`

- [ ] **Paso 1: Editar `set_sent()`**

Código actual (líneas 190-216):
```php
    public function set_sent($order_id, $origin_note = '')
    {
        global $wpdb;
        $now = current_time('mysql');
        $data = [
            'status' => 'enviado',
            'sent_at' => $now,
            'scheduled_at' => null,
            'error_message' => sanitize_textarea_field($origin_note),
            'technical_log' => ''
        ];

        $wpdb->update($this->table, $data, ['order_id' => (int) $order_id]);

        // Red de seguridad: Marcar el pedido en WooCommerce para que no se pierda si se borran los logs
        if (function_exists('update_post_meta')) {
            update_post_meta($order_id, '_mrg_invitation_sent', 'yes');
        }

        // Añadir nota al pedido para que el usuario lo vea en la pantalla de edición
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->add_order_note(sprintf(__('Invitación de reseña enviada automáticamente (%s).', 'mis-resenas-de-google'), $origin_note));
            }
        }
    }
```

Reemplazar por:
```php
    public function set_sent($order_id, $origin_note = '')
    {
        global $wpdb;
        $now = current_time('mysql');
        $data = [
            'status' => 'enviado',
            'sent_at' => $now,
            'scheduled_at' => null,
            'error_message' => sanitize_textarea_field($origin_note),
            'technical_log' => ''
        ];

        $wpdb->update($this->table, $data, ['order_id' => (int) $order_id]);

        // Cancelar cualquier envío programado pendiente para este pedido (evita duplicados: C1)
        wp_clear_scheduled_hook('mrg_send_scheduled_email', [(int) $order_id]);

        // Red de seguridad: Marcar el pedido en WooCommerce para que no se pierda si se borran los logs
        if (function_exists('update_post_meta')) {
            update_post_meta($order_id, '_mrg_invitation_sent', 'yes');
        }

        // Añadir nota al pedido para que el usuario lo vea en la pantalla de edición
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->add_order_note(sprintf(__('Invitación de reseña enviada automáticamente (%s).', 'mis-resenas-de-google'), $origin_note));
            }
        }
    }
```

(Nota: `update_post_meta`/`wc_get_order` aquí se migran a la API de pedido de WooCommerce en la Fase 2 — Task 4. En esta tarea solo se añade la línea de `wp_clear_scheduled_hook`.)

- [ ] **Paso 2: Verificar sintaxis PHP**

```bash
php -l "resenas_woo/includes/Emails/EmailLogRepository.php"
```
Esperado: `No syntax errors detected`.

- [ ] **Paso 3: Commit**

```bash
git add includes/Emails/EmailLogRepository.php
git commit -m "fix: cancelar cron pendiente al marcar un email como enviado (C1)"
```

### Task 2: `EmailScheduler::send_now()` — bloqueo atómico y re-verificación en la ruta cron

**Por qué:**
- C2: la ruta cron (`mis-resenas-de-google.php:54-56`) llama a `send_now()` sin `claim_for_send()` → si WP-Cron dispara dos veces en paralelo, doble envío.
- C4: `send_now()` no re-comprueba `enable_review_requests` → si el admin desactiva la opción después de programar, el cron envía igualmente.
- Los llamadores manuales (`InvitationsPage::ajax_send_now`, `LogsPage::ajax_process_single_task`) **ya** llaman `claim_for_send()` antes de invocar `send_now($order_id, true)`, así que el bloqueo solo debe añadirse para `$is_manual === false` (para no gastar un intento extra en la ruta manual).

**Files:**
- Modify: `resenas_woo/includes/Emails/EmailScheduler.php:54-85`

- [ ] **Paso 1: Editar `send_now()`**

Código actual (líneas 54-85):
```php
    public function send_now($order_id, $is_manual = false)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        $sender = new EmailSender();
        $logs = new EmailLogRepository();
        $result = $sender->send_for_order($order_id);

        if (is_wp_error($result)) {
            $is_permanent = ($result->get_error_code() === 'permanent_error');
            $data = $result->get_error_data();
            $raw_technical = (is_array($data) && isset($data['raw_log'])) ? $data['raw_log'] : $result->get_error_message();
            $logs->set_failed($order_id, $result->get_error_message(), $is_permanent, $raw_technical);
            return false;
        }

        // DETERMINAR LA ETIQUETA SEGUN TU REQUISITO
        $settings = get_option('mrg_settings', []);
        $delay_days = (int) ($settings['send_delay_days'] ?? 0);

        if ($is_manual) {
            $origin_note = __('Invitación', 'mis-resenas-de-google');
        } else {
            $origin_note = ($delay_days > 0) ? __('Programado', 'mis-resenas-de-google') : __('Automático', 'mis-resenas-de-google');
        }

        $logs->set_sent($order_id, $origin_note);
        return true;
    }
```

Reemplazar por:
```php
    public function send_now($order_id, $is_manual = false)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        $logs = new EmailLogRepository();
        $settings = get_option('mrg_settings', []);

        if (!$is_manual) {
            // Ruta cron: re-verificar que los envíos automáticos siguen activos (C4)
            if (empty($settings['enable_review_requests'])) {
                return false;
            }

            // Ruta cron: bloqueo atómico. Si el log ya está 'enviado' o hay otro proceso
            // en curso, claim_for_send() devuelve false y no se envía nada (C1 + C2).
            if (!$logs->claim_for_send($order_id)) {
                return false;
            }
        }

        $sender = new EmailSender();
        $result = $sender->send_for_order($order_id);

        if (is_wp_error($result)) {
            $is_permanent = ($result->get_error_code() === 'permanent_error');
            $data = $result->get_error_data();
            $raw_technical = (is_array($data) && isset($data['raw_log'])) ? $data['raw_log'] : $result->get_error_message();
            $logs->set_failed($order_id, $result->get_error_message(), $is_permanent, $raw_technical);
            return false;
        }

        // DETERMINAR LA ETIQUETA SEGUN TU REQUISITO
        $delay_days = (int) ($settings['send_delay_days'] ?? 0);

        if ($is_manual) {
            $origin_note = __('Invitación', 'mis-resenas-de-google');
        } else {
            $origin_note = ($delay_days > 0) ? __('Programado', 'mis-resenas-de-google') : __('Automático', 'mis-resenas-de-google');
        }

        $logs->set_sent($order_id, $origin_note);
        return true;
    }
```

- [ ] **Paso 2: Verificar sintaxis PHP**

```bash
php -l "resenas_woo/includes/Emails/EmailScheduler.php"
```

- [ ] **Paso 3: Commit**

```bash
git add includes/Emails/EmailScheduler.php
git commit -m "fix: bloqueo atomico y re-verificacion de ajustes en ruta cron (C2, C4)"
```

### Task 3: Verificación manual de la Fase 1 (staging, con Mailpit/MailHog o "Stop Emails")

- [ ] **Paso 1: Escenario C1 — manual + cron pendiente**
  1. Crear un pedido de prueba y pasarlo a "Completado" con `send_delay_days` > 0 (para que quede programado).
  2. En Invitaciones, pulsar "Enviar ahora" para ese pedido → debe llegar 1 email.
  3. Forzar el cron pendiente: `wp cron event run mrg_send_scheduled_email --url=https://tu-staging.test` (o revisar `wp cron event list | grep mrg_send_scheduled_email` — el evento ya no debería existir, porque `set_sent()` lo canceló).
  4. **Resultado esperado:** el evento ya no aparece en `wp cron event list`, y no llega un segundo email.

- [ ] **Paso 2: Escenario C4 — desactivar automáticos con envíos ya programados**
  1. Activar envíos automáticos, `send_delay_days = 7`, completar un pedido (queda programado).
  2. Desactivar "Activar solicitudes automáticas" en Emails.
  3. `wp cron event run mrg_send_scheduled_email`.
  4. **Resultado esperado:** no se envía email; el log del pedido permanece en `pendiente` (no se marca `enviado`, no consumió intento porque `send_now` retorna `false` antes de `claim_for_send()`).

- [ ] **Paso 3: Escenario C2 — concurrencia cron**
  1. Con un pedido pendiente, ejecutar dos veces casi a la vez: `wp cron event run mrg_send_scheduled_email` (dos terminales o `&` en background).
  2. **Resultado esperado:** solo un envío; el segundo `claim_for_send()` falla porque el primero ya puso `status = 'procesando'`/`'enviado'`.

- [ ] **Paso 4: Confirmar con el usuario antes de pasar a producción y a la Fase 2**

---

## FASE 2 — Compatibilidad HPOS (crítico: C3)

Objetivo: que la marca antiduplicados `_mrg_invitation_sent` funcione igual con HPOS activado o desactivado, y que WooCommerce no marque el plugin como incompatible.

### Task 4: `EmailLogRepository` — migrar a la API de meta de pedido

**Files:**
- Modify: `resenas_woo/includes/Emails/EmailLogRepository.php:43-56` (`exists_for_order`) y `:190-220` (`set_sent`, ya tocado en Task 1)

- [ ] **Paso 1: Editar `exists_for_order()`**

Código actual (líneas 43-56):
```php
    public function exists_for_order($order_id)
    {
        global $wpdb;
        // 1. Verificamos en nuestra tabla de logs
        $exists = (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE order_id = %d", (int) $order_id));
        if ($exists)
            return true;

        // 2. Red de seguridad: Verificamos en los metadatos del pedido (por si se borró el historial)
        if (function_exists('get_post_meta')) {
            return get_post_meta($order_id, '_mrg_invitation_sent', true) === 'yes';
        }
        return false;
    }
```

Reemplazar por:
```php
    public function exists_for_order($order_id)
    {
        global $wpdb;
        // 1. Verificamos en nuestra tabla de logs
        $exists = (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE order_id = %d", (int) $order_id));
        if ($exists)
            return true;

        // 2. Red de seguridad: Verificamos en los metadatos del pedido (por si se borró el historial)
        // Usa la API de WooCommerce para funcionar igual con HPOS activado o desactivado.
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                return $order->get_meta('_mrg_invitation_sent', true) === 'yes';
            }
        }
        return false;
    }
```

- [ ] **Paso 2: Editar `set_sent()` (continúa sobre el resultado de Task 1)**

Código actual tras Task 1:
```php
        // Red de seguridad: Marcar el pedido en WooCommerce para que no se pierda si se borran los logs
        if (function_exists('update_post_meta')) {
            update_post_meta($order_id, '_mrg_invitation_sent', 'yes');
        }

        // Añadir nota al pedido para que el usuario lo vea en la pantalla de edición
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->add_order_note(sprintf(__('Invitación de reseña enviada automáticamente (%s).', 'mis-resenas-de-google'), $origin_note));
            }
        }
```

Reemplazar por (una sola llamada a `wc_get_order`, meta + nota vía API de pedido):
```php
        // Red de seguridad: Marcar el pedido en WooCommerce para que no se pierda si se borran los logs.
        // Usa $order->update_meta_data()+save() en vez de update_post_meta() para funcionar con HPOS.
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_mrg_invitation_sent', 'yes');
                $order->save();
                $order->add_order_note(sprintf(__('Invitación de reseña enviada automáticamente (%s).', 'mis-resenas-de-google'), $origin_note));
            }
        }
```

- [ ] **Paso 3: Verificar sintaxis**

```bash
php -l "resenas_woo/includes/Emails/EmailLogRepository.php"
```

- [ ] **Paso 4: Commit**

```bash
git add includes/Emails/EmailLogRepository.php
git commit -m "fix: migrar meta de pedido a API de WooCommerce para compatibilidad HPOS (C3)"
```

### Task 5: `InvitationsPage` — migrar a la API de meta de pedido

**Por qué:** `ajax_clear_sent_meta()` y `ajax_restore_sent_meta()` hacen `DELETE`/`update_post_meta` directos sobre `wp_postmeta`, que con HPOS activo no es donde vive la meta del pedido. El botón "Limpiar historial" prometía "no se duplicará" apoyándose en esta marca — bajo HPOS esa promesa es falsa.

**Files:**
- Modify: `resenas_woo/includes/Admin/InvitationsPage.php:21-56` (`ajax_clear_sent_meta`, `ajax_restore_sent_meta`) y `:152` (`render()`, lectura legacy)

- [ ] **Paso 1: Editar `ajax_clear_sent_meta()`**

Código actual (líneas 21-31):
```php
    public function ajax_clear_sent_meta()
    {
        check_ajax_referer('mrg_invitations_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
        }

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_mrg_invitation_sent'");
        wp_send_json_success(__('Estados de envío limpiados en WooCommerce.', 'mis-resenas-de-google'));
    }
```

Reemplazar por:
```php
    public function ajax_clear_sent_meta()
    {
        check_ajax_referer('mrg_invitations_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
        }

        // wc_get_orders() con meta_key/meta_value ya funciona igual con HPOS activado o no.
        $order_ids = wc_get_orders([
            'meta_key' => '_mrg_invitation_sent',
            'meta_value' => 'yes',
            'limit' => -1,
            'return' => 'ids',
        ]);

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->delete_meta_data('_mrg_invitation_sent');
                $order->save();
            }
        }

        wp_send_json_success(__('Estados de envío limpiados en WooCommerce.', 'mis-resenas-de-google'));
    }
```

- [ ] **Paso 2: Editar `ajax_restore_sent_meta()`**

Código actual (líneas 33-56):
```php
    public function ajax_restore_sent_meta()
    {
        check_ajax_referer('mrg_invitations_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
        }

        global $wpdb;

        // 1. Vaciar marcas actuales (Vaciar tabla visualmente de estados previos)
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_mrg_invitation_sent'");

        // 2. Regenerar desde el historial
        $logs_table = $wpdb->prefix . 'mrg_email_logs';
        $sent_logs = $wpdb->get_results("SELECT order_id FROM $logs_table WHERE status = 'enviado'");

        $count = 0;
        foreach ($sent_logs as $log) {
            update_post_meta($log->order_id, '_mrg_invitation_sent', 'yes');
            $count++;
        }

        wp_send_json_success(sprintf(__('Restauración completada: Se han sincronizado %d registros desde el historial.', 'mis-resenas-de-google'), $count));
    }
```

Reemplazar por:
```php
    public function ajax_restore_sent_meta()
    {
        check_ajax_referer('mrg_invitations_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
        }

        // 1. Vaciar marcas actuales
        $current_ids = wc_get_orders([
            'meta_key' => '_mrg_invitation_sent',
            'meta_value' => 'yes',
            'limit' => -1,
            'return' => 'ids',
        ]);
        foreach ($current_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->delete_meta_data('_mrg_invitation_sent');
                $order->save();
            }
        }

        // 2. Regenerar desde el historial
        global $wpdb;
        $logs_table = $wpdb->prefix . 'mrg_email_logs';
        $sent_logs = $wpdb->get_results("SELECT order_id FROM $logs_table WHERE status = 'enviado'");

        $count = 0;
        foreach ($sent_logs as $log) {
            $order = wc_get_order($log->order_id);
            if ($order) {
                $order->update_meta_data('_mrg_invitation_sent', 'yes');
                $order->save();
                $count++;
            }
        }

        wp_send_json_success(sprintf(__('Restauración completada: Se han sincronizado %d registros desde el historial.', 'mis-resenas-de-google'), $count));
    }
```

- [ ] **Paso 3: Editar la lectura legacy en `render()` (línea 152)**

Código actual:
```php
                } else {
                    $legacy_sent = get_post_meta($oid, '_mrg_invitation_sent', true);
                    if ($legacy_sent === 'yes') {
```

Reemplazar por (`$order_obj` ya está disponible unas líneas arriba, en la línea 133):
```php
                } else {
                    $legacy_sent = $order_obj->get_meta('_mrg_invitation_sent', true);
                    if ($legacy_sent === 'yes') {
```

- [ ] **Paso 4: Verificar sintaxis**

```bash
php -l "resenas_woo/includes/Admin/InvitationsPage.php"
```

- [ ] **Paso 5: Commit**

```bash
git add includes/Admin/InvitationsPage.php
git commit -m "fix: migrar limpieza/restauracion de marcas a API de pedido para HPOS (C3)"
```

### Task 6: Declarar compatibilidad HPOS y cabecera `Requires Plugins`

**Files:**
- Modify: `resenas_woo/mis-resenas-de-google.php:1-28`

- [ ] **Paso 1: Añadir cabecera `Requires Plugins`**

Código actual (líneas 1-12):
```php
<?php
/**
 * Plugin Name: Reseñas Woo
 * Plugin URI: https://www.supudigital.es
 * Description: Visualiza reseñas de Google almacenadas localmente y automatiza solicitudes de reseña post-compra en WooCommerce.
 * Version: 2.11.11
 * Author: Juan Gallardo
 * Author URI: https://www.supudigital.es
 * Text Domain: mis-resenas-de-google
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
```

Reemplazar por:
```php
<?php
/**
 * Plugin Name: Reseñas Woo
 * Plugin URI: https://www.supudigital.es
 * Description: Visualiza reseñas de Google almacenadas localmente y automatiza solicitudes de reseña post-compra en WooCommerce.
 * Version: 2.11.11
 * Author: Juan Gallardo
 * Author URI: https://www.supudigital.es
 * Text Domain: mis-resenas-de-google
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
```

- [ ] **Paso 2: Declarar compatibilidad HPOS**

Código actual (líneas 27-29):
```php
register_activation_hook(__FILE__, ['MRG\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['MRG\\Deactivator', 'deactivate']);

add_action('plugins_loaded', function () {
```

Reemplazar por (se añade el bloque `before_woocommerce_init` entre los register_*_hook y el `plugins_loaded`):
```php
register_activation_hook(__FILE__, ['MRG\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['MRG\\Deactivator', 'deactivate']);

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function () {
```

- [ ] **Paso 3: Verificar sintaxis**

```bash
php -l "resenas_woo/mis-resenas-de-google.php"
```

- [ ] **Paso 4: Commit**

```bash
git add mis-resenas-de-google.php
git commit -m "feat: declarar compatibilidad HPOS y Requires Plugins (C3)"
```

### Task 7: Verificación manual de la Fase 2

- [ ] **Paso 1: Con HPOS DESACTIVADO** (WooCommerce → Ajustes → Avanzado → Funcionalidades)
  1. Completar un pedido → enviar invitación manual.
  2. Comprobar en el pedido (pestaña "Datos personalizados"/meta) que `_mrg_invitation_sent = yes`.
  3. Historial → "Limpiar historial" → "Restaurar Historial" → comprobar que el registro reaparece.

- [ ] **Paso 2: Con HPOS ACTIVADO**
  1. Repetir el mismo flujo completo.
  2. Comprobar en WooCommerce → Pedidos → abrir el pedido → verificar que la meta `_mrg_invitation_sent` es legible (vía `wc_get_order($id)->get_meta('_mrg_invitation_sent')` en WP-CLI: `wp eval 'var_dump(wc_get_order(ORDER_ID)->get_meta("_mrg_invitation_sent"));'`).
  3. Comprobar en **WooCommerce → Estado → Funcionalidades HPOS** (o el aviso de compatibilidad de plugins) que "Reseñas Woo" ya NO aparece como incompatible.

- [ ] **Paso 3: Confirmar con el usuario antes de pasar a la Fase 3**

---

## FASE 3 — UI y flujos masivos (I1, I2)

### Task 8: Arreglar el JavaScript roto en `LogsPage::print_client_script()`

**Por qué:** el bloque de script se construye con fragmentos `' . esc_js(__(...)) . '` que datan de una versión donde el método hacía `echo '...'` con concatenación PHP. Al convertirse a modo HTML directo (`?>...<?php`), esos fragmentos quedaron **fuera** de cualquier tag PHP y se imprimen literalmente al navegador — el admin ve código PHP crudo en confirmaciones, barra de progreso y modal.

**Files:**
- Modify: `resenas_woo/includes/Admin/LogsPage.php:360-531` (todo el `<script>...</script>` dentro de `print_client_script()`)

- [ ] **Paso 1: Reemplazar el bloque `<script>...</script>` completo**

Sustituir el bloque desde `<script>` (línea 360) hasta `</script>` (línea 531) por:

```php
        <script>
            (function () {
                let cancelBulk = false;
                const modal = document.getElementById('mrg-tech-modal');
                const modalContent = document.getElementById('mrg-tech-content');
                const closeModal = document.getElementById('mrg-close-modal');

                document.querySelectorAll('.mrg-view-tech-log').forEach(link => {
                    link.addEventListener('click', function (e) {
                        e.preventDefault();
                        modalContent.textContent = this.getAttribute('data-log');
                        modal.style.display = 'block';
                    });
                });

                closeModal.onclick = () => modal.style.display = 'none';
                window.onclick = (e) => { if (e.target == modal) modal.style.display = 'none'; };

                document.getElementById('mrg-btn-stop').onclick = () => {
                    cancelBulk = true;
                    const btn = document.getElementById('mrg-btn-stop');
                    btn.textContent = "<?php echo esc_js(__('Deteniendo...', 'mis-resenas-de-google')); ?>";
                    btn.disabled = true;
                };

                const startBulk = async (type) => {
                    if (!confirm("<?php echo esc_js(__('¿Deseas iniciar el envío masivo escalonado (espera de 30s entre correos)?', 'mis-resenas-de-google')); ?>")) return;

                    cancelBulk = false;
                    const btnP = document.getElementById('mrg-btn-bulk-pending');
                    const btnE = document.getElementById('mrg-btn-bulk-errors');
                    const btnStop = document.getElementById('mrg-btn-stop');
                    const progressArea = document.getElementById('mrg-progress-area');
                    const statusText = document.getElementById('mrg-progress-status');
                    const progressBar = document.getElementById('mrg-progress-bar');
                    const timerText = document.getElementById('mrg-timer');

                    btnP.disabled = btnE.disabled = true;
                    btnStop.style.display = 'inline-block';
                    btnStop.disabled = false;
                    btnStop.textContent = "<?php echo esc_js(__('⏹ Detener envío', 'mis-resenas-de-google')); ?>";
                    progressArea.style.display = 'block';

                    try {
                        statusText.textContent = "<?php echo esc_js(__('Obteniendo lista de tareas...', 'mis-resenas-de-google')); ?>";
                        const response = await fetch(ajaxurl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'mrg_get_task_list',
                                type: type,
                                nonce: '<?php echo esc_js($nonce); ?>'
                            })
                        });

                        const rData = await response.json();
                        if (!rData.success) {
                            throw new Error(rData.data || 'Error desconocido');
                        }

                        const ids = rData.data.ids || [];
                        const totalInDB = rData.data.total_count || 0;

                        // Actualizar el contador de la cabecera para que coincida con la realidad tras la sincronización
                        const totalLabel = document.getElementById('mrg-total-count');
                        if (totalLabel) totalLabel.textContent = totalInDB;

                        if (!ids || !ids.length) {
                            alert("<?php echo esc_js(__('No hay registros para procesar.', 'mis-resenas-de-google')); ?>");
                            location.reload();
                            return;
                        }

                        for (let i = 0; i < ids.length; i++) {
                            if (cancelBulk) {
                                statusText.textContent = "<?php echo esc_js(__('Proceso detenido por el usuario.', 'mis-resenas-de-google')); ?>";
                                break;
                            }

                            const orderId = ids[i];
                            const currentIdx = i + 1;
                            const percent = (currentIdx / ids.length) * 100;

                            statusText.textContent = "<?php echo esc_js(__('Enviando pedido #', 'mis-resenas-de-google')); ?>" + orderId + " (" + currentIdx + " <?php echo esc_js(__('de', 'mis-resenas-de-google')); ?> " + ids.length + ")...";
                            progressBar.style.width = percent + '%';
                            timerText.textContent = '';

                            const res = await fetch(ajaxurl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({
                                    action: 'mrg_process_single_task',
                                    order_id: orderId,
                                    nonce: '<?php echo esc_js($nonce); ?>'
                                })
                            });

                            if (i < ids.length - 1 && !cancelBulk) {
                                let countdown = 30;
                                while (countdown > 0 && !cancelBulk) {
                                    timerText.textContent = "<?php echo esc_js(__('Próximo envío en', 'mis-resenas-de-google')); ?> " + countdown + " <?php echo esc_js(__('segundos...', 'mis-resenas-de-google')); ?>";
                                    await new Promise(r => setTimeout(r, 1000));
                                    countdown--;
                                }
                            }
                        }

                        if (!cancelBulk) statusText.textContent = "<?php echo esc_js(__('¡Completado!', 'mis-resenas-de-google')); ?>";
                        alert(cancelBulk ? "<?php echo esc_js(__('Envío detenido.', 'mis-resenas-de-google')); ?>" : "<?php echo esc_js(__('Proceso masivo finalizado.', 'mis-resenas-de-google')); ?>");
                        location.reload();

                    } catch (err) {
                        alert("<?php echo esc_js(__('Error en el proceso masivo: ', 'mis-resenas-de-google')); ?>" + err.message);
                        btnP.disabled = btnE.disabled = false;
                        btnStop.style.display = 'none';
                    }
                };

                document.getElementById('mrg-btn-bulk-pending').onclick = () => startBulk('pending');
                document.getElementById('mrg-btn-bulk-errors').onclick = () => startBulk('error');

                document.getElementById('mrg-btn-clear-logs').onclick = function () {
                    const msg = "⚠️ <?php echo esc_js(__('ATENCIÓN: Estás a punto de borrar la tabla de historial.', 'mis-resenas-de-google')); ?>\n\n" +
                        "- <?php echo esc_js(__('Los datos de fecha de envío y errores técnicos se perderán de esta vista.', 'mis-resenas-de-google')); ?>\n" +
                        "- <?php echo esc_js(__('NO se enviarán correos duplicados porque conservamos una marca oculta en WooCommerce.', 'mis-resenas-de-google')); ?>\n" +
                        "- <?php echo esc_js(__('Puedes usar el botón \'Restaurar\' para recuperar los registros enviados.', 'mis-resenas-de-google')); ?>\n\n" +
                        "<?php echo esc_js(__('¿Deseas continuar con el borrado?', 'mis-resenas-de-google')); ?>";
                    if (!confirm(msg)) return;

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'mrg_clear_logs',
                            nonce: '<?php echo esc_js($nonce); ?>'
                        })
                    })
                        .then(r => r.json())
                        .then(data => {
                            alert(data.data);
                            location.reload();
                        });
                };

                document.getElementById('mrg-btn-restore').onclick = function () {
                    if (!confirm("<?php echo esc_js(__('Este proceso buscará en WooCommerce todos los pedidos marcados como \'Enviados\' para reconstruir el historial. ¿Deseas continuar?', 'mis-resenas-de-google')); ?>")) return;

                    const btn = this;
                    btn.disabled = true;
                    btn.textContent = "<?php echo esc_js(__('Restaurando...', 'mis-resenas-de-google')); ?>";

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'mrg_restore_logs',
                            nonce: '<?php echo esc_js($nonce); ?>'
                        })
                    })
                        .then(r => r.json())
                        .then(data => {
                            alert(data.data);
                            location.reload();
                        })
                        .catch(() => {
                            alert("<?php echo esc_js(__('Error de conexión.', 'mis-resenas-de-google')); ?>");
                            btn.disabled = false;
                            btn.textContent = "♻ <?php echo esc_js(__('Restaurar Historial', 'mis-resenas-de-google')); ?>";
                        });
                };
            })();
        </script>
        <?php
    }
}
```

- [ ] **Paso 2: Verificar sintaxis**

```bash
php -l "resenas_woo/includes/Admin/LogsPage.php"
```

- [ ] **Paso 3: Verificación manual en navegador**

Abrir Historial en el admin, DevTools → Console sin errores JS. Pulsar "Enviar Masivo: Pendientes", cancelar con "Detener envío", comprobar que los textos de estado son correctos (no aparece código PHP en pantalla). Ver un log con `technical_log` y comprobar que el modal se abre bien.

- [ ] **Paso 4: Commit**

```bash
git add includes/Admin/LogsPage.php
git commit -m "fix: reparar bloque JS roto que mostraba codigo PHP literal (I1)"
```

### Task 9: Separar "calcular destinatarios" de "crear registros" en `EmailsPage`

**Por qué:** `ajax_bulk_count()` (botón "① Calcular destinatarios") llama a `ensure_log_exists(..., true)` para **todos** los pedidos completados históricos — eso ya inserta filas `no_iniciado` en la tabla como efecto secundario de un botón que solo debería contar. Luego `ajax_bulk_process_orders()` (botón "② Enviar a historial") llama a `schedule_or_send()`, que sale inmediatamente porque el log ya existe (lo creó el paso ①) → no programa ni envía nada, es un no-op silencioso.

**Files:**
- Modify: `resenas_woo/includes/Admin/EmailsPage.php:95-155` (`ajax_bulk_count`)

- [ ] **Paso 1: Editar `ajax_bulk_count()` para que sea de solo lectura**

Código actual (líneas 95-155):
```php
    public function ajax_bulk_count()
    {
        try {
            check_ajax_referer('mrg_bulk_process_orders', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
            }

            if (!class_exists('WooCommerce')) {
                wp_send_json_error(__('WooCommerce no está activo.', 'mis-resenas-de-google'));
            }

            // Usamos return => ids para no saturar la memoria con objetos pesados
            // Añadimos type => shop_order para evitar que se cuelen devoluciones (refunds)
            $order_ids = \wc_get_orders([
                'type' => 'shop_order',
                'status' => 'completed',
                'limit' => -1,
                'return' => 'ids',
            ]);

            error_log("[MRG] ajax_bulk_count: Encontrados " . count($order_ids) . " pedidos completados.");

            if (empty($order_ids)) {
                wp_send_json_success(['count' => 0]);
            }

            global $wpdb;
            $logs_table = $wpdb->prefix . 'mrg_email_logs';
            $existing_emails = $wpdb->get_col("SELECT DISTINCT customer_email FROM $logs_table");
            if (!is_array($existing_emails)) {
                $existing_emails = [];
            }

            $to_send_ids = [];
            $repo = new \MRG\Emails\EmailLogRepository();

            foreach ($order_ids as $order_id) {
                // Intentamos asegurar el log. Si devuelve true, es que es un destinatario válido (o ID ya existente).
                // Pero como aquí buscamos "nuevos", usamos check_email = true.
                if ($repo->ensure_log_exists($order_id, null, true)) {
                    // Verificamos que sea 'no_iniciado' (nuevo) y no uno ya enviado que acaba de encontrar
                    $log = $repo->get_log_by_order_id($order_id);
                    if ($log && $log->status === 'no_iniciado') {
                        $to_send_ids[] = $order_id;
                    }
                }
            }

            error_log("[MRG] ajax_bulk_count: Filtrados a " . count($to_send_ids) . " destinatarios nuevos.");
            wp_send_json_success([
                'count' => count($to_send_ids),
                'ids' => $to_send_ids
            ]);

        } catch (\Throwable $e) {
            error_log("[MRG] Error fatal en ajax_bulk_count: " . $e->getMessage());
            wp_send_json_error(__('Error al procesar la lista de pedidos. Revisa el log de errores.', 'mis-resenas-de-google'));
        }
    }
```

Reemplazar por (mismo resultado — misma lista de IDs "nuevos, un email por cliente" — pero **sin** escribir en la tabla de logs):
```php
    public function ajax_bulk_count()
    {
        try {
            check_ajax_referer('mrg_bulk_process_orders', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
            }

            if (!class_exists('WooCommerce')) {
                wp_send_json_error(__('WooCommerce no está activo.', 'mis-resenas-de-google'));
            }

            // Usamos return => ids para no saturar la memoria con objetos pesados
            // Añadimos type => shop_order para evitar que se cuelen devoluciones (refunds)
            $order_ids = \wc_get_orders([
                'type' => 'shop_order',
                'status' => 'completed',
                'limit' => -1,
                'return' => 'ids',
            ]);

            if (empty($order_ids)) {
                wp_send_json_success(['count' => 0, 'ids' => []]);
            }

            global $wpdb;
            $logs_table = $wpdb->prefix . 'mrg_email_logs';

            // Pedidos que YA tienen fila en el historial (de cualquier estado): se excluyen.
            $logged_order_ids = array_map('intval', $wpdb->get_col("SELECT order_id FROM $logs_table WHERE order_id > 0"));

            // Regla "un email por cliente": excluir también por email ya presente en el historial.
            $existing_emails = $wpdb->get_col("SELECT DISTINCT customer_email FROM $logs_table");
            $existing_emails = is_array($existing_emails) ? array_map('strtolower', $existing_emails) : [];

            // Solo LECTURA: no se crea ninguna fila en mrg_email_logs en este paso.
            $to_send_ids = [];
            foreach ($order_ids as $order_id) {
                if (in_array((int) $order_id, $logged_order_ids, true)) {
                    continue;
                }

                $order = wc_get_order($order_id);
                if (!$order) {
                    continue;
                }

                $email = strtolower($order->get_billing_email());
                if (empty($email) || in_array($email, $existing_emails, true)) {
                    continue;
                }

                $to_send_ids[] = $order_id;
                $existing_emails[] = $email; // no contar dos pedidos del mismo cliente en el mismo calculo
            }

            wp_send_json_success([
                'count' => count($to_send_ids),
                'ids' => $to_send_ids
            ]);

        } catch (\Throwable $e) {
            error_log("[MRG] Error fatal en ajax_bulk_count: " . $e->getMessage());
            wp_send_json_error(__('Error al procesar la lista de pedidos. Revisa el log de errores.', 'mis-resenas-de-google'));
        }
    }
```

**Por qué esto también arregla el paso ②:** ahora `ajax_bulk_process_orders()` recibe IDs que **no** tienen log todavía, así que `schedule_or_send()` (que internamente hace `exists_for_order()` → false) sí programa/envía de verdad. El contador que ve el admin en el `confirm()` de JS (`bulkOrderIds.length`) ya refleja el número real de correos que van a salir — no hace falta tocar el JS.

- [ ] **Paso 2: Verificar sintaxis**

```bash
php -l "resenas_woo/includes/Admin/EmailsPage.php"
```

- [ ] **Paso 3: Verificación manual en staging**
  1. Con varios pedidos completados antiguos sin invitación enviada, pulsar "① Calcular destinatarios".
  2. Comprobar en la tabla `mrg_email_logs` (o en Historial) que **no** aparecen filas nuevas todavía.
  3. Pulsar "② Enviar a historial" y confirmar.
  4. Comprobar que ahora sí aparecen en Historial como `pendiente`/`no_iniciado` según `send_delay_days`, listos para el envío masivo escalonado.

- [ ] **Paso 4: Commit**

```bash
git add includes/Admin/EmailsPage.php
git commit -m "fix: separar contar destinatarios de crear registros en proceso masivo (I2)"
```

---

## FASE 4 — Rendimiento y limpieza

### Task 10: `uninstall.php` — limpiar crons con argumentos y meta HPOS

**Files:**
- Modify: `resenas_woo/uninstall.php:39-45`

- [ ] **Paso 1: Editar**

Código actual (líneas 39-45):
```php
// 3. ELIMINAR METADATOS DE CUALQUIER PEDIDO DE WOOCOMMERCE
// Limpieza de la marca '_mrg_invitation_sent' que indica si ya se invitó a un cliente
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_mrg_invitation_sent'");

// 4. OPCIONAL: Limpiar eventos programados (aunque WP suele manejarlos, forzamos)
wp_clear_scheduled_hook('mrg_send_scheduled_email');
```

Reemplazar por:
```php
// 3. ELIMINAR METADATOS DE CUALQUIER PEDIDO DE WOOCOMMERCE
// Limpieza de la marca '_mrg_invitation_sent' que indica si ya se invito a un cliente.
// Se limpia tanto en postmeta (almacenamiento legacy) como en la tabla de meta de HPOS,
// porque ambas pueden tener datos segun el modo de almacenamiento usado en la vida del sitio.
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_mrg_invitation_sent'");

$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta_table)) === $hpos_meta_table) {
    $wpdb->query("DELETE FROM {$hpos_meta_table} WHERE meta_key = '_mrg_invitation_sent'");
}

// 4. Limpiar eventos programados. wp_clear_scheduled_hook() sin argumentos solo
// desprograma eventos que se programaron SIN argumentos: los reales llevan [$order_id],
// asi que quedaban huerfanos. wp_unschedule_hook() elimina TODOS los eventos del hook
// sin importar sus argumentos (WP 5.1+; este plugin requiere WP 6.0+).
wp_unschedule_hook('mrg_send_scheduled_email');
```

- [ ] **Paso 2: Verificar sintaxis**

```bash
php -l "resenas_woo/uninstall.php"
```

- [ ] **Paso 3: Commit**

```bash
git add uninstall.php
git commit -m "fix: uninstall.php limpia crons con argumentos y meta HPOS (I8)"
```

### Task 11: Quitar cache-busting global y `error_log` del frontend

**Por qué:** el shortcode envía `Cache-Control: no-cache, no-store, must-revalidate` en **toda** la página que lo contenga (no solo el bloque de reseñas), anulando la caché de página completa del sitio — daña PageSpeed globalmente. Las reseñas ya tienen su propio transient de 1h, así que no necesitan esto. Además `Renderer.php` hace `error_log()` en cada render.

**Files:**
- Modify: `resenas_woo/includes/Frontend/Shortcode.php:38-50`
- Modify: `resenas_woo/includes/Frontend/Renderer.php:26-36`

- [ ] **Paso 1: Editar `Shortcode::render()`**

Código actual (líneas 29-57):
```php
    public function render($atts = [])
    {
        $atts = shortcode_atts([
            'theme' => '',
            'stars' => '',
            'limit' => '',
            'design' => 'horizontal',
        ], $atts, 'mis_resenas_google');

        // Excluir página del caché de LiteSpeed
        if (defined('LSCACHE_ENABLED') && LSCACHE_ENABLED) {
            if (function_exists('do_action')) {
                do_action('litespeed_control_set_nocache');
            }
        }

        // Headers HTTP para prevenir caché en navegadores
        if (!headers_sent()) {
            header('Cache-Control: no-cache, no-store, must-revalidate', true);
            header('Pragma: no-cache', true);
            header('Expires: 0', true);
        }

        wp_enqueue_style('mrg-frontend');
        wp_enqueue_script('mrg-frontend');

        $renderer = new Renderer();
        return $renderer->render($atts);
    }
```

Reemplazar por (se elimina el bloqueo de caché de página completa; las reseñas se siguen refrescando solas cada hora vía el transient de `Renderer`):
```php
    public function render($atts = [])
    {
        $atts = shortcode_atts([
            'theme' => '',
            'stars' => '',
            'limit' => '',
            'design' => 'horizontal',
        ], $atts, 'mis_resenas_google');

        wp_enqueue_style('mrg-frontend');
        wp_enqueue_script('mrg-frontend');

        $renderer = new Renderer();
        return $renderer->render($atts);
    }
```

- [ ] **Paso 2: Editar `Renderer::render()`**

Código actual (líneas 26-36):
```php
        $transient_key = 'mrg_reviews_cache_' . md5(json_encode(['limit' => $limit, 'stars' => $stars, 'text' => $only_with_text]));
        $reviews = get_transient($transient_key);

        if (false === $reviews) {
            $reviews = $repo->get_reviews($limit, $stars, true, $only_with_text);
            $cache_duration = 1;
            set_transient($transient_key, $reviews, $cache_duration * HOUR_IN_SECONDS);
            error_log("[MRG] Reviews cached for $cache_duration hours");
        } else {
            error_log('[MRG] Reviews loaded from transient');
        }
```

Reemplazar por:
```php
        $transient_key = 'mrg_reviews_cache_' . md5(json_encode(['limit' => $limit, 'stars' => $stars, 'text' => $only_with_text]));
        $reviews = get_transient($transient_key);

        if (false === $reviews) {
            $reviews = $repo->get_reviews($limit, $stars, true, $only_with_text);
            set_transient($transient_key, $reviews, HOUR_IN_SECONDS);
        }
```

- [ ] **Paso 3: Verificar sintaxis**

```bash
php -l "resenas_woo/includes/Frontend/Shortcode.php"
php -l "resenas_woo/includes/Frontend/Renderer.php"
```

- [ ] **Paso 4: Verificación manual**

Visitar una página con el shortcode y comprobar en DevTools → Network que la respuesta ya no lleva `Cache-Control: no-cache, no-store, must-revalidate` a nivel de página (el resto de headers de caché del sitio/hosting deben seguir aplicando). Comprobar que las reseñas se siguen viendo igual y que `debug.log` no recibe más líneas `[MRG] Reviews...` en cada carga.

- [ ] **Paso 5: Commit**

```bash
git add includes/Frontend/Shortcode.php includes/Frontend/Renderer.php
git commit -m "fix: quitar no-cache global y error_log del frontend (I6)"
```

### Task 12: `delete_transient()` real (compatible con object cache) para la limpieza de reseñas

**Por qué:** `Settings::clear_review_transients()` y `ReviewSyncService::clear_review_transients()` (código duplicado en ambas clases) borran los transients con `DELETE` directo sobre `wp_options`. Con Redis/Memcached activo, los transients no viven en `wp_options` → tras importar reseñas, la web sigue mostrando datos de hasta 1h de antigüedad porque el borrado no llegó al object cache real.

**Files:**
- Modify: `resenas_woo/includes/Admin/Settings.php:143-154`
- Modify: `resenas_woo/includes/Reviews/ReviewSyncService.php:182-193`

**Nota de diseño:** ambas clases generan la misma clave con la misma fórmula que `Renderer.php:26` (`'mrg_reviews_cache_' . md5(...)`), pero solo `Renderer` conoce las combinaciones reales de `limit`/`stars`/`text` que se han usado (dependen de ajustes y atributos de shortcode). Como hoy en la práctica **solo existe una combinación activa a la vez** (los ajustes son globales y `reviews_limit`/`default_stars`/`only_text_reviews` no varían por shortcode individual salvo por atributos `stars`), lo más simple y correcto es seguir borrando por el prefijo `mrg_reviews_cache_` pero vía la API de transients de WordPress, no SQL directo. Para eso hace falta primero obtener las claves existentes (`_transient_` + prefijo) y borrarlas una a una con `delete_transient()`.

- [ ] **Paso 1: Editar `Settings::clear_review_transients()`**

Código actual (líneas 143-154):
```php
    private function clear_review_transients()
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '%_transient_mrg_reviews_cache_%',
                '%_transient_timeout_mrg_reviews_cache_%'
            )
        );
    }
```

Reemplazar por:
```php
    private function clear_review_transients()
    {
        \MRG\Helpers::delete_review_transients();
    }
```

- [ ] **Paso 2: Editar `ReviewSyncService::clear_review_transients()`**

Código actual (líneas 182-193):
```php
    private function clear_review_transients()
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '%_transient_mrg_reviews_cache_%',
                '%_transient_timeout_mrg_reviews_cache_%'
            )
        );
    }
```

Reemplazar por:
```php
    private function clear_review_transients()
    {
        \MRG\Helpers::delete_review_transients();
    }
```

- [ ] **Paso 3: Añadir el método compartido en `Helpers`**

Primero leer `resenas_woo/includes/Helpers.php` para ubicar dónde añadir el método (mismo estilo que `write_review_url()`/`render_stars()` ya existentes en esa clase). Añadir:

```php
    public static function delete_review_transients()
    {
        global $wpdb;

        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_mrg_reviews_cache_') . '%'
            )
        );

        foreach ($option_names as $option_name) {
            $transient_key = str_replace('_transient_', '', $option_name);
            delete_transient($transient_key);
        }
    }
```

(Se sigue usando una consulta a `wp_options` solo para **descubrir qué claves existen** — WordPress no ofrece una función nativa "listar transients por prefijo" — pero el borrado real pasa siempre por `delete_transient()`, que sí es compatible con Redis/Memcached porque WordPress la enruta al object cache si está activo.)

- [ ] **Paso 4: Verificar sintaxis**

```bash
php -l "resenas_woo/includes/Admin/Settings.php"
php -l "resenas_woo/includes/Reviews/ReviewSyncService.php"
php -l "resenas_woo/includes/Helpers.php"
```

- [ ] **Paso 5: Verificación manual**

Importar reseñas manualmente y comprobar que la web muestra las nuevas reseñas de inmediato (sin esperar 1h), tanto con como sin object cache persistente activo si el staging lo permite.

- [ ] **Paso 6: Commit**

```bash
git add includes/Admin/Settings.php includes/Reviews/ReviewSyncService.php includes/Helpers.php
git commit -m "fix: borrar transients de reseñas via delete_transient() compatible con object cache (I7)"
```

### Task 13: Checkbox "solo reseñas con texto" — no se puede desactivar

**Files:**
- Modify: `resenas_woo/includes/Admin/Settings.php:108`

- [ ] **Paso 1: Editar**

Código actual (línea 108):
```php
            'only_text_reviews' => array_key_exists('only_text_reviews', $input) ? 1 : (int) ($current['only_text_reviews'] ?? 1),
```

Reemplazar por (mismo patrón que `hide_review_avatars`, línea 109 — checkbox desmarcado = key ausente = 0):
```php
            'only_text_reviews' => array_key_exists('only_text_reviews', $input) ? 1 : 0,
```

- [ ] **Paso 2: Verificar sintaxis**

```bash
php -l "resenas_woo/includes/Admin/Settings.php"
```

- [ ] **Paso 3: Verificación manual**

En Ajustes, activar "Mostrar solo reseñas con texto", guardar, desactivar, guardar de nuevo → comprobar que queda desactivado (antes se quedaba pegado en activado).

- [ ] **Paso 4: Commit**

```bash
git add includes/Admin/Settings.php
git commit -m "fix: permitir desactivar el checkbox only_text_reviews (I3)"
```

### Task 14: Log de email de prueba — falla silenciosamente a partir del segundo envío

**Por qué:** `ajax_send_test_email()` llama a `$logs->log(0, ...)`. La tabla tiene `UNIQUE KEY order_id`, así que el segundo `INSERT` con `order_id = 0` falla en silencio (el primero se actualiza vía el `UPDATE` de `log()`, pero solo puede existir **una** fila de prueba a la vez — las pruebas se pisan entre sí).

**Files:**
- Modify: `resenas_woo/includes/Admin/EmailsPage.php:83-92`

- [ ] **Paso 1: Editar `ajax_send_test_email()` para usar un id sintético único por prueba**

Código actual (líneas 83-92):
```php
        $sent = wp_mail($to, $subject, $message, $headers);

        $logs = new \MRG\Emails\EmailLogRepository();
        if ($sent) {
            $logs->log(0, 'Prueba Técnica', $to, 'prueba', current_time('mysql'), current_time('mysql'));
            wp_send_json_success(sprintf(__('Email de prueba enviado a %s', 'mis-resenas-de-google'), esc_html($to)));
        } else {
            $logs->log(0, 'Prueba Técnica', $to, 'error', current_time('mysql'), null, 'Error en wp_mail envíando prueba.');
            wp_send_json_error(__('No se pudo enviar el email. Revisa la configuración SMTP de WordPress.', 'mis-resenas-de-google'));
        }
```

Reemplazar por (id negativo único por marca de tiempo — nunca puede colisionar con un `order_id` real de WooCommerce, que siempre es positivo, y cada prueba queda como fila propia en vez de pisar la anterior):
```php
        $sent = wp_mail($to, $subject, $message, $headers);

        // order_id sintético y único por prueba: negativo (nunca choca con un pedido real,
        // que siempre es positivo) y distinto en cada envío, para no pisar pruebas anteriores
        // en la columna UNIQUE KEY order_id.
        $test_log_id = -1 * time();

        $logs = new \MRG\Emails\EmailLogRepository();
        if ($sent) {
            $logs->log($test_log_id, 'Prueba Técnica', $to, 'prueba', current_time('mysql'), current_time('mysql'));
            wp_send_json_success(sprintf(__('Email de prueba enviado a %s', 'mis-resenas-de-google'), esc_html($to)));
        } else {
            $logs->log($test_log_id, 'Prueba Técnica', $to, 'error', current_time('mysql'), null, 'Error en wp_mail envíando prueba.');
            wp_send_json_error(__('No se pudo enviar el email. Revisa la configuración SMTP de WordPress.', 'mis-resenas-de-google'));
        }
```

- [ ] **Paso 2: Confirmar que ningún otro punto del código asume `order_id >= 0`**

Revisar `claim_for_send()`, `set_sent()`, `set_failed()`: todas operan por `order_id` exacto vía `$wpdb->update(..., ['order_id' => (int) $order_id])`/`WHERE order_id = %d`, no asumen positividad. `ajax_bulk_count()` (Task 9) ya filtra `WHERE order_id > 0` para los logs "reales", así que los negativos de prueba quedan naturalmente excluidos del cálculo de destinatarios. Ningún cambio adicional necesario.

- [ ] **Paso 3: Verificar sintaxis**

```bash
php -l "resenas_woo/includes/Admin/EmailsPage.php"
```

- [ ] **Paso 4: Verificación manual**

Enviar 3 emails de prueba seguidos desde Emails → "Enviar prueba". Comprobar en Historial que aparecen las 3 filas (antes solo sobrevivía la última).

- [ ] **Paso 5: Commit**

```bash
git add includes/Admin/EmailsPage.php
git commit -m "fix: cada email de prueba genera su propia fila en el historial (I4)"
```

### Task 15: Rendimiento — `InvitationsPage::render()` no cargue 500+ pedidos en cada carga (I5, caso común)

**Por qué:** en la vista por defecto (sin búsqueda, sin filtro, orden por fecha — el caso de uso más habitual del admin) el código carga hasta 500 IDs y llama a `wc_get_order()` por cada uno **antes** de paginar, en cada carga de página. Con búsqueda, filtro de estado, u ordenación por columnas no nativas de WooCommerce (`customer`, `email`, `status`) no hay forma simple de paginar a nivel de base de datos sin una reescritura mayor (unir la tabla de pedidos con `mrg_email_logs`), así que esos casos mantienen el comportamiento actual. Esta tarea optimiza el camino rápido (caso por defecto), que es el que se ejecuta en el 100% de las cargas iniciales de la pantalla.

**Files:**
- Modify: `resenas_woo/includes/Admin/InvitationsPage.php:85-171` (`render()`, bloques 1 y 2)

- [ ] **Paso 1: Añadir el camino rápido antes del bloque 1 actual**

Código actual, inicio de `render()` (líneas 92-119):
```php
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'date';
        $order = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';

        // 1. OBTENCIÓN DE LA BASE DE DATOS (Pedidos completados)
        // Obtenemos los últimos 500 para tener una base fresca
        $base_ids = wc_get_orders([
            'status' => 'completed',
            'limit' => 500,
            'return' => 'ids',
        ]);

        // Si hay búsqueda, buscamos órdenes que coincidan específicamente (incluso si son antiguas)
        $search_ids = [];
        if (!empty($search)) {
            $search_ids = wc_get_orders([
                'status' => 'completed',
                's' => $search,
                'limit' => 100, // Límite para resultados de búsqueda específicos
                'return' => 'ids'
            ]);
        }

        // Combinamos y eliminamos duplicados
        $all_order_ids = array_unique(array_merge($base_ids, $search_ids));
```

Reemplazar por (se añade `$is_default_view` y, cuando aplica, se pide a WooCommerce solo la página actual en vez de 500+ IDs):
```php
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'date';
        $order = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';

        // Camino rápido: vista por defecto (sin búsqueda, sin filtro de estado, orden por fecha).
        // Es el 100% de las cargas iniciales de esta pantalla. Aquí SÍ paginamos a nivel de
        // consulta en vez de cargar 500+ pedidos en cada carga de página (I5).
        $is_default_view = empty($search) && empty($status_filter) && $orderby === 'date';

        if ($is_default_view) {
            $all_order_ids = wc_get_orders([
                'status' => 'completed',
                'limit' => $per_page,
                'paged' => $current_page,
                'orderby' => 'date',
                'order' => $order,
                'return' => 'ids',
            ]);
            $total_items = (int) wc_get_orders([
                'status' => 'completed',
                'limit' => 1,
                'paginate' => true,
                'return' => 'ids',
            ])->total;
        } else {
            // 1. OBTENCIÓN DE LA BASE DE DATOS (Pedidos completados)
            // Obtenemos los últimos 500 para tener una base fresca
            $base_ids = wc_get_orders([
                'status' => 'completed',
                'limit' => 500,
                'return' => 'ids',
            ]);

            // Si hay búsqueda, buscamos órdenes que coincidan específicamente (incluso si son antiguas)
            $search_ids = [];
            if (!empty($search)) {
                $search_ids = wc_get_orders([
                    'status' => 'completed',
                    's' => $search,
                    'limit' => 100, // Límite para resultados de búsqueda específicos
                    'return' => 'ids'
                ]);
            }

            // Combinamos y eliminamos duplicados
            $all_order_ids = array_unique(array_merge($base_ids, $search_ids));
        }
```

- [ ] **Paso 2: Ajustar el bloque 5 (paginación) para no recalcular cuando ya viene paginado**

Código actual (líneas 217-220):
```php
        // 5. PAGINACIÓN
        $total_items = count($enriched_data);
        $total_pages = ceil($total_items / $per_page);
        $paged_data = array_slice($enriched_data, ($current_page - 1) * $per_page, $per_page);
```

Reemplazar por:
```php
        // 5. PAGINACIÓN
        if ($is_default_view) {
            // $total_items ya viene del conteo de WooCommerce (paso 1); $enriched_data
            // ya es solo la página actual, no hay que volver a recortarla.
            $total_pages = $total_items > 0 ? ceil($total_items / $per_page) : 1;
            $paged_data = $enriched_data;
        } else {
            $total_items = count($enriched_data);
            $total_pages = ceil($total_items / $per_page);
            $paged_data = array_slice($enriched_data, ($current_page - 1) * $per_page, $per_page);
        }
```

- [ ] **Paso 3: Verificar sintaxis**

```bash
php -l "resenas_woo/includes/Admin/InvitationsPage.php"
```

- [ ] **Paso 4: Verificación manual (importante — validar en staging con datos reales antes de producción)**

1. Con >100 pedidos completados en staging, abrir Invitaciones (vista por defecto) y comprobar en el profiler de WP (Query Monitor) que ya no se ejecutan ~500 `wc_get_order()` por carga, solo ~20.
2. Comprobar que la paginación (números de página, "Siguiente"/"Anterior") sigue siendo correcta y que el conteo total coincide con WooCommerce → Pedidos filtrado por "Completado".
3. Probar búsqueda, filtro de estado, y ordenar por "Cliente"/"Email"/"Estado" → deben seguir funcionando igual que antes (camino lento, sin cambios).
4. Si algo no cuadra en el conteo o el orden con datos reales, **no continuar**: este es el punto de mayor riesgo del plan porque no hay forma de ejecutar WooCommerce en este entorno para probarlo de antemano — avisar y decidir con el usuario si se ajusta o se revierte solo esta tarea.

- [ ] **Paso 5: Commit**

```bash
git add includes/Admin/InvitationsPage.php
git commit -m "perf: paginar a nivel de consulta la vista por defecto de Invitaciones (I5)"
```

### Task 16: Verificación manual final de la Fase 4

- [ ] **Paso 1: Repetir el checklist completo de Fase 1 y Fase 2** (regresión — ningún cambio de Fase 3/4 debe reabrir C1-C4).
- [ ] **Paso 2: Revisar `debug.log`** tras una sesión normal de uso del admin: no debe haber `error_log()` de `[MRG]` en el frontend (Task 11), y no debe haber PHP notices nuevas.
- [ ] **Paso 3: Confirmar con el usuario antes de publicar a producción.**

---

## Backlog opcional (no crítico, no incluido como tarea numerada)

Estos puntos del informe quedan fuera de las 4 fases porque no son bugs activos o son cambios de bajo impacto — se documentan aquí por si se quieren abordar más adelante:

- **Mejora 2** (duplicación entre el email de prueba y `EmailSender`/`EmailTemplate`): el test no usa `wp_mail_failed` ni clasifica errores SMTP como los envíos reales. Refactor de conveniencia, no bug.
- **Mejora 3** (doble ruta de guardado de ajustes de email): revisado — `Settings::sanitize()` solo recibe en `$input` los campos que su propio formulario renderiza (no incluye `email_subject`, `from_name`, etc., porque `Settings::render_*()` no pinta esos campos), así que hoy `array_key_exists(...)` siempre cae al `$current[...]` para esas claves y no hay colisión real en la práctica. Se deja como nota de claridad de código, no como fix urgente.
- **Mejora 6** (assets de Google/Wikimedia cargados desde CDN externo en `Renderer.php` y `Activator.php`): mejora de robustez/privacidad, no bug funcional.
- **Mejora 7, 8** (transient sin `place_id`, sin lista de exclusión de clientes): mejoras de producto, no bugs.
- **`LogsPage::ajax_get_task_list`** (segunda mitad de I5 — sincroniza hasta 200 pedidos completos en cada clic de "Enviar Masivo: Pendientes"): requiere rediseñar el flujo de sincronización (posible batching real en servidor) más que un parche puntual. Recomendado como plan aparte tras validar la Task 15 en producción.

---

## Resumen de commits esperados (uno por tarea, en orden)

1. `fix: cancelar cron pendiente al marcar un email como enviado (C1)`
2. `fix: bloqueo atomico y re-verificacion de ajustes en ruta cron (C2, C4)`
3. `fix: migrar meta de pedido a API de WooCommerce para compatibilidad HPOS (C3)`
4. `fix: migrar limpieza/restauracion de marcas a API de pedido para HPOS (C3)`
5. `feat: declarar compatibilidad HPOS y Requires Plugins (C3)`
6. `fix: reparar bloque JS roto que mostraba codigo PHP literal (I1)`
7. `fix: separar contar destinatarios de crear registros en proceso masivo (I2)`
8. `fix: uninstall.php limpia crons con argumentos y meta HPOS (I8)`
9. `fix: quitar no-cache global y error_log del frontend (I6)`
10. `fix: borrar transients de reseñas via delete_transient() compatible con object cache (I7)`
11. `fix: permitir desactivar el checkbox only_text_reviews (I3)`
12. `fix: cada email de prueba genera su propia fila en el historial (I4)`
13. `perf: paginar a nivel de consulta la vista por defecto de Invitaciones (I5)`
