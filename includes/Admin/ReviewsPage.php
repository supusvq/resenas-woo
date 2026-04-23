<?php
namespace MRG\Admin;

use MRG\Reviews\ReviewRepository;
use MRG\Reviews\ReviewStats;
use MRG\Reviews\ReviewSyncService;

if (!defined('ABSPATH')) {
    exit;
}

class ReviewsPage
{
    public function init()
    {
        add_action('mrg_render_reviews_page', [$this, 'render']);
        add_action('admin_post_mrg_sync_reviews', [$this, 'handle_sync']);
        add_action('admin_post_mrg_add_manual_review', [$this, 'handle_add_manual']);
        add_action('admin_post_mrg_delete_review', [$this, 'handle_delete']);
        add_action('admin_post_mrg_toggle_review', [$this, 'handle_toggle']);
    }

    public function handle_sync()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('No autorizado', 'mis-resenas-de-google'));
        }

        check_admin_referer('mrg_sync_reviews');

        $service = new ReviewSyncService();
        $result = $service->sync();

        if (isset($result['error'])) {
            set_transient('mrg_reviews_sync_notice_' . get_current_user_id(), $result['error'], 30);
            wp_safe_redirect(add_query_arg(['page' => 'mrg-reviews', 'mrg_updated' => 'sync_error'], admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'mrg-reviews',
                    'mrg_updated' => 'synced',
                    'added' => (int) $result['added'],
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function handle_add_manual()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('No autorizado', 'mis-resenas-de-google'));
        }

        check_admin_referer('mrg_add_manual_review');

        $repo = new ReviewRepository();
        $repo->add_manual($_POST);

        wp_safe_redirect(add_query_arg(['page' => 'mrg-reviews', 'mrg_updated' => 'added'], admin_url('admin.php')));
        exit;
    }

    public function handle_delete()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('No autorizado', 'mis-resenas-de-google'));
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('mrg_delete_review_' . $id);

        $repo = new ReviewRepository();
        $repo->delete($id);

        wp_safe_redirect(add_query_arg(['page' => 'mrg-reviews', 'mrg_updated' => 'deleted'], admin_url('admin.php')));
        exit;
    }

    public function handle_toggle()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('No autorizado', 'mis-resenas-de-google'));
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('mrg_toggle_review_' . $id);

        $repo = new ReviewRepository();
        $repo->toggle_status($id);

        wp_safe_redirect(add_query_arg(['page' => 'mrg-reviews', 'mrg_updated' => 'toggled'], admin_url('admin.php')));
        exit;
    }

    public function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option('mrg_settings', []);
        $repo = new ReviewRepository();
        $stats = new ReviewStats($repo);
        $reviews = $repo->get_reviews(100);

        echo '<div class="wrap mrg-admin-reviews">';
        echo '<h1>' . esc_html__('Gestion de reseñas', 'mis-resenas-de-google') . '</h1>';

        echo '<div class="notice notice-info inline" style="margin:20px 0; border-left-color:#72aee6; background:#fff; padding:15px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Gestion de reseñas', 'mis-resenas-de-google') . '</h3>';
        echo '<p>' . esc_html__('Desde aqui puedes importar, revisar y ordenar las reseñas que se muestran en tu web.', 'mis-resenas-de-google') . '</p>';
        echo '<ul style="list-style:disc; margin-left:20px;">';
        echo '<li><strong>' . esc_html__('Importacion:', 'mis-resenas-de-google') . '</strong> ' . esc_html__('Trae reseñas desde la URL de Google Maps configurada en los ajustes.', 'mis-resenas-de-google') . '</li>';
        echo '<li><strong>' . esc_html__('Manuales:', 'mis-resenas-de-google') . '</strong> ' . esc_html__('Puedes añadir reseñas propias para combinarlas con las importadas.', 'mis-resenas-de-google') . '</li>';
        echo '<li><strong>' . esc_html__('Visibilidad:', 'mis-resenas-de-google') . '</strong> ' . esc_html__('Haz clic en el estado de cada reseña para activarla u ocultarla.', 'mis-resenas-de-google') . '</li>';
        echo '</ul>';
        echo '</div>';

        $this->render_notice();

        echo '<div class="mrg-admin-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">';

        echo '<div class="card" style="max-width:100%; margin:0; padding:15px;">';
        echo '<h2>' . esc_html__('Importacion desde Google Maps', 'mis-resenas-de-google') . '</h2>';
        echo '<p><strong>' . esc_html__('Ultima:', 'mis-resenas-de-google') . '</strong> ' . esc_html($settings['last_sync'] ?? __('Nunca', 'mis-resenas-de-google')) . '</p>';
        echo '<p><strong>' . esc_html__('Total:', 'mis-resenas-de-google') . '</strong> ' . esc_html((string) $stats->count_all()) . ' | <strong>' . esc_html__('Media:', 'mis-resenas-de-google') . '</strong> ' . esc_html(number_format((float) $stats->average_rating(), 1)) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mrg_sync_reviews');
        echo '<input type="hidden" name="action" value="mrg_sync_reviews" />';
        submit_button(__('Importar reseñas ahora', 'mis-resenas-de-google'), 'primary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="card" style="max-width:100%; margin:0; padding:15px;">';
        echo '<h2>' . esc_html__('Añadir reseña manual', 'mis-resenas-de-google') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mrg_add_manual_review');
        echo '<input type="hidden" name="action" value="mrg_add_manual_review" />';
        echo '<table class="form-table" style="margin:0;">';
        echo '<tr><td style="padding:5px 0;"><input type="text" name="author_name" placeholder="' . esc_attr__('Nombre del autor', 'mis-resenas-de-google') . '" required style="width:100%;" /></td></tr>';
        echo '<tr><td style="padding:5px 0;">
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="hidden" name="author_photo" id="mrg_author_photo" value="" />
                    <div id="mrg_avatar_preview_container" style="width:40px; height:40px; border-radius:50%; background:#eee; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                        <img id="mrg_avatar_preview" src="" style="width:100%; height:100%; object-fit:cover; display:none;" />
                        <span id="mrg_avatar_placeholder" style="color:#aaa;">&#128100;</span>
                    </div>
                    <button type="button" id="mrg_btn_upload_avatar" class="button">' . esc_html__('Subir o elegir avatar', 'mis-resenas-de-google') . '</button>
                    <button type="button" id="mrg_btn_remove_avatar" class="button-link" style="display:none; color:#d63638; text-decoration:none;">' . esc_html__('Quitar', 'mis-resenas-de-google') . '</button>
                </div>
              </td></tr>';
        echo '<tr><td style="padding:5px 0;"><select name="rating" style="width:100%;"><option value="5">★★★★★ (5)</option><option value="4">★★★★ (4)</option><option value="3">★★★ (3)</option><option value="2">★★ (2)</option><option value="1">★ (1)</option></select></td></tr>';
        echo '<tr><td style="padding:5px 0;"><textarea name="review_text" placeholder="' . esc_attr__('Texto de la reseña', 'mis-resenas-de-google') . '" required style="width:100%; height:60px;"></textarea></td></tr>';
        echo '</table>';
        submit_button(__('Añadir reseña', 'mis-resenas-de-google'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '</div>';

        echo '<hr />';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Visible', 'mis-resenas-de-google') . '</th><th>' . esc_html__('Autor', 'mis-resenas-de-google') . '</th><th>' . esc_html__('Estrellas', 'mis-resenas-de-google') . '</th><th>' . esc_html__('Fecha', 'mis-resenas-de-google') . '</th><th>' . esc_html__('Texto', 'mis-resenas-de-google') . '</th><th style="text-align:right;">' . esc_html__('Acciones', 'mis-resenas-de-google') . '</th></tr></thead><tbody>';

        if ($reviews) {
            foreach ($reviews as $review) {
                $status_text = $review->active ? __('Encendida', 'mis-resenas-de-google') : __('Standby', 'mis-resenas-de-google');
                $status_class = $review->active ? 'color:#1e8e3e; font-weight:bold;' : 'color:#d93025;';
                $toggle_url = wp_nonce_url(admin_url('admin-post.php?action=mrg_toggle_review&id=' . $review->id), 'mrg_toggle_review_' . $review->id);
                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=mrg_delete_review&id=' . $review->id), 'mrg_delete_review_' . $review->id);

                echo '<tr>';
                echo '<td><a href="' . esc_url($toggle_url) . '" style="' . esc_attr($status_class . ' text-decoration:none;') . '" title="' . esc_attr__('Cambiar estado', 'mis-resenas-de-google') . '">' . esc_html($status_text) . '</a></td>';
                echo '<td style="display:flex; align-items:center; gap:10px;">';

                if (!empty($review->author_photo)) {
                    echo '<img src="' . esc_url($review->author_photo) . '" style="width:30px; height:30px; border-radius:50%; object-fit:cover;" alt="" />';
                } else {
                    echo '<div style="width:30px; height:30px; border-radius:50%; background:#eee; display:flex; align-items:center; justify-content:center; color:#aaa;">&#128100;</div>';
                }

                echo esc_html($review->author_name) . '</td>';
                echo '<td>' . esc_html(str_repeat('★', (int) $review->rating)) . '</td>';
                echo '<td>' . esc_html(substr((string) $review->review_date, 0, 10)) . '</td>';
                echo '<td>' . esc_html(wp_trim_words((string) $review->review_text, 15)) . '</td>';
                echo '<td style="text-align:right;">';
                echo '<a href="' . esc_url($delete_url) . '" class="button button-link-delete" onclick="return confirm(\'' . esc_js(__('¿Seguro que quieres borrar esta reseña?', 'mis-resenas-de-google')) . '\')">' . esc_html__('Borrar', 'mis-resenas-de-google') . '</a>';
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6">' . esc_html__('No hay reseñas almacenadas.', 'mis-resenas-de-google') . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_notice()
    {
        if (!isset($_GET['mrg_updated'])) {
            return;
        }

        $msg = '';
        $type = 'success';

        switch (sanitize_text_field(wp_unslash($_GET['mrg_updated']))) {
            case 'synced':
                $added = isset($_GET['added']) ? absint($_GET['added']) : 0;
                $msg = sprintf(__('Importacion completada. Nuevas: %d', 'mis-resenas-de-google'), $added);
                break;
            case 'sync_error':
                $msg = get_transient('mrg_reviews_sync_notice_' . get_current_user_id());
                delete_transient('mrg_reviews_sync_notice_' . get_current_user_id());
                $msg = $msg ? $msg : __('No se pudo completar la importacion.', 'mis-resenas-de-google');
                $type = 'error';
                break;
            case 'added':
                $msg = __('Reseña manual añadida correctamente.', 'mis-resenas-de-google');
                break;
            case 'deleted':
                $msg = __('Reseña eliminada.', 'mis-resenas-de-google');
                break;
            case 'toggled':
                $msg = __('Estado de visibilidad actualizado.', 'mis-resenas-de-google');
                break;
        }

        if (!empty($msg)) {
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
    }
}
