<?php
namespace MRG\WooCommerce;

use MRG\Emails\EmailScheduler;

if (!defined('ABSPATH')) {
    exit;
}

class OrderHooks
{
    public function init()
    {
        add_action('woocommerce_order_status_completed', [$this, 'handle_completed_order']);
    }

    public function handle_completed_order($order_id)
    {
        if (!$order_id || !wc_get_order($order_id)) {
            return;
        }

        $scheduler = new EmailScheduler();
        $scheduler->schedule_or_send($order_id);
    }
}
