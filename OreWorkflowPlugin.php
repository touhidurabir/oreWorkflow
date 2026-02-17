<?php

/**
 * @file plugins/generic/oreWorkflow/OreWorkflowPlugin.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OreWorkflowPlugin
 *
 * @brief ORE Workflow plugin - extends workflow capabilities including
 *        allowing authors role to upload files at submission workflow stage.
 */

namespace APP\plugins\generic\oreWorkflow;

use APP\core\Application;
use APP\plugins\generic\oreWorkflow\api\v1\OreSubmissionFileController;
use APP\template\TemplateManager;
use PKP\core\APIRouter;
use PKP\security\Role;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class OreWorkflowPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        $this->addLocaleData();

        if (!$success) {
            return false;
        }

        if (!$this->getEnabled($mainContextId)) {
            return $success;
        }

        $request = Application::get()->getRequest(); 
        $router = $request->getRouter();
        
        // Restrict only for author dashboard
        if ($router
            && $router instanceof \PKP\core\PKPPageRouter
            
            // For page load, will be only available for author dashbaord
            // as check of PKP\pages\dashboard\DashboardPage::MySubmissions which is set to `mySubmissions`
            // But unable to use it directly as it's not being properly namespace and not available
            // immediately before the load of PKP\pages\dashboard\PKPDashboardHandler
            && $router->getRequestedOp($request) !== 'mySubmissions'
        ) { 
            return $success;
        }

        $context = $mainContextId ? app()->get('context')->get($mainContextId) : $request->getContext();
        $user = $request->getUser();
        
        // if user do not have Admin, JM, Editor or Author role, do not have access to ore workflow
        if (!$user
            || !$user->hasRole([
                Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SUB_EDITOR,
                Role::ROLE_ID_AUTHOR
            ], $context->getId())
        ) {
            return $success;
        }

        $this->registerPluginApiControllers();
        $this->registerFrontendScripts();

        return $success;
    }

    /**
     * Register custom API controllers using APIHandler::endpoints::plugin hook
     */
    protected function registerPluginApiControllers(): void
    {
        Hook::add('APIHandler::endpoints::plugin', function (string $hookName, APIRouter $apiRouter): bool {
            $apiRouter->registerPluginApiControllers([
                new OreSubmissionFileController(),
            ]);

            return Hook::CONTINUE;
        });
    }

    /**
     * Register frontend JavaScript and CSS for store extension
     */
    protected function registerFrontendScripts(): void
    {
        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);

        // Add CSS stylesheet
        $templateMgr->addStyleSheet(
            'OreWorkflowStyles',
            "{$request->getBaseUrl()}/{$this->getPluginPath()}/public/build/build.css",
            [
                'contexts' => ['backend'],
                'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
            ]
        );

        // Add JavaScript
        $templateMgr->addJavaScript(
            'OreWorkflowAuthorUpload',
            "{$request->getBaseUrl()}/{$this->getPluginPath()}/public/build/build.iife.js",
            [
                'inline' => false,
                'contexts' => ['backend'],
                'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
            ]
        );
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.oreWorkflow.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.oreWorkflow.description');
    }
}
