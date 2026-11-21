<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;

/**
 * Persistence for certificate records, revisions, number counters and audit trail.
 */
class CertificateRepository
{
    public const T_CERT = 'bijphgr_certificates';
    public const T_REV = 'bijphgr_certificate_revisions';
    public const T_COUNTER = 'bijphgr_certificate_counters';
    public const T_AUDIT = 'bijphgr_certificate_audit';

    public const EMAIL_PENDING = 'pending';
    public const EMAIL_QUEUED = 'queued';
    public const EMAIL_SENT = 'sent';
    public const EMAIL_FAILED = 'failed';

    public const STATUS_VALID = 'valid';
    public const STATUS_NEEDS_REVISION = 'needs_revision';
    public const STATUS_WITHDRAWN = 'withdrawn';

    /** Minutes after which an in-flight ("queued") e-mail claim is considered abandoned. */
    public const CLAIM_STALE_MINUTES = 15;

    public function find(int $id): ?object
    {
        return DB::table(self::T_CERT)->where('id', $id)->first();
    }

    public function findByPublication(int $contextId, int $publicationId): ?object
    {
        return DB::table(self::T_CERT)
            ->where('context_id', $contextId)
            ->where('publication_id', $publicationId)
            ->first();
    }

    public function findByVerifyToken(string $token): ?object
    {
        return DB::table(self::T_CERT)->where('verify_token', $token)->first();
    }

    public function findByDownloadToken(string $token): ?object
    {
        return DB::table(self::T_CERT)
            ->where('download_token_hash', TokenService::hash($token))
            ->first();
    }

    public function revisions(int $certificateId): array
    {
        return DB::table(self::T_REV)->where('certificate_id', $certificateId)->orderBy('version')->get()->all();
    }

    public function latestRevision(int $certificateId): ?object
    {
        return DB::table(self::T_REV)->where('certificate_id', $certificateId)->orderByDesc('version')->first();
    }

