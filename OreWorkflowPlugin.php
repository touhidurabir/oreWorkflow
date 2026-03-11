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
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\PKPPageRouter;
use PKP\core\PKPRequest;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\authorization\PolicySet;
use PKP\security\authorization\SubmissionFileAccessPolicy;
use PKP\security\authorization\internal\SubmissionFileStageRequiredPolicy;
use PKP\security\Role;
use PKP\submissionFile\SubmissionFile;

class OreWorkflowPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if (!$success) {
            return $success;
        }

        if (!$this->getEnabled($mainContextId)) {
            return $success;
        }

        $request = Application::get()->getRequest();
        $context = $mainContextId ? app()->get('context')->get($mainContextId) : $request->getContext();
        $user = $request->getUser();

        // If user does not have Admin, JM, Editor or Author role, skip frontend scripts
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

        // FIXME : Anyway to make this only available for author dashbaords ? Not Sure !!
        // Register authorization hooks for all request types (API + page loads)
        $this->registerAuthorizationHook(); // Stage-level — allows authors to upload NEW files to SUBMISSION_FILE_SUBMISSION
        $this->registerFileAccessHook(); // File-level — allows authors to revise ANY file at SUBMISSION_FILE_SUBMISSION stage

        $router = $request->getRouter();

        if ($router instanceof PKPPageRouter) {
            if ($router->getRequestedPage($request) !== 'dashboard') {
                return $success;
            }

            if ($router->getRequestedOp($request) !== 'mySubmissions') {
                return $success;
            }

            // we only need to register the front end script for dashboard to have 
            // the upload option visible form author dashbaord
            $this->registerFrontendScripts();
        }

        return $success;
    }

    /**
     * Register hook listener for SubmissionFileStageAccessPolicy::effect
     *
     * Allows authors to upload to SUBMISSION_FILE_SUBMISSION stage
     * regardless of submissionProgress. The hook fires after all standard
     * authorization checks but before the final permit/deny decision,
     * so it can only expand access (add file stages), never restrict it.
     *
     * This enables:
     * - FileUploadWizard (legacy modal) to work for authors
     * - REST API (PKPSubmissionFileController) to work for authors
     */
    protected function registerAuthorizationHook(): void
    {
        Hook::add('SubmissionFileStageAccessPolicy::effect', function (
            string $hookName,
            Submission $submission,
            array $userRoles,
            array $stageAssignments,
            int $fileStage,
            int $action,
            array &$assignedFileStages
        ): bool {

            // Only modify SUBMISSION_FILE_SUBMISSION stage with MODIFY action
            if ($fileStage !== SubmissionFile::SUBMISSION_FILE_SUBMISSION
                || $action !== SubmissionFileAccessPolicy::SUBMISSION_FILE_ACCESS_MODIFY) {
                return Hook::CONTINUE;
            }

            // Only if user has author role in submission stage
            // FIXME : should it be only author role or anyone can that access author dashbaord 
	        // 		   e.g. ADMIN, JM, EDITOR and AUTHOR ?
            if (empty($stageAssignments[WORKFLOW_STAGE_ID_SUBMISSION])
                || !in_array(Role::ROLE_ID_AUTHOR, $stageAssignments[WORKFLOW_STAGE_ID_SUBMISSION])) {
                return Hook::CONTINUE;
            }

            // Allow authors to upload to submission files stage (bypasses submissionProgress check)
            if (!in_array(SubmissionFile::SUBMISSION_FILE_SUBMISSION, $assignedFileStages)) {
                $assignedFileStages[] = SubmissionFile::SUBMISSION_FILE_SUBMISSION;
            }

            return Hook::CONTINUE;
        });
    }

    /**
     * Register hook listener for SubmissionFileAccessPolicy::authorFileAccess
     *
     * Allows authors to revise ANY file at the SUBMISSION_FILE_SUBMISSION
     * stage, regardless of who originally uploaded it. Without this, authors can
     * only revise files they uploaded themselves (SubmissionFileUploaderAccessPolicy
     * checks uploaderUserId == currentUser).
     *
     * The hook adds a SubmissionFileStageRequiredPolicy to the author's inner
     * PERMIT_OVERRIDES PolicySet, providing an alternative authorization path:
     * "allow MODIFY if the file is at submission stage."
     */
    protected function registerFileAccessHook(): void
    {
        Hook::add('SubmissionFileAccessPolicy::authorFileAccess', function (
            string $hookName,
            PKPRequest $request,
            int $mode,
            int $submissionFileId,
            PolicySet &$authorFileAccessOptionsPolicy
        ): bool {

            // Only for MODIFY access
            if (!($mode & SubmissionFileAccessPolicy::SUBMISSION_FILE_ACCESS_MODIFY)) {
                return Hook::CONTINUE;
            }

            // Allow authors to modify any file at SUBMISSION_FILE_SUBMISSION stage
            // (regardless of who originally uploaded it)
            $authorFileAccessOptionsPolicy->addPolicy(
                new SubmissionFileStageRequiredPolicy($request, $submissionFileId, SubmissionFile::SUBMISSION_FILE_SUBMISSION)
            );

            return Hook::CONTINUE;
        });
    }

    /**
     * Register frontend JavaScript for store extension
     */
    protected function registerFrontendScripts(): void
    {
        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);

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
