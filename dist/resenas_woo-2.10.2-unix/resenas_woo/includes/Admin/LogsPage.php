<?php
namespace MRG\Admin;

use MRG\Emails\EmailLogRepository;
use MRG\Emails\EmailScheduler;

if (!defined('ABSPATH')) {
    exit;
}

class LogsPage
{
    public function init()
    {
        add_action('mrg_render_logs_page', [$this, 'render']);
        add_action('wp_ajax_mrg_get_task_list', [$this, 'ajax_get_task_list']);
        add_action('wp_ajax_mrg_process_single_task', [$this, 'ajax_process_single_task']);
        add_action('wp_ajax_mrg_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_mrg_restore_logs', [$this, 'ajax_restore_logs']);
    }

    public function ajax_get_task_list()
    {
        try {
            check_ajax_referer('mrg_logs_action', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
            }

            $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'pending';
            global $wpdb;
            $table = $wpdb->prefix . 'mrg_email_logs';
            $max_attempts = 3;
            $repo = new EmailLogRepository();
            // 30 minutos de tiempo de gracia para procesos bloqueados
            $zombie_time = gmdate('Y-m-d H:i:s', time() - 1800);

            $ids = [];

            // 1. Si buscamos pendientes, sincronizamos primero con WooCommerce
            // para que los nuevos pedidos aparezcan en la lista y en el conteo final.
            if ($type === 'pending' && class_exists('WooCommerce')) {
                $orders = wc_get_orders([
                    'status' => 'completed',
                    'limit' => 200,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ]);

                foreach ($orders as $order) {
                    if (!is_a($order, 'WC_Order'))
                        continue;
                    $order_id = $order->get_id();

                    // Sincronizamos validando email único (uno por cliente)
                    $repo->ensure_log_exists($order_id, $order, true);
                }
            }

            // 2. Fuente de verdad final: Consultar qué pedidos en el historial son procesables
            $status_condition = ($type === 'pending')
                ? "(status = 'pendiente' OR status = 'no_iniciado' OR (status = 'procesando' AND updated_at < %s))"
                : "(status = 'error' OR (status = 'procesando' AND updated_at < %s))";

            $final_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT order_id FROM $table WHERE attempts < %d AND $status_condition",
                $max_attempts,
                $zombie_time
            ));

            $ids = $final_ids ? array_map('intval', array_unique($final_ids)) : [];

            wp_send_json_success([
                'ids' => $ids,
                'total_count' => $repo->count_logs()
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(__('Error interno: ', 'mis-resenas-de-google') . $e->getMessage());
        }
    }

    public function ajax_process_single_task()
    {
        try {
            check_ajax_referer('mrg_logs_action', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
            }

            $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
            if (!$order_id)
                wp_send_json_error(__('ID no válido', 'mis-resenas-de-google'));

            $repo = new EmailLogRepository();
            // claim_for_send asegura que el log exista y lo marca como 'procesando'
            if (!$repo->claim_for_send($order_id)) {
                wp_send_json_error(__('Pedido ya en proceso o enviado', 'mis-resenas-de-google'));
            }

            $scheduler = new EmailScheduler();
            $success = $scheduler->send_now($order_id, true); // manual = true

            if ($success) {
                wp_send_json_success(__('Enviado', 'mis-resenas-de-google'));
            } else {
                wp_send_json_error(__('Falló el envío', 'mis-resenas-de-google'));
            }
        } catch (\Exception $e) {
            wp_send_json_error(__('Error en proceso: ', 'mis-resenas-de-google') . $e->getMessage());
        }
    }

    public function ajax_clear_logs()
    {
        check_ajax_referer('mrg_logs_action', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}mrg_email_logs");
        wp_send_json_success(__('Historial limpiado correctamente. Los pedidos enviados conservan su marca en WooCommerce, por lo que no se duplicarán si usas el botón "Restaurar".', 'mis-resenas-de-google'));
    }

