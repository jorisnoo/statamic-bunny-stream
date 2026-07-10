<template>
    <Teleport to="body">
        <ui-stack
            v-model:open="isOpen"
            :title="video.title"
            narrow
        >
            <div class="tw:p-4 tw:space-y-6">
                <div v-if="loading" class="tw:flex tw:items-center tw:justify-center tw:py-12">
                    <svg class="tw:size-8 tw:animate-spin tw:text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="tw:opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="tw:opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                </div>

                <template v-else-if="fullVideo">
                    <!-- Thumbnail -->
                    <div>
                        <h3 class="tw:text-sm tw:font-semibold tw:text-gray-900 tw:dark:text-dark-100 tw:mb-2">{{ __('Thumbnail') }}</h3>
                        <div class="tw:relative tw:group">
                            <img :src="currentThumbnailUrl" class="tw:w-full tw:rounded tw:object-cover tw:aspect-video" />
                            <button
                                v-if="!isUploadingThumbnail"
                                @click.prevent="selectThumbnail"
                                class="tw:absolute tw:inset-0 tw:flex tw:items-center tw:justify-center tw:bg-black/50 tw:rounded tw:opacity-0 tw:group-hover:opacity-100 tw:transition-opacity tw:cursor-pointer"
                            >
                                <svg class="tw:size-8 tw:text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                                </svg>
                            </button>
                            <div
                                v-if="isUploadingThumbnail"
                                class="tw:absolute tw:inset-0 tw:flex tw:items-center tw:justify-center tw:bg-black/50 tw:rounded"
                            >
                                <svg class="tw:size-8 tw:animate-spin tw:text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="tw:opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="tw:opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                </svg>
                            </div>
                            <input
                                ref="thumbnailInput"
                                type="file"
                                accept="image/jpeg,image/png"
                                class="tw:hidden"
                                @change="uploadThumbnail"
                            />
                        </div>
                    </div>

                    <!-- Title -->
                    <div>
                        <h3 class="tw:text-sm tw:font-semibold tw:text-gray-900 tw:dark:text-dark-100 tw:mb-2">{{ __('Title') }}</h3>
                        <input
                            v-model="editableTitle"
                            type="text"
                            class="tw:w-full tw:text-sm tw:border tw:border-gray-300 tw:dark:border-dark-200 tw:rounded tw:px-3 tw:py-2 tw:bg-white tw:dark:bg-dark-500"
                        />
                    </div>

                    <!-- Video Info -->
                    <div class="tw:grid tw:grid-cols-2 tw:gap-x-4 tw:gap-y-1 tw:text-sm tw:text-gray-500">
                        <div>{{ __('Duration') }}: {{ formatDuration(fullVideo.length) }}</div>
                        <div>{{ __('Resolution') }}: {{ fullVideo.width }}x{{ fullVideo.height }}</div>
                        <div>{{ __('Views') }}: {{ fullVideo.views }}</div>
                        <div>{{ __('Size') }}: {{ formatBytes(fullVideo.storageSize) }}</div>
                    </div>

                    <!-- Chapters -->
                    <ChapterEditor
                        :chapters="editableChapters"
                        :video-guid="video.guid"
                        @update:chapters="editableChapters = $event"
                    />

                    <!-- Save -->
                    <div class="tw:flex tw:justify-end tw:pt-2 tw:border-t tw:border-gray-200 tw:dark:border-dark-200">
                        <button
                            class="btn-primary"
                            :disabled="isSaving"
                            @click="save"
                        >
                            {{ isSaving ? __('Saving...') : __('Save') }}
                        </button>
                    </div>
                </template>
            </div>
        </ui-stack>
    </Teleport>
</template>

<script>
import ChapterEditor from './ChapterEditor.vue';
import { formatTime } from '@/utils/time.js';
import { emitter } from '@/utils/emitter.js';
import * as api from '@/utils/api.js';

export default {
    components: { ChapterEditor },
    inject: ['bunnyEndpoint', 'bunnyHostname'],
    props: {
        video: {
            type: Object,
            required: true,
        },
        open: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['update:open', 'updated'],
    data() {
        return {
            fullVideo: null,
            loading: false,
            editableTitle: '',
            editableChapters: [],
            isSaving: false,
            isUploadingThumbnail: false,
            thumbnailCacheBuster: '',
        };
    },
    computed: {
        isOpen: {
            get() {
                return this.open;
            },
            set(value) {
                this.$emit('update:open', value);
            },
        },
        currentThumbnailUrl() {
            if (!this.fullVideo) return '';
            const base = `https://${this.bunnyHostname}/${this.fullVideo.guid}/${this.fullVideo.thumbnailFileName}`;
            return this.thumbnailCacheBuster ? `${base}?v=${this.thumbnailCacheBuster}` : base;
        },
    },
    watch: {
        open(newVal) {
            if (newVal) {
                this.fetchVideo();
            } else {
                this.fullVideo = null;
            }
        },
    },
    methods: {
        fetchVideo() {
            this.loading = true;

            api.get(`${this.bunnyEndpoint}/${this.video.guid}`)
                .then((data) => {
                    this.fullVideo = data;
                    this.editableTitle = data.title || '';
                    this.editableChapters = data.chapters || [];
                })
                .catch((error) => {
                    console.error(error);
                    Statamic.$toast.error(__('Failed to load video details.'));
                })
                .finally(() => {
                    this.loading = false;
                });
        },

        save() {
            this.isSaving = true;

            api.patch(`${this.bunnyEndpoint}/${this.video.guid}`, {
                title: this.editableTitle,
                chapters: this.editableChapters,
            })
                .then(() => {
                    Statamic.$toast.success(__('Video updated.'));
                    this.$emit('updated');
                })
                .catch((error) => {
                    console.error(error);
                    Statamic.$toast.error(__('Failed to save video.'));
                })
                .finally(() => {
                    this.isSaving = false;
                });
        },

        selectThumbnail() {
            this.$refs.thumbnailInput.click();
        },

        uploadThumbnail(event) {
            const file = event.target.files[0];
            if (!file) return;

            this.isUploadingThumbnail = true;

            api.upload(`${this.bunnyEndpoint}/${this.video.guid}/thumbnail`, { thumbnail: file })
                .then(() => {
                    this.thumbnailCacheBuster = Date.now();
                    emitter.emit(`thumbnail-updated:${this.video.guid}`);
                    this.$emit('updated');

                    Statamic.$toast.success(__('Thumbnail has been updated!'));
                })
                .catch((error) => {
                    console.error(error);
                    Statamic.$toast.error(__('An error occured while trying to update the thumbnail.'));
                })
                .finally(() => {
                    this.isUploadingThumbnail = false;
                    this.$refs.thumbnailInput.value = '';
                });
        },

        formatDuration(seconds) {
            return formatTime(seconds || 0);
        },

        formatBytes(bytes) {
            if (!bytes) return '0 B';

            const units = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            let size = bytes;

            while (size >= 1024 && i < units.length - 1) {
                size /= 1024;
                i++;
            }

            return `${size.toFixed(i > 0 ? 1 : 0)} ${units[i]}`;
        },
    },
};
</script>
