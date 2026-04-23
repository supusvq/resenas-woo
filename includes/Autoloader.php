<?php
namespace MRG;

if (!defined('ABSPATH')) {
    exit;
}

class Autoloader {
    public static function register() {
        spl_autoload_register([__CLASS__, 'autoload']);
    }

    public static function autoload($class) {
        if (strpos($class, 'MRG\\') !== 0) {
            return;
        }

        $relative = str_replace('MRG\\', '', $class);
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
        $file = MRG_PATH . 'includes/' . $relative . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    }
}
