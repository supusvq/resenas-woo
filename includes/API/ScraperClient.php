<?php
namespace MRG\API;

if (!defined('ABSPATH')) {
    exit;
}

class ScraperClient
{
    private $base_url;
    private $site_token;

    public function __construct($base_url = '', $site_token = '')
    {
        $this->base_url = untrailingslashit((string) $base_url);
        $this->site_token = sanitize_text_field((string) $site_token);
    }

    public function import_reviews($maps_url, $max_reviews = 200)
    {
        if (empty($this->base_url)) {
            return new \WP_Error('missing_service_url', __('Configura primero la URL del servicio de importacion.', 'mis-resenas-de-google'));
        }

        $endpoint = $this->base_url . '/v1/import-reviews';
        $payload = [
            'maps_url' => esc_url_raw($maps_url),
            'max_reviews' => max(1, absint($max_reviews)),
            'language' => substr(determine_locale(), 0, 2),
            'site_url' => home_url('/'),
        ];

        if (!empty($this->site_token)) {
            $payload['site_token'] = $this->site_token;
        }

        $response = wp_safe_remote_post(
            $endpoint,
            [
                'timeout' => 240,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            $body_excerpt = wp_strip_all_tags(substr((string) $body, 0, 200));
            $message = __('El servicio ha devuelto una respuesta no valida.', 'mis-resenas-de-google');

            if (!empty($body_excerpt)) {
                $message .= ' ' . sprintf(__('Respuesta: %s', 'mis-resenas-de-google'), $body_excerpt);
            }

            return new \WP_Error('invalid_service_response', $message);
        }

        if ($code < 200 || $code >= 300 || (isset($data['success']) && false === $data['success'])) {
            $message = '';

            if (!empty($data['detail'])) {
                $message = is_array($data['detail'])
                    ? wp_json_encode($data['detail'])
                    : (string) $data['detail'];
            } elseif (!empty($data['message'])) {
                $message = (string) $data['message'];
            } elseif (!empty($data['error'])) {
                $message = (string) $data['error'];
            }

            if (empty($message)) {
                $message = __('No se pudo completar la importacion remota de resenas.', 'mis-resenas-de-google');
            }

            return new \WP_Error('remote_import_failed', $message);
        }

        if (empty($data['reviews']) || !is_array($data['reviews'])) {
            return new \WP_Error('empty_reviews', __('El servicio no ha devuelto resenas para esta URL.', 'mis-resenas-de-google'));
        }

        return [
            'reviews' => $data['reviews'],
            'rating' => isset($data['rating']) ? (float) $data['rating'] : 0,
            'user_ratings_total' => isset($data['user_ratings_total']) ? absint($data['user_ratings_total']) : count($data['reviews']),
            'place_id' => !empty($data['place_id']) ? sanitize_text_field((string) $data['place_id']) : '',
            'review_target_url' => $this->get_review_target_url($data, $maps_url),
        ];
    }

    private function get_review_target_url(array $data, $maps_url)
    {
        $candidate_keys = [
            'write_review_url',
            'review_target_url',
            'resolved_url',
            'maps_url',
        ];

        foreach ($candidate_keys as $key) {
            if (!empty($data[$key])) {
                return esc_url_raw((string) $data[$key]);
            }
        }

        return esc_url_raw($maps_url);
    }
}
