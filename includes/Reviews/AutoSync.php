<?php
/**
 * Importación automática semanal de reseñas de Google (lunes a las 9:00, hora de la web).
 *
 * Si falla (servicio ocupado, límite, red...), reintenta a las 2 h, 6 h y 25 h del
 * primer fallo (cada retraso cuenta desde el reintento anterior): el último cae ya
 * fuera de la ventana de 24 h de los límites diarios del servicio.
 *
 * Si falla porque no hay licencia válida NO se programan reintentos: no
 * arreglarían nada y solo llenarían el cron. El evento semanal se mantiene para
 * que vuelva a funcionar en cuanto se active la licencia.
 */

namespace MRG\Reviews;

if (!defined('ABSPATH')) {
    exit;
}

class AutoSync
{
    const WEEKLY_HOOK = 'mrg_weekly_sync';
    const RETRY_HOOK = 'mrg_weekly_sync_retry';
    const MAX_RETRIES = 3;

    // Horas de espera antes de cada reintento (2 h, +4 h = 6 h, +19 h = 25 h).
    const DELAYS = [2, 4, 19];

    public static function hooks()
    {
        add_action(self::WEEKLY_HOOK, [__CLASS__, 'weekly']);
        add_action(self::RETRY_HOOK, [__CLASS__, 'run']);
        self::ensure_scheduled();
    }

    public static function ensure_scheduled()
    {
        if (!wp_next_scheduled(self::WEEKLY_HOOK)) {
            $first = new \DateTimeImmutable('next monday 09:00', wp_timezone());
            wp_schedule_event($first->getTimestamp(), 'weekly', self::WEEKLY_HOOK);
        }
    }

    /**
     * Quita la importación semanal y sus reintentos pendientes.
     */
    public static function unschedule()
    {
        wp_clear_scheduled_hook(self::WEEKLY_HOOK);
        wp_unschedule_hook(self::RETRY_HOOK);
    }

    public static function weekly()
    {
        self::run(0);
    }

    /**
     * Ejecuta una importación y, si falla por un motivo recuperable, programa el reintento.
     *
     * @param int $attempt 0 = ejecución semanal; 1..3 = reintentos.
     * @return array Resultado de ReviewSyncService::sync().
     */
    public static function run($attempt = 0)
    {
        $attempt = (int) $attempt;
        $result = (new ReviewSyncService())->sync();

        update_option('mrg_auto_sync_last', ['time' => time(), 'attempt' => $attempt, 'result' => $result], false);

        if (!empty($result['license_required'])) {
            return $result;
        }

        if (isset($result['error']) && $attempt < self::MAX_RETRIES && !wp_next_scheduled(self::RETRY_HOOK, [$attempt + 1])) {
            wp_schedule_single_event(time() + self::DELAYS[$attempt] * HOUR_IN_SECONDS, self::RETRY_HOOK, [$attempt + 1]);
        }

        return $result;
    }
}