    public function ajax_restore_logs()
    {
        try {
            check_ajax_referer('mrg_logs_action', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(__('No autorizado', 'mis-resenas-de-google'));
            }

            if (!class_exists('WooCommerce')) {
                wp_send_json_error(__('WooCommerce no activo.', 'mis-resenas-de-google'));
            }

            // Buscamos pedidos que tengan la meta de enviado
            $orders = wc_get_orders([
                'meta_key' => '_mrg_invitation_sent',
                'meta_value' => 'yes',
                'limit' => -1,
            ]);

            $repo = new EmailLogRepository();
            $restored = 0;

            foreach ($orders as $order) {
                $order_id = $order->get_id();
                // Si no existe en nuestra tabla, lo recreamos como enviado
                if (!$repo->exists_for_order($order_id)) {
                    $repo->log(
                        $order_id,
                        $order->get_formatted_billing_full_name(),
                        $order->get_billing_email(),
                        'enviado',
                        null,
                        $order->get_date_modified() ? $order->get_date_modified()->date('Y-m-d H:i:s') : current_time('mysql'),
                        __('Restaurado desde metadatos de pedido.', 'mis-resenas-de-google')
                    );
                    $restored++;
                }
            }

            wp_send_json_success(sprintf(__('Se han restaurado %d registros en el historial.', 'mis-resenas-de-google'), $restored));
        } catch (\Exception $e) {
            wp_send_json_error(__('Error al restaurar: ', 'mis-resenas-de-google') . $e->getMessage());
        }
    }

    public function render()
    {
        $repo = new EmailLogRepository();
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset = ($current_page - 1) * $per_page;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'id';
        $order = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';

        $total_items = $repo->count_logs($search);
        $logs = $repo->get_paginated_logs($per_page, $offset, $search, $orderby, $order);
        $total_pages = $total_items > 0 ? ceil($total_items / $per_page) : 1;

        $nonce = wp_create_nonce('mrg_logs_action');

        echo '<div class="wrap mrg-admin-wrap">';
        echo '<h1>' . esc_html__('Historial de envío de invitaciones', 'mis-resenas-de-google') . '</h1>';

        // Formulario de búsqueda
        echo '<form method="get" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center;">';
        echo '  <input type="hidden" name="page" value="' . esc_attr($_GET['page']) . '">';
        echo '  <input type="hidden" name="tab" value="' . esc_attr($_GET['tab'] ?? 'logs') . '">';
        echo '  <input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Buscar por email o nombre...', 'mis-resenas-de-google') . '" style="min-width: 300px; height: 32px;">';
        echo '  <button type="submit" class="button">' . esc_html__('Buscar', 'mis-resenas-de-google') . '</button>';
        if ($search) {
            echo '  <a href="' . esc_url(remove_query_arg(['s', 'paged'])) . '" class="button-link">' . esc_html__('Limpiar', 'mis-resenas-de-google') . '</a>';
        }
        echo '</form>';

        echo '<p style="background:#fff; padding:10px; border-left:4px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,0.04);"><strong>' . esc_html__('Total:', 'mis-resenas-de-google') . '</strong> <span id="mrg-total-count">' . esc_html($total_items) . '</span> ' . esc_html__('registros encontrados en el historial.', 'mis-resenas-de-google') . '</p>';

        echo '<div style="margin: 20px 0; display: flex; flex-direction: column; gap: 10px; background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px;">';
        echo '  <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">';
        echo '    <button id="mrg-btn-bulk-pending" class="button button-primary">' . esc_html__('🚀 Enviar Masivo: Pendientes', 'mis-resenas-de-google') . '</button>';
        echo '    <button id="mrg-btn-bulk-errors" class="button button-secondary">' . esc_html__('🔄 Enviar Masivo: Errores', 'mis-resenas-de-google') . '</button>';
        echo '    <button id="mrg-btn-stop" class="button button-secondary" style="display:none; background-color:#c0392b; color:white; border-color:#c0392b;">' . esc_html__('⏹ Detener envío', 'mis-resenas-de-google') . '</button>';
        echo '    <div style="flex-grow:1;"></div>';
        echo '    <button id="mrg-btn-restore" class="button button-secondary" title="' . esc_attr__('Recrea el historial basándose en los pedidos ya marcados en WooCommerce', 'mis-resenas-de-google') . '">' . esc_html__('♻ Restaurar Historial', 'mis-resenas-de-google') . '</button>';
        echo '    <button id="mrg-btn-clear-logs" class="button button-link" style="color:#d63638;">' . esc_html__('Limpiar historial', 'mis-resenas-de-google') . '</button>';
        echo '  </div>';
        echo '  <div id="mrg-progress-area" style="display:none; margin-top:10px; padding:10px; background:#f0f0f1; border-radius:4px;">';
        echo '    <div style="font-weight:bold; margin-bottom:5px;">' . esc_html__('Progreso:', 'mis-resenas-de-google') . ' <span id="mrg-progress-status">' . esc_html__('Preparando...', 'mis-resenas-de-google') . '</span></div>';
        echo '    <div style="width:100%; height:12px; background:#ddd; border-radius:6px; overflow:hidden;">';
        echo '      <div id="mrg-progress-bar" style="width:0%; height:100%; background:#2271b1; transition: width 0.3s ease;"></div>';
        echo '    </div>';
        echo '    <div id="mrg-timer" style="margin-top:5px; font-size:12px; color:#c0392b;"></div>';
        echo '  </div>';
        echo '</div>';

