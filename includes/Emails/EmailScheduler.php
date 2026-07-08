<?php
namespace MRG\Emails;

if (!defined('ABSPATH')) {
    exit;
}

class EmailScheduler
{
    public function schedule_or_send($order_id, $force_enabled = false)
    {
        $settings = get_option('mrg_settings', []);
        $enabled = !empty($settings['enable_review_requests']) || $force_enabled;
        if (!$enabled) {
            return;
        }

        $order_id = (int) $order_id;
        $order = wc_get_order($order_id);
        if (!$order || !method_exists($order, 'get_billing_email')) {
            return;
        }

        $logs = new EmailLogRepository();
        if ($logs->exists_for_order($order_id)) {
            return;
        }

        if ($logs->exists_for_email($order->get_billing_email())) {
            return;
        }

        $delay_days = (int) ($settings['send_delay_days'] ?? 0);
        $seconds = ($delay_days > 0) ? ($delay_days * DAY_IN_SECONDS) : (5 * MINUTE_IN_SECONDS);
        $timestamp = time() + $seconds;
        $reason = ($delay_days > 0) ? __('automático vía Cron.', 'mis-resenas-de-google') : __('de cortesía (5 min) tras completado.', 'mis-resenas-de-google');

        // EVITAR DUPLICADOS DE EVENTOS CRON
        if (!wp_next_scheduled('mrg_send_scheduled_email', [$order_id])) {
            wp_schedule_single_event($timestamp, 'mrg_send_scheduled_email', [$order_id]);
        }

        $logs->log(
            $order_id,
            $order->get_formatted_billing_full_name(),
            $order->get_billing_email(),
            'pendiente',
            gmdate('Y-m-d H:i:s', $timestamp),
            null,
            sprintf(__('Programado para envío %s', 'mis-resenas-de-google'), $reason)
        );
    }

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
}
