<?php
namespace MRG\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Settings
{
    public function init()
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_mrg_update_reviews_manual', [$this, 'ajax_update_reviews_manual']);
    }

    public function enqueue_scripts($hook)
    {
        if (false === strpos((string) $hook, 'mrg-')) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script(
            'mrg-admin',
            MRG_URL . 'assets/js/admin.js',
            ['jquery'],
            MRG_VERSION,
            true
        );

        wp_localize_script(
            'mrg-admin',
            'mrg_admin_vars',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mrg_admin_nonce'),
            ]
        );
    }

    public function register_settings()
    {
        register_setting('mrg_settings_group', 'mrg_settings', [$this, 'sanitize']);

        add_settings_section(
            'mrg_main_section',
            __('Ajustes principales', 'mis-resenas-de-google'),
            '__return_false',
            'mrg-settings'
        );

        add_settings_field('maps_url', __('URL de Google Maps', 'mis-resenas-de-google'), [$this, 'render_maps_url'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('scraper_service_url', __('URL del servicio de importacion', 'mis-resenas-de-google'), [$this, 'render_scraper_service_url'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('remote_sync_consent', __('Consentimiento del servicio externo', 'mis-resenas-de-google'), [$this, 'render_remote_sync_consent'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('theme', __('Tema', 'mis-resenas-de-google'), [$this, 'render_theme'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('default_stars', __('Filtro de estrellas por defecto', 'mis-resenas-de-google'), [$this, 'render_default_stars'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('reviews_limit', __('Numero de reseñas', 'mis-resenas-de-google'), [$this, 'render_reviews_limit'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('slider_mode', __('Modo de slider', 'mis-resenas-de-google'), [$this, 'render_slider_mode'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('slider_speed', __('Velocidad del slider (modo automatico)', 'mis-resenas-de-google'), [$this, 'render_slider_speed'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('google_rating', __('Puntuacion media', 'mis-resenas-de-google'), [$this, 'render_google_rating'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('google_stars_header', __('Estrellas en cabecera', 'mis-resenas-de-google'), [$this, 'render_google_stars_header'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('google_reviews_total', __('Total de reseñas', 'mis-resenas-de-google'), [$this, 'render_google_reviews_total'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('cache_duration', __('Duracion de cache (horas)', 'mis-resenas-de-google'), [$this, 'render_cache_duration'], 'mrg-settings', 'mrg_main_section');

        add_settings_section(
            'mrg_sync_section',
            __('Importacion manual', 'mis-resenas-de-google'),
            [$this, 'render_sync_section'],
            'mrg-settings'
        );

        add_settings_section(
            'mrg_instructions_section',
            __('Instrucciones de uso', 'mis-resenas-de-google'),
            [$this, 'render_instructions_section'],
            'mrg-settings'
        );
    }

    public function sanitize($input)
    {
        $input = is_array($input) ? $input : [];
        $current = get_option('mrg_settings', []);

        $use_raw = function ($key, $fallback = '') use ($input, $current) {
            return array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? $fallback);
        };

        $use_text = function ($key, $fallback = '') use ($use_raw) {
            return sanitize_text_field(wp_unslash((string) $use_raw($key, $fallback)));
        };

        return [
            'place_id' => $use_text('place_id'),
            'maps_url' => esc_url_raw(wp_unslash((string) $use_raw('maps_url'))),
            'scraper_service_url' => esc_url_raw(wp_unslash((string) $use_raw('scraper_service_url'))),
            'remote_sync_consent' => !empty($input['remote_sync_consent']) ? 1 : 0,
            'review_target_url' => esc_url_raw(wp_unslash((string) $use_raw('review_target_url'))),
            'theme' => in_array($use_text('theme', 'dark'), ['dark', 'light'], true) ? $use_text('theme', 'dark') : 'dark',
            'default_stars' => $use_text('default_stars', 'all'),
            'reviews_limit' => 10,
            'slider_mode' => in_array($use_text('slider_mode', 'auto'), ['auto', 'manual'], true) ? $use_text('slider_mode', 'auto') : 'auto',
            'slider_speed' => max(0.1, min(5.0, round((float) $use_raw('slider_speed', 0.6), 1))),
            'last_sync' => $use_text('last_sync'),
            'last_sync_timestamp' => absint($use_raw('last_sync_timestamp', 0)),
            'last_sync_datetime' => $use_text('last_sync_datetime'),
            'cache_duration' => max(1, min(72, absint($use_raw('cache_duration', 24)))),
            'enable_review_requests' => !empty($input['enable_review_requests']) ? 1 : (int) ($current['enable_review_requests'] ?? 0),
            'send_delay_days' => array_key_exists('send_delay_days', $input) ? max(0, min(30, absint($input['send_delay_days']))) : (int) ($current['send_delay_days'] ?? 0),
            'email_subject' => array_key_exists('email_subject', $input) ? sanitize_text_field(wp_unslash((string) $input['email_subject'])) : sanitize_text_field($current['email_subject'] ?? ''),
            'from_name' => array_key_exists('from_name', $input) ? sanitize_text_field(wp_unslash((string) $input['from_name'])) : sanitize_text_field($current['from_name'] ?? ''),
            'from_email' => array_key_exists('from_email', $input) ? sanitize_email(wp_unslash((string) $input['from_email'])) : sanitize_email($current['from_email'] ?? ''),
            'reply_to' => array_key_exists('reply_to', $input) ? sanitize_email(wp_unslash((string) $input['reply_to'])) : sanitize_email($current['reply_to'] ?? ''),
            'email_template' => array_key_exists('email_template', $input) ? wp_kses_post(wp_unslash((string) $input['email_template'])) : ($current['email_template'] ?? ''),
            'footer_privacy_email' => array_key_exists('footer_privacy_email', $input) ? sanitize_email(wp_unslash((string) $input['footer_privacy_email'])) : sanitize_email($current['footer_privacy_email'] ?? ''),
            'footer_privacy_url' => array_key_exists('footer_privacy_url', $input) ? esc_url_raw(wp_unslash((string) $input['footer_privacy_url'])) : esc_url_raw($current['footer_privacy_url'] ?? ''),
            'google_rating' => $use_text('google_rating', '5.0'),
            'google_stars_header' => max(1, min(5, absint($use_raw('google_stars_header', 5)))),
            'google_reviews_total' => absint($use_raw('google_reviews_total', 0)),
        ];
    }

    private function get_settings()
    {
        return get_option('mrg_settings', []);
    }

    public function render_maps_url()
    {
        $settings = $this->get_settings();

        printf(
            '<input type="url" id="mrg_maps_url" name="mrg_settings[maps_url]" value="%s" class="large-text" placeholder="https://www.google.com/maps/place/..." />',
            esc_url($settings['maps_url'] ?? '')
        );

        echo '<p class="description">' . esc_html__('Pega la URL publica de tu ficha de Google Maps. El plugin la enviara al servicio externo solo cuando lances una importacion.', 'mis-resenas-de-google') . '</p>';
    }

    public function render_scraper_service_url()
    {
        $settings = $this->get_settings();

        printf(
            '<input type="url" id="mrg_scraper_service_url" name="mrg_settings[scraper_service_url]" value="%s" class="large-text" placeholder="https://tu-servicio.com" />',
            esc_url($settings['scraper_service_url'] ?? '')
        );

        echo '<p class="description">' . esc_html__('URL base de tu servicio de importacion. El plugin llamara al endpoint /v1/import-reviews.', 'mis-resenas-de-google') . '</p>';
    }

    public function render_remote_sync_consent()
    {
        $settings = $this->get_settings();

        echo '<label>';
        printf(
            '<input type="checkbox" name="mrg_settings[remote_sync_consent]" value="1" %s /> ',
            checked(!empty($settings['remote_sync_consent']), true, false)
        );
        echo esc_html__('Acepto enviar la URL de Google Maps a un servicio externo para importar las reseñas.', 'mis-resenas-de-google');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Este consentimiento es necesario para cumplir con la politica de servicios externos de WordPress.org.', 'mis-resenas-de-google') . '</p>';
    }

    public function render_instructions_section()
    {
        echo '<div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-left:4px solid #4285f4; margin-top:15px; box-shadow:0 1px 1px rgba(0,0,0,0.04);">';
        echo '<h3 style="margin-top:0; color:#4285f4;">' . esc_html__('Como usar el plugin', 'mis-resenas-de-google') . '</h3>';
        echo '<ol style="margin-left:20px; line-height:1.8;">';
        echo '<li>' . esc_html__('Pega la URL de tu ficha de Google Maps.', 'mis-resenas-de-google') . '</li>';
        echo '<li>' . esc_html__('Configura la URL del servicio de importacion.', 'mis-resenas-de-google') . '</li>';
        echo '<li>' . esc_html__('Guarda los cambios y pulsa "Importar reseñas ahora".', 'mis-resenas-de-google') . '</li>';
        echo '<li>' . esc_html__('Muestra las reseñas con el shortcode en cualquier pagina o widget.', 'mis-resenas-de-google') . '</li>';
        echo '</ol>';

        echo '<p style="margin:15px 0 8px 0;"><strong>' . esc_html__('Shortcodes disponibles:', 'mis-resenas-de-google') . '</strong></p>';
        echo '<ul style="line-height:1.8; margin-left:20px;">';
        echo '<li><code>[mis_resenas_google design="horizontal"]</code></li>';
        echo '<li><code>[mis_resenas_google design="vertical"]</code></li>';
        echo '<li><code>[mis_resenas_google design="square"]</code></li>';
        echo '<li><code>[mis_resenas_google design="spotlight"]</code></li>';
        echo '</ul>';

        echo '<p style="margin-top:15px; padding-top:10px; border-top:1px solid #eee; margin-bottom:0;"><strong>' . esc_html__('Atributos utiles:', 'mis-resenas-de-google') . '</strong> <code>theme="light"</code>, <code>stars="5"</code>.</p>';
        echo '</div>';
    }

    public function render_theme()
    {
        $settings = $this->get_settings();
        $value = $settings['theme'] ?? 'dark';

        echo '<select name="mrg_settings[theme]">';
        echo '<option value="dark" ' . selected($value, 'dark', false) . '>' . esc_html__('Oscuro', 'mis-resenas-de-google') . '</option>';
        echo '<option value="light" ' . selected($value, 'light', false) . '>' . esc_html__('Claro', 'mis-resenas-de-google') . '</option>';
        echo '</select>';
    }

    public function render_default_stars()
    {
        $settings = $this->get_settings();
        $value = $settings['default_stars'] ?? 'all';
        $options = [
            'all' => __('Todas', 'mis-resenas-de-google'),
            '5' => __('Solo 5', 'mis-resenas-de-google'),
            '4-5' => __('4 a 5', 'mis-resenas-de-google'),
            '3-5' => __('3 a 5', 'mis-resenas-de-google'),
            '4' => __('Solo 4', 'mis-resenas-de-google'),
        ];

        echo '<select name="mrg_settings[default_stars]">';
        foreach ($options as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function render_reviews_limit()
    {
        echo '<input type="number" min="10" max="10" name="mrg_settings[reviews_limit]" value="10" readonly />';
        echo '<p class="description">' . esc_html__('El plugin muestra y conserva siempre las 10 reseñas más recientes importadas.', 'mis-resenas-de-google') . '</p>';
    }

    public function render_slider_mode()
    {
        $settings = $this->get_settings();
        $mode = $settings['slider_mode'] ?? 'auto';

        echo '<fieldset>';
        echo '<label style="margin-right:15px;"><input type="radio" name="mrg_settings[slider_mode]" value="auto" ' . checked($mode, 'auto', false) . ' /> ' . esc_html__('Automatico', 'mis-resenas-de-google') . '</label>';
        echo '<label><input type="radio" name="mrg_settings[slider_mode]" value="manual" ' . checked($mode, 'manual', false) . ' /> ' . esc_html__('Manual', 'mis-resenas-de-google') . '</label>';
        echo '<p class="description">' . esc_html__('Elige si las tarjetas se desplazan solas o con flechas.', 'mis-resenas-de-google') . '</p>';
        echo '</fieldset>';
    }

    public function render_slider_speed()
    {
        $settings = $this->get_settings();
        $speed = $settings['slider_speed'] ?? 0.6;

        printf(
            '<input type="range" min="0.1" max="5" step="0.1" name="mrg_settings[slider_speed]" value="%s" id="mrg_slider_speed" style="width:200px;vertical-align:middle;" oninput="document.getElementById(\'mrg_speed_val\').textContent=this.value" />
            <strong id="mrg_speed_val" style="margin-left:8px;">%s</strong>
            <p class="description">%s</p>',
            esc_attr($speed),
            esc_html($speed),
            esc_html__('Velocidad del desplazamiento automatico. Mas bajo = mas lento.', 'mis-resenas-de-google')
        );
    }

    public function render_google_rating()
    {
        $settings = $this->get_settings();
        $rating = $settings['google_rating'] ?? '5.0';

        echo '<input type="text" name="mrg_settings[google_rating]" value="' . esc_attr($rating) . '" class="small-text" /> ';
        echo '<span class="description">' . esc_html__('Se actualiza al importar. Puedes corregirlo manualmente si quieres.', 'mis-resenas-de-google') . '</span>';
    }

    public function render_google_stars_header()
    {
        $settings = $this->get_settings();
        $stars = absint($settings['google_stars_header'] ?? 5);

        echo '<select name="mrg_settings[google_stars_header]">';
        for ($i = 5; $i >= 1; $i--) {
            printf('<option value="%d" %s>%d %s</option>', $i, selected($stars, $i, false), $i, esc_html(_n('estrella', 'estrellas', $i, 'mis-resenas-de-google')));
        }
        echo '</select>';
    }

    public function render_google_reviews_total()
    {
        $settings = $this->get_settings();
        $total = absint($settings['google_reviews_total'] ?? 0);

        echo '<input type="number" name="mrg_settings[google_reviews_total]" value="' . esc_attr($total) . '" class="regular-text" style="width:100px;" /> ';
        echo '<span class="description">' . esc_html__('Numero total de reseñas mostrado en la cabecera.', 'mis-resenas-de-google') . '</span>';
    }

    public function render_cache_duration()
    {
        $settings = $this->get_settings();
        $duration = absint($settings['cache_duration'] ?? 24);

        printf(
            '<input type="number" min="1" max="72" name="mrg_settings[cache_duration]" value="%d" style="width:80px;" /> <span class="description">%s</span>',
            $duration,
            esc_html__('Horas (1-72).', 'mis-resenas-de-google')
        );
    }

    public function render_sync_section()
    {
        $settings = $this->get_settings();
        $last_sync = $settings['last_sync_datetime'] ?? '';

        echo '<div style="background:#f9f9f9; padding:15px; border-radius:4px; margin-top:10px;">';

        if (!empty($last_sync)) {
            $days_ago = (time() - (int) ($settings['last_sync_timestamp'] ?? 0)) / DAY_IN_SECONDS;
            echo '<p style="margin:0 0 10px 0;"><strong>' . esc_html__('Ultima importacion:', 'mis-resenas-de-google') . '</strong> <code>' . esc_html($last_sync) . '</code></p>';

            if ($days_ago > 7) {
                echo '<div style="background:#fff3cd; padding:10px; border-left:4px solid #ffc107; margin-bottom:15px; border-radius:3px;">';
                echo '<p style="margin:0; color:#333;">' . esc_html(sprintf(__('Tus reseñas no se actualizan desde hace %.0f dias.', 'mis-resenas-de-google'), $days_ago)) . '</p>';
                echo '</div>';
            }
        } else {
            echo '<p style="margin:0 0 10px 0;"><strong>' . esc_html__('Ultima importacion:', 'mis-resenas-de-google') . '</strong> ' . esc_html__('Nunca sincronizado', 'mis-resenas-de-google') . '</p>';
        }

        echo '<button type="button" id="mrg_btn_update_manual" class="button button-primary" style="margin-bottom:15px;">' . esc_html__('Importar reseñas ahora', 'mis-resenas-de-google') . '</button>';
        echo '<span id="mrg_manual_update_status" style="margin-left:10px;"></span>';
        echo '<p class="description" style="margin-top:12px;">' . esc_html__('La importacion enviara la URL configurada a tu servicio externo y guardara las reseñas localmente en WordPress.', 'mis-resenas-de-google') . '</p>';
        echo '</div>';
    }

    public function ajax_update_reviews_manual()
    {
        check_ajax_referer('mrg_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado.', 'mis-resenas-de-google'));
        }

        $sync_service = new \MRG\Reviews\ReviewSyncService();
        $result = $sync_service->sync();

        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
        }

        wp_send_json_success(
            [
                'message' => sprintf(
                    __('Se han importado %d reseñas correctamente.', 'mis-resenas-de-google'),
                    (int) $result['total_synced']
                ),
                'timestamp' => $result['timestamp'],
                'added' => $result['added'],
                'updated' => $result['updated'] ?? 0,
                'total' => $result['total_synced'],
            ]
        );
    }
}
