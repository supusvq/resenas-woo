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
        add_action('wp_ajax_mrg_register_site', [$this, 'ajax_register_site']);
        add_action('wp_ajax_mrg_start_google_oauth', [$this, 'ajax_start_google_oauth']);
        add_action('wp_ajax_mrg_load_google_locations', [$this, 'ajax_load_google_locations']);
        add_action('wp_ajax_mrg_save_google_location', [$this, 'ajax_save_google_location']);
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
        add_settings_field('slider_mode', __('Modo de slider', 'mis-resenas-de-google'), [$this, 'render_slider_mode'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('google_stars_header', __('Estrellas mostradas en cabecera', 'mis-resenas-de-google'), [$this, 'render_google_stars_header'], 'mrg-settings', 'mrg_main_section');
        add_settings_field('google_reviews_total', __('Total mostrado en cabecera', 'mis-resenas-de-google'), [$this, 'render_google_reviews_total'], 'mrg-settings', 'mrg_main_section');

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

        $settings = [
            'place_id' => $use_text('place_id'),
            'maps_url' => esc_url_raw(wp_unslash((string) $use_raw('maps_url'))),
            'scraper_service_url' => esc_url_raw(wp_unslash((string) $use_raw('scraper_service_url'))),
            'service_site_token' => $use_text('service_site_token'),
            'google_account_id' => $use_text('google_account_id'),
            'google_location_id' => $use_text('google_location_id'),
            'google_place_name' => $use_text('google_place_name'),
            'remote_sync_consent' => !empty($input['remote_sync_consent']) ? 1 : 0,
            'review_target_url' => esc_url_raw(wp_unslash((string) $use_raw('review_target_url'))),
            'theme' => in_array($use_text('theme', 'light'), ['dark', 'light'], true) ? $use_text('theme', 'light') : 'light',
            'default_stars' => in_array($use_text('default_stars', 'all'), ['all', '5', '4-5', '3-5', '4'], true) ? $use_text('default_stars', 'all') : 'all',
            'reviews_limit' => 6,
            'slider_mode' => in_array($use_text('slider_mode', 'auto'), ['auto', 'manual'], true) ? $use_text('slider_mode', 'auto') : 'auto',
            'slider_speed' => 0.6,
            'last_sync' => $use_text('last_sync'),
            'last_sync_timestamp' => absint($use_raw('last_sync_timestamp', 0)),
            'last_sync_datetime' => $use_text('last_sync_datetime'),
            'cache_duration' => 1,
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

        $this->clear_review_transients();

        return $settings;
    }

    private function get_settings()
    {
        return get_option('mrg_settings', []);
    }

    private function clear_review_transients()
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '%_transient_mrg_reviews_cache_%',
                '%_transient_timeout_mrg_reviews_cache_%'
            )
        );
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
        $value = $settings['scraper_service_url'] ?? 'https://scraper.supufactory.es';

        if (empty($value)) {
            $value = 'https://scraper.supufactory.es';
        }

        printf(
            '<input type="url" id="mrg_scraper_service_url" name="mrg_settings[scraper_service_url]" value="%s" class="large-text" placeholder="https://tu-servicio.com" />',
            esc_url($value)
        );

        echo '<p class="description">' . esc_html__('URL base de tu servicio de importacion. El plugin llamara al endpoint /v1/import-reviews. Por defecto queda preparada para scraper.supufactory.es.', 'mis-resenas-de-google') . '</p>';
    }

    public function render_service_site_token()
    {
        $settings = $this->get_settings();

        printf(
            '<input type="text" id="mrg_service_site_token" name="mrg_settings[service_site_token]" value="%s" class="large-text" autocomplete="off" />',
            esc_attr($settings['service_site_token'] ?? '')
        );

        echo '<p class="description">' . esc_html__('Identifica esta web frente al servicio Supu. No es una clave de Google; se genera al registrar el sitio en la plataforma.', 'mis-resenas-de-google') . '</p>';
        echo '<p>';
        echo '<button type="button" id="mrg_btn_register_site" class="button">' . esc_html__('Registrar este sitio', 'mis-resenas-de-google') . '</button> ';
        echo '<button type="button" id="mrg_btn_connect_google" class="button button-secondary">' . esc_html__('Conectar Google', 'mis-resenas-de-google') . '</button> ';
        echo '<span id="mrg_site_token_status"></span>';
        echo '</p>';
    }

    public function render_google_location()
    {
        $settings = $this->get_settings();
        $place_name = sanitize_text_field($settings['google_place_name'] ?? '');
        $location_id = sanitize_text_field($settings['google_location_id'] ?? '');

        if (!empty($place_name)) {
            echo '<p><strong>' . esc_html($place_name) . '</strong></p>';
        } elseif (!empty($location_id)) {
            echo '<p><code>' . esc_html($location_id) . '</code></p>';
        } else {
            echo '<p>' . esc_html__('Todavia no hay ficha seleccionada.', 'mis-resenas-de-google') . '</p>';
        }

        printf(
            '<input type="hidden" id="mrg_google_account_id" name="mrg_settings[google_account_id]" value="%s" />',
            esc_attr($settings['google_account_id'] ?? '')
        );
        printf(
            '<input type="hidden" id="mrg_google_location_id" name="mrg_settings[google_location_id]" value="%s" />',
            esc_attr($settings['google_location_id'] ?? '')
        );
        printf(
            '<input type="hidden" id="mrg_google_place_name" name="mrg_settings[google_place_name]" value="%s" />',
            esc_attr($place_name)
        );

        echo '<select id="mrg_google_locations_select" style="min-width:320px;display:none;"></select> ';
        echo '<button type="button" id="mrg_btn_load_locations" class="button">' . esc_html__('Cargar fichas de Google', 'mis-resenas-de-google') . '</button> ';
        echo '<button type="button" id="mrg_btn_save_location" class="button button-secondary" style="display:none;">' . esc_html__('Guardar ficha', 'mis-resenas-de-google') . '</button> ';
        echo '<span id="mrg_google_location_status"></span>';
        echo '<p class="description">' . esc_html__('Despues de conectar Google, carga tus fichas y selecciona la que usara este WordPress.', 'mis-resenas-de-google') . '</p>';
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
        $value = $settings['theme'] ?? 'light';

        echo '<select name="mrg_settings[theme]">';
        echo '<option value="light" ' . selected($value, 'light', false) . '>' . esc_html__('Claro', 'mis-resenas-de-google') . '</option>';
        echo '<option value="dark" ' . selected($value, 'dark', false) . '>' . esc_html__('Oscuro', 'mis-resenas-de-google') . '</option>';
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
        echo '<p class="description">' . esc_html__('Este filtro solo cambia las reseñas que se muestran en la web.', 'mis-resenas-de-google') . '</p>';
    }

    public function render_slider_mode()
    {
        $settings = $this->get_settings();
        $mode = $settings['slider_mode'] ?? 'auto';

        echo '<fieldset>';
        echo '<label style="margin-right:15px;"><input type="radio" name="mrg_settings[slider_mode]" value="auto" ' . checked($mode, 'auto', false) . ' /> ' . esc_html__('Automatico', 'mis-resenas-de-google') . '</label>';
        echo '<label><input type="radio" name="mrg_settings[slider_mode]" value="manual" ' . checked($mode, 'manual', false) . ' /> ' . esc_html__('Manual', 'mis-resenas-de-google') . '</label>';
        echo '<p class="description">' . esc_html__('Solo cambia la navegacion visual del slider: automatica o con flechas.', 'mis-resenas-de-google') . '</p>';
        echo '</fieldset>';
    }

    public function render_google_stars_header()
    {
        $settings = $this->get_settings();
        $stars = max(1, min(5, absint($settings['google_stars_header'] ?? 5)));

        echo '<select name="mrg_settings[google_stars_header]">';
        for ($i = 5; $i >= 1; $i--) {
            printf(
                '<option value="%d" %s>%d %s</option>',
                $i,
                selected($stars, $i, false),
                $i,
                esc_html(_n('estrella', 'estrellas', $i, 'mis-resenas-de-google'))
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Controla las estrellas grandes que aparecen junto a "Excelente".', 'mis-resenas-de-google') . '</p>';
    }

    public function render_google_reviews_total()
    {
        $settings = $this->get_settings();
        $total = absint($settings['google_reviews_total'] ?? 0);

        printf(
            '<input type="number" min="0" step="1" name="mrg_settings[google_reviews_total]" value="%d" class="small-text" />',
            $total
        );
        echo '<p class="description">' . esc_html__('Controla el numero del texto "A base de X reseñas". Si lo dejas a 0, se usara el total local.', 'mis-resenas-de-google') . '</p>';
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
        echo '<p class="description" style="margin-top:12px;">' . esc_html__('La importacion guardara localmente las 6 reseñas mas recientes para mostrarlas en WordPress.', 'mis-resenas-de-google') . '</p>';
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

    public function ajax_register_site()
    {
        check_ajax_referer('mrg_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado.', 'mis-resenas-de-google'));
        }

        $settings = $this->get_settings();
        $service_url = untrailingslashit(esc_url_raw($settings['scraper_service_url'] ?? 'https://scraper.supufactory.es'));

        if (empty($service_url)) {
            wp_send_json_error(__('Configura primero la URL del servicio.', 'mis-resenas-de-google'));
        }

        $response = wp_safe_remote_post(
            $service_url . '/v1/sites/register',
            [
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode(['site_url' => home_url('/')]),
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['site_token'])) {
            wp_send_json_error(__('El servicio no devolvio token del sitio.', 'mis-resenas-de-google'));
        }

        $settings['service_site_token'] = sanitize_text_field((string) $data['site_token']);
        update_option('mrg_settings', $settings);

        wp_send_json_success(
            [
                'site_token' => $settings['service_site_token'],
                'message' => __('Sitio registrado correctamente.', 'mis-resenas-de-google'),
            ]
        );
    }

    public function ajax_start_google_oauth()
    {
        check_ajax_referer('mrg_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado.', 'mis-resenas-de-google'));
        }

        $settings = $this->get_settings();
        $service_url = untrailingslashit(esc_url_raw($settings['scraper_service_url'] ?? 'https://scraper.supufactory.es'));
        $site_token = sanitize_text_field($settings['service_site_token'] ?? '');

        if (empty($service_url) || empty($site_token)) {
            wp_send_json_error(__('Registra primero este sitio para obtener el token.', 'mis-resenas-de-google'));
        }

        $endpoint = add_query_arg(
            [
                'site_url' => home_url('/'),
                'site_token' => $site_token,
            ],
            $service_url . '/v1/google/oauth/start'
        );

        $response = wp_safe_remote_get(
            $endpoint,
            [
                'timeout' => 30,
                'headers' => ['Accept' => 'application/json'],
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['authorization_url'])) {
            wp_send_json_error(__('El servicio no devolvio URL de conexion con Google.', 'mis-resenas-de-google'));
        }

        wp_send_json_success(['authorization_url' => esc_url_raw((string) $data['authorization_url'])]);
    }

    public function ajax_load_google_locations()
    {
        check_ajax_referer('mrg_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado.', 'mis-resenas-de-google'));
        }

        $settings = $this->get_settings();
        $service_url = untrailingslashit(esc_url_raw($settings['scraper_service_url'] ?? 'https://scraper.supufactory.es'));
        $site_token = sanitize_text_field($settings['service_site_token'] ?? '');

        if (empty($service_url) || empty($site_token)) {
            wp_send_json_error(__('Registra y conecta primero este sitio.', 'mis-resenas-de-google'));
        }

        $endpoint = add_query_arg(
            [
                'site_url' => home_url('/'),
                'site_token' => $site_token,
            ],
            $service_url . '/v1/google/locations'
        );

        $response = wp_safe_remote_get(
            $endpoint,
            [
                'timeout' => 60,
                'headers' => ['Accept' => 'application/json'],
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['locations']) || !is_array($data['locations'])) {
            wp_send_json_error(__('No se encontraron fichas conectadas.', 'mis-resenas-de-google'));
        }

        wp_send_json_success(['locations' => $data['locations']]);
    }

    public function ajax_save_google_location()
    {
        check_ajax_referer('mrg_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No autorizado.', 'mis-resenas-de-google'));
        }

        $settings = $this->get_settings();
        $service_url = untrailingslashit(esc_url_raw($settings['scraper_service_url'] ?? 'https://scraper.supufactory.es'));
        $site_token = sanitize_text_field($settings['service_site_token'] ?? '');
        $account_id = sanitize_text_field(wp_unslash((string) ($_POST['account_id'] ?? '')));
        $location_id = sanitize_text_field(wp_unslash((string) ($_POST['location_id'] ?? '')));
        $place_name = sanitize_text_field(wp_unslash((string) ($_POST['place_name'] ?? '')));

        if (empty($service_url) || empty($site_token) || empty($account_id) || empty($location_id)) {
            wp_send_json_error(__('Faltan datos para guardar la ficha.', 'mis-resenas-de-google'));
        }

        $response = wp_safe_remote_post(
            $service_url . '/v1/google/location',
            [
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode(
                    [
                        'site_url' => home_url('/'),
                        'site_token' => $site_token,
                        'account_id' => $account_id,
                        'location_id' => $location_id,
                        'place_name' => $place_name,
                    ]
                ),
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['success'])) {
            wp_send_json_error(__('No se pudo guardar la ficha en el servicio.', 'mis-resenas-de-google'));
        }

        $settings['google_account_id'] = $account_id;
        $settings['google_location_id'] = $location_id;
        $settings['google_place_name'] = $place_name;
        update_option('mrg_settings', $settings);

        wp_send_json_success(
            [
                'message' => __('Ficha guardada correctamente.', 'mis-resenas-de-google'),
                'place_name' => $place_name,
            ]
        );
    }
}
