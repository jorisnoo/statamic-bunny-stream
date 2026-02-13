<template>
    <tr>
        <td>
            <div class="flex items-center gap-2">
                <template v-if="currentVideo.status >= 4">
                    <a :href="videoUrl" target="_blank" class="shrink-0">
                        <img :src="thumbnailUrl" class="size-16 rounded object-cover" />
                    </a>
                    <a :href="videoUrl" target="_blank" class="text-sm font-medium truncate">
                        {{ currentVideo.title }}
                    </a>
                </template>
                <template v-else>
                    <div class="size-16 rounded bg-gray-300 dark:bg-dark-200 flex items-center justify-center shrink-0">
                        <svg class="size-5 animate-spin text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                    </div>
                    <span class="text-sm text-gray-500">
                        {{ __('Processing...') }} {{ currentVideo.encodeProgress * 2 }}%
                    </span>
                </template>
            </div>
        </td>
        <td class="hidden md:table-cell">
            <span v-if="currentVideo.status >= 4" class="text-sm text-gray-500">
                {{ formatDate(currentVideo.dateUploaded) }}
            </span>
            <span v-else class="text-sm text-gray-500">
                {{ __('Processing...') }}
            </span>
        </td>
        <td class="actions-column pr-4">
            <button @click="confirmDeletion" class="text-gray-500 hover:text-red-500 cursor-pointer">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
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

            return date.toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
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
    }
}
</script>
