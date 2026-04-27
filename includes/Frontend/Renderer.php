<?php
namespace MRG\Frontend;

use MRG\Helpers;
use MRG\Reviews\ReviewRepository;

if (!defined('ABSPATH')) {
    exit;
}

class Renderer
{
    public function render($atts = [])
    {
        $settings = get_option('mrg_settings', []);
        $repo = new ReviewRepository();
        $theme = !empty($atts['theme']) ? sanitize_text_field($atts['theme']) : ($settings['theme'] ?? 'light');
        $theme = in_array($theme, ['dark', 'light'], true) ? $theme : 'light';
        $limit = 6;
        $stars = !empty($atts['stars']) ? sanitize_text_field($atts['stars']) : ($settings['default_stars'] ?? 'all');
        $stars = in_array($stars, ['all', '5', '4-5', '3-5', '4'], true) ? $stars : 'all';
        $only_with_text = !empty($settings['only_text_reviews']);
        $design = !empty($atts['design']) ? sanitize_text_field($atts['design']) : 'horizontal';

        $transient_key = 'mrg_reviews_cache_' . md5(json_encode(['limit' => $limit, 'stars' => $stars, 'text' => $only_with_text]));
        $reviews = get_transient($transient_key);

        if (false === $reviews) {
            $reviews = $repo->get_reviews($limit, $stars, true, $only_with_text);
            $cache_duration = 1;
            set_transient($transient_key, $reviews, $cache_duration * HOUR_IN_SECONDS);
            error_log("[MRG] Reviews cached for $cache_duration hours");
        } else {
            error_log('[MRG] Reviews loaded from transient');
        }

        $avg = $repo->average_rating();
        $count = !empty($settings['google_reviews_total']) ? absint($settings['google_reviews_total']) : $repo->count_all();
        $write_url = Helpers::write_review_url($settings['place_id'] ?? '');
        $speed = 0.6;
        $slider_mode = in_array(($settings['slider_mode'] ?? 'auto'), ['auto', 'manual'], true) ? $settings['slider_mode'] : 'auto';
        $header_stars = max(1, min(5, absint($settings['google_stars_header'] ?? 5)));
        $is_spotlight = ('spotlight' === $design);

        ob_start();
        ?>
        <div class="mrg-reviews-widget mrg-theme-<?php echo esc_attr($theme); ?> mrg-design-<?php echo esc_attr($design); ?>"
            id="mrg-widget-<?php echo esc_attr(uniqid()); ?>" data-speed="<?php echo esc_attr($speed); ?>"
            data-mode="<?php echo esc_attr($slider_mode); ?>" data-design="<?php echo esc_attr($design); ?>">
            <?php if ($is_spotlight): ?>
                <div class="mrg-spotlight-shell">
                    <aside class="mrg-spotlight-summary">
                        <span class="mrg-spotlight-kicker"><?php echo esc_html__('EXCELENTE', 'mis-resenas-de-google'); ?></span>
                        <div class="mrg-spotlight-stars" aria-hidden="true">
                            <?php echo esc_html(Helpers::render_stars($header_stars)); ?>
                        </div>
                        <p class="mrg-spotlight-count">
                            <?php
                            echo wp_kses_post(
                                sprintf(
                                    __('A base de <strong>%d reseñas</strong>', 'mis-resenas-de-google'),
                                    $count
                                )
                            );
                            ?>
                        </p>
                        <a href="<?php echo esc_url($write_url); ?>" class="mrg-spotlight-brand-link" target="_blank"
                            rel="noopener noreferrer" aria-label="<?php echo esc_attr__('Escribir una reseña en Google', 'mis-resenas-de-google'); ?>">
                            <img src="https://www.google.com/images/branding/googlelogo/2x/googlelogo_color_92x30dp.png"
                                class="mrg-google-logo-main mrg-spotlight-logo" alt="Google">
                        </a>
                    </aside>

                    <?php $this->render_carousel($reviews, $slider_mode, true); ?>
                </div>
            <?php else: ?>
                <div class="mrg-header">
                    <div class="mrg-header-left">
                        <img src="https://www.google.com/images/branding/googlelogo/2x/googlelogo_color_92x30dp.png"
                            class="mrg-google-logo-main" alt="Google">
                        <div class="mrg-overall-info">
                            <span class="mrg-excellent-text"><?php echo esc_html__('Excelente', 'mis-resenas-de-google'); ?></span>
                            <div class="mrg-stars-header" aria-hidden="true">
                                <?php echo esc_html(Helpers::render_stars($header_stars)); ?>
                            </div>
                            <span class="mrg-stats-text">
                                <?php echo esc_html(number_format((float) $avg, 1)); ?> | <?php echo esc_html($count); ?>
                                <?php echo esc_html__('reseñas', 'mis-resenas-de-google'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="mrg-header-right">
                        <a href="<?php echo esc_url($write_url); ?>" class="mrg-btn-write" target="_blank"
                            rel="noopener noreferrer"><?php echo esc_html__('Escribe una reseña', 'mis-resenas-de-google'); ?></a>
                    </div>
                </div>

                <?php $this->render_carousel($reviews, $slider_mode, false); ?>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    private function render_carousel($reviews, $slider_mode, $is_spotlight)
    {
        ?>
        <div class="mrg-carousel-container<?php echo $is_spotlight ? ' mrg-carousel-container-spotlight' : ''; ?>">
            <?php if ('manual' === $slider_mode && !empty($reviews)): ?>
                <button class="mrg-nav-btn mrg-nav-prev" aria-label="<?php echo esc_attr__('Anterior', 'mis-resenas-de-google'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                        <path d="M15.41 16.59 10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z" />
                    </svg>
                </button>
            <?php endif; ?>

            <div class="mrg-carousel-wrapper <?php echo 'manual' === $slider_mode ? 'mrg-manual-mode' : ''; ?>">
                <div class="mrg-reviews-track">
                    <?php if (!empty($reviews)): ?>
                        <?php foreach ($reviews as $review): ?>
                            <?php if ($is_spotlight): ?>
                                <article class="mrg-review-card mrg-review-card-spotlight">
                                    <div class="mrg-spotlight-card-head">
                                        <div class="mrg-spotlight-card-author">
                                            <?php $this->render_avatar($review); ?>
                                            <div class="mrg-spotlight-card-meta">
                                                <span class="mrg-name"><?php echo esc_html($review->author_name); ?></span>
                                                <span class="mrg-date"><?php echo esc_html($review->relative_time ?: $review->review_date); ?></span>
                                            </div>
                                        </div>
                                        <?php $this->render_google_icon(); ?>
                                    </div>

                                    <div class="mrg-card-rating mrg-card-rating-spotlight">
                                        <?php echo esc_html(Helpers::render_stars($review->rating)); ?>
                                        <?php if ($review->rating >= 4): ?>
                                            <span class="mrg-verify-badge" title="<?php echo esc_attr__('Reseña verificada', 'mis-resenas-de-google'); ?>"
                                                aria-label="<?php echo esc_attr__('Verificada', 'mis-resenas-de-google'); ?>">✓</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mrg-review-content mrg-review-content-spotlight">
                                        <?php echo esc_html($review->review_text); ?>
                                    </div>

                                    <?php if (mb_strlen($review->review_text) > 120): ?>
                                        <button class="mrg-read-more mrg-read-more-spotlight"><?php echo esc_html__('Leer más', 'mis-resenas-de-google'); ?></button>
                                    <?php endif; ?>
                                </article>
                            <?php else: ?>
                                <article class="mrg-review-card">
                                    <div class="mrg-card-left">
                                        <?php $this->render_avatar($review); ?>
                                        <div class="mrg-author-info">
                                            <span class="mrg-name"><?php echo esc_html($review->author_name); ?></span>
                                            <span class="mrg-date"><?php echo esc_html($review->relative_time ?: $review->review_date); ?></span>
                                        </div>
                                        <?php $this->render_google_icon(); ?>
                                    </div>

                                    <div class="mrg-card-body">
                                        <div class="mrg-card-rating">
                                            <?php echo esc_html(Helpers::render_stars($review->rating)); ?>
                                            <?php if ($review->rating >= 4): ?>
                                                <span class="mrg-verify-badge" title="<?php echo esc_attr__('Reseña verificada', 'mis-resenas-de-google'); ?>"
                                                    aria-label="<?php echo esc_attr__('Verificada', 'mis-resenas-de-google'); ?>">✓</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mrg-review-content">
                                            <?php echo esc_html($review->review_text); ?>
                                        </div>
                                        <?php if (mb_strlen($review->review_text) > 120): ?>
                                            <button class="mrg-read-more"><?php echo esc_html__('Leer más', 'mis-resenas-de-google'); ?></button>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="mrg-no-reviews"><?php echo esc_html__('No hay reseñas almacenadas todavía en este lugar.', 'mis-resenas-de-google'); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ('manual' === $slider_mode && !empty($reviews)): ?>
                <button class="mrg-nav-btn mrg-nav-next" aria-label="<?php echo esc_attr__('Siguiente', 'mis-resenas-de-google'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                        <path d="M8.59 16.59 13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z" />
                    </svg>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_avatar($review)
    {
        if (!empty($review->author_photo)) {
            ?>
            <img src="<?php echo esc_url($review->author_photo); ?>" class="mrg-author-photo" alt="">
            <?php
            return;
        }
        ?>
        <div class="mrg-author-photo mrg-author-photo-fallback">
            <?php echo esc_html(mb_substr($review->author_name, 0, 1)); ?>
        </div>
        <?php
    }

    private function render_google_icon()
    {
        ?>
        <svg class="mrg-google-icon-small" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path
                d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                fill="#4285F4" />
            <path
                d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-1 .67-2.28 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                fill="#34A853" />
            <path
                d="M5.84 14.09c-.22-.67-.35-1.39-.35-2.09s.13-1.42.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"
                fill="#FBBC05" />
            <path
                d="M12 5.38c1.62 0 3.06.56 4.21 1.66l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                fill="#EA4335" />
        </svg>
        <?php
    }
}
