/**
 * @file plugins/generic/oreWorkflow/resources/js/main.js
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * ORE Workflow Plugin - Author File Upload Extension
 *
 * Extends fileManager_SUBMISSION_FILES getTopItems to add an upload button
 * for authors on the author dashboard. The button calls the existing
 * fileStore.fileUpload() method which opens the FileUploadWizard
 * (genre selection + file upload + metadata editing).
 *
 * Backend authorization is handled by a hook in SubmissionFileStageAccessPolicy::effect()
 * registered in OreWorkflowPlugin.php, which allows authors to upload to
 * SUBMISSION_FILE_SUBMISSION stage regardless of submissionProgress.
 *
 * NOTE: We extend getTopItems (not getManagerConfig) because managerConfig
 * is a computed property that evaluates early during store setup (forced by
 * an {immediate: true} watcher). This happens BEFORE Pinia plugin extensions
 * (storeExtend) run, so extendFn('getManagerConfig') would never take effect.
 * getTopItems is lazy — evaluated only on template render, after extensions
 * are registered.
 */

/**
 * Extend the fileManager store to add upload button for authors
 *
 * @param {Object} piniaContext - Pinia store context from pkp.registry.storeExtend
 */
function runOreAuthorUpload(piniaContext) {
	// Only for author dashboard
	const dashboardStore = pkp.registry.getPiniaStore('dashboard');
	if (dashboardStore.dashboardPage !== 'mySubmissions') {
		return;
	}

	const {useCurrentUser} = pkp.modules.useCurrentUser;
	const {useLocalize} = pkp.modules.useLocalize;
	const {hasCurrentUserAtLeastOneAssignedRoleInStage} = useCurrentUser();
	const {t} = useLocalize();

	const fileStore = piniaContext.store;
	const {submission, submissionStageId} = fileStore.props;

	// Check if user has eligible role on this submission at this stage
	// FIXME: 	should it be only author role or anyone can that access author dashbaord
	// 			e.g. ADMIN, JM, EDITOR and AUTHOR ?
	if (
		!hasCurrentUserAtLeastOneAssignedRoleInStage(
			submission,
			submissionStageId,
			[
				pkp.const.ROLE_ID_SITE_ADMIN,
				pkp.const.ROLE_ID_MANAGER,
				pkp.const.ROLE_ID_SUB_EDITOR,
				pkp.const.ROLE_ID_AUTHOR,
			],
		)
	) {
		return;
	}

	// Extend getTopItems to add upload button (only if standard upload not already permitted)
	// Uses 'fileUpload' action which calls the existing fileStore.fileUpload() method
	// that opens the FileUploadWizard legacy modal
	fileStore.extender.extendFn('getTopItems', (originalItems, args) => {
		const permittedActions = args.managerConfig?.permittedActions || [];
		if (permittedActions.includes('fileUpload')) {
			return originalItems; // Standard upload already available
		}
		return [
			...originalItems,
			{
				component: 'FileManagerActionButton',
				props: {
					label: t('common.upload'),
					action: 'fileUpload',
				},
			},
		];
	});
}

// Register store extension for SUBMISSION_FILES file manager only
pkp.registry.storeExtend('fileManager_SUBMISSION_FILES', (piniaContext) => {
	runOreAuthorUpload(piniaContext);
});
