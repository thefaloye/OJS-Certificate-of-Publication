<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes;

/**
 * Certificate reference format: {PREFIX}-CERT-{YYYY}-{NNNNNN}, e.g. BIJPHGR-CERT-2026-000001.
 *
 * The sequence is allocated transactionally by CertificateRepository. Numbers are
 * sequential and therefore guessable; they are NOT secrets. Download and
 * verification links use separate random tokens (see TokenService).
 */
final class CertificateNumber
{
    public const DEFAULT_PREFIX = 'BIJPHGR';

    public static function normalisePrefix(?string $prefix): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix));
        return $clean !== '' ? substr($clean, 0, 12) : self::DEFAULT_PREFIX;
    }

    public static function format(int $year, int $sequence, ?string $prefix = null): string
    {
        return sprintf('%s-CERT-%04d-%06d', self::normalisePrefix($prefix), $year, $sequence);
    }

    public static function isValid(string $number): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{1,12}-CERT-\d{4}-\d{6,}$/', $number);
    }
}