        $this->render_pagination($current_page, $total_pages);

        // Función auxiliar para renderizar encabezados ordenables
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
        echo '<th>' . $render_sort_link(__('ID Pedido', 'mis-resenas-de-google'), 'order_id') . '</th>';
        echo '<th>' . $render_sort_link(__('Cliente', 'mis-resenas-de-google'), 'customer_name') . '</th>';
        echo '<th>' . $render_sort_link(__('Email', 'mis-resenas-de-google'), 'customer_email') . '</th>';
        echo '<th>' . $render_sort_link(__('Estado', 'mis-resenas-de-google'), 'status') . '</th>';
        echo '<th>' . $render_sort_link(__('Intentos', 'mis-resenas-de-google'), 'attempts') . '</th>';
        echo '<th>' . $render_sort_link(__('Programado para', 'mis-resenas-de-google'), 'scheduled_at') . '</th>';
        echo '<th>' . $render_sort_link(__('Fecha Envío', 'mis-resenas-de-google'), 'sent_at') . '</th>';
        echo '<th>' . esc_html__('Información / Error', 'mis-resenas-de-google') . '</th>';
        echo '</tr></thead><tbody>';

        if ($logs && is_array($logs)) {
            $row_number = ($current_page - 1) * $per_page + 1;
            foreach ($logs as $log) {
                $status_style = '';
                $status_label = ucfirst((string) ($log->status ?? ''));

                if ($log->status === 'pendiente' || $log->status === 'no_iniciado') {
                    $status_style = 'color: #e67e22; font-weight: bold;';
                    $status_label = ($log->status === 'no_iniciado') ? __('Pendiente', 'mis-resenas-de-google') : __('Programado', 'mis-resenas-de-google');
                }
                if ($log->status === 'enviado')
                    $status_style = 'color: #27ae60; font-weight: bold;';
                if ($log->status === 'error')
                    $status_style = 'color: #c0392b; font-weight: bold;';
                if ($log->status === 'procesando')
                    $status_style = 'color: #3498db; font-style: italic;';

                echo '<tr>';
                echo '<td style="color:#888;">' . $row_number . '</td>';
                echo '<td>#' . esc_html((string) ($log->order_id ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($log->customer_name ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($log->customer_email ?? '')) . '</td>';
                $row_number++;
                echo '<td><span style="' . $status_style . '">' . esc_html($status_label) . '</span></td>';
                echo '<td>' . esc_html((string) ($log->attempts ?? 0)) . ' / 3</td>';
                echo '<td>' . esc_html((string) ($log->scheduled_at ?: '-')) . '</td>';
                echo '<td>' . esc_html((string) ($log->sent_at ?: '-')) . '</td>';
                echo '<td style="font-size: 0.9em; opacity: 0.8;">';
                echo esc_html((string) ($log->error_message ?? ''));
                if (!empty($log->technical_log)) {
                    echo '<br><a href="#" class="mrg-view-tech-log" data-log="' . esc_attr($log->technical_log) . '" style="text-decoration:none; font-size:11px; color:#2271b1;">[' . esc_html__('Ver Log Técnico', 'mis-resenas-de-google') . ']</a>';
                }
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="8">' . esc_html__('Sin envíos registrados.', 'mis-resenas-de-google') . '</td></tr>';
        }
        echo '</tbody></table>';

        $this->render_pagination($current_page, $total_pages);
        $this->print_client_script($nonce);
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

    private function print_client_script($nonce)
    {
        ?>
        <div id="mrg-tech-modal"
            style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter:blur(3px);">
            <div
                style="background:#fff; margin:10% auto; padding:25px; border-radius:12px; width:60%; max-width:800px; box-shadow:0 20px 40px rgba(0,0,0,0.2); position:relative; animation: mrgFadeIn 0.3s ease-out;">
                <span id="mrg-close-modal"
                    style="position:absolute; right:20px; top:15px; font-size:28px; cursor:pointer; color:#999;">&times;</span>
                <h2 style="margin-top:0; color:#1d2327; border-bottom:1px solid #eee; padding-bottom:15px;">' . esc_html__('Diagnóstico Técnico SMTP', 'mis-resenas-de-google') . '</h2>
                <div style="margin-top:20px; max-height:400px; overflow-y:auto; background:#f6f7f7; padding:15px; border-radius:6px; border:1px solid #dcdcde; font-family:monospace; font-size:13px; line-height:1.6; white-space:pre-wrap; color:#3c434a;"
                    id="mrg-tech-content"></div>
                <p style="margin-top:20px; font-size:12px; color:#646970; border-top:1px solid #eee; padding-top:15px;">' . esc_html__('Este es el mensaje bruto recibido del servidor.', 'mis-resenas-de-google') . '</p>
            </div>
        </div>

        <style>
            @keyframes mrgFadeIn {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .mrg-view-tech-log:hover {
                text-decoration: underline !important;
            }

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
                    btn.textContent = "' . esc_js(__('Deteniendo...', 'mis-resenas-de-google')) . '";
                    btn.disabled = true;
                };

                const startBulk = async (type) => {
                    if (!confirm("' . esc_js(__('¿Deseas iniciar el envío masivo escalonado (espera de 30s entre correos)?', 'mis-resenas-de-google')) . '")) return;

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
                    btnStop.textContent = "' . esc_js(__('⏹ Detener envío', 'mis-resenas-de-google')) . '";
                    progressArea.style.display = 'block';

                    try {
                        statusText.textContent = "' . esc_js(__('Obteniendo lista de tareas...', 'mis-resenas-de-google')) . '";
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
                            alert("' . esc_js(__('No hay registros para procesar.', 'mis-resenas-de-google')) . '");
                            location.reload();
                            return;
                        }

                        for (let i = 0; i < ids.length; i++) {
                            if (cancelBulk) {
                                statusText.textContent = "' . esc_js(__('Proceso detenido por el usuario.', 'mis-resenas-de-google')) . '";
                                break;
                            }

                            const orderId = ids[i];
                            const currentIdx = i + 1;
                            const percent = (currentIdx / ids.length) * 100;

                            statusText.textContent = "' . esc_js(__('Enviando pedido #', 'mis-resenas-de-google')) . '" + orderId + " (" + currentIdx + " ' . esc_js(__('de', 'mis-resenas-de-google')) . ' " + ids.length + ")...";
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
                                    timerText.textContent = "' . esc_js(__('Próximo envío en', 'mis-resenas-de-google')) . ' " + countdown + " ' . esc_js(__('segundos...', 'mis-resenas-de-google')) . '";
                                    await new Promise(r => setTimeout(r, 1000));
                                    countdown--;
                                }
                            }
                        }

                        if (!cancelBulk) statusText.textContent = "' . esc_js(__('¡Completado!', 'mis-resenas-de-google')) . '";
                        alert(cancelBulk ? "' . esc_js(__('Envío detenido.', 'mis-resenas-de-google')) . '" : "' . esc_js(__('Proceso masivo finalizado.', 'mis-resenas-de-google')) . '");
                        location.reload();

                    } catch (err) {
                        alert("' . esc_js(__('Error en el proceso masivo: ', 'mis-resenas-de-google')) . '" + err.message);
                        btnP.disabled = btnE.disabled = false;
                        btnStop.style.display = 'none';
                    }
                };

                document.getElementById('mrg-btn-bulk-pending').onclick = () => startBulk('pending');
                document.getElementById('mrg-btn-bulk-errors').onclick = () => startBulk('error');

                document.getElementById('mrg-btn-clear-logs').onclick = function () {
                    const msg = "⚠️ ' . esc_js(__('ATENCIÓN: Estás a punto de borrar la tabla de historial.', 'mis-resenas-de-google')) . '\n\n" +
                        "- ' . esc_js(__('Los datos de fecha de envío y errores técnicos se perderán de esta vista.', 'mis-resenas-de-google')) . '\n" +
                        "- ' . esc_js(__('NO se enviarán correos duplicados porque conservamos una marca oculta en WooCommerce.', 'mis-resenas-de-google')) . '\n" +
                        "- ' . esc_js(__('Puedes usar el botón \'Restaurar\' para recuperar los registros enviados.', 'mis-resenas-de-google')) . '\n\n" +
                        "' . esc_js(__('¿Deseas continuar con el borrado?', 'mis-resenas-de-google')) . '";
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
                    if (!confirm("' . esc_js(__('Este proceso buscará en WooCommerce todos los pedidos marcados como \'Enviados\' para reconstruir el historial. ¿Deseas continuar?', 'mis-resenas-de-google')) . '")) return;

                    const btn = this;
                    btn.disabled = true;
                    btn.textContent = "' . esc_js(__('Restaurando...', 'mis-resenas-de-google')) . '";

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
                            alert("' . esc_js(__('Error de conexión.', 'mis-resenas-de-google')) . '");
                            btn.disabled = false;
                            btn.textContent = "♻ ' . esc_js(__('Restaurar Historial', 'mis-resenas-de-google')) . '";
                        });
                };
            })();
        </script>
        <?php
    }
}
