<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes\pages;

use APP\handler\Handler;
use APP\plugins\generic\bijphgrPublicationCertificate\BijphgrPublicationCertificatePlugin;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateRepository;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateService;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateStorage;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\PublicationEligibility;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\TokenService;
use APP\template\TemplateManager;
use PKP\core\Core;
use PKP\security\authorization\ContextRequiredPolicy;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Public pages:
 *   /{journal}/bijphgrCertificate/verify/{verifyToken}     – what the QR code opens
 *   /{journal}/bijphgrCertificate/download/{downloadToken} – the link in the e-mail
 *
 * Both answer a missing / malformed / foreign token identically (404) so tokens
 * cannot be probed. The verification page only ever shows public bibliographic
 * information and the current status; it never shows e-mail addresses, file
 * locations or internal identifiers.
 */
class CertificatePageHandler extends Handler
{
    public function __construct(private BijphgrPublicationCertificatePlugin $plugin)
    {
        parent::__construct();
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function verify($args, $request): void
    {
        $context = $request->getContext();
        $token = (string) ($args[0] ?? '');
        $service = CertificateService::forContext($context->getId());
        $repo = $service->repository();

        $record = TokenService::isVerifyToken($token) ? $repo->findByVerifyToken($token) : null;
        if ($record && (int) $record->context_id !== (int) $context->getId()) {
            $record = null;
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addHeader('robots', '<meta name="robots" content="noindex, nofollow">');
        header('Cache-Control: no-store');

        if (!$record) {
            // A real 404 (OJS renders its themed error page with this message).
            throw new NotFoundHttpException(__('plugins.generic.bijphgrPublicationCertificate.verify.notFound'));
        }

        // Catches changes the events cannot see (e.g. author edits) and queues a revision.
        try {
            $service->checkForMetadataChange((int) $context->getId(), (int) $record->publication_id);
            $record = $repo->find((int) $record->id);
        } catch (Throwable $e) {
            error_log('[bijphgrPublicationCertificate] verify check failed: ' . $e->getMessage());
        }

        $view = $this->buildView($service, $record, $context);
        $templateMgr->assign($view + ['found' => true, 'pageTitle' => __('plugins.generic.bijphgrPublicationCertificate.verify.title')]);
        $templateMgr->display($this->plugin->getTemplateResource('verify.tpl'));
    }

    public function download($args, $request): void
    {
        $context = $request->getContext();
        $token = (string) ($args[0] ?? '');
        $service = CertificateService::forContext($context->getId());
        $repo = $service->repository();

        $record = TokenService::isDownloadToken($token) ? $repo->findByDownloadToken($token) : null;
        if (!$record || (int) $record->context_id !== (int) $context->getId()) {
            $this->plain(404, 'Not found.');
        }

        if ($record->download_token_expires_at && $record->download_token_expires_at < Core::getCurrentDate()) {
            $this->plain(410, 'This download link has expired. Please contact the editorial office to receive a new one.');
        }

        // A certificate is only downloadable while its article is publicly published.
        if ($record->certificate_status === CertificateRepository::STATUS_WITHDRAWN) {
            $this->plain(410, 'This certificate is no longer available because the article is not currently published. Please contact the editorial office.');
        }

        $storage = new CertificateStorage();
        $path = $record->file_path ? $storage->resolve($record->file_path) : null;
        if ($path === null) {
            $this->plain(404, 'Not found.');
        }
        // Integrity check against the hash recorded at generation time.
        if ($record->file_sha256 && !hash_equals($record->file_sha256, hash_file('sha256', $path))) {
            $repo->audit((int) $record->id, (int) $record->context_id, 'integrity_failure');
            $this->plain(500, 'This certificate file could not be verified. Please contact the editorial office.');
        }

        $repo->audit((int) $record->id, (int) $record->context_id, 'downloaded', ['version' => (int) $record->certificate_version]);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '', $record->certificate_number) . '.pdf"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        readfile($path);
        exit;
    }

    /** @return never */
    private function plain(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $message;
        exit;
    }

    private function buildView(CertificateService $service, object $record, $context): array
    {
        $repo = $service->repository();
        $view = [
            'journalName' => $context->getLocalizedName(),
            'certificateNumber' => $record->certificate_number,
            'certificateVersion' => (int) $record->certificate_version,
            'issuedDate' => $this->date($record->generated_at),
            'revisions' => array_map(
                fn ($r) => ['version' => (int) $r->version, 'date' => $this->date($r->created_at)],
                $repo->revisions((int) $record->id)
            ),
            'published' => false,
            'unavailable' => false,
            'correctionPending' => $record->certificate_status === CertificateRepository::STATUS_NEEDS_REVISION,
        ];

        try {
            [$ctx, $submission, $publication, $issue] = $service->load((int) $record->context_id, (int) $record->submission_id, (int) $record->publication_id);
        } catch (Throwable $e) {
            error_log('[bijphgrPublicationCertificate] verify load failed: ' . $e->getMessage());
            $submission = $publication = $issue = null;
        }

        if (!$publication || !$submission) {
            $view['unavailable'] = true;
            return $view;
        }

        if (PublicationEligibility::reason($submission, $publication, $issue) !== null) {
            // Not (or no longer) published: show the status only, no bibliographic details.
            $view['unavailable'] = true;
            return $view;
        }

        $mapper = new \APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateDataMapper($service->config((int) $record->context_id));
        $d = $mapper->map($submission, $publication, $issue, $context, $record);

        // array_merge, not "+": the later values must override the defaults above.
        return array_merge($view, [
            'published' => true,
            'articleTitle' => $d['articleTitle'],
            'authors' => $d['allAuthors'],
            'issueLabel' => $d['issueLabel'],
            'publicationDate' => $d['publicationDate'],
            'doi' => $d['doi'],
            'doiUrl' => $d['doiUrl'],
            'articleUrl' => $d['articleUrl'],
        ]);
    }

    private function date(?string $value): string
    {
        return $value ? date('j F Y', strtotime($value)) : '';
    }
}
