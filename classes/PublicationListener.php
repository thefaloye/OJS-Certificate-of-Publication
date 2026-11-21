<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes;

use APP\plugins\generic\bijphgrPublicationCertificate\BijphgrPublicationCertificatePlugin;
use PKP\observers\events\MetadataChanged;
use PKP\observers\events\PublicationPublished;
use PKP\observers\events\PublicationUnpublished;
use Throwable;

/**
 * Bridges OJS 3.5 events to the certificate service.
 *
 * PublicationPublished is dispatched after the publication has been written to
 * the database, once per publication, for every publishing path (single
 * article, whole issue, scheduled-date publishing, new versions). It also
 * fires when a publication is only *scheduled*, which is why the service
 * re-checks eligibility rather than trusting the event.
 *
 * Nothing thrown from here may interrupt the editor's publishing workflow:
 * failures are logged and the record can be re-queued from the dashboard.
 */
class PublicationListener
{
    public function __construct(private BijphgrPublicationCertificatePlugin $plugin)
    {
    }

    public function onPublished(PublicationPublished $event): void
    {
        try {
            (new CertificateService($this->plugin))->handlePublished(
                (int) $event->submission->getData('contextId'),
                (int) $event->submission->getId(),
                (int) $event->publication->getId()
            );
        } catch (Throwable $e) {
            error_log('[bijphgrPublicationCertificate] publish handler failed: ' . $e->getMessage());
        }
    }

    public function onUnpublished(PublicationUnpublished $event): void
    {
        try {
            (new CertificateService($this->plugin))->handleUnpublished(
                (int) $event->submission->getData('contextId'),
                (int) $event->publication->getId()
            );
        } catch (Throwable $e) {
            error_log('[bijphgrPublicationCertificate] unpublish handler failed: ' . $e->getMessage());
        }
    }

    public function onMetadataChanged(MetadataChanged $event): void
    {
        try {
            $service = new CertificateService($this->plugin);
            $contextId = (int) $event->submission->getData('contextId');
            if (!$service->config($contextId)->isEnabled()) {
                return;
            }
            foreach ($event->submission->getData('publications') ?? [] as $publication) {
                $service->checkForMetadataChange($contextId, (int) $publication->getId());
            }
        } catch (Throwable $e) {
            error_log('[bijphgrPublicationCertificate] metadata handler failed: ' . $e->getMessage());
        }
    }
}
