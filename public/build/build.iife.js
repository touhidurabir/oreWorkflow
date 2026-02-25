(function() {
  "use strict";
  function runOreAuthorUpload(piniaContext) {
    const dashboardStore = pkp.registry.getPiniaStore("dashboard");
    if (dashboardStore.dashboardPage !== "mySubmissions") {
      return;
    }
    const { useCurrentUser } = pkp.modules.useCurrentUser;
    const { useLocalize } = pkp.modules.useLocalize;
    const { hasCurrentUserAtLeastOneAssignedRoleInStage } = useCurrentUser();
    const { t } = useLocalize();
    const fileStore = piniaContext.store;
    const { submission, submissionStageId } = fileStore.props;
    if (!hasCurrentUserAtLeastOneAssignedRoleInStage(
      submission,
      submissionStageId,
      [
        pkp.const.ROLE_ID_SITE_ADMIN,
        pkp.const.ROLE_ID_MANAGER,
        pkp.const.ROLE_ID_SUB_EDITOR,
        pkp.const.ROLE_ID_AUTHOR
      ]
    )) {
      return;
    }
    fileStore.extender.extendFn("getTopItems", (originalItems, args) => {
      var _a;
      const permittedActions = ((_a = args.managerConfig) == null ? void 0 : _a.permittedActions) || [];
      if (permittedActions.includes("fileUpload")) {
        return originalItems;
      }
      return [
        ...originalItems,
        {
          component: "FileManagerActionButton",
          props: {
            label: t("common.upload"),
            action: "fileUpload"
          }
        }
      ];
    });
  }
  pkp.registry.storeExtend("fileManager_SUBMISSION_FILES", (piniaContext) => {
    runOreAuthorUpload(piniaContext);
  });
})();
