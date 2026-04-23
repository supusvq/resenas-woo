<?php
namespace MRG;

if (!defined('ABSPATH')) {
    exit;
}

class Privacy
{
    public function init()
    {
        add_action('admin_init', [$this, 'add_policy_content']);
    }

    public function add_policy_content()
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content  = '<p>' . esc_html__('Reseñas Woo puede enviar la URL de Google Maps configurada por el administrador a un servicio externo de importación para obtener reseñas públicas y guardarlas localmente en WordPress.', 'mis-resenas-de-google') . '</p>';
        $content .= '<p>' . esc_html__('Ese envío solo se realiza cuando el administrador configura la URL del servicio externo, marca su consentimiento y lanza la importación manualmente.', 'mis-resenas-de-google') . '</p>';
        $content .= '<p>' . esc_html__('Los datos importados se almacenan en la base de datos del sitio para mostrarse en el frontend y no se envían automáticamente a terceros adicionales desde el plugin.', 'mis-resenas-de-google') . '</p>';

        wp_add_privacy_policy_content(
            __('Reseñas Woo', 'mis-resenas-de-google'),
            wp_kses_post($content)
        );
    }
}
