<template>
    <div>
        <div class="tw:flex tw:items-center tw:justify-between tw:mb-4">
            <h3 class="tw:text-sm tw:font-semibold tw:text-gray-900 tw:dark:text-dark-100">{{ __('Chapters') }}</h3>
            <div class="tw:flex tw:gap-2">
                <button
                    class="btn-flat btn-sm"
                    :disabled="isGenerating"
                    @click="autoGenerate"
                >
                    {{ isGenerating ? __('Generating...') : __('Auto-generate') }}
                </button>
                <button
                    class="btn-flat btn-sm"
                    :disabled="isGenerating"
                    @click="addChapter"
                >
                    {{ __('Add Chapter') }}
                </button>
            </div>
        </div>

        <div v-if="isGenerating" class="tw:flex tw:items-center tw:gap-2 tw:text-sm tw:text-gray-500 tw:py-4">
            <svg class="tw:size-4 tw:animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="tw:opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="tw:opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            {{ __('Generating chapters... This may take a few minutes.') }}
        </div>

        <div v-else-if="localChapters.length === 0" class="tw:text-sm tw:text-gray-500 tw:py-4">
            {{ __('No chapters yet.') }}
        </div>

        <div v-else class="tw:space-y-2">
            <div
                v-for="(chapter, index) in localChapters"
                :key="index"
                class="tw:flex tw:items-center tw:gap-2 tw:rounded tw:border tw:border-gray-300 tw:dark:border-dark-200 tw:p-2"
            >
                <input
                    v-model="chapter.title"
                    type="text"
                    :placeholder="__('Chapter title')"
                    class="tw:flex-1 tw:text-sm tw:border tw:border-gray-300 tw:dark:border-dark-200 tw:rounded tw:px-2 tw:py-1 tw:bg-white tw:dark:bg-dark-500"
                    @input="emitChapters"
                />
                <input
                    v-model="chapter.startFormatted"
                    type="text"
                    placeholder="00:00"
                    class="tw:w-20 tw:text-sm tw:text-center tw:border tw:border-gray-300 tw:dark:border-dark-200 tw:rounded tw:px-2 tw:py-1 tw:bg-white tw:dark:bg-dark-500"
                    @blur="onTimeBlur(chapter, 'start')"
                    @input="emitChapters"
                />
                <span class="tw:text-gray-400 tw:text-sm">-</span>
                <input
                    v-model="chapter.endFormatted"
                    type="text"
                    placeholder="00:00"
                    class="tw:w-20 tw:text-sm tw:text-center tw:border tw:border-gray-300 tw:dark:border-dark-200 tw:rounded tw:px-2 tw:py-1 tw:bg-white tw:dark:bg-dark-500"
                    @blur="onTimeBlur(chapter, 'end')"
                    @input="emitChapters"
                />
                <button
                    @click="removeChapter(index)"
                    class="tw:text-gray-400 tw:hover:text-red-500 tw:cursor-pointer tw:shrink-0"
                >
                    <svg class="tw:size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>

<script>
import { formatTime, parseTime } from '@/utils/time.js';

export default {
    inject: ['bunnyApiKey', 'bunnyLibrary'],
    props: {
        chapters: {
            type: Array,
            default: () => [],
        },
        videoGuid: {
            type: String,
            required: true,
        },
    },
    emits: ['update:chapters'],
    data() {
        return {
            localChapters: this.toLocalChapters(this.chapters),
            isGenerating: false,
            pollingInterval: null,
            pollCount: 0,
        };
    },
    watch: {
        chapters(newVal) {
            this.localChapters = this.toLocalChapters(newVal);
        },
    },
    beforeUnmount() {
        this.stopPolling();
    },
    methods: {
        toLocalChapters(chapters) {
            return (chapters || []).map((ch) => ({
                title: ch.title || '',
                start: ch.start || 0,
                end: ch.end || 0,
                startFormatted: formatTime(ch.start || 0),
                endFormatted: formatTime(ch.end || 0),
            }));
        },

        addChapter() {
            const lastEnd = this.localChapters.length > 0
                ? this.localChapters[this.localChapters.length - 1].end
                : 0;

            this.localChapters.push({
                title: '',
                start: lastEnd,
                end: lastEnd,
                startFormatted: formatTime(lastEnd),
                endFormatted: formatTime(lastEnd),
            });

            this.emitChapters();
        },

        removeChapter(index) {
            this.localChapters.splice(index, 1);
            this.emitChapters();
        },

        onTimeBlur(chapter, field) {
            const formatted = field === 'start' ? chapter.startFormatted : chapter.endFormatted;
            const seconds = parseTime(formatted);

            chapter[field] = seconds;

            if (field === 'start') {
                chapter.startFormatted = formatTime(seconds);
            } else {
                chapter.endFormatted = formatTime(seconds);
            }

            this.emitChapters();
        },

        emitChapters() {
            this.$emit('update:chapters', this.localChapters.map((ch) => ({
                title: ch.title,
                start: parseTime(ch.startFormatted),
                end: parseTime(ch.endFormatted),
            })));
        },

        autoGenerate() {
            this.isGenerating = true;
            this.pollCount = 0;

            fetch(`https://video.bunnycdn.com/library/${this.bunnyLibrary}/videos/${this.videoGuid}/transcribe`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    AccessKey: this.bunnyApiKey,
                },
                body: JSON.stringify({
                    generateChapters: true,
                }),
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Failed to start chapter generation');
                    }

                    this.startPolling();
                })
                .catch((error) => {
                    console.error(error);
                    this.isGenerating = false;
                    Statamic.$toast.error(__('Failed to start chapter generation.'));
                });
        },

        startPolling() {
            this.pollingInterval = setInterval(() => {
                this.pollCount++;

                if (this.pollCount > 24) {
                    this.stopPolling();
                    this.isGenerating = false;
                    Statamic.$toast.info(__('Chapter generation is taking longer than expected. Please check back later.'));
                    return;
                }

                fetch(`https://video.bunnycdn.com/library/${this.bunnyLibrary}/videos/${this.videoGuid}`, {
                    headers: {
                        Accept: 'application/json',
                        AccessKey: this.bunnyApiKey,
                    },
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.chapters && data.chapters.length > 0) {
                            this.stopPolling();
                            this.isGenerating = false;
                            this.localChapters = this.toLocalChapters(data.chapters);
                            this.emitChapters();
                            Statamic.$toast.success(__('Chapters generated successfully.'));
                        }
                    })
                    .catch((error) => {
                        console.error(error);
                    });
            }, 5000);
        },

        stopPolling() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
                this.pollingInterval = null;
            }
        },
    },
};
</script>
