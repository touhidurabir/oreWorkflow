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
use APP\facades\Repo;
use APP\plugins\generic\oreWorkflow\jobs\SendAuthorFileUploadNotification;
use APP\plugins\generic\oreWorkflow\mailables\AuthorSubmissionFileNotify;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\PKPPageRouter;
use PKP\core\PKPRequest;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\authorization\internal\SubmissionFileStageRequiredPolicy;
use PKP\security\authorization\PolicySet;
use PKP\security\authorization\SubmissionFileAccessPolicy;
use PKP\security\Role;
use PKP\submissionFile\SubmissionFile;

class OreWorkflowPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
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

        // If user does not have Admin, JM, Editor or Author role, skip hooks registration
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

        // Register mailable for admin email template management UI
        Hook::add('Mailer::Mailables', $this->addMailable(...));

        // Register file upload notification hooks
        Hook::add('SubmissionFile::add', $this->onSubmissionFileAdd(...));
        Hook::add('SubmissionFile::edit', $this->onSubmissionFileEdit(...));

        // FIXME : Anyway to make this only available for author dashbaords ? Not Sure !!
        // Register authorization hooks for all request types (API + page loads)
        Hook::add('SubmissionFileStageAccessPolicy::effect', $this->onStageAccessPolicy(...));
        Hook::add('SubmissionFileAccessPolicy::authorFileAccess', $this->onAuthorFileAccess(...));

        $router = $request->getRouter();

        if ($router instanceof PKPPageRouter) {
            if ($router->getRequestedPage($request) != 'dashboard') {
                return $success;
            }

            if ($router->getRequestedOp($request) != 'mySubmissions') {
                return $success;
            }

            // Register frontend script for author dashboard upload button
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

        return $success;
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

    /**
     * @copydoc Plugin::getInstallEmailTemplatesFile()
     */
    public function getInstallEmailTemplatesFile()
    {
        return "{$this->getPluginPath()}/emailTemplates.xml";
    }

    /**
     * Allow authors to upload to SUBMISSION_FILE_SUBMISSION stage regardless of submissionProgress.
     *
     * The hook fires after all standard authorization checks but before the final
     * permit/deny decision, so it can only expand access (add file stages), never restrict it.
     * This enables FileUploadWizard (legacy modal) and REST API to work for authors.
     */
    public function onStageAccessPolicy(
        string $hookName,
        Submission $submission,
        array $userRoles,
        array $stageAssignments,
        int $fileStage,
        int $action,
        array &$assignedFileStages
    ): bool {

        // Only modify SUBMISSION_FILE_SUBMISSION stage with MODIFY action
        if ($fileStage != SubmissionFile::SUBMISSION_FILE_SUBMISSION
            || $action != SubmissionFileAccessPolicy::SUBMISSION_FILE_ACCESS_MODIFY) {
            return Hook::CONTINUE;
        }

        // Only if user has author role in submission stage
        // FIXME : should it be only author role or anyone can that access author dashbaord
        //         e.g. ADMIN, JM, EDITOR and AUTHOR ?
        if (empty($stageAssignments[WORKFLOW_STAGE_ID_SUBMISSION])
            || !in_array(Role::ROLE_ID_AUTHOR, $stageAssignments[WORKFLOW_STAGE_ID_SUBMISSION])) {
            return Hook::CONTINUE;
        }

        // Allow authors to upload to submission files stage (bypasses submissionProgress check)
        if (!in_array(SubmissionFile::SUBMISSION_FILE_SUBMISSION, $assignedFileStages)) {
            $assignedFileStages[] = SubmissionFile::SUBMISSION_FILE_SUBMISSION;
        }

        return Hook::CONTINUE;
    }

    /**
     * Allow authors to revise ANY file at SUBMISSION_FILE_SUBMISSION stage,
     * regardless of who originally uploaded it.
     *
     * Without this, authors can only revise files they uploaded themselves
     * (SubmissionFileUploaderAccessPolicy checks uploaderUserId == currentUser).
     * Adds a SubmissionFileStageRequiredPolicy to the author's inner
     * PERMIT_OVERRIDES PolicySet as an alternative authorization path.
     */
    public function onAuthorFileAccess(
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
    }

    /**
     * Register the plugin's mailable with the mailable collection
     */
    public function addMailable(string $hookName, array $args): bool
    {
        $mailableCollections = $args[0]; /** @var \Illuminate\Support\Collection $mailableCollections */
        $mailableCollections->push(AuthorSubmissionFileNotify::class);

        return Hook::CONTINUE;
    }

    /**
     * Handle new file upload at submission stage — dispatch deferred notification job
     */
    public function onSubmissionFileAdd(string $hookName, array $args): bool
    {
        $submissionFile = $args[0]; /** @var SubmissionFile $submissionFile */

        if ($submissionFile->getData('fileStage') != SubmissionFile::SUBMISSION_FILE_SUBMISSION) {
            return Hook::CONTINUE;
        }

        $this->dispatchNotificationJob($submissionFile);

        return Hook::CONTINUE;
    }

    /**
     * Handle file revision at submission stage (fileId changed = new file uploaded)
     */
    public function onSubmissionFileEdit(string $hookName, array $args): bool
    {
        $newSubmissionFile = $args[0]; /** @var SubmissionFile $newSubmissionFile */
        $submissionFile = $args[1]; /** @var SubmissionFile $submissionFile */
        $params = $args[2]; /** @var array $params */

        if ($submissionFile->getData('fileStage') != SubmissionFile::SUBMISSION_FILE_SUBMISSION) {
            return Hook::CONTINUE;
        }

        // Only notify when a new file is uploaded (revision), not metadata edits
        if (empty($params['fileId']) || $params['fileId'] == $submissionFile->getData('fileId')) {
            return Hook::CONTINUE;
        }

        // Skip if fileId already exists in revision history — this is a revert (cancel), not a new upload.
        // The hook fires BEFORE DAO::update() creates the revision record, so a genuine new upload's
        // fileId won't be in the history yet, but a cancelled revision's reverted fileId will be.
        $revisions = Repo::submissionFile()->getRevisions($submissionFile->getId());
        $existingRevisionFileIds = $revisions->map(fn ($r) => $r->fileId)->all();
        if (in_array($params['fileId'], $existingRevisionFileIds)) {
            return Hook::CONTINUE;
        }

        $this->dispatchNotificationJob($newSubmissionFile);

        return Hook::CONTINUE;
    }

    /**
     * Dispatch a deferred notification job.
     *
     * The 60-second delay ensures the user has time to complete or cancel the
     * FileUploadWizard. The job checks if the file still exists before sending —
     * if the wizard was cancelled, the file will have been deleted and no email is sent.
     */
    protected function dispatchNotificationJob(SubmissionFile $submissionFile): void
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();

        SendAuthorFileUploadNotification::dispatch(
            $context->getId(),
            $submissionFile->getId(),
            $submissionFile->getData('fileId')
        )->delay(now()->addSeconds(60));
    }
}
