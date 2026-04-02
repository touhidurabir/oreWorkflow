<?php

/**
 * @file plugins/generic/oreWorkflow/jobs/SendAuthorFileUploadNotification.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SendAuthorFileUploadNotification
 *
 * @brief Deferred job to notify editors when an author uploads a file at the submission stage.
 */

namespace APP\plugins\generic\oreWorkflow\jobs;

use APP\facades\Repo;
use APP\plugins\generic\oreWorkflow\mailables\AuthorSubmissionFileNotify;
use Illuminate\Support\Facades\Mail;
use PKP\jobs\BaseJob;
use PKP\log\SubmissionEmailLogEventType;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;

class SendAuthorFileUploadNotification extends BaseJob
{
    public function __construct(
        public int $contextId,
        public int $submissionFileId,
        public int $expectedFileId,
        public bool $isRevision
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        // Check file still exists — if wizard was cancelled (new upload), the row is deleted
        $submissionFile = Repo::submissionFile()->get($this->submissionFileId);
        if (!$submissionFile) {
            return;
        }

        // Check fileId still matches — if wizard was cancelled (revision), the fileId is reverted
        if ($submissionFile->getData('fileId') != $this->expectedFileId) {
            return;
        }

        $uploaderUserId = $submissionFile->getData('uploaderUserId');
        $submissionId = $submissionFile->getData('submissionId');

        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            return;
        }

        $context = app()->get('context')->get($this->contextId);
        if (!$context) {
            return;
        }

        $uploader = Repo::user()->get($uploaderUserId);
        if (!$uploader) {
            return;
        }

        // Get editors (managers + sub-editors) assigned to the submission stage
        $editorAssignments = StageAssignment::query()
            ->withSubmissionIds([$submissionId])
            ->withRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])
            ->withStageIds([WORKFLOW_STAGE_ID_SUBMISSION])
            ->get();

        $recipients = [];
        foreach ($editorAssignments as $assignment) {
            $editor = Repo::user()->get($assignment->userId);
            if ($editor && !isset($recipients[$editor->getId()])) {
                $recipients[$editor->getId()] = $editor;
            }
        }

        if (empty($recipients)) {
            return;
        }

        $fileName = $submissionFile->getLocalizedData('name');
        if (!$fileName) {
            $fileName = $submissionFile->getData('name', $context->getPrimaryLocale());
        }

        $mailable = new AuthorSubmissionFileNotify($context, $submission, $uploader, $fileName ?? '', $this->isRevision);
        $template = Repo::emailTemplate()->getByKey($context->getId(), AuthorSubmissionFileNotify::getEmailTemplateKey());

        if (!$template) {
            return;
        }

        $mailable
            ->from($context->getData('contactEmail'), $context->getData('contactName'))
            ->recipients(array_values($recipients))
            ->subject($template->getLocalizedData('subject'))
            ->body($template->getLocalizedData('body'))
            ->replyTo($context->getData('contactEmail'), $context->getData('contactName'));

        Mail::send($mailable);

        // Log the email in submission email log
        Repo::emailLogEntry()->logMailable(
            SubmissionEmailLogEventType::AUTHOR_NOTIFY_REVISED_VERSION,
            $mailable,
            $submission,
            $uploader
        );
    }
}
