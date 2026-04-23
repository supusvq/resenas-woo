<?php
namespace MRG;

if (!defined('ABSPATH')) {
    exit;
}

class Helpers
{
    public static function get_settings()
    {
        return get_option('mrg_settings', []);
    }

    public static function write_review_url($place_id = '')
    {
        $settings = self::get_settings();

        if (!empty($settings['review_target_url'])) {
            return esc_url_raw($settings['review_target_url']);
        }

        if (!empty($settings['maps_url'])) {
            return esc_url_raw($settings['maps_url']);
        }

        if (empty($place_id)) {
            $place_id = $settings['place_id'] ?? '';
        }

        if (empty($place_id)) {
            return '';
        }

        return 'https://search.google.com/local/writereview?placeid=' . rawurlencode((string) $place_id);
    }

    public static function render_stars($rating)
    {
        $rating = max(0, min(5, (int) $rating));
        return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
    }
}
