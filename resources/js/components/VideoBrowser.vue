<template>
    <div>
        <div class="mb-4">
            <input
                type="text"
                :placeholder="__('Search videos...')"
                v-model="search"
                @input="debouncedSearch"
                class="input-text"
            />
        </div>

        <div v-if="loading" class="flex items-center justify-center py-12">
            <SpinnerIcon class="size-8 animate-spin text-gray-500" />
        </div>

        <div v-else-if="result.totalItems >= 1" class="card p-0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>{{ __('Video') }}</th>
                        <th class="hidden md:table-cell">{{ __('Date') }}</th>
                        <th class="actions-column" />
                    </tr>
                </thead>
                <tbody>
                    <VideoCard
                        v-for="video in result.items"
                        :key="video.guid"
                        :video="video"
                    />
                </tbody>
            </table>

            <div v-if="result.totalItems > itemsPerPage" class="flex items-center justify-between border-t px-4 py-2 text-sm text-gray-700 dark:text-dark-150">
                <span>{{ rangeStart }}–{{ rangeEnd }} {{ __('of') }} {{ result.totalItems }}</span>
                <div class="flex gap-1">
                    <button class="btn-flat btn-sm" :disabled="page <= 1" @click="prevPage">&laquo;</button>
                    <button class="btn-flat btn-sm" :disabled="page >= maxPage" @click="nextPage">&raquo;</button>
                </div>
            </div>
        </div>

        <div v-else-if="search.length > 0" class="text-center text-sm text-gray-500 py-8">
            {{ __('No videos found.') }}
        </div>

        <div v-else class="text-center text-sm text-gray-500 py-8">
            <p>{{ __('No videos yet.') }}</p>
            <button class="btn-primary mt-4" @click="openUpload">
                {{ __('Upload Video') }}
            </button>
        </div>
    </div>
</template>

<script>
import SpinnerIcon from "../icons/Spinner.vue";
import VideoCard from "./VideoCard.vue";
import { emitter } from '@/utils/emitter.js';
import debounce from "debounce";

export default {
    components: { SpinnerIcon, VideoCard },
    inject: ['bunnyApiKey', 'bunnyLibrary'],
    data() {
        return {
            search: '',
            loading: true,
            polling: null,
            result: null,
            page: 1,
            maxPage: 1,
            itemsPerPage: 10,
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

        emitter.on('load', (context) => {
            if (context && context.page) {
                this.page = context.page;
            }

            this.getVideos();
        });
    },
    methods: {
        getVideos() {
            let url = `https://video.bunnycdn.com/library/${this.bunnyLibrary}/videos?page=${this.page}&itemsPerPage=${this.itemsPerPage}&orderBy=date`;

            if (this.search !== '') {
                url += `&search=${this.search}`;
            }

            fetch(url, {
                headers: {
                    Accept: 'application/json',
                    AccessKey: this.bunnyApiKey,
                },
            })
                .then((response) => response.json())
                .then((data) => {
                    this.maxPage = Math.ceil(data.totalItems / this.itemsPerPage);
                    this.result = data;
                    this.loading = false;
                })
                .catch((error) => {
                    console.error(error);
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
    },
};
</script>
