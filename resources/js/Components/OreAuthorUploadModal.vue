<template>
    <PkpSideModalBody>
        <template #title>
            {{ t("common.upload.addFile") }}
        </template>

        <PkpSideModalLayoutBasic>
            <!-- Revision Selection (only shown if existing files exist) -->
            <div v-if="existingFiles.length" class="pkpFormField pkpFormField--select">
                <div class="pkpFormField__heading">
                    <label class="pkpFormFieldLabel">
                        {{ t("submission.upload.selectOptionalFileToRevise") }}
                    </label>
                </div>
                <div class="pkpFormField__control">
                    <select
                        v-model="selectedRevisionFileId"
                        class="pkpFormField__input pkpFormField__input--select"
                        :disabled="files.length > 0"
                        @change="onRevisionFileChange"
                    >
                        <option value="">
                            {{ t("plugins.generic.oreWorkflow.notARevision") }}
                        </option>
                        <option
                            v-for="file in existingFiles"
                            :key="file.id"
                            :value="file.id"
                        >
                            {{ file.name }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Genre Selection (PKP form field styling) -->
            <div class="pkpFormField pkpFormField--select oreUpload__genreSelect">
                <div class="pkpFormField__heading">
                    <label class="pkpFormFieldLabel">
                        {{ t("submission.upload.selectComponent") }}
                        <span class="pkpFormFieldLabel__required">*</span>
                    </label>
                </div>
                <div class="pkpFormField__control">
                    <select
                        v-model="selectedGenreId"
                        class="pkpFormField__input pkpFormField__input--select"
                        :disabled="isRevision || files.length > 0"
                    >
                        <option value="" disabled>
                            {{ t("plugins.generic.oreWorkflow.selectGenre.placeholder") }}
                        </option>
                        <option
                            v-for="genre in genres"
                            :key="genre.id"
                            :value="genre.id"
                        >
                            {{ genre.name }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- File Upload Area (only shown after genre selected) -->
            <div v-if="selectedGenreId" class="oreUpload">
                <div class="oreUpload__wrapper">
                    <!-- Upload prompt (no files yet) -->
                    <div v-if="!files.length" class="oreUpload__prompt">
                        <p>{{ t("common.upload.dragFile") }}</p>
                        <PkpButton @click="openFileBrowser">
                            {{ t("common.upload.addFile") }}
                        </PkpButton>
                    </div>

                    <!-- Files list (uploading or uploaded) -->
                    <div v-else class="oreUpload__files">
                        <template v-for="(file, i) in files" :key="file.id || i">
                            <!-- Uploading: show progress -->
                            <PkpFileUploadProgress
                                v-if="'progress' in file"
                                :cancel-upload-label="t('common.cancel')"
                                :errors="file.errors || []"
                                :name="file.name"
                                :progress="file.progress"
                                @cancel="cancelUpload(file.id)"
                            />
                            <!-- Uploaded: show file with remove button -->
                            <div v-else class="oreUpload__uploadedFile">
                                <span class="oreUpload__fileName">
                                    {{ getFileName(file) }}
                                </span>
                                <PkpButton :is-warnable="true" @click="removeFile(i)">
                                    {{ t("common.remove") }}
                                </PkpButton>
                            </div>
                        </template>
                    </div>

                    <!-- Hidden FileUploader component -->
                    <PkpFileUploader
                        ref="uploader"
                        id="oreAuthorUploader"
                        :api-url="uploadApiUrl"
                        :files="files"
                        :query-params="queryParams"
                        :options="dropzoneOptions"
                        :upload-progress-label="t('plugins.generic.oreWorkflow.uploadProgress')"
                        @updated:files="onFilesUpdated"
                    />
                </div>
            </div>

            <!-- Actions -->
            <div class="oreUpload__actions">
                <PkpButton @click="handleCancel">
                    {{ t("common.cancel") }}
                </PkpButton>
                <PkpButton
                    v-if="hasUploadedFile"
                    :is-primary="true"
                    @click="handleSave"
                >
                    {{ t("common.save") }}
                </PkpButton>
            </div>
        </PkpSideModalLayoutBasic>
    </PkpSideModalBody>
</template>

<script setup>
import {ref, computed, onMounted, inject, nextTick} from 'vue';

const props = defineProps({
    submissionId: {type: Number, required: true},
    onSuccess: {type: Function, default: () => {}},
});

const {useLocalize} = pkp.modules.useLocalize;
const {useUrl} = pkp.modules.useUrl;
const {useNotify} = pkp.modules.useNotify;

const {t, localize} = useLocalize();
const {notify} = useNotify();
const closeModal = inject('closeModal');

// State
const genres = ref([]);
const existingFiles = ref([]);
const selectedGenreId = ref('');
const selectedRevisionFileId = ref(''); // '' = new file, otherwise = file ID
const files = ref([]);
const uploader = ref(null);
const isManuallyRemoving = ref(false);

// API URLs for fetching data
const {apiUrl: genresApiUrl} = useUrl('ore-author-files/genres');
const {apiUrl: existingFilesApiUrl} = useUrl(`ore-author-files/${props.submissionId}/files`);

// Computed: is this a revision upload?
const isRevision = computed(() => selectedRevisionFileId.value !== '');

// Computed: dynamic upload API URL based on revision mode
const uploadApiUrl = computed(() => {
    if (isRevision.value) {
        const {apiUrl} = useUrl(
            `ore-author-files/${props.submissionId}/submissionFile/${selectedRevisionFileId.value}`
        );
        return apiUrl.value;
    }
    const {apiUrl} = useUrl(`ore-author-files/${props.submissionId}`);
    return apiUrl.value;
});

// Query params for file upload - only genreId for new files
const queryParams = computed(() => ({
    genreId: selectedGenreId.value,
}));

// Dropzone options - add PUT override header for revisions
const dropzoneOptions = computed(() => {
    const baseOptions = {
        maxFiles: 1,
        maxFilesize: 100, // MB
    };

    if (isRevision.value) {
        return {
            ...baseOptions,
            headers: {
                'X-Csrf-Token': pkp.currentUser.csrfToken,
                'X-Http-Method-Override': 'PUT',
            },
        };
    }

    return baseOptions;
});

// Computed
const hasUploadedFile = computed(() =>
    files.value.some((f) => !('progress' in f)),
);

// Get file name - handles both localized objects and plain strings
function getFileName(file) {
    if (file.name && typeof file.name === 'object') {
        return localize(file.name);
    }
    return file.name || '';
}

// Handle revision file selection change
function onRevisionFileChange() {
    if (selectedRevisionFileId.value) {
        // Find the file and auto-set genre
        const file = existingFiles.value.find(
            (f) => f.id === parseInt(selectedRevisionFileId.value)
        );
        if (file) {
            selectedGenreId.value = file.genreId;
        }
    } else {
        // Reset genre when switching back to new file
        selectedGenreId.value = '';
    }
}

// Fetch genres and existing files on mount
onMounted(async () => {
    // Fetch genres
    try {
        const response = await fetch(genresApiUrl.value, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {'X-Csrf-Token': pkp.currentUser.csrfToken},
        });
        if (response.ok) {
            genres.value = await response.json();
        }
    } catch (e) {
        console.error('Failed to fetch genres:', e);
    }

    // Fetch existing files for revision dropdown
    try {
        const response = await fetch(existingFilesApiUrl.value, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {'X-Csrf-Token': pkp.currentUser.csrfToken},
        });
        if (response.ok) {
            existingFiles.value = await response.json();
        }
    } catch (e) {
        console.error('Failed to fetch existing files:', e);
    }
});

