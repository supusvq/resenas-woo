<?php
namespace MRG\Emails;

if (!defined('ABSPATH')) {
    exit;
}

class EmailLogRepository
{
    private $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'mrg_email_logs';
    }

    public function log($order_id, $customer_name, $customer_email, $status, $scheduled_at = null, $sent_at = null, $error_message = '')
    {
        global $wpdb;

        $existing = ($order_id > 0) ? $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE order_id = %d", $order_id)) : null;
        $data = [
            'order_id' => (int) $order_id,
            'customer_name' => sanitize_text_field($customer_name),
            'customer_email' => sanitize_email($customer_email),
            'status' => sanitize_text_field($status),
            'scheduled_at' => $scheduled_at,
            'sent_at' => $sent_at,
            'error_message' => sanitize_textarea_field($error_message),
            'updated_at' => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($this->table, $data, ['id' => (int) $existing]);
            return;
        }

        $data['created_at'] = current_time('mysql');
        $wpdb->insert($this->table, $data);
    }

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

    public function exists_for_email($email)
    {
        global $wpdb;
        // 1. Verificamos en nuestra tabla de logs
        $exists = (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE customer_email = %s", sanitize_email($email)));
        if ($exists)
            return true;

        // 2. Red de seguridad: Buscar si hay algún pedido enviado a este email en WooCommerce
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'billing_email' => $email,
                'meta_key' => '_mrg_invitation_sent',
                'meta_value' => 'yes',
                'limit' => 1,
                'return' => 'ids'
            ]);
            return !empty($orders);
        }
        return false;
    }

    public function count_logs($search = '')
    {
        global $wpdb;
        $where = "WHERE 1=1";
        if (!empty($search)) {
            $where .= $wpdb->prepare(" AND (customer_email LIKE %s OR customer_name LIKE %s)", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} $where");
    }

    public function get_paginated_logs($limit = 20, $offset = 0, $search = '', $orderby = 'id', $order = 'DESC')
    {
        global $wpdb;
        $limit = max(1, absint($limit));
        $offset = absint($offset);

        // Validar columnas para evitar inyección
        $allowed_columns = ['id', 'order_id', 'customer_name', 'customer_email', 'status', 'attempts', 'scheduled_at', 'sent_at'];
        if (!in_array($orderby, $allowed_columns)) {
            $orderby = 'id';
        }
        $order = (strtoupper($order) === 'ASC') ? 'ASC' : 'DESC';

        $where = "WHERE 1=1";
        if (!empty($search)) {
            $where .= $wpdb->prepare(" AND (customer_email LIKE %s OR customer_name LIKE %s)", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} $where ORDER BY $orderby $order LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }

    public function get_logs_by_order_ids($order_ids)
    {
        global $wpdb;
        if (empty($order_ids) || !is_array($order_ids)) {
            return [];
        }
        $order_ids = array_map('intval', $order_ids);
        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE order_id IN ($placeholders)",
            ...$order_ids
        ));
    }

    public function get_log_by_order_id($order_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE order_id = %d", (int) $order_id));
    }

    public function ensure_log_exists($order_id, $order = null, $check_email = false)
    {
        if (!$order && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }

        if (!is_a($order, 'WC_Order')) {
            return false;
        }

        // 1. Si ya existe un log para ESTE pedido exacto, no hacemos nada más.
        $log = $this->get_log_by_order_id($order_id);
        if ($log) {
            return true;
        }

        // 2. Si se pide validación de email único, comprobamos si el cliente ya está en el historial
        if ($check_email) {
            $email = $order->get_billing_email();
            if ($this->exists_for_email($email)) {
                return false; // Ya tiene un registro con otro pedido, saltamos.
            }
        }

        $this->log(
            $order_id,
            $order->get_formatted_billing_full_name(),
            $order->get_billing_email(),
            'no_iniciado',
            null,
            null,
            'Registro creado para gestión.'
        );
        return true;
    }

    public function claim_for_send($order_id)
    {
        global $wpdb;
        $order_id = (int) $order_id;

        if (!$this->ensure_log_exists($order_id)) {
            return false;
        }

        // Intento atómico de bloqueo e incremento de intentos
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET status = 'procesando', attempts = attempts + 1, updated_at = %s WHERE order_id = %d AND status != 'enviado'",
            current_time('mysql'),
            $order_id
        ));

        return (bool) $result;
    }

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

    public function set_failed($order_id, $error_message, $permanent = false, $technical_log = '')
    {
        global $wpdb;
        $data = [
            'status' => 'error',
            'error_message' => sanitize_textarea_field($error_message),
            'technical_log' => $technical_log
        ];

        if ($permanent) {
            $data['attempts'] = 3;
        }

        $wpdb->update($this->table, $data, ['order_id' => (int) $order_id]);
    }
}
