<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes\pages;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\bijphgrPublicationCertificate\BijphgrPublicationCertificatePlugin;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateDataMapper;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateRepository as Repository;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateService;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateStorage;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\PublicationEligibility;
use APP\template\TemplateManager;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Editorial dashboard: /{journal}/bijphgrCertificates
 *
 *  index              list, search, filters, summary           (manager, admin, editor)
 *  view/{id}          one certificate: details, revisions, log (manager, admin, editor)
 *  pdf/{id}[/{ver}]   download a certificate PDF               (manager, admin, editor)
 *  resend, regenerate, retryFailed, releaseHeld   POST only    (manager, admin)
 *
 * Every state-changing operation requires POST + CSRF token and is written to the
 * audit trail together with the acting user.
 */
class CertificateDashboardHandler extends Handler
{
    private const PER_PAGE = 20;

    /** Flash messages that may be shown, keyed by the value of ?msg= (never echoed raw). */
    private const FLASH = [
        'queued' => 'success', 'regenQueued' => 'success', 'retryDone' => 'success', 'releaseDone' => 'success',
        'notFound' => 'error', 'withdrawn' => 'error', 'inProgress' => 'warn', 'noRecipient' => 'error',
        'notConfigured' => 'error', 'notPublished' => 'error', 'noFile' => 'error', 'nothingToDo' => 'warn',
        'badRequest' => 'error',
    ];

