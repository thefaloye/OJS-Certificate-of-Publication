<?php

/**
 * BIJPHGR Publication Certificate and Notification Plugin
 *
 * Issues a PDF Certificate of Publication when an article becomes publicly
 * available and e-mails it to the corresponding author.
 */

namespace APP\plugins\generic\bijphgrPublicationCertificate;

use APP\plugins\generic\bijphgrPublicationCertificate\classes\form\SettingsForm;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\migration\Install;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\pages\CertificateDashboardHandler;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\pages\CertificatePageHandler;
use APP\plugins\generic\bijphgrPublicationCertificate\classes\PublicationListener;
use Illuminate\Support\Facades\Event;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RedirectAction;
use PKP\security\Role;
use APP\template\TemplateManager;
use PKP\observers\events\MetadataChanged;
use PKP\observers\events\PublicationPublished;
use PKP\observers\events\PublicationUnpublished;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class BijphgrPublicationCertificatePlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }

        // The listeners are attached whenever the plugin is loaded (also in CLI and
        // scheduled-task requests, where there is no "current journal"). Each handler
        // checks the plugin's per-journal "enabled" flag itself.
        $listener = new PublicationListener($this);
        Event::listen(PublicationPublished::class, [$listener, 'onPublished']);
        Event::listen(PublicationUnpublished::class, [$listener, 'onUnpublished']);
        Event::listen(MetadataChanged::class, [$listener, 'onMetadataChanged']);

        if ($this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', [$this, 'setPageHandler']);
            Hook::add('TemplateManager::setupBackendPage', [$this, 'addDashboardMenuItem']);
        }

        return true;
    }

    public function getName()
    {
        return 'bijphgrPublicationCertificatePlugin';
    }

    public function getDisplayName()
    {
        return __('plugins.generic.bijphgrPublicationCertificate.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.bijphgrPublicationCertificate.description');
    }

    public function getInstallMigration()
    {
        return new Install();
    }

    /**
     * Serve /{journal}/bijphgrCertificate/verify|download/{token}.
     *
     * @hook LoadHandler [[&$page, &$op, &$sourceFile, &$handler]]
     */
    public function setPageHandler(string $hookName, array $args): bool
    {
        $page = &$args[0];
        $handler = &$args[3];
        if ($page === 'bijphgrCertificate') {
            $handler = new CertificatePageHandler($this);
            return true;
        }
        if ($page === 'bijphgrCertificates') {
            $handler = new CertificateDashboardHandler($this);
            return true;
        }
        return false;
    }

    /**
     * Adds "Publication Certificates" to the editorial sidebar, for managers,
     * administrators and editors only. Never allowed to break the backend.
     *
     * @hook TemplateManager::setupBackendPage []
     */
    public function addDashboardMenuItem(string $hookName, array $args = []): bool
    {
        try {
            $request = \APP\core\Application::get()->getRequest();
            $user = $request->getUser();
            $context = $request->getContext();
            if (!$user || !$context) {
                return false;
            }
            if (!$user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR], $context->getId())) {
                return false;
            }
            $templateMgr = TemplateManager::getManager($request);
            $menu = $templateMgr->getState('menu');
            if (!is_array($menu) || isset($menu['bijphgrCertificates'])) {
                return false;
            }
            $item = [
                'name' => __('plugins.generic.bijphgrPublicationCertificate.dash.menu'),
                'url' => $request->getDispatcher()->url($request, \APP\core\Application::ROUTE_PAGE, null, 'bijphgrCertificates'),
                'isCurrent' => $request->getRequestedPage() === 'bijphgrCertificates',
                'icon' => 'Tools',
            ];
            // Place it just before "Tools" when that item exists.
            $new = [];
            foreach ($menu as $key => $value) {
                if ($key === 'tools') {
                    $new['bijphgrCertificates'] = $item;
                }
                $new[$key] = $value;
            }
            if (!isset($new['bijphgrCertificates'])) {
                $new['bijphgrCertificates'] = $item;
            }
            $templateMgr->setState(['menu' => $new]);
        } catch (\Throwable $e) {
            error_log('[bijphgrPublicationCertificate] menu item failed: ' . $e->getMessage());
        }
        return false;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'certificates',
            new RedirectAction($request->getDispatcher()->url($request, \APP\core\Application::ROUTE_PAGE, null, 'bijphgrCertificates')),
            __('plugins.generic.bijphgrPublicationCertificate.dash.menu'),
            null
        ));
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, array_merge($actionArgs, ['verb' => 'settings'])),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));
        return $actions;
    }

    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') === 'settings') {
            $context = $request->getContext();
            $form = new SettingsForm($this, $context->getId());

            if ($request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true);
                }
            } else {
                $form->initData();
            }
            return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }
}
