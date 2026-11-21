<?php

namespace APP\plugins\generic\bijphgrPublicationCertificate\classes\form;

use APP\plugins\generic\bijphgrPublicationCertificate\BijphgrPublicationCertificatePlugin;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateNumber;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateService;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\CertificateStorage;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\PluginConfig;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class SettingsForm extends Form
{
    private const TEXT_FIELDS = ['publisherName', 'signatoryName', 'signatoryTitle', 'certificatePrefix', 'emailSubject', 'emailBody', 'downloadLinkDays'];

    public function __construct(private BijphgrPublicationCertificatePlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData(): void
    {
        $config = new PluginConfig($this->plugin, $this->contextId);
        foreach (self::TEXT_FIELDS as $field) {
            $this->setData($field, $config->get($field));
        }
        $this->setData('autoRevise', (bool) $config->get('autoRevise'));
        $this->setData('hasSignature', (string) $config->get('signatureImage') !== '');
        parent::initData();
    }

    public function readInputData(): void
    {
        $this->readUserVars(array_merge(self::TEXT_FIELDS, ['autoRevise', 'signatureData', 'removeSignature']));
    }

    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $config = new PluginConfig($this->plugin, $this->contextId);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'missingSettings' => $config->missingRequired(),
        ]);
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $p = $this->plugin;
        $c = $this->contextId;

        $p->updateSetting($c, 'publisherName', trim((string) $this->getData('publisherName')), 'string');
        $p->updateSetting($c, 'signatoryName', trim((string) $this->getData('signatoryName')), 'string');
        $p->updateSetting($c, 'signatoryTitle', trim((string) $this->getData('signatoryTitle')), 'string');
        $p->updateSetting($c, 'certificatePrefix', CertificateNumber::normalisePrefix((string) $this->getData('certificatePrefix')), 'string');
        $p->updateSetting($c, 'emailSubject', trim((string) $this->getData('emailSubject')), 'string');
        $p->updateSetting($c, 'emailBody', trim((string) $this->getData('emailBody')), 'string');
        $p->updateSetting($c, 'downloadLinkDays', max(1, min(3650, (int) $this->getData('downloadLinkDays') ?: 180)), 'int');
        $p->updateSetting($c, 'autoRevise', (bool) $this->getData('autoRevise'), 'bool');

        if ($this->getData('removeSignature')) {
            $p->updateSetting($c, 'signatureImage', '', 'string');
        }
        $this->saveSignature((string) $this->getData('signatureData'));

        // If certificates were held because the configuration was incomplete, release them now.
        $service = new CertificateService($p);
        if (!$service->missingConfiguration($service->config($c), app()->get('context')->get($c))) {
            $service->dispatchUnprocessed($c);
        }

        parent::execute(...$functionArgs);
    }

    /** Accepts a PNG/JPEG data URL (read in the browser), verifies it really is an image, and stores it privately. */
    private function saveSignature(string $dataUrl): void
    {
        if (!preg_match('#^data:image/(?:png|jpeg);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            return;
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || strlen($bytes) > 1048576) {
            return;
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $info[0] > 3000 || $info[1] > 3000) {
            return;
        }
        $path = (new CertificateStorage())->writeSignature($this->contextId, $bytes, $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg');
        $this->plugin->updateSetting($this->contextId, 'signatureImage', $path, 'string');
    }
}
