<template>
    <div>
        <div class="tw:mb-4">
            <Input
                type="text"
                :placeholder="__('Search...')"
                v-model="search"
                @input="debouncedSearch"
            />
        </div>

        <div v-if="loading" class="tw:flex tw:items-center tw:justify-center tw:py-12">
            <SpinnerIcon class="tw:size-8 tw:animate-spin tw:text-gray-500" />
        </div>

        <div v-else-if="loadError" class="tw:text-center tw:text-sm tw:text-red-600 tw:py-8">
            <p>{{ loadError }}</p>
            <Button class="tw:mt-4" icon="sync" :text="__('Try again')" @click="getVideos" />
        </div>

        <div v-else-if="result.totalItems >= 1" class="tw:rounded-lg tw:border tw:border-gray-200 tw:dark:border-dark-200 tw:overflow-hidden">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>{{ __('File') }}</th>
                        <th class="tw:hidden tw:md:table-cell">{{ __('Date') }}</th>
                        <th class="actions-column" />
                    </tr>
                </thead>
                <tbody>
                    <VideoCard
                        v-for="video in result.items"
                        :key="video.guid"
                        :video="video"
                        @select="openDetail"
                    />
                </tbody>
            </table>

            <div v-if="result.totalItems > itemsPerPage" class="tw:flex tw:items-center tw:justify-between tw:border-t tw:px-4 tw:py-2 tw:text-sm tw:text-gray-700 tw:dark:text-dark-150">
                <span>{{ rangeStart }}–{{ rangeEnd }} {{ __('of') }} {{ result.totalItems }}</span>
                <div class="tw:flex tw:gap-1">
                    <Button size="sm" variant="ghost" icon="chevron-left" icon-only :aria-label="__('Previous page')" :disabled="page <= 1" @click="prevPage" />
                    <Button size="sm" variant="ghost" icon="chevron-right" icon-only :aria-label="__('Next page')" :disabled="page >= maxPage" @click="nextPage" />
                </div>
            </div>
        </div>

        <div v-else-if="search.length > 0" class="tw:text-center tw:text-sm tw:text-gray-500 tw:py-8">
            {{ __('No media found.') }}
        </div>

        <div v-else class="tw:text-center tw:text-sm tw:text-gray-500 tw:py-8">
            <p>{{ __('No media yet.') }}</p>
            <Button class="tw:mt-4" variant="primary" :text="__('Upload Media')" @click="openUpload" />
        </div>

        <VideoDetailStack
            v-if="selectedVideo"
            :key="selectedVideo.guid"
            :video="selectedVideo"
            v-model:open="detailOpen"
            @updated="onDetailUpdated"
        />
    </div>
</template>

<script>
import SpinnerIcon from "../icons/Spinner.vue";
import VideoCard from "./VideoCard.vue";
import VideoDetailStack from "./VideoDetailStack.vue";
import { Button, Input } from '@statamic/cms/ui';
import { emitter } from '@/utils/emitter.js';
import * as api from '@/utils/api.js';
import debounce from "debounce";

export default {
    components: { SpinnerIcon, VideoCard, VideoDetailStack, Button, Input },
    inject: ['bunnyEndpoint'],
    data() {
        return {
            search: '',
            loading: true,
            polling: null,
            result: null,
            page: 1,
            maxPage: 1,
            itemsPerPage: 10,
            selectedVideo: null,
            detailOpen: false,
            loadError: null,
            request: 0,
        };
    },
    computed: {
        rangeStart() {
            return (this.page - 1) * this.itemsPerPage + 1;
        },
        rangeEnd() {
            return Math.min(this.page * this.itemsPerPage, this.result.totalItems);
        },
    },
    created() {
        this.getVideos();
        emitter.on('load', this.onLoad);
    },
    beforeUnmount() {
        emitter.off('load', this.onLoad);
    },
    methods: {
        onLoad(context) {
            if (context && context.page) {
                this.page = context.page;
            }

            this.getVideos();
        },
        getVideos() {
            // Ignore responses that arrive after a newer search or page change.
            const request = ++this.request;
            this.loading = true;
            this.loadError = null;

            const params = new URLSearchParams({
                page: this.page,
                perPage: this.itemsPerPage,
            });

            if (this.search !== '') {
                params.set('search', this.search);
            }

            api.get(`${this.bunnyEndpoint}?${params}`)
                .then((data) => {
                    if (request !== this.request) return;
                    this.maxPage = Math.ceil(data.totalItems / this.itemsPerPage);
                    this.result = data;
                })
                .catch((error) => {
                    if (request !== this.request) return;
                    this.loadError = error.message || __('Could not load videos.');
                })
                .finally(() => {
                    if (request === this.request) this.loading = false;
                });
        },
        openUpload() {
            emitter.emit('upload');
        },
        debouncedSearch: debounce(() => {
            emitter.emit('load', { page: 1 });
        }, 500),
        nextPage() {
            if (this.page < this.maxPage) {
                this.page++;
                this.getVideos();
            }
        },
        prevPage() {
            if (this.page > 1) {
                this.page--;
                this.getVideos();
            }
        },
        openDetail(video) {
            this.selectedVideo = video;
            this.detailOpen = true;
        },
        onDetailUpdated() {
            this.getVideos();
        },
    },
};
</script>
