<template>
    <Modal v-model:open="assetUploads.open" :title="__('Upload Video')" icon="upload-cloud">
        <div
            class="tw:relative tw:overflow-hidden tw:rounded-xl tw:border tw:border-dashed tw:border-gray-300 tw:bg-gray-50 tw:dark:border-gray-700 tw:dark:bg-gray-850"
            @dragenter.prevent="dragenter"
            @dragover.prevent
            @dragleave="dragleave"
            @drop.prevent="drop"
        >
            <button
                type="button"
                class="tw:flex tw:w-full tw:flex-col tw:items-center tw:justify-center tw:gap-3 tw:px-3 tw:py-10 tw:text-gray-600 tw:cursor-pointer tw:hover:bg-gray-100 tw:focus-visible:outline-2 tw:focus-visible:-outline-offset-2 tw:focus-visible:outline-blue-500 tw:dark:text-gray-400 tw:dark:hover:bg-gray-800"
                @click="input.click()"
            >
                <Icon name="upload-cloud" class="tw:size-4 tw:shrink-0" />
                <span class="tw:text-base tw:sm:text-sm">
                    {{ __('Drop videos here or') }}&nbsp;<span class="tw:underline tw:underline-offset-2">{{ __('choose a file') }}</span>
                </span>
            </button>
            <div
                v-show="dragging"
                role="status"
                class="tw:pointer-events-none tw:absolute tw:inset-0 tw:flex tw:items-center tw:justify-center tw:gap-2 tw:rounded-xl tw:border-2 tw:border-dotted tw:border-blue-400 tw:bg-blue-50 tw:text-sm tw:text-blue-600 tw:dark:border-blue-500 tw:dark:bg-gray-850 tw:dark:text-blue-400"
            >
                <Icon name="upload-cloud" class="tw:size-4 tw:shrink-0" />
                {{ __('Drop to Upload') }}
            </div>
            <input ref="input" type="file" accept="video/*,.mkv" multiple class="tw:hidden" @change="choose" />
        </div>

        <ul v-if="assetUploads.files.length" class="tw:divide-y tw:divide-gray-200 tw:rounded-lg tw:border tw:border-gray-200 tw:dark:divide-gray-700 tw:dark:border-gray-700">
            <li
                v-for="file in assetUploads.files"
                :key="file.id"
                class="tw:flex tw:items-center tw:gap-2 tw:p-3 tw:text-sm tw:text-gray-600 tw:sm:gap-3 tw:dark:text-gray-400"
            >
                <div class="tw:flex tw:size-7 tw:shrink-0 tw:items-center tw:justify-center">
                    <Icon v-if="file.status === 'error'" name="warning-diamond" class="tw:size-5 tw:text-red-600" v-tooltip="file.error" />
                    <Icon v-else-if="file.status === 'done'" name="checkmark" class="tw:size-4 tw:text-green-600" />
                    <Icon v-else name="loading" />
                </div>

                <div class="tw:truncate">{{ file.name }}</div>

                <div
                    v-if="file.status === 'uploading'"
                    class="tw:h-1.5 tw:flex-1 tw:rounded-lg tw:bg-gray-100 tw:dark:bg-gray-700"
                    role="progressbar"
                    :aria-label="file.name"
                    :aria-valuenow="file.percent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div class="tw:h-full tw:rounded-sm tw:bg-blue-500" :style="{ width: `${file.percent}%` }" />
                </div>
                <div v-else class="tw:flex-1" />

                <div v-if="file.status === 'error'" class="tw:flex tw:shrink-0 tw:items-center tw:gap-2">
                    <Button size="xs" :text="__('Retry')" @click="retryUpload(file.id)" />
                    <Button size="xs" :text="__('Discard')" @click="discardUpload(file.id)" />
                </div>
                <span v-else-if="file.status === 'done'" class="tw:shrink-0 tw:text-xs">{{ __('Uploaded') }}</span>
            </li>
        </ul>

        <template #footer>
            <div class="tw:flex tw:items-center tw:justify-end tw:pt-3 tw:pb-1">
                <ModalClose asChild>
                    <Button variant="ghost" :text="__('Close')" />
                </ModalClose>
            </div>
        </template>
    </Modal>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Button, Icon, Modal, ModalClose } from '@statamic/cms/ui';
import { addUploadFiles, assetUploads, clearFinishedUploads, discardUpload, retryUpload } from '../utils/assetUpload.js';

// Mounted once by openAssetUpload; uploads keep running while it is closed.
const input = ref(null);
const dragging = ref(false);

function dragenter(event) {
    if (event.dataTransfer.types.includes('Files')) dragging.value = true;
}

function dragleave(event) {
    // Moving onto a child also fires dragleave on the drop zone.
    if (!event.currentTarget.contains(event.relatedTarget)) dragging.value = false;
}

function drop(event) {
    dragging.value = false;
    addUploadFiles(event.dataTransfer.files);
}

function choose(event) {
    addUploadFiles(event.target.files);
    event.target.value = '';
}

watch(() => assetUploads.open, (open) => { if (!open) clearFinishedUploads(); });
</script>
