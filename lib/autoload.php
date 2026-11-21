<?php

/**
 * Minimal PSR-4 autoloader for the libraries bundled with this plugin:
 *  - chillerlan/php-qrcode 5.0.5 (Apache-2.0 OR MIT)
 *  - chillerlan/php-settings-container 3.2.1 (MIT)
 * They are bundled because OJS 3.5 ships mPDF without a QR encoder, and the
 * plugin must work without running Composer on the production server.
 */

spl_autoload_register(static function (string $class): void {
    static $map = [
        'chillerlan\\QRCode\\' => __DIR__ . '/chillerlan/php-qrcode/src/',
        'chillerlan\\Settings\\' => __DIR__ . '/chillerlan/php-settings-container/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
