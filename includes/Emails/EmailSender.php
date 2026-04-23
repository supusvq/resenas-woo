<?php
namespace MRG\Emails;

if (!defined('ABSPATH')) {
    exit;
}

class EmailSender
{
    private $last_wp_error = null;

    public function capture_mail_error($error)
    {
        $this->last_wp_error = $error;
    }

    public function send_for_order($order_id)
    {
        $this->last_wp_error = null;

        if (!function_exists('wc_get_order')) {
            return new \WP_Error('permanent_error', 'WooCommerce no está activo.');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('permanent_error', 'Pedido no encontrado.');
        }

        $email = $order->get_billing_email();
        if (empty($email) || !is_email($email)) {
            return new \WP_Error('permanent_error', 'Email inválido o vacío.');
        }

        $settings = get_option('mrg_settings', []);
        $template = new EmailTemplate();
        $subject = $template->replace_variables($settings['email_subject'] ?? '', $order);
        $message = $template->replace_variables($settings['email_template'] ?? '', $order);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        // Configuración de From y Reply-to...
        if (!empty($settings['from_name']) && !empty($settings['from_email'])) {
            $headers[] = 'From: ' . $settings['from_name'] . ' <' . $settings['from_email'] . '>';
        }
        if (!empty($settings['reply_to'])) {
            $headers[] = 'Reply-To: ' . $settings['reply_to'];
        }

        // Activamos el "escucha" de errores de WP
        add_action('wp_mail_failed', [$this, 'capture_mail_error']);

        $sent = wp_mail($email, $subject, $message, $headers);

        remove_action('wp_mail_failed', [$this, 'capture_mail_error']);

        if (!$sent) {
            $error_msg = 'Error en el servidor de correo (SMTP).';
            $error_type = 'temporary_error';

            if (is_wp_error($this->last_wp_error)) {
                $raw_msg = $this->last_wp_error->get_error_message();
                $error_msg = 'SMTP: ' . $raw_msg;

                // REGLAS DE INTERPRETACIÓN SMTP
                $permanent_patterns = [
                    '/550/i',
                    '/554/i',
                    '/501/i', // Códigos 5xx
                    '/Unknown user/i',
                    '/User unknown/i',
                    '/No such user/i',
                    '/Recipient address rejected/i',
                    '/Unrouteable address/i'
                ];

                foreach ($permanent_patterns as $pattern) {
                    if (preg_match($pattern, $raw_msg)) {
                        $error_type = 'permanent_error';
                        break;
                    }
                }
            }

            return new \WP_Error($error_type, $error_msg);
        }

        return true;
    }
}
