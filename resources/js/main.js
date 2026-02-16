/**
 * @file plugins/generic/oreWorkflow/resources/js/main.js
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * ORE Workflow Plugin - Author File Upload Extension
 *
 * Extends fileManager_SUBMISSION_FILES to allow authors to upload
 * files at any workflow stage, with genre (article component) selection.
 */

import OreAuthorUploadModal from './Components/OreAuthorUploadModal.vue';

pkp.registry.registerComponent('OreAuthorUploadModal', OreAuthorUploadModal);

/**
 * Extend the fileManager store for author uploads
 */
function runOreAuthorUpload(piniaContext) {
	// Only for author dashboard
	const dashboardStore = pkp.registry.getPiniaStore('dashboard');
	if (dashboardStore.dashboardPage !== 'mySubmissions') {
		return;
	}

	const {useLocalize} = pkp.modules.useLocalize;
	const {useModal} = pkp.modules.useModal;
	const {useCurrentUser} = pkp.modules.useCurrentUser;

	const {t} = useLocalize();
	const {openSideModal} = useModal();
	const {hasCurrentUserAtLeastOneAssignedRoleInStage} = useCurrentUser();

	const fileStore = piniaContext.store;
	const {submission, submissionStageId} = fileStore.props;

	// Check if user has author role on this submission at this stage
	// FIXME: 	should it be only author role or anyone can that access author dashbaord 
	// 			e.g. ADMIN, JM, EDITOR and AUTHOR ?
	if (
		!hasCurrentUserAtLeastOneAssignedRoleInStage(submission, submissionStageId, [
			pkp.const.ROLE_ID_SITE_ADMIN,
			pkp.const.ROLE_ID_MANAGER,
			pkp.const.ROLE_ID_SUB_EDITOR,
			pkp.const.ROLE_ID_AUTHOR,
		])
	) {
		return;
	}

	// Add upload method to store - opens SideModal with genre selection + file upload
	fileStore.oreAuthorUpload = function () {
		openSideModal(OreAuthorUploadModal, {
			submissionId: submission.id,
			onSuccess: () => fileStore.fetchFiles(),
		});
	};

	// Extend getTopItems to add upload button (only if standard upload not permitted)
	fileStore.extender.extendFn('getTopItems', (originalItems, args) => {
		const permittedActions = args.managerConfig?.permittedActions || [];
		if (permittedActions.includes('fileUpload')) {
			return originalItems; // Standard upload available, don't add ours
		}

		return [
			...originalItems,
			{
				component: 'FileManagerActionButton',
				props: {
					label: t('common.upload'),
					action: 'oreAuthorUpload',
				},
			},
		];
	});
}

// Register store extension for SUBMISSION_FILES file manager only
pkp.registry.storeExtend('fileManager_SUBMISSION_FILES', (piniaContext) => {
	runOreAuthorUpload(piniaContext);
});
