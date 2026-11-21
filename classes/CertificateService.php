<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\plugins\generic\bijphgrPublicationCertificate\BijphgrPublicationCertificatePlugin;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\jobs\ProcessCertificateJob;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\mail\CertificateNotificationMailable;
use APP\publication\Publication;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PKP\context\Context;
use PKP\core\Core;
use PKP\plugins\PluginRegistry;
use RuntimeException;
use Throwable;

/**
 * Orchestrates certificate issuing: records, PDF generation, revisions,
 * notification e-mail and status changes. Every state change is audited.
 */
class CertificateService
{
    public const PLUGIN_NAME = 'bijphgrPublicationCertificatePlugin';

    private CertificateRepository $repo;
    private CertificateStorage $storage;

    public function __construct(
        private BijphgrPublicationCertificatePlugin $plugin,
        ?CertificateRepository $repo = null,
        ?CertificateStorage $storage = null
    ) {
        $this->repo = $repo ?? new CertificateRepository();
        $this->storage = $storage ?? new CertificateStorage();
    }

    /** Resolve the service from anywhere (event listener, queue worker, page handler). */
    public static function forContext(int $contextId): self
    {
        $plugin = PluginRegistry::getPlugin('generic', self::PLUGIN_NAME)
            ?? PluginRegistry::loadPlugin('generic', 'bijphgrPublicationCertificate');
        if (!$plugin instanceof BijphgrPublicationCertificatePlugin) {
            throw new RuntimeException('BIJPHGR Publication Certificate plugin is not available.');
        }
        return new self($plugin);
    }

    public function repository(): CertificateRepository
    {
        return $this->repo;
    }

    public function config(int $contextId): PluginConfig
    {
        return new PluginConfig($this->plugin, $contextId);
    }

    // ------------------------------------------------------------------
    // Triggers (called from the event listener)
    // ------------------------------------------------------------------

    /**
     * A publication has just been published. Re-checks eligibility against the
     * freshly stored record (the event also fires for merely "scheduled"
     * publications), creates the certificate record once, and queues the work.
     */
    public function handlePublished(int $contextId, int $submissionId, int $publicationId): void
    {
        $config = $this->config($contextId);
        if (!$config->isEnabled()) {
            return;
        }

        [$context, $submission, $publication, $issue] = $this->load($contextId, $submissionId, $publicationId);
        if (PublicationEligibility::reason($submission, $publication, $issue) !== null) {
            return;
        }

        $year = (int) date('Y', strtotime((string) $publication->getData('datePublished')) ?: time());
        [$record, $created] = $this->repo->createOrGet(
            $contextId,
            $submissionId,
            $publicationId,
            $year,
            (string) $config->get('certificatePrefix')
        );

        if ($created) {
            $this->repo->audit((int) $record->id, $contextId, 'queued', ['reason' => 'publication_published']);
            $this->dispatchProcess($contextId, $publicationId);
            return;
        }

        if ($record->certificate_status === CertificateRepository::STATUS_WITHDRAWN) {
            // Same publication published again after an unpublish: restore, don't re-send.
            $data = (new CertificateDataMapper($config))->map($submission, $publication, $issue, $context, $record);
            $fingerprint = (new CertificateDataMapper($config))->fingerprint($data, $publication);
            $status = $fingerprint === $record->metadata_hash
                ? CertificateRepository::STATUS_VALID
                : CertificateRepository::STATUS_NEEDS_REVISION;
            $this->repo->update((int) $record->id, ['certificate_status' => $status]);
            $this->repo->audit((int) $record->id, $contextId, 'republished', ['status' => $status]);
            if ($status === CertificateRepository::STATUS_NEEDS_REVISION && $config->get('autoRevise')) {
                $this->dispatchRevise($contextId, $publicationId, 'Metadata changed while unpublished');
            }
            return;
        }

        if ($record->file_path === null || $record->email_status === CertificateRepository::EMAIL_PENDING) {
            $this->dispatchProcess($contextId, $publicationId);
        }
    }

