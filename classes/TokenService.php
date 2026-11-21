<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes;

/**
 * Unguessable references. Verification tokens (160-bit) are printed in the QR
 * code and open the public verification page only. Download tokens (256-bit)
 * are e-mailed to the corresponding author; only their SHA-256 hash is stored.
 */
final class TokenService
{
    public static function verifyToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    public static function downloadToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isVerifyToken(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{40}$/', $value);
    }

    public static function isDownloadToken(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $value);
    }
}
