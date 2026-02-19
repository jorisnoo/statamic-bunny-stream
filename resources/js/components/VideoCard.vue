<template>
    <tr>
        <td>
            <div class="tw:flex tw:items-center tw:gap-2">
                <template v-if="currentVideo.status >= 4">
                    <a :href="videoUrl" target="_blank" class="tw:shrink-0">
                        <img :src="thumbnailUrl" class="tw:size-16 tw:rounded tw:object-cover" />
                    </a>
                    <template v-if="!isEditing">
                        <a :href="videoUrl" target="_blank" class="tw:text-sm tw:font-medium tw:truncate">
                            {{ currentVideo.title }}
                        </a>
                        <button @click="startEditing" class="tw:text-gray-400 tw:hover:text-gray-700 tw:dark:hover:text-gray-200 tw:cursor-pointer tw:shrink-0">
                            <svg class="tw:size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                            </svg>
                        </button>
                    </template>
                    <template v-else>
                        <input
                            ref="titleInput"
                            v-model="editTitle"
                            type="text"
                            class="tw:text-sm tw:font-medium tw:border tw:border-gray-400 tw:dark:border-dark-200 tw:rounded tw:px-2 tw:py-1 tw:w-full tw:bg-white tw:dark:bg-dark-500"
                            @keydown.enter="saveTitle"
                            @keydown.escape="cancelEditing"
                        />
                        <button @click="saveTitle" class="tw:text-gray-400 tw:hover:text-green-600 tw:cursor-pointer tw:shrink-0">
                            <svg class="tw:size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        </button>
                        <button @click="cancelEditing" class="tw:text-gray-400 tw:hover:text-red-500 tw:cursor-pointer tw:shrink-0">
                            <svg class="tw:size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </template>
                </template>
                <template v-else>
                    <div class="tw:size-16 tw:rounded tw:bg-gray-300 tw:dark:bg-dark-200 tw:flex tw:items-center tw:justify-center tw:shrink-0">
                        <svg class="tw:size-5 tw:animate-spin tw:text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="tw:opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="tw:opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                    </div>
                    <span class="tw:text-sm tw:text-gray-500">
                        {{ __('Processing...') }} {{ currentVideo.encodeProgress * 2 }}%
                    </span>
                </template>
            </div>
        </td>
        <td class="tw:hidden tw:md:table-cell">
            <span v-if="currentVideo.status >= 4" class="tw:text-sm tw:text-gray-500">
                {{ formatDate(currentVideo.dateUploaded) }}
            </span>
            <span v-else class="tw:text-sm tw:text-gray-500">
                {{ __('Processing...') }}
            </span>
        </td>
        <td class="actions-column tw:!pr-4">
            <button @click="confirmDeletion" class="tw:text-gray-500 tw:hover:text-red-500 tw:cursor-pointer">
                <svg class="tw:size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
            </button>
        </td>
    </tr>

    <Teleport to="body">
        <ui-confirmation-modal
            v-model:open="triggerDeletion"
            :title="__('Delete video :title', {'title': currentVideo.title})"
            @confirm="deleteVideo"
            @cancel="cancelDeletion"
            danger="true"
        />
    </Teleport>
</template>

<script>
import { emitter } from '@/utils/emitter.js';

export default {
    inject: ['bunnyApiKey', 'bunnyHostname', 'bunnyLibrary'],
    props: {
        video: Object,
    },
    data() {
        return {
            currentVideo: this.video,
            isLoading: false,
            thumbnailUrl: `https://${this.bunnyHostname}/${this.video.guid}/${this.video.thumbnailFileName}`,
            videoUrl: `https://iframe.mediadelivery.net/play/${this.video.videoLibraryId}/${this.video.guid}`,
            triggerDeletion: false,
            isEditing: false,
            editTitle: '',
        }
    },
    mounted() {
        if (this.currentVideo.status < 4) {
            this.polling = setInterval(() => {
                this.loadVideo();
            }, 5000);
        }
    },
    methods: {
        formatDate(dateString) {
            const date = new Date(dateString);

            return date.toLocaleString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            });
        },
        confirmDeletion() {
            this.triggerDeletion = true;
        },
        loadVideo() {
            this.isLoading = true;

            fetch(`https://video.bunnycdn.com/library/${this.bunnyLibrary}/videos/${this.currentVideo.guid}`, {
                headers: {
                    Accept: 'application/json',
                    AccessKey: this.bunnyApiKey,
                },
            })
                .then((response) => response.json())
                .then((data) => {
                    this.currentVideo = data;
                    if (this.currentVideo.status >= 4) {
                        clearInterval(this.polling);
                        emitter.emit('load');
                    }
                })
                .catch((error) => {
                    console.error(error);
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
        deleteVideo() {
            this.isLoading = true;

            fetch(`https://video.bunnycdn.com/library/${this.bunnyLibrary}/videos/${this.currentVideo.guid}`, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    AccessKey: this.bunnyApiKey,
                },
            })
                .then(() => {
                    this.cancelDeletion();
                    clearInterval(this.polling);
                    emitter.emit('load');
                })
                .catch((error) => {
                    console.error(error);
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
        cancelDeletion() {
            this.triggerDeletion = false;
        },
        startEditing() {
            this.editTitle = this.currentVideo.title;
            this.isEditing = true;

            this.$nextTick(() => {
                this.$refs.titleInput.focus();
                this.$refs.titleInput.select();
            });
        },
        saveTitle() {
            const title = this.editTitle.trim();

            if (! title || title === this.currentVideo.title) {
                this.isEditing = false;
                return;
            }

            fetch(`https://video.bunnycdn.com/library/${this.bunnyLibrary}/videos/${this.currentVideo.guid}`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/*+json',
                    AccessKey: this.bunnyApiKey,
                },
                body: JSON.stringify({ title }),
            })
                .then((response) => {
                    if (! response.ok) {
                        throw new Error('Failed to update title');
                    }

                    this.currentVideo.title = title;
                    this.isEditing = false;
                    Statamic.$toast.success(__('Title updated'));
                })
                .catch((error) => {
                    console.error(error);
                    Statamic.$toast.error(__('Failed to update title'));
                });
        },
        cancelEditing() {
            this.isEditing = false;
        },
    }
}
</script>
