<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes\mail;

use PKP\mail\Mailable;

/**
 * The notification e-mail. Subject and body are rendered (variables substituted
 * and HTML-escaped) by CertificateService before they reach this class, so
 * the standard OJS mail infrastructure (SMTP settings, from address, queueing,
 * logging) does the actual delivery.
 */
class CertificateNotificationMailable extends Mailable
{
    public function __construct(string $subject, string $htmlBody)
    {
        parent::__construct();
        $this->subject($subject);
        $this->body($htmlBody);
    }
}
