<?php
namespace MRG\Reviews;

if (!defined('ABSPATH')) {
    exit;
}

class ReviewRepository
{
    private $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'mrg_reviews';
    }

    public function upsert(array $review)
    {
        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE review_id = %s", $review['review_id']));

        $data = [
            'place_id' => $review['place_id'],
            'review_id' => $review['review_id'],
            'author_name' => $review['author_name'],
            'author_photo' => $review['author_photo'],
            'rating' => (int) $review['rating'],
            'review_text' => $review['review_text'],
            'review_date' => $review['review_date'],
            'relative_time' => $review['relative_time'],
            'is_anonymous' => (int) $review['is_anonymous'],
            'updated_at' => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($this->table, $data, ['id' => (int) $existing]);
            return false;
        }

        $data['created_at'] = current_time('mysql');
        $data['active'] = 1;
        $wpdb->insert($this->table, $data);
        return true;
    }

    public function add_manual(array $data)
    {
        global $wpdb;

        $review_id = 'manual_' . time() . '_' . wp_generate_password(4, false);

        $insert_data = [
            'place_id' => 'manual',
            'review_id' => $review_id,
            'author_name' => sanitize_text_field($data['author_name']),
            'author_photo' => !empty($data['author_photo']) ? esc_url_raw($data['author_photo']) : '',
            'rating' => min(5, max(1, (int) $data['rating'])),
            'review_text' => sanitize_textarea_field($data['review_text']),
            'review_date' => !empty($data['review_date']) ? sanitize_text_field($data['review_date']) : current_time('mysql'),
            'relative_time' => '',
            'is_anonymous' => 0,
            'active' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        return $wpdb->insert($this->table, $insert_data);
    }

    public function toggle_status($id)
    {
        global $wpdb;
        $id = (int) $id;
        $current = $wpdb->get_var($wpdb->prepare("SELECT active FROM {$this->table} WHERE id = %d", $id));
        $new_status = $current ? 0 : 1;
        return $wpdb->update($this->table, ['active' => $new_status], ['id' => $id]);
    }

    public function delete($id)
    {
        global $wpdb;
        return $wpdb->delete($this->table, ['id' => (int) $id]);
    }

    public function get_reviews($limit = 6, $stars = 'all', $only_active = false, $only_with_text = false)
    {
        global $wpdb;
        $limit = max(1, min(50, absint($limit)));
        $where = '1=1';

        if ($only_active) {
            $where .= ' AND active = 1';
        }

        if ($only_with_text) {
            $where .= " AND TRIM(COALESCE(review_text, '')) <> ''";
        }

        if ($stars === '5') {
            $where .= ' AND rating = 5';
        } elseif ($stars === '4-5') {
            $where .= ' AND rating >= 4';
        } elseif ($stars === '3-5') {
            $where .= ' AND rating >= 3';
        } elseif (in_array($stars, ['1', '2', '3', '4'], true)) {
            $where .= $wpdb->prepare(' AND rating = %d', (int) $stars);
        }

        $results = $wpdb->get_results("SELECT * FROM {$this->table} WHERE {$where} ORDER BY review_date DESC, id DESC LIMIT {$limit}");
        return $results ?: [];
    }

    public function prune_place_reviews($place_id, $keep = 6)
    {
        global $wpdb;

        $place_id = sanitize_text_field((string) $place_id);
        $keep = max(1, min(50, absint($keep)));

        if (empty($place_id)) {
            return 0;
        }

        $ids_to_keep = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$this->table} WHERE place_id = %s ORDER BY review_date DESC, id DESC LIMIT %d",
                $place_id,
                $keep
            )
        );

        if (empty($ids_to_keep)) {
            return 0;
        }

        $ids_to_keep = array_map('absint', $ids_to_keep);
        $place_sql = $wpdb->prepare('%s', $place_id);
        $keep_sql = implode(',', $ids_to_keep);

        return (int) $wpdb->query(
            "DELETE FROM {$this->table} WHERE place_id = {$place_sql} AND id NOT IN ({$keep_sql})"
        );
    }

    public function count_all()
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }

    public function average_rating()
    {
        global $wpdb;
        $avg = $wpdb->get_var("SELECT AVG(rating) FROM {$this->table}");
        return $avg ? (float) $avg : 0;
    }

    public function truncate()
    {
        global $wpdb;
        return $wpdb->query("TRUNCATE TABLE {$this->table}");
    }

    public function get_last_sync_time()
    {
        $settings = get_option('mrg_settings', []);
        return !empty($settings['last_sync_timestamp'])
            ? $settings['last_sync_timestamp']
            : null;
    }
}
