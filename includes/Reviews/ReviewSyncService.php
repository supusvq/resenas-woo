<?php
namespace MRG\Reviews;

use MRG\API\ScraperClient;

if (!defined('ABSPATH')) {
    exit;
}

class ReviewSyncService
{
    private const REMOTE_REVIEW_LIMIT = 6;

    public function sync()
    {
        $settings = get_option('mrg_settings', []);
        $maps_url = esc_url_raw($settings['maps_url'] ?? '');
        $service_url = esc_url_raw($settings['scraper_service_url'] ?? '');
        $has_consent = !empty($settings['remote_sync_consent']);

        if (empty($maps_url)) {
            return [
                'added' => 0,
                'error' => __('Pega primero la URL de Google Maps de tu negocio.', 'mis-resenas-de-google'),
            ];
        }

        if (empty($service_url)) {
            return [
                'added' => 0,
                'error' => __('Configura primero la URL del servicio de importacion.', 'mis-resenas-de-google'),
            ];
        }

        if (!$has_consent) {
            return [
                'added' => 0,
                'error' => __('Debes aceptar el uso del servicio externo para importar resenas.', 'mis-resenas-de-google'),
            ];
        }

        $client = new ScraperClient($service_url);
        $result = $client->import_reviews($maps_url, self::REMOTE_REVIEW_LIMIT);

        if (is_wp_error($result)) {
            return [
                'added' => 0,
                'error' => sprintf(__('Error del servicio remoto: %s', 'mis-resenas-de-google'), $result->get_error_message()),
            ];
        }

        $reviews = $result['reviews'] ?? [];
        if (empty($reviews)) {
            return [
                'added' => 0,
                'error' => __('El servicio no ha devuelto resenas para esta URL.', 'mis-resenas-de-google'),
            ];
        }

        $repo = new ReviewRepository();
        $internal_place_id = !empty($result['place_id']) ? $result['place_id'] : $this->build_internal_place_id($maps_url);
        $added = 0;
        $updated = 0;

        foreach ($reviews as $review) {
            $formatted_review = $this->format_remote_review((array) $review, $internal_place_id);

            if ($repo->upsert($formatted_review)) {
                $added++;
            } else {
                $updated++;
            }
        }

        $repo->prune_place_reviews($internal_place_id, self::REMOTE_REVIEW_LIMIT);

        $current_timestamp = time();
        $current_datetime = current_time('Y-m-d H:i:s');
        $rating = isset($result['rating']) ? (float) $result['rating'] : 0;
        $total_reviews = isset($result['user_ratings_total']) ? absint($result['user_ratings_total']) : count($reviews);

        $settings['maps_url'] = $maps_url;
        $settings['place_id'] = $internal_place_id;
        $settings['reviews_limit'] = self::REMOTE_REVIEW_LIMIT;
        $settings['review_target_url'] = esc_url_raw($result['review_target_url'] ?? $maps_url);
        $settings['google_rating'] = $rating > 0 ? (string) $rating : ($settings['google_rating'] ?? '5.0');
        $settings['google_stars_header'] = max(1, min(5, (int) round($rating > 0 ? $rating : 5)));
        $settings['google_reviews_total'] = $total_reviews;
        $settings['last_sync'] = $current_datetime;
        $settings['last_sync_timestamp'] = $current_timestamp;
        $settings['last_sync_datetime'] = $current_datetime;

        update_option('mrg_settings', $settings);
        $this->clear_review_transients();

        return [
            'added' => $added,
            'updated' => $updated,
            'total_synced' => count($reviews),
            'timestamp' => $current_datetime,
        ];
    }

    public function has_throttled_recently()
    {
        return false;
    }

    private function format_remote_review(array $review, $place_id)
    {
        $author_name = '';

        if (!empty($review['author_name'])) {
            $author_name = sanitize_text_field((string) $review['author_name']);
        } elseif (!empty($review['author'])) {
            $author_name = sanitize_text_field((string) $review['author']);
        } else {
            $author_name = __('Cliente', 'mis-resenas-de-google');
        }

        $review_id = !empty($review['review_id']) ? sanitize_text_field((string) $review['review_id']) : md5(wp_json_encode($review));
        $review_text = '';

        if (!empty($review['review_text'])) {
            $review_text = sanitize_textarea_field((string) $review['review_text']);
        } elseif (!empty($review['text'])) {
            $review_text = sanitize_textarea_field((string) $review['text']);
        }

        $relative_time = '';

        if (!empty($review['relative_time'])) {
            $relative_time = sanitize_text_field((string) $review['relative_time']);
        } elseif (!empty($review['published_relative'])) {
            $relative_time = sanitize_text_field((string) $review['published_relative']);
        }

        return [
            'place_id' => $place_id,
            'review_id' => $review_id,
            'author_name' => $author_name,
            'author_photo' => !empty($review['author_photo']) ? esc_url_raw((string) $review['author_photo']) : '',
            'rating' => $this->normalize_rating($review['rating'] ?? 0),
            'review_text' => $review_text,
            'review_date' => $this->normalize_review_date($review['review_date'] ?? ''),
            'relative_time' => $relative_time,
            'is_anonymous' => empty($review['author_name']) && empty($review['author']) ? 1 : 0,
        ];
    }

    private function normalize_rating($rating)
    {
        $normalized = (int) round((float) $rating);
        return max(1, min(5, $normalized));
    }

    private function normalize_review_date($review_date)
    {
        if (empty($review_date)) {
            return current_time('mysql');
        }

        $timestamp = strtotime((string) $review_date);
        if (false === $timestamp) {
            return current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function build_internal_place_id($maps_url)
    {
        return 'remote_' . md5(strtolower(trim((string) $maps_url)));
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
}
