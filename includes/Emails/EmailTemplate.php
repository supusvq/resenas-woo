<?php
namespace MRG\Emails;

use MRG\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

class EmailTemplate
{
    public function replace_variables($template, $order)
    {
        $settings = get_option('mrg_settings', []);
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name();
        }

        $replacements = [
            '{nombre_cliente}' => $order->get_billing_first_name(),
            '{numero_pedido}' => $order->get_order_number(),
            '{fecha_pedido}' => $order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/Y') : '',
            '{productos}' => implode(', ', $items),
            '{enlace_resena}' => !empty($settings['email_review_url']) ? esc_url($settings['email_review_url']) : Helpers::write_review_url(),
            '{nombre_empresa}' => $settings['email_company_name'] ?? get_bloginfo('name'),
            '{correo_empresa}' => $settings['footer_privacy_email'] ?? '',
            '{url_politica_privacidad}' => $settings['footer_privacy_url'] ?? '',
        ];

        return strtr($template, $replacements);
    }
}
