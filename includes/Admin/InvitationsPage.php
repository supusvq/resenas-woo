<?php
namespace MRG\Admin;

use MRG\Emails\EmailLogRepository;
use MRG\Emails\EmailScheduler;

if (!defined('ABSPATH')) {
    exit;
}

class InvitationsPage
{
    public function init()
    {
        add_action('mrg_render_invitations_page', [$this, 'render']);
        add_action('wp_ajax_mrg_send_single_invitation', [$this, 'ajax_send_now']);
        add_action('wp_ajax_mrg_clear_sent_meta', [$this, 'ajax_clear_sent_meta']);
        add_action('wp_ajax_mrg_restore_sent_meta', [$this, 'ajax_restore_sent_meta']);
    }

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

    public function ajax_send_now()
    {
        check_ajax_referer('mrg_invitations_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
        }

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        if (!$order_id) {
            wp_send_json_error(__('ID de pedido no válido', 'mis-resenas-de-google'));
        }

        $repo = new EmailLogRepository();
        if (!$repo->claim_for_send($order_id)) {
            wp_send_json_error(__('No se pudo procesar el pedido (posible envío en curso o ya enviado).', 'mis-resenas-de-google'));
        }

        $scheduler = new EmailScheduler();
        $success = $scheduler->send_now($order_id, true); // true = manual

        if ($success) {
            wp_send_json_success(__('Invitación enviada correctamente.', 'mis-resenas-de-google'));
        } else {
            wp_send_json_error(__('Error al enviar la invitación. Consulta el historial para más detalles.', 'mis-resenas-de-google'));
        }
    }

    public function render()
    {
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce no está activo.', 'mis-resenas-de-google') . '</p></div>';
            return;
        }

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

        // 2. ENRIQUECIMIENTO DE DATOS
        $enriched_data = [];
        if (!empty($all_order_ids)) {
            $repo = new EmailLogRepository();
            $logs_by_id_list = $repo->get_logs_by_order_ids($all_order_ids);

            $logs_map = [];
            foreach ($logs_by_id_list as $l) {
                $logs_map[$l->order_id] = $l;
            }

            foreach ($all_order_ids as $oid) {
                $order_obj = wc_get_order($oid);
                if (!$order_obj || !is_a($order_obj, 'WC_Order') || is_a($order_obj, 'WC_Order_Refund')) {
                    continue;
                }

                $email = $order_obj->get_billing_email();
                $customer_name = $order_obj->get_formatted_billing_full_name();
                $log = isset($logs_map[$oid]) ? $logs_map[$oid] : null;

                // Determinar estado lógico
                $status = 'no_iniciado';
                $attempts = 0;
                $error = '-';

                if ($log) {
                    $status = $log->status;
                    $attempts = $log->attempts;
                    $error = $log->error_message;
                } else {
                    $legacy_sent = get_post_meta($oid, '_mrg_invitation_sent', true);
                    if ($legacy_sent === 'yes') {
                        $status = 'enviado';
                        $error = __('Sincronizado vía meta', 'mis-resenas-de-google');
                    }
                }

                $enriched_data[] = [
                    'id' => $oid,
                    'order_number' => $order_obj->get_order_number(),
                    'date' => $order_obj->get_date_created() ? $order_obj->get_date_created()->getTimestamp() : 0,
                    'display_date' => $order_obj->get_date_created() ? $order_obj->get_date_created()->date_i18n('Y-m-d H:i') : '-',
                    'customer' => $customer_name,
                    'email' => $email,
                    'status' => $status,
                    'attempts' => $attempts,
                    'error' => $error
                ];
            }
        }

        // 3. FILTRADO (Buscador y Status)
        if (!empty($search)) {
            $search_lc = mb_strtolower($search);
            $enriched_data = array_filter($enriched_data, function ($item) use ($search_lc) {
                return (strpos(mb_strtolower($item['id']), $search_lc) !== false) ||
                    (strpos(mb_strtolower($item['customer']), $search_lc) !== false) ||
                    (strpos(mb_strtolower($item['email']), $search_lc) !== false);
            });
        }

        if (!empty($status_filter)) {
            $enriched_data = array_filter($enriched_data, function ($item) use ($status_filter) {
                return $item['status'] === $status_filter;
            });
        }