    public function __construct(private BijphgrPublicationCertificatePlugin $plugin)
    {
        parent::__construct();
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR],
            ['index', 'view', 'pdf']
        );
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN],
            ['resend', 'regenerate', 'retryFailed', 'releaseHeld']
        );
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    public function index($args, $request): void
    {
        $context = $request->getContext();
        $contextId = (int) $context->getId();
        $service = CertificateService::forContext($contextId);
        $repo = $service->repository();

        $filters = [
            'q' => mb_substr(trim((string) $request->getUserVar('q')), 0, 100),
            'status' => (string) $request->getUserVar('status'),
            'email' => (string) $request->getUserVar('email'),
            'attention' => (string) $request->getUserVar('attention') === '1',
        ];
        if (!in_array($filters['status'], Repository::FILTER_STATUSES, true)) {
            $filters['status'] = '';
        }
        if (!in_array($filters['email'], Repository::FILTER_EMAIL, true)) {
            $filters['email'] = '';
        }
        $page = max(1, (int) $request->getUserVar('page'));

        $result = $repo->search($contextId, $filters, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $result = $repo->search($contextId, $filters, $page, self::PER_PAGE);
        }

        $rows = [];
        foreach ($result['rows'] as $record) {
            $rows[] = $this->buildRow($service, $record, $context, $request);
        }

        $query = array_filter([
            'q' => $filters['q'],
            'status' => $filters['status'],
            'email' => $filters['email'],
            'attention' => $filters['attention'] ? '1' : '',
        ], fn ($v) => $v !== '');

        $this->display($request, 'dashboard.tpl', [
            'counts' => $repo->counts($contextId),
            'rows' => $rows,
            'filters' => $filters,
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
            'prevUrl' => $page > 1 ? $this->url($request, 'index', null, $query + ['page' => $page - 1]) : null,
            'nextUrl' => $page < $pages ? $this->url($request, 'index', null, $query + ['page' => $page + 1]) : null,
            'resetUrl' => $this->url($request, 'index'),
            'canManage' => $this->canManage($request),
            'configMissing' => $service->missingConfiguration($service->config($contextId), $context),
            'settingsUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'management', 'settings', ['website'], null, 'plugins'),
            'retryUrl' => $this->url($request, 'retryFailed'),
            'releaseUrl' => $this->url($request, 'releaseHeld'),
            'indexUrl' => $this->url($request, 'index'),
            'attentionUrl' => $this->url($request, 'index', null, ['attention' => 1]),
            'sentUrl' => $this->url($request, 'index', null, ['email' => 'sent']),
            'failedUrl' => $this->url($request, 'index', null, ['email' => 'failed']),
            'waitingUrl' => $this->url($request, 'index', null, ['email' => 'pending']),
            'revisionUrl' => $this->url($request, 'index', null, ['status' => 'needs_revision']),
            'withdrawnUrl' => $this->url($request, 'index', null, ['status' => 'withdrawn']),
        ]);
    }

    public function view($args, $request): void
    {
        $context = $request->getContext();
        $service = CertificateService::forContext((int) $context->getId());
        $repo = $service->repository();
        $record = $this->findOwned($repo, ($args[0] ?? ''), $context);

        $row = $this->buildRow($service, $record, $context, $request);
        $id = (int) $record->id;

        $revisions = [];
        foreach (array_reverse($repo->revisions($id)) as $rev) {
            $revisions[] = [
                'version' => (int) $rev->version,
                'date' => $this->dateTime($rev->created_at),
                'reason' => (string) $rev->reason,
                'by' => $this->userName($rev->created_by),
                'pdfUrl' => $this->url($request, 'pdf', [$id, (int) $rev->version]),
                'isCurrent' => (int) $rev->version === (int) $record->certificate_version,
            ];
        }

        $log = [];
        foreach (array_reverse($repo->auditTrail($id)) as $entry) {
            $key = 'plugins.generic.bijphgrPublicationCertificate.dash.event.' . preg_replace('/[^a-z_]/', '', (string) $entry->event);
            $label = __($key);
            $log[] = [
                'date' => $this->dateTime($entry->created_at),
                'event' => $label === $key ? (string) $entry->event : $label,
                'detail' => $this->summariseDetail($entry->detail),
                'by' => $entry->user_id ? $this->userName($entry->user_id) : '',
            ];
        }

        $this->display($request, 'dashboardDetail.tpl', [
            'row' => $row,
            'revisions' => $revisions,
            'log' => $log,
            'verifyUrl' => $row['verifyUrl'],
            'canManage' => $this->canManage($request),
            'indexUrl' => $this->url($request, 'index'),
            'resendUrl' => $this->url($request, 'resend', [$id]),
            'regenerateUrl' => $this->url($request, 'regenerate', [$id]),
            'pdfUrl' => $this->url($request, 'pdf', [$id]),
            'settingsUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'management', 'settings', ['website'], null, 'plugins'),
        ]);
    }

    /** Stream a certificate PDF (latest, or a specific version) to an editor. */
    public function pdf($args, $request): void
    {
        $context = $request->getContext();
        $service = CertificateService::forContext((int) $context->getId());
        $repo = $service->repository();
        $record = $this->findOwned($repo, ($args[0] ?? ''), $context);

        if (isset($args[1]) && !(is_string($args[1]) && ctype_digit($args[1]) && strlen($args[1]) < 6)) {
            throw new NotFoundHttpException();
        }
        $version = isset($args[1]) ? (int) $args[1] : (int) $record->certificate_version;
        $relative = null;
        $sha = null;
        if ($version === (int) $record->certificate_version && $record->file_path) {
            $relative = $record->file_path;
            $sha = $record->file_sha256;
        } else {
            $rev = $repo->revision((int) $record->id, $version);
            if ($rev) {
                $relative = $rev->file_path;
                $sha = $rev->file_sha256;
            }
        }

        $path = $relative ? (new CertificateStorage())->resolve($relative) : null;
        if ($path === null) {
            throw new NotFoundHttpException();
        }
        if ($sha && !hash_equals($sha, hash_file('sha256', $path))) {
            $repo->audit((int) $record->id, (int) $record->context_id, 'integrity_failure', ['version' => $version], $this->userId($request));
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit('This certificate file failed its integrity check. It was not served.');
        }

        $repo->audit((int) $record->id, (int) $record->context_id, 'admin_downloaded', ['version' => $version], $this->userId($request));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '', $record->certificate_number) . '-v' . $version . '.pdf"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    // ------------------------------------------------------------------
    // Actions (POST + CSRF only)
    // ------------------------------------------------------------------

    public function resend($args, $request): void
    {
        $this->guardPost($request);
        $context = $request->getContext();
        $service = CertificateService::forContext((int) $context->getId());
        $record = $this->findOwned($service->repository(), ($args[0] ?? ''), $context);

        $outcome = $service->resend((int) $record->id, $this->userId($request));
        $this->back($request, (int) $record->id, $outcome);
    }

    public function regenerate($args, $request): void
    {
        $this->guardPost($request);
        $context = $request->getContext();
        $service = CertificateService::forContext((int) $context->getId());
        $record = $this->findOwned($service->repository(), ($args[0] ?? ''), $context);

        $outcome = $service->regenerate((int) $record->id, $this->userId($request));
        $this->back($request, (int) $record->id, $outcome === CertificateService::OUTCOME_QUEUED ? 'regenQueued' : $outcome);
    }

    public function retryFailed($args, $request): void
    {
        $this->guardPost($request);
        $contextId = (int) $request->getContext()->getId();
        $result = CertificateService::forContext($contextId)->retryFailedEmails($contextId, $this->userId($request));
        $this->backToIndex($request, $result['queued'] > 0 ? 'retryDone' : 'nothingToDo', ['n' => $result['queued']]);
    }

    public function releaseHeld($args, $request): void
    {
        $this->guardPost($request);
        $contextId = (int) $request->getContext()->getId();
        $service = CertificateService::forContext($contextId);
        $context = $request->getContext();
        if ($service->missingConfiguration($service->config($contextId), $context)) {
            $this->backToIndex($request, 'notConfigured');
        }
        $n = $service->dispatchUnprocessed($contextId);
        $this->backToIndex($request, $n > 0 ? 'releaseDone' : 'nothingToDo', ['n' => $n]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function guardPost($request): void
    {
        if (!$request->isPost()) {
            http_response_code(405);
            header('Allow: POST');
            exit('Method not allowed.');
        }
        if (!$request->checkCSRF()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Your session has expired or the request could not be verified. Go back, reload the page and try again.');
        }
    }

    /** Record must exist and belong to the journal being viewed; otherwise a plain 404. */
    private function findOwned(Repository $repo, $rawId, $context): object
    {
        // Only a plain positive integer is an id ("2' or 1=1" must not be read as 2).
        $id = is_string($rawId) && ctype_digit($rawId) && strlen($rawId) < 10 ? (int) $rawId : 0;
        $record = $id > 0 ? $repo->find($id) : null;
        if (!$record || (int) $record->context_id !== (int) $context->getId()) {
            throw new NotFoundHttpException();
        }
        return $record;
    }

    private function canManage($request): bool
    {
        $user = $request->getUser();
        $context = $request->getContext();
        if (!$user || !$context) {
            return false;
        }
        return (bool) $user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $context->getId());
    }

    private function userId($request): ?int
    {
        $user = $request->getUser();
        return $user ? (int) $user->getId() : null;
    }

    private function userName($userId): string
    {
        static $cache = [];
        $userId = (int) $userId;
        if ($userId <= 0) {
            return '';
        }
        if (!isset($cache[$userId])) {
            $user = Repo::user()->get($userId, true);
            $cache[$userId] = $user ? trim((string) $user->getFullName()) : '#' . $userId;
        }
        return $cache[$userId];
    }

    private function dateTime(?string $value): string
    {
        return $value ? date('j M Y, H:i', strtotime($value)) : '';
    }

    private function url($request, string $op, ?array $path = null, array $params = []): string
    {
        return (string) $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'bijphgrCertificates', $op, $path, $params ?: null);
    }

    private function back($request, int $id, string $code): void
    {
        $request->redirectUrl($this->url($request, 'view', [$id], ['msg' => isset(self::FLASH[$code]) ? $code : 'badRequest']));
    }

    private function backToIndex($request, string $code, array $extra = []): void
    {
        $request->redirectUrl($this->url($request, 'index', null, ['msg' => isset(self::FLASH[$code]) ? $code : 'badRequest'] + $extra));
    }

    /** Render a standalone dashboard template with the shared variables. */
    private function display($request, string $template, array $vars): void
    {
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        header('Cache-Control: no-store');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');

        $flash = null;
        $msg = (string) $request->getUserVar('msg');
        if ($msg !== '' && isset(self::FLASH[$msg])) {
            $flash = [
                'type' => self::FLASH[$msg],
                'text' => __('plugins.generic.bijphgrPublicationCertificate.dash.flash.' . $msg, ['n' => max(0, min(100000, (int) $request->getUserVar('n')))]),
            ];
        }

        $logo = '';
        $logoFile = dirname(__DIR__, 2) . '/resources/logo-icon.png';
        if (is_readable($logoFile)) {
            $logo = 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoFile));
        }

        $templateMgr->assign($vars + [
            'flash' => $flash,
            'logoDataUri' => $logo,
            'journalName' => $request->getContext()->getLocalizedName(),
            'dashHead' => $this->plugin->getTemplateResource('dashboardHead.tpl'),
            'dashHeader' => $this->plugin->getTemplateResource('dashboardHeader.tpl'),
            'dashboardTitle' => __('plugins.generic.bijphgrPublicationCertificate.dash.title'),
            'ojsDashboardUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'submissions'),
        ]);
        $templateMgr->display($this->plugin->getTemplateResource($template));
    }

    /**
     * Everything the list and detail pages need to know about one certificate,
     * computed once here so templates stay free of logic.
     */
    private function buildRow(CertificateService $service, object $record, $context, $request): array
    {
        $config = $service->config((int) $record->context_id);
        $row = [
            'id' => (int) $record->id,
            'number' => (string) $record->certificate_number,
            'version' => (int) $record->certificate_version,
            'status' => (string) $record->certificate_status,
            'emailStatus' => (string) $record->email_status,
            'held' => $record->file_path === null,
            'attempts' => (int) $record->email_attempts,
            'lastError' => (string) $record->last_error,
            'generatedAt' => $this->dateTime($record->generated_at),
            'sentAt' => $this->dateTime($record->sent_at),
            'linkExpiry' => $record->download_token_expires_at ? date('j M Y', strtotime($record->download_token_expires_at)) : '',
            'linkExpired' => $record->download_token_expires_at && $record->download_token_expires_at < date('Y-m-d H:i:s'),
            'submissionId' => (int) $record->submission_id,
            'title' => '', 'authors' => '', 'issueLabel' => '', 'publicationDate' => '', 'doi' => '', 'doiUrl' => '',
            'articleUrl' => '', 'recipientName' => '', 'recipientEmail' => (string) $record->recipient_email,
            'verifyUrl' => $this->verifyUrl($request, $record),
            'viewUrl' => $this->url($request, 'view', [(int) $record->id]),
            'pdfUrl' => $this->url($request, 'pdf', [(int) $record->id]),
            'workflowUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'workflow', 'access', [(int) $record->submission_id]),
            'articleAvailable' => false,
            'published' => false,
            'hasRecipient' => false,
        ];

        try {
            [$ctx, $submission, $publication, $issue] = $service->load((int) $record->context_id, (int) $record->submission_id, (int) $record->publication_id);
        } catch (Throwable) {
            $submission = $publication = $issue = null;
        }

        if ($submission && $publication) {
            $row['articleAvailable'] = true;
            $row['published'] = PublicationEligibility::reason($submission, $publication, $issue) === null;
            $d = (new CertificateDataMapper($config))->map($submission, $publication, $issue, $context, $record);
            $row['title'] = $d['articleTitle'];
            $row['authors'] = $d['allAuthors'];
            $row['issueLabel'] = $d['issueLabel'];
            $row['publicationDate'] = $d['publicationDate'];
            $row['doi'] = $d['doi'];
            $row['doiUrl'] = $d['doiUrl'];
            $row['articleUrl'] = $d['articleUrl'];
            $row['recipientName'] = $d['correspondingAuthorName'];
            if (filter_var($d['correspondingAuthorEmail'], FILTER_VALIDATE_EMAIL)) {
                $row['hasRecipient'] = true;
                $row['recipientEmail'] = $d['correspondingAuthorEmail'];
            }
        }

        $inProgress = $row['emailStatus'] === Repository::EMAIL_QUEUED && $record->email_claimed_at
            && $record->email_claimed_at >= date('Y-m-d H:i:s', time() - Repository::CLAIM_STALE_MINUTES * 60);
        $withdrawn = $row['status'] === Repository::STATUS_WITHDRAWN;
        $row['inProgress'] = $inProgress;
        $row['canResend'] = !$withdrawn && $row['published'] && $row['hasRecipient'] && !$inProgress;
        $row['canRegenerate'] = !$withdrawn && $row['published'] && !$row['held'];
        $row['needsAttention'] = !$withdrawn && ($row['emailStatus'] === Repository::EMAIL_FAILED || $row['status'] === Repository::STATUS_NEEDS_REVISION || $row['held']);
        $row['next'] = $this->nextStep($row, (bool) $config->get('autoRevise'));
        return $row;
    }

    /** One plain-language "what should I do?" key for the detail page. */
    private function nextStep(array $row, bool $autoRevise): string
    {
        if (!$row['articleAvailable']) {
            return 'articleGone';
        }
        if ($row['status'] === Repository::STATUS_WITHDRAWN) {
            return 'withdrawn';
        }
        if ($row['held']) {
            return 'held';
        }
        if (!$row['hasRecipient']) {
            return 'noRecipient';
        }
        if ($row['status'] === Repository::STATUS_NEEDS_REVISION) {
            return $autoRevise ? 'revisionComing' : 'revisionManual';
        }
        if ($row['emailStatus'] === Repository::EMAIL_FAILED) {
            return 'emailFailed';
        }
        if ($row['inProgress']) {
            return 'sending';
        }
        if ($row['emailStatus'] === Repository::EMAIL_PENDING || $row['emailStatus'] === Repository::EMAIL_QUEUED) {
            return 'waiting';
        }
        if ($row['linkExpired']) {
            return 'linkExpired';
        }
        return 'ok';
    }

    private function verifyUrl($request, object $record): string
    {
        return (string) $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $request->getContext()->getPath(),
            'bijphgrCertificate',
            'verify',
            [$record->verify_token]
        );
    }

    /** Turn the stored JSON detail into short "key: value" text (never secrets: none are stored). */
    private function summariseDetail(?string $json): string
    {
        if (!$json) {
            return '';
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return '';
        }
        $parts = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
            }
            if ($key === 'sha256') {
                $value = substr((string) $value, 0, 12) . '…';
            }
            $parts[] = $key . ': ' . mb_substr((string) $value, 0, 120);
        }
        return implode(' · ', $parts);
    }
}