    /**
     * Create the record for a publication, or return the existing one.
     *
     * Duplicate protection is enforced by the database: the unique key on
     * (context_id, publication_id) decides, not an application-level check.
     * The sequence number is allocated inside the same transaction, so a lost
     * race rolls back its number as well (no gaps, no duplicates).
     *
     * @return array{0: object, 1: bool} [record, created]
     */
    public function createOrGet(int $contextId, int $submissionId, int $publicationId, int $year, ?string $prefix): array
    {
        $existing = $this->findByPublication($contextId, $publicationId);
        if ($existing) {
            return [$existing, false];
        }

        try {
            $record = DB::transaction(function () use ($contextId, $submissionId, $publicationId, $year, $prefix) {
                $sequence = $this->allocateSequence($contextId, $year);
                $now = Core::getCurrentDate();
                $id = DB::table(self::T_CERT)->insertGetId([
                    'context_id' => $contextId,
                    'submission_id' => $submissionId,
                    'publication_id' => $publicationId,
                    'certificate_number' => CertificateNumber::format($year, $sequence, $prefix),
                    'certificate_version' => 1,
                    'verify_token' => TokenService::verifyToken(),
                    'certificate_status' => self::STATUS_VALID,
                    'email_status' => self::EMAIL_PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return $this->find((int) $id);
            });
        } catch (QueryException $e) {
            // Lost a race against a concurrent request for the same publication.
            $existing = $this->findByPublication($contextId, $publicationId);
            if ($existing) {
                return [$existing, false];
            }
            throw $e;
        }

        $this->audit((int) $record->id, $contextId, 'record_created', [
            'publicationId' => $publicationId,
            'certificateNumber' => $record->certificate_number,
        ]);

        return [$record, true];
    }

    /**
     * Allocate the next number for a journal/year. Must run inside a transaction:
     * the row lock serialises concurrent allocations.
     */
    private function allocateSequence(int $contextId, int $year): int
    {
        DB::table(self::T_COUNTER)->insertOrIgnore([
            'context_id' => $contextId,
            'year' => $year,
            'last_value' => 0,
        ]);

        $row = DB::table(self::T_COUNTER)
            ->where('context_id', $contextId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        $next = (int) $row->last_value + 1;
        DB::table(self::T_COUNTER)
            ->where('context_id', $contextId)
            ->where('year', $year)
            ->update(['last_value' => $next]);

        return $next;
    }

    public function update(int $id, array $fields): void
    {
        $fields['updated_at'] = Core::getCurrentDate();
        DB::table(self::T_CERT)->where('id', $id)->update($fields);
    }

    /**
     * Atomically claim the right to send the notification e-mail.
     *
     * Only one worker can move pending/failed (or an abandoned "queued") to
     * "queued"; everyone else gets false and must not send. This is what keeps
     * retries and duplicate events from producing a second e-mail.
     */
    public function claimEmail(int $id): bool
    {
        $now = Core::getCurrentDate();
        $stale = date('Y-m-d H:i:s', time() - self::CLAIM_STALE_MINUTES * 60);

        $affected = DB::table(self::T_CERT)
            ->where('id', $id)
            ->where(function ($q) use ($stale) {
                $q->whereIn('email_status', [self::EMAIL_PENDING, self::EMAIL_FAILED])
                    ->orWhere(function ($q2) use ($stale) {
                        $q2->where('email_status', self::EMAIL_QUEUED)->where('email_claimed_at', '<', $stale);
                    });
            })
            ->update([
                'email_status' => self::EMAIL_QUEUED,
                'email_claimed_at' => $now,
                'updated_at' => $now,
            ]);

        return $affected === 1;
    }

    public function addRevision(int $certificateId, int $version, string $filePath, string $sha256, string $metadataHash, ?string $reason, ?int $userId): void
    {
        DB::table(self::T_REV)->insert([
            'certificate_id' => $certificateId,
            'version' => $version,
            'file_path' => $filePath,
            'file_sha256' => $sha256,
            'metadata_hash' => $metadataHash,
            'reason' => $reason,
            'created_by' => $userId,
            'created_at' => Core::getCurrentDate(),
        ]);
    }

    /**
     * Append-only audit record. Never put secrets (tokens, SMTP credentials,
     * internal file paths) in $detail.
     */
    public function audit(?int $certificateId, int $contextId, string $event, array $detail = [], ?int $userId = null): void
    {
        DB::table(self::T_AUDIT)->insert([
            'certificate_id' => $certificateId,
            'context_id' => $contextId,
            'event' => $event,
            'detail' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'user_id' => $userId,
            'created_at' => Core::getCurrentDate(),
        ]);
    }

    public function auditTrail(int $certificateId): array
    {
        return DB::table(self::T_AUDIT)->where('certificate_id', $certificateId)->orderBy('id')->get()->all();
    }

    // ------------------------------------------------------------------
    // Dashboard queries
    // ------------------------------------------------------------------

    public const FILTER_STATUSES = [self::STATUS_VALID, self::STATUS_NEEDS_REVISION, self::STATUS_WITHDRAWN];
    public const FILTER_EMAIL = [self::EMAIL_PENDING, self::EMAIL_QUEUED, self::EMAIL_SENT, self::EMAIL_FAILED];

    /**
     * Search a journal's certificates for the dashboard.
     *
     * @param array{q?: string, status?: string, email?: string, attention?: bool} $filters
     *
     * @return array{rows: object[], total: int}
     */
    public function search(int $contextId, array $filters, int $page, int $perPage): array
    {
        $query = $this->filteredQuery($contextId, $filters);
        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('c.id')
            ->offset(max(0, ($page - 1) * $perPage))
            ->limit($perPage)
            ->get()
            ->all();
        return ['rows' => $rows, 'total' => $total];
    }

    private function filteredQuery(int $contextId, array $filters)
    {
        $query = DB::table(self::T_CERT . ' as c')->select('c.*')->where('c.context_id', $contextId);

        if (in_array($filters['status'] ?? '', self::FILTER_STATUSES, true)) {
            $query->where('c.certificate_status', $filters['status']);
        }
        if (in_array($filters['email'] ?? '', self::FILTER_EMAIL, true)) {
            $query->where('c.email_status', $filters['email']);
        }
        if (!empty($filters['attention'])) {
            $query->where(function ($q) {
                $q->where('c.email_status', self::EMAIL_FAILED)
                    ->orWhere('c.certificate_status', self::STATUS_NEEDS_REVISION)
                    ->orWhereNull('c.file_path');
            })->where('c.certificate_status', '!=', self::STATUS_WITHDRAWN);
        }

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%' . addcslashes(mb_substr($term, 0, 100), '%_\\') . '%';
            $query->where(function ($q) use ($like, $term) {
                $q->where('c.certificate_number', 'like', $like)
                    ->orWhere('c.recipient_email', 'like', $like)
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))->from('publication_settings as ps')
                            ->whereColumn('ps.publication_id', 'c.publication_id')
                            ->whereIn('ps.setting_name', ['title', 'subtitle'])
                            ->where('ps.setting_value', 'like', $like);
                    })
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))->from('authors as a')
                            ->join('author_settings as s', 's.author_id', '=', 'a.author_id')
                            ->whereColumn('a.publication_id', 'c.publication_id')
                            ->whereIn('s.setting_name', ['givenName', 'familyName'])
                            ->where('s.setting_value', 'like', $like);
                    });
                if (ctype_digit($term)) {
                    $q->orWhere('c.submission_id', (int) $term);
                }
            });
        }

        return $query;
    }

    /** @return array<string, int> */
    public function counts(int $contextId): array
    {
        $rows = DB::table(self::T_CERT)->where('context_id', $contextId)
            ->selectRaw('certificate_status as s, email_status as e, (file_path IS NULL) as held, COUNT(*) as n')
            ->groupBy('certificate_status', 'email_status', DB::raw('(file_path IS NULL)'))
            ->get();

        $c = ['total' => 0, 'valid' => 0, 'needs_revision' => 0, 'withdrawn' => 0,
            'sent' => 0, 'failed' => 0, 'waiting' => 0, 'held' => 0, 'attention' => 0];
        foreach ($rows as $r) {
            $n = (int) $r->n;
            $c['total'] += $n;
            $c[$r->s] = ($c[$r->s] ?? 0) + $n;
            if ($r->s === self::STATUS_WITHDRAWN) {
                continue; // withdrawn items never need action
            }
            if ($r->e === self::EMAIL_SENT) {
                $c['sent'] += $n;
            } elseif ($r->e === self::EMAIL_FAILED) {
                $c['failed'] += $n;
            } else {
                $c['waiting'] += $n;
            }
            if ((int) $r->held === 1) {
                $c['held'] += $n;
            }
            if ($r->e === self::EMAIL_FAILED || $r->s === self::STATUS_NEEDS_REVISION || (int) $r->held === 1) {
                $c['attention'] += $n;
            }
        }
        return $c;
    }

    public function revision(int $certificateId, int $version): ?object
    {
        return DB::table(self::T_REV)->where('certificate_id', $certificateId)->where('version', $version)->first();
    }

    /** @return object[] failed, not-withdrawn records (for the bulk retry) */
    public function findFailedEmails(int $contextId, int $limit = 200): array
    {
        return DB::table(self::T_CERT)
            ->where('context_id', $contextId)
            ->where('email_status', self::EMAIL_FAILED)
            ->where('certificate_status', '!=', self::STATUS_WITHDRAWN)
            ->orderBy('id')->limit($limit)->get()->all();
    }

    /** Records that never completed processing (for the dashboard / re-dispatch). */
    public function findUnprocessed(int $contextId): array
    {
        return DB::table(self::T_CERT)
            ->where('context_id', $contextId)
            ->where(function ($q) {
                $q->whereNull('file_path')->orWhere('email_status', self::EMAIL_PENDING);
            })
            ->orderBy('id')
            ->get()
            ->all();
    }
}