function openFileBrowser() {
    uploader.value?.openFileBrowser();
}

function onFilesUpdated(newFiles) {
    // Ignore Dropzone events during manual removal to prevent race condition
    if (isManuallyRemoving.value) return;
    files.value = newFiles;
}

function cancelUpload(fileId) {
    uploader.value?.cancelUpload(fileId);
}

function removeFile(index) {
    // Set flag to ignore Dropzone events during manual removal
    isManuallyRemoving.value = true;
    files.value = files.value.filter((_, i) => i !== index);
    // Also clear Dropzone's internal file list to reset maxFiles counter
    if (uploader.value?.$refs?.dropzone?.dropzone) {
        uploader.value.$refs.dropzone.dropzone.removeAllFiles(true);
    }
    // Reset flag after next tick to allow future Dropzone events
    nextTick(() => {
        isManuallyRemoving.value = false;
    });
}

function handleCancel() {
    selectedRevisionFileId.value = '';
    selectedGenreId.value = '';
    files.value = [];
    closeModal();
}

function handleSave() {
    // File already uploaded via FileUploader
    const successMessage = isRevision.value
        ? t('plugins.generic.oreWorkflow.revisionSuccess')
        : t('plugins.generic.oreWorkflow.uploadSuccess');
    notify(successMessage, 'success');
    props.onSuccess();
    closeModal();
}
</script>

<style>
/* Full-width dropdown with proper styling */
.pkpFormField--select .pkpFormField__input--select {
    width: 100%;
    background-color: #fff;
    color: #000;
}

/* Spacing between revision and genre dropdowns */
.oreUpload__genreSelect {
    margin-top: 1.5rem;
}

.oreUpload {
    margin-top: 1rem;
}

.oreUpload__wrapper {
    position: relative;
}

.oreUpload__prompt {
    display: flex;
    flex-direction: row;
    justify-content: space-between;
    align-items: center;
    min-height: 5rem;
    padding: 1rem;
    border: 2px dashed #ddd;
    font-size: 0.875rem;
}

.oreUpload__prompt p {
    margin: 0;
    color: #666;
}

.oreUpload__files {
    padding: 0.5rem 0;
}

.oreUpload__uploadedFile {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem;
    background-color: #f3f3f3;
    border: 1px solid #ddd;
    border-radius: 2px;
}

.oreUpload__fileName {
    flex: 1;
    font-size: 0.875rem;
}

.oreUpload__actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid #eee;
}
</style>
