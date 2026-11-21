<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes\jobs;

use APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateService;
use PKP\jobs\BaseJob;

/**
 * Generates the certificate (if needed) and sends the notification e-mail.
 *
 * Idempotent by design: the record is created by the event listener, the PDF is
 * generated at most once per version under a row lock, and the e-mail is sent
 * only by the worker that wins CertificateRepository::claimEmail(). Retrying or
 * dispatching the job twice therefore cannot produce duplicates.
 */
class ProcessCertificateJob extends BaseJob
{
    public const MODE_ISSUE = 'issue';
    public const MODE_REVISE = 'revise';

    public function __construct(
        private int $contextId,
        private int $publicationId,
        private string $mode = self::MODE_ISSUE,
        private ?string $reason = null,
        private ?int $userId = null,
        private bool $force = false
    ) {
        parent::__construct();
        $this->tries = 5;
        $this->maxExceptions = 5;
        $this->backoff = 120;
        $this->timeout = 180;
    }

    public function handle(): void
    {
        $service = CertificateService::forContext($this->contextId);

        if ($this->mode === self::MODE_REVISE) {
            $service->revise($this->contextId, $this->publicationId, $this->reason ?? 'Metadata correction', $this->userId, $this->force ?? false);
            return;
        }

        $service->process($this->contextId, $this->publicationId);
    }

    public function failed(\Throwable $exception): void
    {
        CertificateService::forContext($this->contextId)->recordJobFailure($this->contextId, $this->publicationId, $exception);
    }
}