        // 4. ORDENACIÓN
        $status_priority = [
            'no_iniciado' => 0,
            'pendiente' => 1,
            'procesando' => 2,
            'enviado' => 3,
            'error' => 4
        ];

        usort($enriched_data, function ($a, $b) use ($orderby, $order, $status_priority) {
            $val_a = $a[$orderby] ?? '';
            $val_b = $b[$orderby] ?? '';

            if ($orderby === 'status') {
                $val_a = $status_priority[$a['status']] ?? 99;
                $val_b = $status_priority[$b['status']] ?? 99;
            }

            if ($val_a == $val_b)
                return 0;

            if ($order === 'ASC') {
                return ($val_a < $val_b) ? -1 : 1;
            } else {
                return ($val_a > $val_b) ? -1 : 1;
            }
        });

        // 5. PAGINACIÓN
        $total_items = count($enriched_data);
        $total_pages = ceil($total_items / $per_page);
        $paged_data = array_slice($enriched_data, ($current_page - 1) * $per_page, $per_page);

        // 6. RENDER
        $nonce = wp_create_nonce('mrg_invitations_action');

        echo '<div class="wrap mrg-admin-wrap">';
        echo '<h1>' . esc_html__('Invitaciones manuales', 'mis-resenas-de-google') . '</h1>';

        // Formulario de búsqueda y filtros
        echo '<form method="get" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px;">';
        echo '  <input type="hidden" name="page" value="' . esc_attr($_GET['page']) . '">';
        echo '  <input type="hidden" name="tab" value="' . esc_attr($_GET['tab'] ?? 'invitations') . '">';

