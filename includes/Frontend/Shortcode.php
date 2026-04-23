<?php
namespace MRG\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

class Shortcode
{
    public function init()
    {
        add_shortcode('mis_resenas_google', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_assets()
    {
        $css_ver = file_exists(MRG_PATH . 'assets/css/frontend.css')
            ? filemtime(MRG_PATH . 'assets/css/frontend.css')
            : MRG_VERSION;
        $js_ver = file_exists(MRG_PATH . 'assets/js/frontend.js')
            ? filemtime(MRG_PATH . 'assets/js/frontend.js')
            : MRG_VERSION;

        wp_register_style('mrg-frontend', MRG_URL . 'assets/css/frontend.css', [], $css_ver);
        wp_register_script('mrg-frontend', MRG_URL . 'assets/js/frontend.js', [], $js_ver, true);
    }

    public function render($atts = [])
    {
        $atts = shortcode_atts([
            'theme' => '',
            'stars' => '',
            'limit' => '',
            'design' => 'horizontal',
        ], $atts, 'mis_resenas_google');

        // Excluir página del caché de LiteSpeed
        if (defined('LSCACHE_ENABLED') && LSCACHE_ENABLED) {
            if (function_exists('do_action')) {
                do_action('litespeed_control_set_nocache');
            }
        }

        // Headers HTTP para prevenir caché en navegadores
        if (!headers_sent()) {
            header('Cache-Control: no-cache, no-store, must-revalidate', true);
            header('Pragma: no-cache', true);
            header('Expires: 0', true);
        }

        wp_enqueue_style('mrg-frontend');
        wp_enqueue_script('mrg-frontend');

        $renderer = new Renderer();
        return $renderer->render($atts);
    }
}
