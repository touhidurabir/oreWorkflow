(function(vue) {
  "use strict";
  const _hoisted_1 = {
    key: 0,
    class: "pkpFormField pkpFormField--select"
  };
  const _hoisted_2 = { class: "pkpFormField__heading" };
  const _hoisted_3 = { class: "pkpFormFieldLabel" };
  const _hoisted_4 = { class: "pkpFormField__control" };
  const _hoisted_5 = ["disabled"];
  const _hoisted_6 = { value: "" };
  const _hoisted_7 = ["value"];
  const _hoisted_8 = { class: "pkpFormField pkpFormField--select oreUpload__genreSelect" };
  const _hoisted_9 = { class: "pkpFormField__heading" };
  const _hoisted_10 = { class: "pkpFormFieldLabel" };
  const _hoisted_11 = { class: "pkpFormField__control" };
  const _hoisted_12 = ["disabled"];
  const _hoisted_13 = {
    value: "",
    disabled: ""
  };
  const _hoisted_14 = ["value"];
  const _hoisted_15 = {
    key: 1,
    class: "oreUpload"
  };
  const _hoisted_16 = { class: "oreUpload__wrapper" };
  const _hoisted_17 = {
    key: 0,
    class: "oreUpload__prompt"
  };
  const _hoisted_18 = {
    key: 1,
    class: "oreUpload__files"
  };
  const _hoisted_19 = {
    key: 1,
    class: "oreUpload__uploadedFile"
  };
  const _hoisted_20 = { class: "oreUpload__fileName" };
  const _hoisted_21 = { class: "oreUpload__actions" };
  const _sfc_main = {
    __name: "OreAuthorUploadModal",
    props: {
      submissionId: { type: Number, required: true },
      onSuccess: { type: Function, default: () => {
      } }
    },
    setup(__props) {
      const props = __props;
      const { useLocalize } = pkp.modules.useLocalize;
      const { useUrl } = pkp.modules.useUrl;
      const { useNotify } = pkp.modules.useNotify;
      const { t, localize } = useLocalize();
      const { notify } = useNotify();
      const closeModal = vue.inject("closeModal");
      const genres = vue.ref([]);
      const existingFiles = vue.ref([]);
      const selectedGenreId = vue.ref("");
      const selectedRevisionFileId = vue.ref("");
      const files = vue.ref([]);
      const uploader = vue.ref(null);
      const isManuallyRemoving = vue.ref(false);
      const { apiUrl: genresApiUrl } = useUrl("ore-author-files/genres");
      const { apiUrl: existingFilesApiUrl } = useUrl(`ore-author-files/${props.submissionId}/files`);
      const isRevision = vue.computed(() => selectedRevisionFileId.value !== "");
      const uploadApiUrl = vue.computed(() => {
        if (isRevision.value) {
          const { apiUrl: apiUrl2 } = useUrl(
            `ore-author-files/${props.submissionId}/submissionFile/${selectedRevisionFileId.value}`
          );
          return apiUrl2.value;
        }
        const { apiUrl } = useUrl(`ore-author-files/${props.submissionId}`);
        return apiUrl.value;
      });
      const queryParams = vue.computed(() => ({
        genreId: selectedGenreId.value
      }));
      const dropzoneOptions = vue.computed(() => {
        const baseOptions = {
          maxFiles: 1,
          maxFilesize: 100
          // MB
        };
        if (isRevision.value) {
          return {
            ...baseOptions,
            headers: {
              "X-Csrf-Token": pkp.currentUser.csrfToken,
              "X-Http-Method-Override": "PUT"
            }
          };
        }
        return baseOptions;
      });
      const hasUploadedFile = vue.computed(
        () => files.value.some((f) => !("progress" in f))
      );
      function getFileName(file) {
        if (file.name && typeof file.name === "object") {
          return localize(file.name);
        }
        return file.name || "";
      }
      function onRevisionFileChange() {
        if (selectedRevisionFileId.value) {
          const file = existingFiles.value.find(
            (f) => f.id === parseInt(selectedRevisionFileId.value)
          );
          if (file) {
            selectedGenreId.value = file.genreId;
          }
        } else {
          selectedGenreId.value = "";
        }
      }
      vue.onMounted(async () => {
        try {
          const response = await fetch(genresApiUrl.value, {
            method: "GET",
            credentials: "same-origin",
            headers: { "X-Csrf-Token": pkp.currentUser.csrfToken }
          });
          if (response.ok) {
            genres.value = await response.json();
          }
        } catch (e) {
          console.error("Failed to fetch genres:", e);
        }
        try {
          const response = await fetch(existingFilesApiUrl.value, {
            method: "GET",
            credentials: "same-origin",
            headers: { "X-Csrf-Token": pkp.currentUser.csrfToken }
          });
          if (response.ok) {
            existingFiles.value = await response.json();
          }
        } catch (e) {
          console.error("Failed to fetch existing files:", e);
        }
      });
      function openFileBrowser() {
        var _a;
        (_a = uploader.value) == null ? void 0 : _a.openFileBrowser();
      }
      function onFilesUpdated(newFiles) {
        if (isManuallyRemoving.value) return;
        files.value = newFiles;
      }
      function cancelUpload(fileId) {
        var _a;
        (_a = uploader.value) == null ? void 0 : _a.cancelUpload(fileId);
      }
      function removeFile(index) {
        var _a, _b, _c;
        isManuallyRemoving.value = true;
        files.value = files.value.filter((_, i) => i !== index);
        if ((_c = (_b = (_a = uploader.value) == null ? void 0 : _a.$refs) == null ? void 0 : _b.dropzone) == null ? void 0 : _c.dropzone) {
          uploader.value.$refs.dropzone.dropzone.removeAllFiles(true);
        }
        vue.nextTick(() => {
          isManuallyRemoving.value = false;
        });
      }
      function handleCancel() {
        selectedRevisionFileId.value = "";
        selectedGenreId.value = "";
        files.value = [];
        closeModal();
      }
      function handleSave() {
        const successMessage = isRevision.value ? t("plugins.generic.oreWorkflow.revisionSuccess") : t("plugins.generic.oreWorkflow.uploadSuccess");
        notify(successMessage, "success");
        props.onSuccess();
        closeModal();
      }
      return (_ctx, _cache) => {
        const _component_PkpButton = vue.resolveComponent("PkpButton");
        const _component_PkpFileUploadProgress = vue.resolveComponent("PkpFileUploadProgress");
        const _component_PkpFileUploader = vue.resolveComponent("PkpFileUploader");
        const _component_PkpSideModalLayoutBasic = vue.resolveComponent("PkpSideModalLayoutBasic");
        const _component_PkpSideModalBody = vue.resolveComponent("PkpSideModalBody");
        return vue.openBlock(), vue.createBlock(_component_PkpSideModalBody, null, {
          title: vue.withCtx(() => [
            vue.createTextVNode(vue.toDisplayString(vue.unref(t)("common.upload.addFile")), 1)
          ]),
          default: vue.withCtx(() => [
            vue.createVNode(_component_PkpSideModalLayoutBasic, null, {
              default: vue.withCtx(() => [
                existingFiles.value.length ? (vue.openBlock(), vue.createElementBlock("div", _hoisted_1, [
                  vue.createElementVNode("div", _hoisted_2, [
                    vue.createElementVNode("label", _hoisted_3, vue.toDisplayString(vue.unref(t)("submission.upload.selectOptionalFileToRevise")), 1)
                  ]),
                  vue.createElementVNode("div", _hoisted_4, [
                    vue.withDirectives(vue.createElementVNode("select", {
                      "onUpdate:modelValue": _cache[0] || (_cache[0] = ($event) => selectedRevisionFileId.value = $event),
                      class: "pkpFormField__input pkpFormField__input--select",
                      disabled: files.value.length > 0,
                      onChange: onRevisionFileChange
                    }, [
                      vue.createElementVNode("option", _hoisted_6, vue.toDisplayString(vue.unref(t)("plugins.generic.oreWorkflow.notARevision")), 1),
                      (vue.openBlock(true), vue.createElementBlock(vue.Fragment, null, vue.renderList(existingFiles.value, (file) => {
                        return vue.openBlock(), vue.createElementBlock("option", {
                          key: file.id,
                          value: file.id
                        }, vue.toDisplayString(file.name), 9, _hoisted_7);
                      }), 128))
                    ], 40, _hoisted_5), [
                      [vue.vModelSelect, selectedRevisionFileId.value]
                    ])
                  ])
                ])) : vue.createCommentVNode("", true),
                vue.createElementVNode("div", _hoisted_8, [
                  vue.createElementVNode("div", _hoisted_9, [
                    vue.createElementVNode("label", _hoisted_10, [
                      vue.createTextVNode(vue.toDisplayString(vue.unref(t)("submission.upload.selectComponent")) + " ", 1),
                      _cache[2] || (_cache[2] = vue.createElementVNode("span", { class: "pkpFormFieldLabel__required" }, "*", -1))
                    ])
                  ]),
                  vue.createElementVNode("div", _hoisted_11, [
                    vue.withDirectives(vue.createElementVNode("select", {
                      "onUpdate:modelValue": _cache[1] || (_cache[1] = ($event) => selectedGenreId.value = $event),
                      class: "pkpFormField__input pkpFormField__input--select",
                      disabled: isRevision.value || files.value.length > 0
                    }, [
                      vue.createElementVNode("option", _hoisted_13, vue.toDisplayString(vue.unref(t)("plugins.generic.oreWorkflow.selectGenre.placeholder")), 1),
                      (vue.openBlock(true), vue.createElementBlock(vue.Fragment, null, vue.renderList(genres.value, (genre) => {
                        return vue.openBlock(), vue.createElementBlock("option", {
                          key: genre.id,
                          value: genre.id
                        }, vue.toDisplayString(genre.name), 9, _hoisted_14);
                      }), 128))
                    ], 8, _hoisted_12), [
                      [vue.vModelSelect, selectedGenreId.value]
                    ])
                  ])
                ]),
                selectedGenreId.value ? (vue.openBlock(), vue.createElementBlock("div", _hoisted_15, [
                  vue.createElementVNode("div", _hoisted_16, [
                    !files.value.length ? (vue.openBlock(), vue.createElementBlock("div", _hoisted_17, [
                      vue.createElementVNode("p", null, vue.toDisplayString(vue.unref(t)("common.upload.dragFile")), 1),
                      vue.createVNode(_component_PkpButton, { onClick: openFileBrowser }, {
                        default: vue.withCtx(() => [
                          vue.createTextVNode(vue.toDisplayString(vue.unref(t)("common.upload.addFile")), 1)
                        ]),
                        _: 1
                      })
                    ])) : (vue.openBlock(), vue.createElementBlock("div", _hoisted_18, [
                      (vue.openBlock(true), vue.createElementBlock(vue.Fragment, null, vue.renderList(files.value, (file, i) => {
                        return vue.openBlock(), vue.createElementBlock(vue.Fragment, {
                          key: file.id || i
                        }, [
                          "progress" in file ? (vue.openBlock(), vue.createBlock(_component_PkpFileUploadProgress, {
                            key: 0,
                            "cancel-upload-label": vue.unref(t)("common.cancel"),
                            errors: file.errors || [],
                            name: file.name,
                            progress: file.progress,
                            onCancel: ($event) => cancelUpload(file.id)
                          }, null, 8, ["cancel-upload-label", "errors", "name", "progress", "onCancel"])) : (vue.openBlock(), vue.createElementBlock("div", _hoisted_19, [
                            vue.createElementVNode("span", _hoisted_20, vue.toDisplayString(getFileName(file)), 1),
                            vue.createVNode(_component_PkpButton, {
                              "is-warnable": true,
                              onClick: ($event) => removeFile(i)
                            }, {
                              default: vue.withCtx(() => [
                                vue.createTextVNode(vue.toDisplayString(vue.unref(t)("common.remove")), 1)
                              ]),
                              _: 1
                            }, 8, ["onClick"])
                          ]))
                        ], 64);
                      }), 128))
                    ])),
                    vue.createVNode(_component_PkpFileUploader, {
                      ref_key: "uploader",
                      ref: uploader,
                      id: "oreAuthorUploader",
                      "api-url": uploadApiUrl.value,
                      files: files.value,
                      "query-params": queryParams.value,
                      options: dropzoneOptions.value,
                      "upload-progress-label": vue.unref(t)("plugins.generic.oreWorkflow.uploadProgress"),
                      "onUpdated:files": onFilesUpdated
                    }, null, 8, ["api-url", "files", "query-params", "options", "upload-progress-label"])
                  ])
                ])) : vue.createCommentVNode("", true),
                vue.createElementVNode("div", _hoisted_21, [
                  vue.createVNode(_component_PkpButton, { onClick: handleCancel }, {
                    default: vue.withCtx(() => [
                      vue.createTextVNode(vue.toDisplayString(vue.unref(t)("common.cancel")), 1)
                    ]),
                    _: 1
                  }),
                  hasUploadedFile.value ? (vue.openBlock(), vue.createBlock(_component_PkpButton, {
                    key: 0,
                    "is-primary": true,
                    onClick: handleSave
                  }, {
                    default: vue.withCtx(() => [
                      vue.createTextVNode(vue.toDisplayString(vue.unref(t)("common.save")), 1)
                    ]),
                    _: 1
                  })) : vue.createCommentVNode("", true)
                ])
              ]),
              _: 1
            })
          ]),
          _: 1
        });
      };
    }
  };
  pkp.registry.registerComponent("OreAuthorUploadModal", _sfc_main);
  function runOreAuthorUpload(piniaContext) {
    const dashboardStore = pkp.registry.getPiniaStore("dashboard");
    if (dashboardStore.dashboardPage !== "mySubmissions") {
      return;
    }
    const { useLocalize } = pkp.modules.useLocalize;
    const { useModal } = pkp.modules.useModal;
    const { useCurrentUser } = pkp.modules.useCurrentUser;
    const { t } = useLocalize();
    const { openSideModal } = useModal();
    const { hasCurrentUserAtLeastOneAssignedRoleInStage } = useCurrentUser();
    const fileStore = piniaContext.store;
    const { submission, submissionStageId } = fileStore.props;
    if (!hasCurrentUserAtLeastOneAssignedRoleInStage(submission, submissionStageId, [
      pkp.const.ROLE_ID_SITE_ADMIN,
      pkp.const.ROLE_ID_MANAGER,
      pkp.const.ROLE_ID_SUB_EDITOR,
      pkp.const.ROLE_ID_AUTHOR
    ])) {
      return;
    }
    fileStore.oreAuthorUpload = function() {
      openSideModal(_sfc_main, {
        submissionId: submission.id,
        onSuccess: () => fileStore.fetchFiles()
      });
    };
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
            action: "oreAuthorUpload"
          }
        }
      ];
    });
  }
  pkp.registry.storeExtend("fileManager_SUBMISSION_FILES", (piniaContext) => {
    runOreAuthorUpload(piniaContext);
  });
})(pkp.modules.vue);
