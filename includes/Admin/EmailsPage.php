<?php
namespace MRG\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class EmailsPage
{
    public function init()
    {
        add_action('mrg_render_emails_page', [$this, 'render']);
        add_action('admin_post_mrg_save_email_settings', [$this, 'save']);
        add_action('wp_ajax_mrg_send_test_email', [$this, 'ajax_send_test_email']);
        add_action('wp_ajax_mrg_bulk_process_orders', [$this, 'ajax_bulk_process_orders']);
        add_action('wp_ajax_mrg_bulk_count', [$this, 'ajax_bulk_count']);
        add_action('wp_ajax_mrg_reset_email_template', [$this, 'ajax_reset_email_template']);
    }

    public function save()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('No autorizado', 'mis-resenas-de-google'));
        }
        check_admin_referer('mrg_save_email_settings');

        $settings = get_option('mrg_settings', []);
        $settings['enable_review_requests'] = isset($_POST['enable_review_requests']) ? 1 : 0;
        $settings['send_delay_days'] = max(0, min(30, absint($_POST['send_delay_days'] ?? 0)));
        $settings['email_subject'] = sanitize_text_field(wp_unslash($_POST['email_subject'] ?? ''));
        $settings['from_name'] = sanitize_text_field(wp_unslash($_POST['from_name'] ?? ''));
        $settings['from_email'] = sanitize_email(wp_unslash($_POST['from_email'] ?? ''));
        $settings['reply_to'] = sanitize_email(wp_unslash($_POST['reply_to'] ?? ''));
        $settings['email_company_name'] = sanitize_text_field(wp_unslash($_POST['email_company_name'] ?? ''));
        $settings['email_review_url'] = esc_url_raw(wp_unslash($_POST['email_review_url'] ?? ''));
        $settings['footer_privacy_email'] = sanitize_email(wp_unslash($_POST['footer_privacy_email'] ?? ''));
        $settings['footer_privacy_url'] = esc_url_raw(wp_unslash($_POST['footer_privacy_url'] ?? ''));
        $settings['email_template'] = wp_kses_post(wp_unslash($_POST['email_template'] ?? ''));
        update_option('mrg_settings', $settings);

        wp_safe_redirect(add_query_arg(['page' => 'mrg-emails', 'updated' => 1], admin_url('admin.php')));
        exit;
    }

    public function ajax_send_test_email()
    {
        check_ajax_referer('mrg_send_test_email', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
        }

        $to = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        if (!is_email($to)) {
            wp_send_json_error(__('Email no válido.', 'mis-resenas-de-google'));
        }

        $settings = get_option('mrg_settings', []);
        $subject = $settings['email_subject'] ?? __('Correo de prueba', 'mis-resenas-de-google');
        $message = $settings['email_template'] ?? '<p>' . __('Este es un correo de prueba.', 'mis-resenas-de-google') . '</p>';

        // Replace variables with dummy values
        $vars = [
            '{nombre_cliente}' => 'Cliente de prueba',
            '{numero_pedido}' => '12345',
            '{fecha_pedido}' => date_i18n('d/m/Y'),
            '{productos}' => __('Producto de ejemplo', 'mis-resenas-de-google'),
            '{enlace_resena}' => !empty($settings['email_review_url']) ? esc_url($settings['email_review_url']) : \MRG\Helpers::write_review_url(),
            '{nombre_empresa}' => $settings['email_company_name'] ?? get_bloginfo('name'),
            '{correo_empresa}' => $settings['footer_privacy_email'] ?? '',
            '{url_politica_privacidad}' => $settings['footer_privacy_url'] ?? '',
        ];
        $subject = strtr($subject, $vars);
        $message = strtr($message, $vars);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (!empty($settings['from_name']) && !empty($settings['from_email'])) {
            $headers[] = 'From: ' . $settings['from_name'] . ' <' . $settings['from_email'] . '>';
        }
        if (!empty($settings['reply_to'])) {
            $headers[] = 'Reply-To: ' . $settings['reply_to'];
        }

        $sent = wp_mail($to, $subject, $message, $headers);

        $logs = new \MRG\Emails\EmailLogRepository();
        if ($sent) {
            $logs->log(0, 'Prueba Técnica', $to, 'prueba', current_time('mysql'), current_time('mysql'));
            wp_send_json_success(sprintf(__('Email de prueba enviado a %s', 'mis-resenas-de-google'), esc_html($to)));
        } else {
            $logs->log(0, 'Prueba Técnica', $to, 'error', current_time('mysql'), null, 'Error en wp_mail envíando prueba.');
            wp_send_json_error(__('No se pudo enviar el email. Revisa la configuración SMTP de WordPress.', 'mis-resenas-de-google'));
        }
    }

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

    public function ajax_bulk_process_orders()
    {
        try {
            check_ajax_referer('mrg_bulk_process_orders', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
            }

            if (!class_exists('WooCommerce')) {
                wp_send_json_error(__('WooCommerce no está activo.', 'mis-resenas-de-google'));
            }

            $order_ids = isset($_POST['ids']) ? array_map('absint', (array) $_POST['ids']) : [];
            if (empty($order_ids)) {
                wp_send_json_error(__('No se recibieron IDs para procesar.', 'mis-resenas-de-google'));
            }

            // Lock simple para evitar colisiones
            if (get_transient('mrg_bulk_lock')) {
                wp_send_json_error(__('Ya hay un proceso en marcha en el servidor. Por favor, espera.', 'mis-resenas-de-google'));
            }
            set_transient('mrg_bulk_lock', 1, 60);

            $scheduler = new \MRG\Emails\EmailScheduler();
            $processed = 0;
            $errors = 0;

            foreach ($order_ids as $order_id) {
                try {
                    $scheduler->schedule_or_send($order_id, true);
                    $processed++;
                } catch (\Exception $e) {
                    $errors++;
                    error_log("[MRG] Error procesando pedido individual $order_id: " . $e->getMessage());
                }
            }

            delete_transient('mrg_bulk_lock');

            wp_send_json_success([
                'processed' => $processed,
                'errors' => $errors,
                'message' => sprintf(__('Lote de %d procesado.', 'mis-resenas-de-google'), $processed)
            ]);

        } catch (\Throwable $e) {
            delete_transient('mrg_bulk_lock');
            error_log("[MRG] Error fatal en ajax_bulk_process_orders: " . $e->getMessage());
            wp_send_json_error(sprintf(__('Error al procesar el lote: %s', 'mis-resenas-de-google'), $e->getMessage()));
        }
    }

    public function ajax_reset_email_template()
    {
        check_ajax_referer('mrg_reset_template', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
        }

        $default = \MRG\Activator::get_default_email_template();
        wp_send_json_success(['template' => $default]);
    }

    public function render()
    {
        $settings = get_option('mrg_settings', []);
        echo '<div class="wrap"><h1>' . esc_html__('Emails', 'mis-resenas-de-google') . '</h1>';

        // Cuadro de instrucciones de uso
        echo '<div class="notice notice-info inline" style="margin: 20px 0; border-left-color: #72aee6; background: #fff; padding: 15px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('🚀 Instrucciones de Uso', 'mis-resenas-de-google') . '</h3>';
        echo '<p>' . esc_html__('Este sistema automatiza el envío de solicitudes de reseña. Así es como funciona:', 'mis-resenas-de-google') . '</p>';
        echo '<ul style="list-style:disc; margin-left:20px;">';
        echo '<li><strong>' . esc_html__('Automatización:', 'mis-resenas-de-google') . '</strong> ' . esc_html__('Si activas las "solicitudes automáticas", el plugin detectará cuando un pedido pase a estado "Completado".', 'mis-resenas-de-google') . '</li>';
        echo '<li><strong>' . esc_html__('Espera:', 'mis-resenas-de-google') . '</strong> ' . esc_html__('El correo se enviará (o programará) tras los "Días de espera" que elijas.', 'mis-resenas-de-google') . '</li>';
        echo '<li><strong>' . esc_html__('Seguridad:', 'mis-resenas-de-google') . '</strong> ' . sprintf(esc_html__('Solo se envía %s para evitar spam.', 'mis-resenas-de-google'), '<u>' . esc_html__('un correo por pedido', 'mis-resenas-de-google') . '</u>') . '</li>';
        echo '<li><strong>' . esc_html__('Variables:', 'mis-resenas-de-google') . '</strong> ' . sprintf(esc_html__('Puedes personalizar el mensaje usando las etiquetas (ej. %s) que verás debajo del editor.', 'mis-resenas-de-google'), '<code>{nombre_cliente}</code>') . '</li>';
        echo '</ul>';
        echo '<p style="margin-bottom:0;"><strong>' . esc_html__('Tip:', 'mis-resenas-de-google') . '</strong> ' . esc_html__('Usa el botón "Enviar prueba" de abajo para verificar el diseño antes de activarlo masivamente.', 'mis-resenas-de-google') . '</p>';
        echo '</div>';

        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Ajustes guardados.', 'mis-resenas-de-google') . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mrg_save_email_settings');
        echo '<input type="hidden" name="action" value="mrg_save_email_settings" />';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Activar solicitudes automáticas', 'mis-resenas-de-google') . '</th><td><label><input type="checkbox" name="enable_review_requests" value="1" ' . checked((int) ($settings['enable_review_requests'] ?? 0), 1, false) . ' /> ' . esc_html__('Sí', 'mis-resenas-de-google') . '</label></td></tr>';
        echo '<tr><th>' . esc_html__('Días de espera', 'mis-resenas-de-google') . '</th><td><input type="number" min="0" max="30" name="send_delay_days" value="' . esc_attr((string) ($settings['send_delay_days'] ?? 0)) . '" /></td></tr>';
        echo '<tr><th>' . esc_html__('Asunto', 'mis-resenas-de-google') . '</th><td><input type="text" class="regular-text" name="email_subject" value="' . esc_attr($settings['email_subject'] ?? '') . '" /></td></tr>';
        echo '<tr><th>' . esc_html__('Remitente', 'mis-resenas-de-google') . '</th><td><input type="text" class="regular-text" name="from_name" value="' . esc_attr($settings['from_name'] ?? '') . '" /></td></tr>';
        echo '<tr><th>' . esc_html__('Email remitente', 'mis-resenas-de-google') . '</th><td><input type="email" class="regular-text" name="from_email" value="' . esc_attr($settings['from_email'] ?? '') . '" /></td></tr>';
        echo '<tr><th>' . esc_html__('Reply-To', 'mis-resenas-de-google') . '</th><td><input type="email" class="regular-text" name="reply_to" value="' . esc_attr($settings['reply_to'] ?? '') . '" /></td></tr>';
        echo '<tr><th style="vertical-align:top;padding-top:12px;">' . esc_html__('Plantilla HTML', 'mis-resenas-de-google') . '<br><br><button type="button" id="mrg-reset-template" class="button button-small">' . esc_html__('Restaurar plantilla legal', 'mis-resenas-de-google') . '</button></th><td>';
        wp_editor($settings['email_template'] ?? '', 'mrg_email_template', [
            'textarea_name' => 'email_template',
            'textarea_rows' => 14,
            'teeny' => false,
            'wpautop' => false,
        ]);
        echo '<p class="description">' . sprintf(esc_html__('Variables: %s', 'mis-resenas-de-google'), '<code>{nombre_cliente}</code>, <code>{numero_pedido}</code>, <code>{fecha_pedido}</code>, <code>{productos}</code>, <code>{enlace_resena}</code>, <code>{nombre_empresa}</code>, <code>{correo_empresa}</code>, <code>{url_politica_privacidad}</code>') . '</p>';
        echo '</td></tr>';
        echo '</table>';

        echo '<h3 style="margin-top:40px; border-bottom:1px solid #ccc; padding-bottom:10px;">' . esc_html__('VARIABLES EDITABLES', 'mis-resenas-de-google') . '</h3>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Nombre de empresa', 'mis-resenas-de-google') . '</th><td><input type="text" class="regular-text" name="email_company_name" value="' . esc_attr($settings['email_company_name'] ?? get_bloginfo('name')) . '" /><p class="description">' . sprintf(esc_html__('Se usa para la variable %s', 'mis-resenas-de-google'), '<code>{nombre_empresa}</code>') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('Enlace de reseña en Google', 'mis-resenas-de-google') . '</th><td><input type="url" class="regular-text" name="email_review_url" value="' . esc_attr($settings['email_review_url'] ?? '') . '" placeholder="https://g.page/r/..." /><p class="description">' . sprintf(esc_html__('Se usa para la variable %s. Si lo dejas vacío, se usará el enlace configurado automáticamente.', 'mis-resenas-de-google'), '<code>{enlace_resena}</code>') . '</p></td></tr>';
        echo '</table>';

        echo '<h3 style="margin-top:40px; border-bottom:1px solid #ccc; padding-bottom:10px;">' . esc_html__('FOOTER EMAIL', 'mis-resenas-de-google') . '</h3>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Email de privacidad', 'mis-resenas-de-google') . '</th><td><input type="email" class="regular-text" name="footer_privacy_email" value="' . esc_attr($settings['footer_privacy_email'] ?? '') . '" /><p class="description">' . sprintf(esc_html__('Se usa para la variable %s', 'mis-resenas-de-google'), '<code>{correo_empresa}</code>') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('URL de política de privacidad', 'mis-resenas-de-google') . '</th><td><input type="url" class="regular-text" name="footer_privacy_url" value="' . esc_attr($settings['footer_privacy_url'] ?? '') . '" /><p class="description">' . sprintf(esc_html__('Se usa para la variable %s', 'mis-resenas-de-google'), '<code>{url_politica_privacidad}</code>') . '</p></td></tr>';
        echo '</table>';

        submit_button(__('Guardar ajustes', 'mis-resenas-de-google'));
        echo '</form>';

        // ── Sección: Enviar correo de prueba ─────────────────────────────
        echo '<hr style="margin:30px 0;">';
        echo '<h2>' . esc_html__('Enviar correo de prueba', 'mis-resenas-de-google') . '</h2>';
        echo '<p class="description">' . esc_html__('Envía un email usando la plantilla y configuración actuales (con datos ficticios) para comprobar que llega correctamente.', 'mis-resenas-de-google') . '</p>';
        echo '<div style="display:flex;align-items:center;gap:12px;margin-top:12px;">';
        echo '<input type="email" id="mrg-test-email" class="regular-text" placeholder="' . esc_attr__('tucorreo@ejemplo.com', 'mis-resenas-de-google') . '" />';
        echo '<button type="button" id="mrg-send-test" class="button button-secondary">' . esc_html__('Enviar prueba', 'mis-resenas-de-google') . '</button>';
        echo '<span id="mrg-test-result" style="font-style:italic;"></span>';
        echo '</div>';

        // ── Sección: Procesar pedidos pasados ───────────────────────────
        echo '<hr style="margin:30px 0;">';
        echo '<h2>' . esc_html__('Procesar pedidos pasados', 'mis-resenas-de-google') . '</h2>';
        echo '<p class="description">' . sprintf(esc_html__('Esta opción buscará pedidos antiguos con estado %s y los añadirá al historial para que se procesen (máxima una vez por cliente).', 'mis-resenas-de-google'), '<strong>' . esc_html__('Completado', 'mis-resenas-de-google') . '</strong>') . '</p>';
        echo '<div style="margin-top:12px; display:flex; gap:10px; align-items:center;">';
        echo '<button type="button" id="mrg-bulk-count" class="button button-secondary">' . esc_html__('① Calcular destinatarios', 'mis-resenas-de-google') . '</button>';
        echo '<button type="button" id="mrg-bulk-process" class="button button-primary" disabled>' . esc_html__('② Enviar a historial', 'mis-resenas-de-google') . '</button>';
        echo '<div id="mrg-bulk-result" style="font-weight:bold;"></div>';
        echo '</div>';

        $nonce = wp_create_nonce('mrg_send_test_email');
        $bulk_nonce = wp_create_nonce('mrg_bulk_process_orders');
        $nonce_reset = wp_create_nonce('mrg_reset_template');
        echo '<script>
        (function(){
            // TinyMCE sync on main form submit
            var mainForm = document.querySelector("form[action*=\'admin-post.php\']");
            if (mainForm) {
                mainForm.addEventListener("submit", function() {
                    if (typeof tinyMCE !== "undefined") { tinyMCE.triggerSave(); }
                });
            }

            // Test email button
            document.getElementById("mrg-send-test").addEventListener("click", function() {
                var email  = document.getElementById("mrg-test-email").value.trim();
                var result = document.getElementById("mrg-test-result");
                if (!email) { result.textContent = "' . esc_js(__('Introduce un email de destino.', 'mis-resenas-de-google')) . '"; result.style.color = "#c00"; return; }
                result.style.color = "#555";
                result.textContent = "' . esc_js(__('Enviando...', 'mis-resenas-de-google')) . '";
                fetch(ajaxurl, {
                    method: "POST",
                    headers: {"Content-Type": "application/x-www-form-urlencoded"},
                    body: new URLSearchParams({action: "mrg_send_test_email", nonce: "' . esc_js($nonce) . '", test_email: email})
                })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data.success) {
                        result.style.color = "#0a0";
                        result.textContent = "✔ " + data.data;
                    } else {
                        result.style.color = "#c00";
                        result.textContent = "✘ " + data.data;
                    }
                })
                .catch(function(){ result.style.color="#c00"; result.textContent="' . esc_js(__('Error de conexión.', 'mis-resenas-de-google')) . '"; });
            });

            // Bulk process button
            var bulkOrderIds = [];
            
            document.getElementById("mrg-bulk-count").addEventListener("click", function() {
                var btn = this;
                var processBtn = document.getElementById("mrg-bulk-process");
                var result = document.getElementById("mrg-bulk-result");
                
                btn.disabled = true;
                result.style.color = "#555";
                result.textContent = "' . esc_js(__('Calculando...', 'mis-resenas-de-google')) . '";
                
                fetch(ajaxurl, {
                    method: "POST",
                    headers: {"Content-Type": "application/x-www-form-urlencoded"},
                    body: new URLSearchParams({action: "mrg_bulk_count", nonce: "' . esc_js($bulk_nonce) . '"})
                })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    btn.disabled = false;
                    if (data.success) {
                        bulkOrderIds = data.data.ids || [];
                        result.style.color = "#555";
                        result.textContent = "' . esc_js(__('Se enviarán aproximadamente', 'mis-resenas-de-google')) . ' " + data.data.count + " ' . esc_js(__('correos nuevos.', 'mis-resenas-de-google')) . '";
                        if (data.data.count > 0) {
                            processBtn.disabled = false;
                        } else {
                            processBtn.disabled = true;
                        }
                    } else {
                        result.style.color = "#c00";
                        result.textContent = "✘ Error: " + data.data;
                    }
                })
                .catch(function(){ 
                    btn.disabled = false;
                    result.style.color="#c00"; 
                    result.textContent="' . esc_js(__('Error de conexión.', 'mis-resenas-de-google')) . '"; 
                });
            });

            document.getElementById("mrg-bulk-process").addEventListener("click", function() {
                if (!bulkOrderIds.length) return;
                if (!confirm("' . esc_js(__('¿Seguro que quieres enviar', 'mis-resenas-de-google')) . ' " + bulkOrderIds.length + " ' . esc_js(__('correos masivos?', 'mis-resenas-de-google')) . '")) return;
                
                var btn = this;
                var countBtn = document.getElementById("mrg-bulk-count");
                var result = document.getElementById("mrg-bulk-result");
                
                btn.disabled = true;
                countBtn.disabled = true;
                
                var total = bulkOrderIds.length;
                var processedTotal = 0;
                var chunkSize = 20;
                
                function processNextChunk() {
                    var chunk = bulkOrderIds.splice(0, chunkSize);
                    if (chunk.length === 0) {
                        result.style.color = "#0a0";
                        result.textContent = "✔ ' . esc_js(__('¡Proceso finalizado! Se han procesado los', 'mis-resenas-de-google')) . ' " + total + " ' . esc_js(__('pedidos.', 'mis-resenas-de-google')) . '";
                        countBtn.disabled = false;
                        btn.style.display = "none";
                        return;
                    }
                    
                    result.style.color = "#555";
                    result.textContent = "' . esc_js(__('Procesando:', 'mis-resenas-de-google')) . ' " + processedTotal + " / " + total + "...";
                    
                    fetch(ajaxurl, {
                        method: "POST",
                        headers: {"Content-Type": "application/x-www-form-urlencoded"},
                        body: new URLSearchParams({
                            action: "mrg_bulk_process_orders", 
                            nonce: "' . esc_js($bulk_nonce) . '",
                            ids: chunk
                        })
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (data.success) {
                            processedTotal += data.data.processed;
                            // Pequeña pausa para no saturar
                            setTimeout(processNextChunk, 500);
                        } else {
                            result.style.color = "#c00";
                            result.textContent = "✘ ' . esc_js(__('Error en lote:', 'mis-resenas-de-google')) . ' " + (data.data || "' . esc_js(__('Error desconocido', 'mis-resenas-de-google')) . '");
                            btn.disabled = false;
                            countBtn.disabled = false;
                        }
                    })
                    .catch(function(e){ 
                        result.style.color="#c00"; 
                        result.textContent="' . esc_js(__('Error de conexión en el lote. Pulsa de nuevo para reintentar.', 'mis-resenas-de-google')) . '"; 
                        btn.disabled = false;
                        countBtn.disabled = false;
                    });
                }
                
                processNextChunk();
            });

            // Reset template
            var resetBtn = document.getElementById("mrg-reset-template");
            if (resetBtn) {
                resetBtn.addEventListener("click", function() {
                    if (!confirm("' . esc_js(__('¿Estás seguro de que quieres restaurar la plantilla legal por defecto? Se borrará todo tu contenido actual en el editor.', 'mis-resenas-de-google')) . '")) {
                        return;
                    }

                    var btn = this;
                    btn.disabled = true;

                    fetch(ajaxurl, {
                        method: "POST",
                        headers: {"Content-Type": "application/x-www-form-urlencoded"},
                        body: new URLSearchParams({
                            action: "mrg_reset_email_template",
                            nonce: "' . esc_js($nonce_reset) . '"
                        })
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        btn.disabled = false;
                        if (data.success) {
                            if (typeof tinyMCE !== "undefined" && tinyMCE.get("mrg_email_template")) {
                                tinyMCE.get("mrg_email_template").setContent(data.data.template);
                                tinyMCE.triggerSave();
                            }
                            document.getElementById("mrg_email_template").value = data.data.template;
                            alert("' . esc_js(__('Plantilla restaurada. Recuerda guardar los cambios.', 'mis-resenas-de-google')) . '");
                        } else {
                            alert("Error: " + data.data);
                        }
                    })
                    .catch(function(){ 
                        btn.disabled = false;
                        alert("' . esc_js(__('Error de conexión.', 'mis-resenas-de-google')) . '"); 
                    });
                });
            }
        })();
        </script>';
        echo '</div>';
    }
}
