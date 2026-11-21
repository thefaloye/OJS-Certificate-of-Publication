<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes;

use PKP\config\Config;
use RuntimeException;

/**
 * Private storage for certificate PDFs and the signature image.
 *
 * Everything lives under files_dir (which OJS requires to be outside the web
 * root). Stored paths are relative; absolute paths and the directory layout are
 * never shown to users.
 */
class CertificateStorage
{
    public function baseDir(): string
    {
        $filesDir = rtrim((string) Config::getVar('files', 'files_dir'), '/\\');
        if ($filesDir === '') {
            throw new RuntimeException('files_dir is not configured.');
        }
        return $filesDir . '/bijphgr-certificates';
    }

    public function tempDir(): string
    {
        $dir = $this->baseDir() . '/tmp';
        $this->ensureDir($dir);
        return $dir;
    }

    /**
     * @return array{0: string, 1: string} [relativePath, sha256]
     */
    public function writePdf(int $contextId, string $certificateNumber, int $version, string $bytes): array
    {
        $relative = sprintf(
            'pdf/%d/%s-v%d-%s.pdf',
            $contextId,
            preg_replace('/[^A-Za-z0-9_-]/', '', $certificateNumber),
            $version,
            bin2hex(random_bytes(6))
        );
        $this->writeAtomic($relative, $bytes);
        return [$relative, hash('sha256', $bytes)];
    }

    public function writeSignature(int $contextId, string $bytes, string $extension): string
    {
        $relative = sprintf('assets/%d/signature-%s.%s', $contextId, bin2hex(random_bytes(6)), $extension);
        $this->writeAtomic($relative, $bytes);
        return $relative;
    }

    public function exists(?string $relative): bool
    {
        return $relative !== null && $relative !== '' && ($path = $this->resolve($relative)) !== null && is_file($path);
    }

    /**
     * Resolve a stored relative path to an absolute one, refusing anything that
     * would escape the certificate directory.
     */
    public function resolve(string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            return null;
        }
        $base = realpath($this->baseDir());
        $path = realpath($this->baseDir() . '/' . $relative);
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $path;
    }

    public function read(string $relative): string
    {
        $path = $this->resolve($relative);
        if ($path === null) {
            throw new RuntimeException('Stored file is missing.');
        }
        return (string) file_get_contents($path);
    }

    private function writeAtomic(string $relative, string $bytes): void
    {
        $target = $this->baseDir() . '/' . $relative;
        $this->ensureDir(dirname($target));
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.part';
        if (file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write certificate file.');
        }
        chmod($tmp, 0640);
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to store certificate file.');
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create certificate storage directory.');
        }
    }
}
