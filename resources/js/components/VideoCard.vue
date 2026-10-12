<template>
    <tr
        :class="ready ? 'tw:cursor-pointer tw:hover:bg-gray-50 tw:dark:hover:bg-dark-400' : ''"
        @click="ready && $emit('select', currentVideo)"
    >
        <td>
            <div class="tw:flex tw:items-center tw:gap-2">
                <template v-if="ready">
                    <img :src="thumbnailUrl" alt="" class="tw:size-16 tw:rounded tw:object-cover tw:shrink-0" />
                    <button type="button" class="tw:text-sm tw:font-medium tw:truncate tw:text-left tw:cursor-pointer" @click.stop="$emit('select', currentVideo)">
                        {{ currentVideo.title }}
                    </button>
                </template>
                <template v-else-if="failed">
                    <div class="tw:size-16 tw:rounded tw:bg-red-100 tw:shrink-0" />
                    <span class="tw:text-sm tw:font-medium tw:truncate">{{ currentVideo.title }}</span>
                    <span class="tw:text-sm tw:text-red-600">{{ __('Encoding failed') }}</span>
                </template>
                <template v-else>
                    <div class="tw:size-16 tw:rounded tw:bg-gray-300 tw:dark:bg-dark-200 tw:flex tw:items-center tw:justify-center tw:shrink-0">
                        <svg class="tw:size-5 tw:animate-spin tw:text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="tw:opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="tw:opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                    </div>
                    <span class="tw:text-sm tw:text-gray-500">
                        {{ __('Processing...') }} {{ currentVideo.encodeProgress ?? 0 }}%
                    </span>
                </template>
            </div>
        </td>
        <td class="tw:hidden tw:md:table-cell">
            <span v-if="ready || failed" class="tw:text-sm tw:text-gray-500">
                {{ formatDate(currentVideo.dateUploaded) }}
            </span>
            <span v-else class="tw:text-sm tw:text-gray-500">
                {{ __('Processing...') }}
            </span>
        </td>
        <td class="actions-column tw:!pr-4">
            <button type="button" :aria-label="__('Delete video :title', { title: currentVideo.title })" @click.stop="confirmDeletion" class="tw:text-gray-500 tw:hover:text-red-500 tw:cursor-pointer">
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
import * as api from '@/utils/api.js';

export default {
    inject: ['bunnyEndpoint', 'bunnyHostname'],
    props: {
        video: Object,
    },
    emits: ['select'],
    data() {
        return {
            currentVideo: this.video,
            isLoading: false,
            triggerDeletion: false,
            thumbnailCacheBuster: '',
        }
    },
    computed: {
        // Bunny statuses: 4 finished, 7/8 just-in-time playback ready, 5/6 failed.
        ready() {
            return [4, 7, 8].includes(this.currentVideo.status);
        },
        failed() {
            return [5, 6].includes(this.currentVideo.status);
        },
        thumbnailUrl() {
            const base = `https://${this.bunnyHostname}/${this.currentVideo.guid}/${this.currentVideo.thumbnailFileName}`;
            return this.thumbnailCacheBuster ? `${base}?v=${this.thumbnailCacheBuster}` : base;
        },
    },
    mounted() {
        if (!this.ready && !this.failed) {
            this.polling = setInterval(() => {
                this.loadVideo();
            }, 5000);
        }

        emitter.on(`thumbnail-updated:${this.currentVideo.guid}`, () => {
            this.thumbnailCacheBuster = Date.now();
        });
    },
    beforeUnmount() {
        clearInterval(this.polling);
        emitter.off(`thumbnail-updated:${this.currentVideo.guid}`);
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

            api.get(`${this.bunnyEndpoint}/${this.currentVideo.guid}`)
                .then((data) => {
                    this.currentVideo = data;
                    if (this.ready || this.failed) {
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

            api.destroy(`${this.bunnyEndpoint}/${this.currentVideo.guid}`)
                .then(() => {
                    this.cancelDeletion();
                    clearInterval(this.polling);
                    emitter.emit('load');
                })
                .catch((error) => {
                    this.cancelDeletion();
                    Statamic.$toast.error(error.message);
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