        echo '  <div style="display: flex; flex-direction: column; gap: 4px;">';
        echo '    <label style="font-size: 11px; font-weight: bold; color: #666;">' . esc_html__('BUSCAR (Pedido, Nombre, Email)', 'mis-resenas-de-google') . '</label>';
        echo '    <input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Ej: 12345 o Juan...', 'mis-resenas-de-google') . '" style="min-width: 250px; height: 32px;">';
        echo '  </div>';

        echo '  <div style="display: flex; flex-direction: column; gap: 4px;">';
        echo '    <label style="font-size: 11px; font-weight: bold; color: #666;">' . esc_html__('ESTADO INVITACIÓN', 'mis-resenas-de-google') . '</label>';
        echo '    <select name="status_filter" style="height: 32px; min-width: 150px;">';
        echo '      <option value="">' . esc_html__('Todos los estados', 'mis-resenas-de-google') . '</option>';
        echo '      <option value="no_iniciado" ' . selected($status_filter, 'no_iniciado', false) . '>' . esc_html__('No intentado', 'mis-resenas-de-google') . '</option>';
        echo '      <option value="pendiente" ' . selected($status_filter, 'pendiente', false) . '>' . esc_html__('Pendiente / Programado', 'mis-resenas-de-google') . '</option>';
        echo '      <option value="enviado" ' . selected($status_filter, 'enviado', false) . '>' . esc_html__('Enviado', 'mis-resenas-de-google') . '</option>';
        echo '      <option value="error" ' . selected($status_filter, 'error', false) . '>' . esc_html__('Con Errores', 'mis-resenas-de-google') . '</option>';
        echo '    </select>';
        echo '  </div>';

        echo '  <div style="display: flex; align-items: flex-end; height: 50px;">';
        echo '    <button type="submit" class="button button-primary">' . esc_html__('Aplicar Filtros', 'mis-resenas-de-google') . '</button>';
        if ($search || $status_filter) {
            echo '    <a href="' . esc_url(remove_query_arg(['s', 'status_filter', 'paged'])) . '" class="button-link" style="margin-left: 10px; line-height: 28px;">' . esc_html__('Limpiar', 'mis-resenas-de-google') . '</a>';
        }
        echo '  </div>';
        echo '</form>';

        echo '<p style="background:#fff; padding:10px; border-left:4px solid #2271b1; box-shadow:0 1px 1px rgba(0,0,0,0.04);"><strong>' . esc_html__('Total:', 'mis-resenas-de-google') . '</strong> ' . sprintf(esc_html__('%d registros encontrados.', 'mis-resenas-de-google'), $total_items) . '</p>';

        echo '<div style="margin: 20px 0; display: flex; gap: 10px; align-items: center; background: #f0f0f1; padding: 15px; border-radius: 4px; border: 1px dashed #2271b1;">';
        echo '  <span style="font-weight:bold; color:#2271b1;">' . esc_html__('🧰 Mantenimiento:', 'mis-resenas-de-google') . '</span>';
        echo '  <button id="mrg-btn-restore-orders" class="button button-secondary" title="' . esc_attr__('Vacía las marcas actuales y sincroniza todo con el historial real', 'mis-resenas-de-google') . '">' . esc_html__('🔄 Restaurar Pedidos (Sincronización Total)', 'mis-resenas-de-google') . '</button>';
        echo '  <div style="flex-grow:1;"></div>';
        echo '  <button id="mrg-btn-clear-invitations" class="button button-link" style="color:#d63638; font-size:12px;">' . esc_html__('Limpiar todas las marcas de envío', 'mis-resenas-de-google') . '</button>';
        echo '</div>';

        $this->render_pagination($current_page, $total_pages);

        $render_sort_link = function ($label, $column) use ($orderby, $order) {
            $new_order = ($orderby === $column && $order === 'ASC') ? 'DESC' : 'ASC';
            $url = add_query_arg(['orderby' => $column, 'order' => $new_order, 'paged' => 1]);
            $icon = '';
            if ($orderby === $column) {
                $icon = ($order === 'ASC') ? ' ▲' : ' ▼';
            }
            return '<a href="' . esc_url($url) . '" style="text-decoration:none; color:inherit; font-weight:bold;">' . esc_html($label) . $icon . '</a>';
        };

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>#</th>';
        echo '<th>' . $render_sort_link(__('Pedido', 'mis-resenas-de-google'), 'id') . '</th>';
        echo '<th>' . $render_sort_link(__('Fecha', 'mis-resenas-de-google'), 'date') . '</th>';
        echo '<th>' . $render_sort_link(__('Cliente', 'mis-resenas-de-google'), 'customer') . '</th>';
        echo '<th>' . $render_sort_link(__('Email', 'mis-resenas-de-google'), 'email') . '</th>';
        echo '<th>' . $render_sort_link(__('Estado Invitación', 'mis-resenas-de-google'), 'status') . '</th>';
        echo '<th>' . esc_html__('Intentos', 'mis-resenas-de-google') . '</th>';
        echo '<th>' . esc_html__('Último Error', 'mis-resenas-de-google') . '</th>';
        echo '<th>' . esc_html__('Acción', 'mis-resenas-de-google') . '</th>';
        echo '</tr></thead><tbody>';

        if (!empty($paged_data)) {
            $row_number = ($current_page - 1) * $per_page + 1;
            foreach ($paged_data as $item) {
                $status = $item['status'];
                $status_style = '';
                if ($status === 'pendiente')
                    $status_style = 'color: #e67e22; font-weight: bold;';
                if ($status === 'enviado')
                    $status_style = 'color: #27ae60; font-weight: bold;';
                if ($status === 'error')
                    $status_style = 'color: #c0392b; font-weight: bold;';
                $status_label = $status;
                if ($status === 'no_iniciado') $status_label = __('No intentado', 'mis-resenas-de-google');
                if ($status === 'pendiente') $status_label = __('Pendiente', 'mis-resenas-de-google');
                if ($status === 'enviado') $status_label = __('Enviado', 'mis-resenas-de-google');
                if ($status === 'error') $status_label = __('Error', 'mis-resenas-de-google');
                if ($status === 'procesando') $status_label = __('Procesando', 'mis-resenas-de-google');

                echo '<tr>';
                echo '<td style="color:#888;">' . $row_number . '</td>';
                echo '<td>#' . esc_html($item['order_number']) . '</td>';
                echo '<td>' . esc_html($item['display_date']) . '</td>';
                echo '<td>' . esc_html($item['customer']) . '</td>';
                echo '<td>' . esc_html($item['email']) . '</td>';
                echo '<td><span style="' . $status_style . '">' . esc_html(ucfirst($status_label)) . '</span></td>';
                echo '<td>' . esc_html($item['attempts']) . ' / 3</td>';
                echo '<td style="font-size: 0.9em; opacity: 0.8;">' . esc_html($item['error']) . '</td>';
                echo '<td>';
                if ($status !== 'enviado') {
                    echo '<button class="button button-small mrg-send-invitation" data-order-id="' . esc_attr($item['id']) . '">' . esc_html__('Enviar ahora', 'mis-resenas-de-google') . '</button>';
                } else {
                    echo '<span class="dashicons dashicons-yes" style="color:#27ae60;"></span>';
                }
                echo '</td>';
                echo '</tr>';
                $row_number++;
            }
        } else {
            echo '<tr><td colspan="9">' . esc_html__('No se encontraron pedidos con los criterios seleccionados.', 'mis-resenas-de-google') . '</td></tr>';
        }
        echo '</tbody></table>';

        $this->render_pagination($current_page, $total_pages);
        $this->print_script($nonce);
        echo '</div>';
    }

    private function render_pagination($current, $total)
    {
        if ($total <= 1)
            return;
        echo '<div class="mrg-pagination">';
        echo paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => '&laquo; ' . __('Anterior', 'mis-resenas-de-google'),
            'next_text' => __('Siguiente', 'mis-resenas-de-google') . ' &raquo;',
            'total' => $total,
            'current' => $current,
            'type' => 'plain'
        ]);
        echo '</div>';
    }

    private function print_script($nonce)
    {
        ?>
        <style>
            .mrg-pagination {
                margin: 15px 0;
                display: flex;
                gap: 5px;
            }

            .mrg-pagination .page-numbers {
                padding: 5px 10px;
                background: #fff;
                border: 1px solid #ccd0d4;
                text-decoration: none;
                border-radius: 3px;
            }

            .mrg-pagination .current {
                background: #2271b1;
                color: #fff;
                border-color: #2271b1;
            }
        </style>
        <script>
            document.querySelectorAll(".mrg-send-invitation").forEach(btn => {
                btn.addEventListener("click", function () {
                    if (!confirm("<?php echo esc_js(__('¿Deseas enviar la invitación de reseña para este pedido ahora?', 'mis-resenas-de-google')); ?>")) return;

                    const orderId = this.getAttribute("data-order-id");
                    const originalText = this.textContent;
                    this.disabled = true;
                    this.textContent = "<?php echo esc_js(__('Enviando...', 'mis-resenas-de-google')); ?>";

                    fetch(ajaxurl, {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({
                            action: "mrg_send_single_invitation",
                            nonce: "<?php echo esc_js($nonce); ?>",
                            order_id: orderId
                        })
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.data);
                                location.reload();
                            } else {
                                alert("<?php echo esc_js(__('Error: ', 'mis-resenas-de-google')); ?>" + data.data);
                                this.disabled = false;
                                this.textContent = originalText;
                            }
                        })
                        .catch(err => {
                            alert("<?php echo esc_js(__('Error en la conexión', 'mis-resenas-de-google')); ?>");
                            this.disabled = false;
                            this.textContent = originalText;
                        });
                });
            });

            document.getElementById('mrg-btn-clear-invitations').onclick = function () {
                if (!confirm("⚠️ <?php echo esc_js(__('¿Estás seguro de que quieres borrar las marcas de \'Enviado\' de WooCommerce?\n\nEsto hará que todos los pedidos aparezcan como \'No intentado\' en esta lista, pero NO borrará el Historial real.', 'mis-resenas-de-google')); ?>")) return;

                const btn = this;
                btn.disabled = true;
                const originalText = btn.textContent;
                btn.textContent = "<?php echo esc_js(__('Limpiando...', 'mis-resenas-de-google')); ?>";

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'mrg_clear_sent_meta',
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
                        btn.textContent = originalText;
                    });
            };

            const btnRestore = document.getElementById('mrg-btn-restore-orders');
            if (btnRestore) {
                btnRestore.onclick = function () {
                    const msg = "<?php echo esc_js(__('Esta acción realizará lo siguiente:\n1. Limpiará los estados actuales de la tabla.\n2. Volverá a marcar como \'Enviado\' aquellos pedidos que existan en el Historial.\n\n¿Deseas proceder con la Restauración Total?', 'mis-resenas-de-google')); ?>";

                    if (!confirm(msg)) return;

                    const btn = this;
                    btn.disabled = true;
                    const originalText = btn.textContent;
                    btn.textContent = "<?php echo esc_js(__('Restaurando y Sincronizando...', 'mis-resenas-de-google')); ?>";

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'mrg_restore_sent_meta',
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
                            btn.textContent = originalText;
                        });
                };
            }
        </script>
        <?php
    }
}
