<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Generates a vector QR code (SVG data URI) for the verification URL.
 * Black modules on white give scanners the best contrast.
 */
final class QrCodeImage
{
    private static bool $booted = false;

    public static function svgDataUri(string $text): string
    {
        if (!self::$booted) {
            require_once dirname(__DIR__) . '/lib/autoload.php';
            self::$booted = true;
        }

        $options = new QROptions([
            'eccLevel' => EccLevel::M,
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => true,
            'imageBase64' => true,
            'drawLightModules' => false,
                        'addQuietzone' => true,
            'quietzoneSize' => 1,
            'svgConnectPaths' => true,
        ]);

        return (new QRCode($options))->render($text);
    }
}
