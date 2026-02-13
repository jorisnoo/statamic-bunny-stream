<template>
    <div>
        <div
            v-if="currentVideo.status >= 4"
            class="sm:grid sm:grid-cols-3 overflow-hidden bg-white rounded shadow-xl w-full mb-4"
        >
            <a :href="videoUrl" target="_blank" class="">
                <img :src="thumbnailUrl" class="aspect-video inset-0 h-full object-cover w-full" />
            </a>
            <div class="sm:col-span-2 py-2 sm:py-4 px-2 sm:px-6 text-gray-800 flex flex-col justify-between gap-1">
                <div class="flex sm:flex-grow items-start justify-between gap-4 w-full">
                    <a :href="videoUrl" target="_blank" class="flex-grow min-w-0 font-semibold text-base sm:text-lg leading-tight truncate">
                        {{ currentVideo.title }}
                    </a>
                    <div class="flex gap-1">
                        <VideoSettings :id="currentVideo.guid" :title="currentVideo.title" :assetOptions="assetOptions" />
                        <button class="size-5" @click="confirmDeletion()">
                            <TrashIcon class="size-5" />
                        </button>
                    </div>
                </div>
                <p class="flex gap-2 md:gap-4 items-center justify-start text-xs md:text-sm whitespace-nowrap">
                    <a :href="thumbnailUrl" target="_blank" class="flex gap-1 items-center">
                        {{ __('Thumbnail') }}
                        <LinkIcon class="size-4" />
                    </a>
                </p>
                <div class="text-xs md:text-sm text-gray-600 flex justify-between">
                    <div class="flex gap-2 items-center">
                        <CloudIcon class="text-gray-500 size-4" />
                        {{ new Date(currentVideo.dateUploaded).toLocaleString() }}
                    </div>
                    <div class="flex gap-2 items-center">
                        <EyeIcon class="text-gray-500 size-4" />
                        {{ currentVideo.views }}
                    </div>
                </div>
            </div>
        </div>
        <div
            v-else
            class="flex flex-col overflow-hidden bg-white rounded shadow-xl w-full mb-4 p-6 items-center justify-center"
        >
            <h2 class="text-lg">
                {{ __('Video is being processed') }} &ndash; {{ currentVideo.encodeProgress * 2 }}%
            </h2>
            <p class="text-xs text-gray-600">
                {{ __('This may take some time.') }}
            </p>
            <button class="text-xs text-red-500" @click="confirmDeletion()">
                {{ __('Cancel and delete video') }}
            </button>
            <div role="status" class="mt-4 mx-auto">
                <SpinnerIcon class="w-8 h-8 mr-2 animate-spin"/>
                <span class="sr-only">{{ __('Loading...') }}</span>
            </div>
        </div>

        <ui-confirmation-modal
            v-model:open="triggerDeletion"
            :title="__('Delete video :title', {'title': currentVideo.title})"
            @confirm="deleteVideo"
            @cancel="cancelDeletion"
            danger="true"
        />
    </div>
</template>

<script>
import CloudIcon from "../icons/Cloud.vue";
import EyeIcon from "../icons/Eye.vue";
import LinkIcon from "../icons/Link.vue";
import SpinnerIcon from "../icons/Spinner.vue";
import TrashIcon from "../icons/Trash.vue";
import VideoSettings from "./VideoSettings.vue";
import {emitter} from '@/utils/emitter.js';

export default {
    components: {VideoSettings, CloudIcon, LinkIcon, TrashIcon, SpinnerIcon, EyeIcon},
    inject: ['bunnyApiKey', 'bunnyHostname', 'bunnyLibrary'],
    props: {
        video: Object,
        assetOptions: Array,
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
