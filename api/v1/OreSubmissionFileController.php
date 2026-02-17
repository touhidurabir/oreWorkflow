<?php

/**
 * @file plugins/generic/oreWorkflow/api/v1/OreSubmissionFileController.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OreSubmissionFileController
 *
 * @brief Custom API controller for author file uploads with relaxed authorization.
 */

namespace APP\plugins\generic\oreWorkflow\api\v1;

use APP\core\Application;
use APP\submission\Submission;
use APP\facades\Repo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\core\PKPBaseController;
use PKP\context\Context;
use PKP\core\PKPRequest;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\file\TemporaryFileManager;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\SubmissionAccessPolicy;
use PKP\security\authorization\UserRolesRequiredPolicy;
use PKP\security\Role;
use PKP\submissionFile\SubmissionFile;

class OreSubmissionFileController extends PKPBaseController
{
    /**
     * @copydoc \PKP\core\PKPBaseController::getHandlerPath()
     */
    public function getHandlerPath(): string
    {
        return 'ore-author-files';
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::getRouteGroupMiddleware()
     */
    public function getRouteGroupMiddleware(): array
    {
        return [
            'has.user',
            'has.context',
        ];
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::getGroupRoutes()
     */
    public function getGroupRoutes(): void
    {
        Route::middleware([
            self::roleAuthorizer([
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_SUB_EDITOR,
                Role::ROLE_ID_AUTHOR,
            ]),
        ])->group(function () {
            // Get available genres: GET /api/v1/ore-author-files/genres
            Route::get('genres', $this->getGenres(...))
                ->name('ore.authorFiles.genres');

            // Upload new file: POST /api/v1/ore-author-files/{submissionId}
            Route::post('{submissionId}', $this->add(...))
                ->name('ore.authorFiles.add')
                ->whereNumber('submissionId');

            // Upload revision: PUT /api/v1/ore-author-files/{submissionId}/submissionFile/{submissionFileId}
            Route::put('{submissionId}/submissionFile/{submissionFileId}', $this->edit(...))
                ->name('ore.authorFiles.edit')
                ->whereNumber(['submissionId', 'submissionFileId']);

            // Get existing files: GET /api/v1/ore-author-files/{submissionId}/files
            Route::get('{submissionId}/files', $this->getFiles(...))
                ->name('ore.authorFiles.files')
                ->whereNumber('submissionId');
        });
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::authorize()
     */
    public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool
    {
        $illuminateRequest = $args[0]; /** @var \Illuminate\Http\Request $illuminateRequest */
        $actionName = static::getRouteActionName($illuminateRequest);

        $this->addPolicy(new UserRolesRequiredPolicy($request), true);
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));

        if ($actionName !== 'getGenres') {
            $this->addPolicy(new SubmissionAccessPolicy(
                $request,
                $args,
                $roleAssignments,
                'submissionId'
            ));
        }

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Upload a new file to submission files stage
     */
    public function add(Request $illuminateRequest): JsonResponse
    {
        $request = $this->getRequest();
        $user = $request->getUser();
        $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION); 

        $context = app()->get('context')->get($submission->getData('contextId')); /** @var Context $context */

        if (empty($_FILES['file'])) {
            return response()->json([
                'error' => __('api.files.400.uploadFailed'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $temporaryFileManager = new TemporaryFileManager();
        $temporaryFile = $temporaryFileManager->handleUpload('file', $user->getId());

        if (!$temporaryFile) {
            return response()->json([
                'error' => __('api.files.400.uploadFailed'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $fileManager = new FileManager();
        $extension = $fileManager->parseFileExtension($temporaryFile->getOriginalFileName());

        $submissionDir = Repo::submissionFile()->getSubmissionDir(
            $context->getId(),
            $submission->getId()
        );

        $fileId = app()->get('file')->add(
            $temporaryFile->getFilePath(),
            $submissionDir . '/' . uniqid() . '.' . $extension
        );

        $params = $illuminateRequest->input() ?? [];
        $params['fileId'] = $fileId;
        $params['submissionId'] = $submission->getId();
        $params['uploaderUserId'] = $user->getId();
        $params['fileStage'] = SubmissionFile::SUBMISSION_FILE_SUBMISSION;
        $params['name'] = [$submission->getData('locale') => $temporaryFile->getOriginalFileName()];

        // Set genre if provided, otherwise use first available non-supplementary genre
        if (empty($params['genreId'])) {
            $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var \PKP\submission\GenreDAO $genreDao */
            $genres = $genreDao->getByContextId($context->getId());
            foreach ($genres->toArray() as $genre) {
                if (!$genre->getSupplementary()) { // Use first non-supplementary genre
                    $params['genreId'] = $genre->getId();
                    break;
                }
            }
        }

        $errors = Repo::submissionFile()->validate(
            null,
            $params,
            $context->getData('supportedSubmissionLocales'),
            $submission->getData('locale')
        );

        if (!empty($errors)) {
            app()->get('file')->delete($fileId);
            return response()->json([
                'error' => $errors,
            ], Response::HTTP_BAD_REQUEST);
        }

        $submissionFile = Repo::submissionFile()->newDataObject($params);
        $submissionFileId = Repo::submissionFile()->add($submissionFile);
        $submissionFile = Repo::submissionFile()->get($submissionFileId);

        return response()->json(
            Repo::submissionFile()
                ->getSchemaMap($submission, $this->getFileGenres($context))
                ->map($submissionFile),
            Response::HTTP_OK
        );
    }

    /**
     * Upload a revision of an existing file
     */
    public function edit(Request $illuminateRequest): JsonResponse
    {
        $request = $this->getRequest();
        $user = $request->getUser();
        $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION); /** @var Submission $submission */
        $context = app()->get('context')->get($submission->getData('contextId')); /** @var Context $context */
        $submissionFile = Repo::submissionFile()->get((int) $illuminateRequest->route('submissionFileId'), $submission->getId());

        if (!$submissionFile) {
            return response()->json([
                'error' => __('api.404.resourceNotFound'),
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify file is in submission files stage (security: only allow this stage)
        if ($submissionFile->getData('fileStage') !== SubmissionFile::SUBMISSION_FILE_SUBMISSION) {
            return response()->json([
                'error' => __('plugins.generic.oreWorkflow.error.wrongStage'),
            ], Response::HTTP_FORBIDDEN);
        }

        if (empty($_FILES['file'])) {
            return response()->json([
                'error' => __('api.files.400.uploadFailed'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $temporaryFileManager = new TemporaryFileManager();
        $temporaryFile = $temporaryFileManager->handleUpload('file', $user->getId());

        if (!$temporaryFile) {
            return response()->json([
                'error' => __('api.files.400.uploadFailed'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $fileManager = new FileManager();
        $extension = $fileManager->parseFileExtension($temporaryFile->getOriginalFileName());

        $submissionDir = Repo::submissionFile()->getSubmissionDir(
            $context->getId(),
            $submission->getId()
        );

        $fileId = app()->get('file')->add(
            $temporaryFile->getFilePath(),
            $submissionDir . '/' . uniqid() . '.' . $extension
        );

        Repo::submissionFile()->edit(
            $submissionFile,
            [
                'fileId' => $fileId,
                'uploaderUserId' => $user->getId(),
                'name' => [$submission->getData('locale') => $temporaryFile->getOriginalFileName()],
            ]
        );

        $submissionFile = Repo::submissionFile()->get($submissionFile->getId());

        return response()->json(
            Repo::submissionFile()
                ->getSchemaMap($submission, $this->getFileGenres($context))
                ->map($submissionFile),
            Response::HTTP_OK
        );
    }

    /**
     * Get existing submission files for revision selection
     * Returns files in SUBMISSION_FILE_SUBMISSION stage for the given submission.
     */
    public function getFiles(Request $illuminateRequest): JsonResponse
    {
        $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
        $context = app()->get('context')->get($submission->getData('contextId'));

        $files = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_SUBMISSION])
            ->getMany();

        $result = [];
        $genres = $this->getFileGenres($context);

        foreach ($files as $file) {
            $genre = $genres[$file->getData('genreId')] ?? null;
            $result[] = [
                'id' => $file->getId(),
                'name' => $file->getLocalizedData('name'),
                'genreId' => $file->getData('genreId'),
                'genreName' => $genre ? $genre->getLocalizedName() : '',
            ];
        }

        return response()->json($result, Response::HTTP_OK);
    }

    /**
     * Get available genres for file upload
     */
    public function getGenres(Request $illuminateRequest): JsonResponse
    {
        $context = $this->getRequest()->getContext();

        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var \PKP\submission\GenreDAO $genreDao */
        $genres = $genreDao->getEnabledByContextId($context->getId());

        $result = [];
        foreach ($genres->toArray() as $genre) {
            // Exclude dependent genres (require parent file association)
            if (!$genre->getDependent()) {
                $result[] = [
                    'id' => $genre->getId(),
                    'name' => $genre->getLocalizedName(),
                    'isSupplementary' => $genre->getSupplementary(),
                ];
            }
        }

        return response()->json($result, Response::HTTP_OK);
    }

    /**
     * Get the file genres for the current context
     */
    protected function getFileGenres(Context $context): array
    {
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var \PKP\submission\GenreDAO $genreDao */
        return $genreDao->getByContextId($context->getId())->toAssociativeArray();
    }
}
