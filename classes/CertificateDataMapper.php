<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes;

use APP\core\Application;
use APP\issue\Issue;
use APP\publication\Publication;
use APP\submission\Submission;
use DateTimeImmutable;
use PKP\context\Context;

/**
 * Maps OJS records to the certificate / e-mail view model. All values are plain
 * strings or lists of strings; escaping happens at output time.
 */
class CertificateDataMapper
{
    public function __construct(private PluginConfig $config)
    {
    }

    public function map(Submission $submission, Publication $publication, ?Issue $issue, Context $context, object $record): array
    {
        $locale = $publication->getData('locale') ?: $submission->getData('locale');

        $authors = $this->authorNames($publication, $locale);
        $correspondingAuthor = $this->correspondingAuthor($publication);

        $volume = $issue ? trim((string) $issue->getVolume()) : '';
        $number = $issue ? trim((string) $issue->getNumber()) : '';
        $year = $issue ? trim((string) $issue->getYear()) : '';
        $issueLabel = $this->issueLabel($volume, $number, $year);

        $doi = (string) ($publication->getDoi() ?? '');

        $title = trim((string) $publication->getLocalizedFullTitle($locale, 'text'));
        if ($title === '') {
            $title = trim((string) $publication->getLocalizedTitle($locale));
        }

        return [
            'journalName' => trim((string) $context->getLocalizedName()),
            'publisherName' => $this->publisherName($context),
            'journalUrl' => $this->url($context, 'index'),
            'certificateNumber' => (string) $record->certificate_number,
            'certificateVersion' => (int) $record->certificate_version,
            'verifyUrl' => $this->url($context, 'bijphgrCertificate', 'verify', [$record->verify_token]),
            'articleId' => (string) $submission->getId(),
            'publicationId' => (int) $publication->getId(),
            'articleTitle' => $title,
            'authors' => $authors,
            'allAuthors' => $this->joinNames($authors),
            'volume' => $volume,
            'issue' => $number,
            'issueYear' => $year,
            'issueLabel' => $issueLabel,
            'publicationDate' => $this->formatDate((string) $publication->getData('datePublished')),
            'doi' => $doi,
            'doiUrl' => $doi !== '' ? 'https://doi.org/' . $doi : '',
            'articleUrl' => $this->articleUrl($submission, $publication, $context),
            'signatoryName' => trim((string) $this->config->get('signatoryName')),
            'signatoryTitle' => trim((string) $this->config->get('signatoryTitle')),
            'signatureImage' => (string) $this->config->get('signatureImage'),
            'correspondingAuthorName' => $correspondingAuthor['name'] ?? '',
            'correspondingAuthorEmail' => $correspondingAuthor['email'] ?? '',
            'correspondingAuthorId' => $correspondingAuthor['id'] ?? null,
        ];
    }

    /**
     * Fingerprint of everything printed on the certificate. A change means the
     * issued certificate no longer matches the published record.
     */
    public function fingerprint(array $data, Publication $publication): string
    {
        return hash('sha256', json_encode([
            $data['articleTitle'],
            $data['authors'],
            $data['volume'],
            $data['issue'],
            $data['issueYear'],
            $data['publicationDate'],
            $data['doi'],
            (string) $publication->getData('urlPath'),
            $data['journalName'],
            $data['publisherName'],
        ], JSON_UNESCAPED_UNICODE));
    }

    public function publisherName(Context $context): string
    {
        $configured = trim((string) $this->config->get('publisherName'));
        if ($configured !== '') {
            return $configured;
        }
        return trim((string) $context->getData('publisherInstitution'));
    }

    /**
     * The designated corresponding author is the publication's primary contact.
     * We deliberately do NOT fall back to the first author.
     *
     * @return array{id?: int, name?: string, email?: string}
     */
    public function correspondingAuthor(Publication $publication): array
    {
        $primaryId = (int) $publication->getData('primaryContactId');
        if ($primaryId <= 0) {
            return [];
        }
        foreach ($publication->getData('authors') ?? [] as $author) {
            if ((int) $author->getId() === $primaryId) {
                $locale = $publication->getData('locale');
                return [
                    'id' => (int) $author->getId(),
                    'name' => trim((string) $author->getFullName(true, false, $locale)),
                    'email' => trim((string) $author->getEmail()),
                ];
            }
        }
        return [];
    }

    /** @return string[] */
    private function authorNames(Publication $publication, ?string $locale): array
    {
        $authors = $publication->getData('authors');
        $list = [];
        foreach ($authors ?? [] as $author) {
            $list[] = [(int) $author->getData('seq'), trim((string) $author->getFullName(true, false, $locale))];
        }
        usort($list, fn ($a, $b) => $a[0] <=> $b[0]);
        return array_values(array_filter(array_map(fn ($a) => $a[1], $list), fn ($n) => $n !== ''));
    }

    private function joinNames(array $names): string
    {
        $count = count($names);
        if ($count <= 1) {
            return $names[0] ?? '';
        }
        return implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[$count - 1];
    }

    private function issueLabel(string $volume, string $number, string $year): string
    {
        $parts = [];
        if ($volume !== '') {
            $parts[] = 'Volume ' . $volume;
        }
        if ($number !== '') {
            $parts[] = 'Issue ' . $number;
        }
        $label = implode(', ', $parts);
        if ($year !== '' && $label !== '') {
            $label .= ' (' . $year . ')';
        }
        return $label;
    }

    private function formatDate(string $date): string
    {
        if ($date === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($date))->format('j F Y');
        } catch (\Exception) {
            return $date;
        }
    }

    private function articleUrl(Submission $submission, Publication $publication, Context $context): string
    {
        $bestId = (string) ($publication->getData('urlPath') ?: $submission->getId());
        $args = [$bestId];
        // Non-current versions live at article/view/{id}/version/{publicationId}.
        if ((int) $submission->getData('currentPublicationId') !== (int) $publication->getId()) {
            $args = [$bestId, 'version', $publication->getId()];
        }
        return $this->url($context, 'article', 'view', $args);
    }

    private function url(Context $context, string $page, ?string $op = null, array $args = []): string
    {
        $request = Application::get()->getRequest();
        return (string) $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            $page,
            $op,
            $args
        );
    }
}
