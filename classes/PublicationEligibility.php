<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes;

use APP\issue\Issue;
use APP\publication\Publication;
use APP\submission\Submission;

/**
 * Central eligibility rules. A publication qualifies only when OJS has
 * persisted it as publicly published:
 *  - publication status is STATUS_PUBLISHED (STATUS_SCHEDULED never qualifies),
 *  - it has a publication date, and
 *  - any assigned issue is itself published.
 *
 * Always pass objects freshly loaded from the database, never an in-flight
 * copy from a "before" hook.
 */
final class PublicationEligibility
{
    /**
     * @return string|null null when eligible, otherwise a short machine-readable reason
     */
    public static function reason(?Submission $submission, ?Publication $publication, ?Issue $issue = null): ?string
    {
        if (!$submission || !$publication) {
            return 'missing_record';
        }

        if ((int) $publication->getData('submissionId') !== (int) $submission->getId()) {
            return 'submission_mismatch';
        }

        // The STATUS_* constants live on Submission in OJS 3.x (Publication has none).
        if ((int) $publication->getData('status') !== Submission::STATUS_PUBLISHED) {
            return 'status_not_published';
        }

        if (!$publication->getData('datePublished')) {
            return 'no_publication_date';
        }

        $issueId = (int) $publication->getData('issueId');
        if ($issueId > 0) {
            if (!$issue || (int) $issue->getId() !== $issueId) {
                return 'issue_missing';
            }
            if (!$issue->getPublished()) {
                return 'issue_not_published';
            }
        }

        return null;
    }

    public static function isEligible(?Submission $submission, ?Publication $publication, ?Issue $issue = null): bool
    {
        return self::reason($submission, $publication, $issue) === null;
    }
}