    public function handleUnpublished(int $contextId, int $publicationId): void
    {
        $record = $this->repo->findByPublication($contextId, $publicationId);
        if (!$record || $record->certificate_status === CertificateRepository::STATUS_WITHDRAWN) {
            return;
        }
        $this->repo->update((int) $record->id, ['certificate_status' => CertificateRepository::STATUS_WITHDRAWN]);
        $this->repo->audit((int) $record->id, $contextId, 'withdrawn', ['reason' => 'publication_unpublished']);
    }

    /**
     * A published publication's metadata may have changed. If the printed
     * details no longer match, flag the certificate and (optionally) queue an
     * audited revision. The issued PDF is never overwritten.
     */
    public function checkForMetadataChange(int $contextId, int $publicationId): bool
    {
        $record = $this->repo->findByPublication($contextId, $publicationId);
        if (!$record || $record->file_path === null || $record->certificate_status !== CertificateRepository::STATUS_VALID) {
            return false;
        }

        $config = $this->config($contextId);
        [$context, $submission, $publication, $issue] = $this->load($contextId, (int) $record->submission_id, $publicationId);
        if (PublicationEligibility::reason($submission, $publication, $issue) !== null) {
            return false;
        }

        $mapper = new CertificateDataMapper($config);
        $fingerprint = $mapper->fingerprint($mapper->map($submission, $publication, $issue, $context, $record), $publication);
        if ($fingerprint === $record->metadata_hash) {
            return false;
        }

        $this->repo->update((int) $record->id, ['certificate_status' => CertificateRepository::STATUS_NEEDS_REVISION]);
        $this->repo->audit((int) $record->id, $contextId, 'metadata_changed', ['version' => (int) $record->certificate_version]);
        if ($config->get('autoRevise')) {
            $this->dispatchRevise($contextId, $publicationId, 'Metadata correction');
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Queue work
    // ------------------------------------------------------------------

    public function dispatchProcess(int $contextId, int $publicationId): void
    {
        dispatch(new ProcessCertificateJob($contextId, $publicationId));
    }

    public function dispatchRevise(int $contextId, int $publicationId, string $reason, ?int $userId = null, bool $force = false): void
    {
        dispatch(new ProcessCertificateJob($contextId, $publicationId, ProcessCertificateJob::MODE_REVISE, $reason, $userId, $force));
    }

    /** Re-queue records that never finished (e.g. held because the signatory was not yet configured). */
    public function dispatchUnprocessed(int $contextId): int
    {
        $count = 0;
        foreach ($this->repo->findUnprocessed($contextId) as $record) {
            if ($record->certificate_status === CertificateRepository::STATUS_WITHDRAWN) {
                continue;
            }
            $this->dispatchProcess($contextId, (int) $record->publication_id);
            $count++;
        }
        return $count;
    }

    /** Issue the certificate if needed, then send the notification. Safe to run repeatedly. */
    public function process(int $contextId, int $publicationId): void
    {
        $record = $this->repo->findByPublication($contextId, $publicationId);
        if (!$record) {
            return;
        }
        $config = $this->config($contextId);
        if (!$config->isEnabled()) {
            return;
        }

        [$context, $submission, $publication, $issue] = $this->load($contextId, (int) $record->submission_id, $publicationId);
        $reason = PublicationEligibility::reason($submission, $publication, $issue);
        if ($reason !== null) {
            // Unpublished or still scheduled by the time the job ran: nothing is issued or sent.
            $this->repo->audit((int) $record->id, $contextId, 'skipped_ineligible', ['reason' => $reason]);
            return;
        }

        $missing = $this->missingConfiguration($config, $context);
        if ($missing) {
            $this->repo->update((int) $record->id, ['last_error' => 'Configuration incomplete: ' . implode(', ', $missing)]);
            $this->repo->audit((int) $record->id, $contextId, 'held_configuration', ['missing' => $missing]);
            return;
        }

        if ($record->file_path === null || !$this->storage->exists($record->file_path)) {
            $record = DB::transaction(function () use ($record, $submission, $publication, $issue, $context, $config) {
                $locked = DB::table(CertificateRepository::T_CERT)->where('id', $record->id)->lockForUpdate()->first();
                if ($locked->file_path !== null && $this->storage->exists($locked->file_path)) {
                    return $locked; // another worker generated it while we waited for the lock
                }
                $this->generate($locked, $submission, $publication, $issue, $context, $config, 'Initial issue', null);
                return $this->repo->find((int) $locked->id);
            });
        }

        $this->sendEmail((int) $record->id);
    }

    /** Create a new certificate version. The previous PDF is kept; nothing is overwritten. */
    public function revise(int $contextId, int $publicationId, string $reason, ?int $userId, bool $force = false): void
    {
        $record = $this->repo->findByPublication($contextId, $publicationId);
        if (!$record || $record->file_path === null || $record->certificate_status === CertificateRepository::STATUS_WITHDRAWN) {
            return;
        }
        $config = $this->config($contextId);
        [$context, $submission, $publication, $issue] = $this->load($contextId, (int) $record->submission_id, $publicationId);
        if (PublicationEligibility::reason($submission, $publication, $issue) !== null || $this->missingConfiguration($config, $context)) {
            return;
        }

        DB::transaction(function () use ($record, $submission, $publication, $issue, $context, $config, $reason, $userId, $force) {
            $locked = DB::table(CertificateRepository::T_CERT)->where('id', $record->id)->lockForUpdate()->first();
            $mapper = new CertificateDataMapper($config);
            $nextData = $mapper->map($submission, $publication, $issue, $context, $locked);
            if (!$force && $mapper->fingerprint($nextData, $publication) === $locked->metadata_hash && $locked->certificate_status === CertificateRepository::STATUS_VALID) {
                return; // nothing changed since the current version
            }
            $this->generate($locked, $submission, $publication, $issue, $context, $config, $reason, $userId, (int) $locked->certificate_version + 1);
        });
    }

    public const OUTCOME_QUEUED = 'queued';
    public const OUTCOME_NOT_FOUND = 'notFound';
    public const OUTCOME_WITHDRAWN = 'withdrawn';
    public const OUTCOME_IN_PROGRESS = 'inProgress';
    public const OUTCOME_NO_RECIPIENT = 'noRecipient';
    public const OUTCOME_NOT_CONFIGURED = 'notConfigured';
    public const OUTCOME_NOT_PUBLISHED = 'notPublished';
    public const OUTCOME_NO_FILE = 'noFile';

    /**
     * Re-send the notification (new download link, previous link stops working).
     *
     * @return string one of the OUTCOME_* constants; only OUTCOME_QUEUED changed anything
     */
    public function resend(int $certificateId, ?int $userId = null): string
    {
        $record = $this->repo->find($certificateId);
        if (!$record) {
            return self::OUTCOME_NOT_FOUND;
        }
        if ($record->certificate_status === CertificateRepository::STATUS_WITHDRAWN) {
            return self::OUTCOME_WITHDRAWN;
        }
        $contextId = (int) $record->context_id;

        // Another worker is sending right now: re-queuing would allow a second e-mail.
        $stale = date('Y-m-d H:i:s', time() - CertificateRepository::CLAIM_STALE_MINUTES * 60);
        if ($record->email_status === CertificateRepository::EMAIL_QUEUED && $record->email_claimed_at && $record->email_claimed_at >= $stale) {
            return self::OUTCOME_IN_PROGRESS;
        }

        try {
            [$context, $submission, $publication, $issue] = $this->load($contextId, (int) $record->submission_id, (int) $record->publication_id);
        } catch (Throwable) {
            return self::OUTCOME_NOT_FOUND;
        }
        if (!$submission || !$publication || PublicationEligibility::reason($submission, $publication, $issue) !== null) {
            return self::OUTCOME_NOT_PUBLISHED;
        }
        if ($this->missingConfiguration($this->config($contextId), $context)) {
            return self::OUTCOME_NOT_CONFIGURED;
        }
        $recipient = (new CertificateDataMapper($this->config($contextId)))->correspondingAuthor($publication);
        if (!filter_var($recipient['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return self::OUTCOME_NO_RECIPIENT;
        }

        $this->repo->update($certificateId, ['email_status' => CertificateRepository::EMAIL_PENDING, 'last_error' => null]);
        $this->repo->audit($certificateId, $contextId, 'resend_requested', [], $userId);
        $this->dispatchProcess($contextId, (int) $record->publication_id);
        return self::OUTCOME_QUEUED;
    }

    /**
     * Editor-requested new certificate version from the article's current details
     * (for example after adding the signature). Earlier versions are kept; no e-mail.
     *
     * @return string one of the OUTCOME_* constants
     */
    public function regenerate(int $certificateId, ?int $userId = null): string
    {
        $record = $this->repo->find($certificateId);
        if (!$record) {
            return self::OUTCOME_NOT_FOUND;
        }
        if ($record->certificate_status === CertificateRepository::STATUS_WITHDRAWN) {
            return self::OUTCOME_WITHDRAWN;
        }
        if ($record->file_path === null) {
            return self::OUTCOME_NO_FILE;
        }
        $contextId = (int) $record->context_id;
        try {
            [$context, $submission, $publication, $issue] = $this->load($contextId, (int) $record->submission_id, (int) $record->publication_id);
        } catch (Throwable) {
            return self::OUTCOME_NOT_FOUND;
        }
        if (!$submission || !$publication || PublicationEligibility::reason($submission, $publication, $issue) !== null) {
            return self::OUTCOME_NOT_PUBLISHED;
        }
        if ($this->missingConfiguration($this->config($contextId), $context)) {
            return self::OUTCOME_NOT_CONFIGURED;
        }
        $this->repo->audit($certificateId, $contextId, 'regenerate_requested', [], $userId);
        $this->dispatchRevise($contextId, (int) $record->publication_id, 'Regenerated by editor', $userId, true);
        return self::OUTCOME_QUEUED;
    }

    /**
     * Re-send every failed notification (bulk "retry").
     *
     * @return array{queued: int, skipped: int}
     */
    public function retryFailedEmails(int $contextId, ?int $userId = null): array
    {
        $queued = $skipped = 0;
        foreach ($this->repo->findFailedEmails($contextId) as $record) {
            $this->resend((int) $record->id, $userId) === self::OUTCOME_QUEUED ? $queued++ : $skipped++;
        }
        return ['queued' => $queued, 'skipped' => $skipped];
    }

    // ------------------------------------------------------------------
    // PDF generation
    // ------------------------------------------------------------------

    /**
     * Render and store a certificate version for $record (row must be locked by the caller).
     */
    private function generate(
        object $record,
        Submission $submission,
        Publication $publication,
        ?Issue $issue,
        Context $context,
        PluginConfig $config,
        string $reason,
        ?int $userId,
        int $version = 1
    ): void {
        $mapper = new CertificateDataMapper($config);
        $record->certificate_version = $version;
        $data = $mapper->map($submission, $publication, $issue, $context, $record);

        $renderer = new CertificateRenderer($this->storage);
        $bytes = $renderer->render($data);
        [$path, $sha] = $this->storage->writePdf((int) $record->context_id, (string) $record->certificate_number, $version, $bytes);
        $hash = $mapper->fingerprint($data, $publication);

        $this->repo->addRevision((int) $record->id, $version, $path, $sha, $hash, $reason, $userId);
        $this->repo->update((int) $record->id, [
            'certificate_version' => $version,
            'file_path' => $path,
            'file_sha256' => $sha,
            'metadata_hash' => $hash,
            'certificate_status' => CertificateRepository::STATUS_VALID,
            'generated_at' => Core::getCurrentDate(),
            'last_error' => null,
        ]);
        $this->repo->audit((int) $record->id, (int) $record->context_id, $version === 1 ? 'generated' : 'revised', [
            'version' => $version,
            'reason' => $reason,
            'sha256' => $sha,
        ], $userId);
    }

    // ------------------------------------------------------------------
    // E-mail
    // ------------------------------------------------------------------

    /**
     * @throws Throwable on delivery failure (so the queue retries); the failure is recorded first.
     */
    public function sendEmail(int $certificateId): void
    {
        $record = $this->repo->find($certificateId);
        if (!$record || $record->email_status === CertificateRepository::EMAIL_SENT) {
            return;
        }
        if (!$this->repo->claimEmail($certificateId)) {
            return; // someone else is sending, or it was already sent
        }

        $contextId = (int) $record->context_id;
        $attempts = (int) $record->email_attempts + 1;

        try {
            $config = $this->config($contextId);
            [$context, $submission, $publication, $issue] = $this->load($contextId, (int) $record->submission_id, (int) $record->publication_id);
            if (PublicationEligibility::reason($submission, $publication, $issue) !== null) {
                $this->repo->update($certificateId, ['email_status' => CertificateRepository::EMAIL_PENDING]);
                return;
            }

            $mapper = new CertificateDataMapper($config);
            $data = $mapper->map($submission, $publication, $issue, $context, $record);

            $recipient = $mapper->correspondingAuthor($publication);
            if (!$recipient || !filter_var($recipient['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $this->repo->update($certificateId, [
                    'email_status' => CertificateRepository::EMAIL_FAILED,
                    'email_attempts' => $attempts,
                    'last_error' => 'No corresponding author with a valid e-mail address is designated for this publication.',
                ]);
                $this->repo->audit($certificateId, $contextId, 'email_failed', ['reason' => 'no_corresponding_author']);
                return; // retrying cannot help; fix the publication and use "resend"
            }

            $fromEmail = trim((string) ($context->getData('contactEmail') ?: $context->getData('supportEmail')));
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('The journal has no valid contact e-mail address to send from.');
            }
            $fromName = trim((string) ($context->getData('contactName') ?: $context->getLocalizedName()));

            $days = max(1, (int) $config->get('downloadLinkDays'));
            $token = TokenService::downloadToken();
            $this->repo->update($certificateId, [
                'download_token_hash' => TokenService::hash($token),
                'download_token_expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
                'recipient_email' => $recipient['email'],
            ]);

            $vars = $this->emailVariables($data, $recipient['name'], $this->downloadUrl($context, $token));
            $mailable = new CertificateNotificationMailable(
                $this->renderSubject((string) $config->get('emailSubject'), $vars),
                $this->renderBody((string) $config->get('emailBody'), $vars)
            );
            $mailable->from($fromEmail, $fromName)->to($recipient['email'], $recipient['name']);
            Mail::send($mailable);

            $this->repo->update($certificateId, [
                'email_status' => CertificateRepository::EMAIL_SENT,
                'email_attempts' => $attempts,
                'sent_at' => Core::getCurrentDate(),
                'last_error' => null,
            ]);
            $this->repo->audit($certificateId, $contextId, 'email_sent', ['attempt' => $attempts]);
        } catch (Throwable $e) {
            $this->repo->update($certificateId, [
                'email_status' => CertificateRepository::EMAIL_FAILED,
                'email_attempts' => $attempts,
                'last_error' => mb_substr($e->getMessage(), 0, 500),
            ]);
            $this->repo->audit($certificateId, $contextId, 'email_failed', ['attempt' => $attempts, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            throw $e;
        }
    }

    /** Called by the queue when all retries are exhausted. */
    public function recordJobFailure(int $contextId, int $publicationId, Throwable $e): void
    {
        $record = $this->repo->findByPublication($contextId, $publicationId);
        if ($record) {
            $this->repo->update((int) $record->id, [
                'email_status' => $record->email_status === CertificateRepository::EMAIL_SENT
                    ? CertificateRepository::EMAIL_SENT
                    : CertificateRepository::EMAIL_FAILED,
                'last_error' => mb_substr($e->getMessage(), 0, 500),
            ]);
            $this->repo->audit((int) $record->id, $contextId, 'job_failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    public function emailVariables(array $data, string $authorName, string $certificateUrl): array
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return [
            'authorName' => $authorName,
            'articleTitle' => $data['articleTitle'],
            'articleId' => $data['articleId'],
            'allAuthors' => $data['allAuthors'],
            'journalName' => $data['journalName'],
            'journalUrl' => $data['journalUrl'],
            'volume' => $data['volume'],
            'issue' => $data['issue'],
            'publicationDate' => $data['publicationDate'],
            'doi' => $data['doiUrl'],
            'articleUrl' => $data['articleUrl'],
            'certificateNumber' => $data['certificateNumber'],
            'certificateUrl' => $certificateUrl,
            // Convenience variables: whole lines that disappear when the value is absent.
            'issueLine' => $data['issueLabel'] !== '' ? '<strong>Issue:</strong> ' . $e($data['issueLabel']) . '<br />' . "\n" : '',
            'doiLine' => $data['doiUrl'] !== '' ? '<br />' . "\n" . '<strong>DOI:</strong> <a href="' . $e($data['doiUrl']) . '">' . $e($data['doiUrl']) . '</a>' : '',
        ];
    }

    /** Variables whose value is already safe HTML. */
    private const RAW_HTML_VARIABLES = ['issueLine', 'doiLine'];

    public function renderBody(string $template, array $vars): string
    {
        return $this->substitute($template, $vars, true);
    }

    public function renderSubject(string $template, array $vars): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->substitute($template, $vars, false)));
    }

    private function substitute(string $template, array $vars, bool $html): string
    {
        return preg_replace_callback('/\{\$([A-Za-z][A-Za-z0-9]*)\}/', function ($m) use ($vars, $html) {
            if (!array_key_exists($m[1], $vars)) {
                return $m[0];
            }
            $value = (string) $vars[$m[1]];
            if (!$html || in_array($m[1], self::RAW_HTML_VARIABLES, true)) {
                return $value;
            }
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $template);
    }

    private function downloadUrl(Context $context, string $token): string
    {
        $request = Application::get()->getRequest();
        return (string) $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'bijphgrCertificate',
            'download',
            [$token]
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return string[] */
    public function missingConfiguration(PluginConfig $config, Context $context): array
    {
        $missing = $config->missingRequired();
        $mapper = new CertificateDataMapper($config);
        if ($mapper->publisherName($context) === '') {
            $missing[] = 'publisherName';
        }
        return $missing;
    }

    /**
     * Load fresh copies from the database.
     *
     * @return array{0: Context, 1: ?Submission, 2: ?Publication, 3: ?Issue}
     */
    public function load(int $contextId, int $submissionId, int $publicationId): array
    {
        $context = app()->get('context')->get($contextId);
        if (!$context) {
            throw new RuntimeException('Journal not found.');
        }
        $submission = Repo::submission()->get($submissionId);
        $publication = Repo::publication()->get($publicationId);
        if ($submission && (int) $submission->getData('contextId') !== $contextId) {
            // Never process a record against another journal's submission.
            $submission = $publication = null;
        }
        $issue = null;
        if ($publication && (int) $publication->getData('issueId') > 0) {
            $issue = Repo::issue()->get((int) $publication->getData('issueId'));
        }
        return [$context, $submission, $publication, $issue];
    }
}
