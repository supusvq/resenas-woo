<?php
namespace MRG\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Menu
{
    public function init()
    {
        add_action('admin_menu', [$this, 'register_menu']);
    }

    public function register_menu()
    {
        add_menu_page(
            __('Reseñas Woo', 'mis-resenas-de-google'),
            __('Reseñas Woo', 'mis-resenas-de-google'),
            'manage_options',
            'mrg-settings',
            [$this, 'render_settings_page'],
            'dashicons-star-filled',
            56
        );

        add_submenu_page('mrg-settings', __('Configuración general', 'mis-resenas-de-google'), __('Configuración general', 'mis-resenas-de-google'), 'manage_options', 'mrg-settings', [$this, 'render_settings_page']);
        add_submenu_page('mrg-settings', __('Invitaciones', 'mis-resenas-de-google'), __('Invitaciones', 'mis-resenas-de-google'), 'manage_options', 'mrg-invitations', [$this, 'render_invitations_page']);
        add_submenu_page('mrg-settings', __('Reseñas', 'mis-resenas-de-google'), __('Reseñas', 'mis-resenas-de-google'), 'manage_options', 'mrg-reviews', [$this, 'render_reviews_page']);
        add_submenu_page('mrg-settings', __('Emails', 'mis-resenas-de-google'), __('Emails', 'mis-resenas-de-google'), 'manage_options', 'mrg-emails', [$this, 'render_emails_page']);
        add_submenu_page('mrg-settings', __('Historial', 'mis-resenas-de-google'), __('Historial', 'mis-resenas-de-google'), 'manage_options', 'mrg-history', [$this, 'render_history_page']);
    }

    public function render_invitations_page()
    {
        do_action('mrg_render_invitations_page');
    }

    public function render_settings_page()
    {
        echo '<div class="wrap"><h1>' . esc_html__('Configuración general', 'mis-resenas-de-google') . '</h1><form method="post" action="options.php">';
        settings_fields('mrg_settings_group');
        do_settings_sections('mrg-settings');
        submit_button();
        echo '</form></div>';
    }

    public function render_reviews_page()
    {
        do_action('mrg_render_reviews_page');
    }

    public function render_emails_page()
    {
        do_action('mrg_render_emails_page');
    }

    public function render_history_page()
    {
        do_action('mrg_render_logs_page');
    }
}
