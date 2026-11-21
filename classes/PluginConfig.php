<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes;

use APP\plugins\generic\bijphgrPublicationCertificate\BijphgrPublicationCertificatePlugin;

/**
 * Per-journal plugin settings with defaults.
 */
class PluginConfig
{
    public const DEFAULT_SUBJECT = 'Your article is published: Certificate of Publication {$certificateNumber}';

    public const DEFAULT_BODY = <<<'HTML'
<p>Dear {$authorName},</p>
<p>Congratulations! Your article has been published in <strong>{$journalName}</strong>, and a Certificate of Publication has been issued for it.</p>
<p>
<strong>Title:</strong> {$articleTitle}<br />
<strong>Authors:</strong> {$allAuthors}<br />
<strong>Article ID:</strong> {$articleId}<br />
{$issueLine}<strong>Publication date:</strong> {$publicationDate}{$doiLine}<br />
<strong>Certificate number:</strong> {$certificateNumber}
</p>
<p><a href="{$certificateUrl}">Download your Certificate of Publication (PDF)</a></p>
<p>You can read the published article here: <a href="{$articleUrl}">{$articleUrl}</a></p>
<p>Kind regards,<br />The Editorial Office<br />{$journalName}<br /><a href="{$journalUrl}">{$journalUrl}</a></p>
HTML;

    private const DEFAULTS = [
        'certificatePrefix' => CertificateNumber::DEFAULT_PREFIX,
        'signatoryTitle' => 'Editor-in-Chief',
        'downloadLinkDays' => 180,
        'autoRevise' => true,
        'emailSubject' => self::DEFAULT_SUBJECT,
        'emailBody' => self::DEFAULT_BODY,
    ];

    public function __construct(
        private BijphgrPublicationCertificatePlugin $plugin,
        private int $contextId
    ) {
    }

    public function get(string $key): mixed
    {
        $value = $this->plugin->getSetting($this->contextId, $key);
        if ($value === null || $value === '') {
            return self::DEFAULTS[$key] ?? null;
        }
        return $value;
    }

    public function contextId(): int
    {
        return $this->contextId;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->plugin->getSetting($this->contextId, 'enabled');
    }

    /** @return string[] Names of settings that must be filled in before any certificate is issued. */
    public function missingRequired(): array
    {
        $missing = [];
        if (trim((string) $this->get('signatoryName')) === '') {
            $missing[] = 'signatoryName';
        }
        return $missing;
    }
}
