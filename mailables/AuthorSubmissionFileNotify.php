<?php

/**
 * @file plugins/generic/oreWorkflow/mailables/AuthorSubmissionFileNotify.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AuthorSubmissionFileNotify
 *
 * @brief Email sent to editors when an author uploads a file at the submission stage
 */

namespace APP\plugins\generic\oreWorkflow\mailables;

use APP\submission\Submission;
use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;
use PKP\user\User;

class AuthorSubmissionFileNotify extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'plugins.generic.oreWorkflow.authorSubmissionFileNotify.name';
    protected static ?string $description = 'plugins.generic.oreWorkflow.authorSubmissionFileNotify.description';
    protected static ?string $emailTemplateKey = 'ORE_AUTHOR_SUBMISSION_FILE_NOTIFY';
    protected static bool $supportsTemplates = false;
    protected static array $groupIds = [self::GROUP_SUBMISSION];
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];

    protected const AUTHOR_NAME = 'authorName';
    protected const FILE_NAME = 'fileName';
    protected const UPLOAD_TYPE = 'uploadType';

    public function __construct(Context $context, Submission $submission, User $uploader, string $fileName, bool $isRevision = false)
    {
        parent::__construct([$context, $submission]);
        $this->addData([
            static::AUTHOR_NAME => $uploader->getFullName(),
            static::FILE_NAME => $fileName,
            static::UPLOAD_TYPE => $isRevision
                ? __('plugins.generic.oreWorkflow.emailVariable.uploadType.revision')
                : __('plugins.generic.oreWorkflow.emailVariable.uploadType.new'),
        ]);
    }

    public static function getDataDescriptions(): array
    {
        return array_merge(
            parent::getDataDescriptions(),
            [
                static::AUTHOR_NAME => __('plugins.generic.oreWorkflow.emailVariable.authorName'),
                static::FILE_NAME => __('plugins.generic.oreWorkflow.emailVariable.fileName'),
                static::UPLOAD_TYPE => __('plugins.generic.oreWorkflow.emailVariable.uploadType'),
            ]
        );
    }
}
