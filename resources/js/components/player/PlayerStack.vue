<template>
    <Stack :open="open" size="full" :title="title || __('Video preview')" icon="movie-video-clip" @update:open="$emit('update:open', $event)">
        <div class="tw:flex tw:min-h-full tw:flex-col tw:items-center tw:justify-center tw:gap-5 tw:py-4">
            <div class="tw:w-full" :style="{ maxWidth }">
                <BunnyPlayer :src="src" :title="title" :start="start" autoplay />
            </div>

            <div class="tw:flex tw:w-full tw:flex-wrap tw:items-start tw:justify-between tw:gap-4" :style="{ maxWidth }">
                <div class="tw:flex tw:flex-wrap tw:gap-2">
                    <Badge v-if="duration" icon="time-clock" :text="formatTime(duration)" />
                    <Badge v-if="width && height" :text="`${width} × ${height}`" />
                    <slot name="badges" />
                </div>

                <slot name="aside" />

                <nav v-if="chapters.length" class="tw:min-w-60" :aria-label="__('Chapters')">
                    <ol class="tw:flex tw:flex-wrap tw:gap-1.5">
                        <li v-for="(chapter, index) in chapters" :key="index">
                            <Button
                                size="sm"
                                :variant="start === chapter.start ? 'pressed' : 'default'"
                                :text="`${formatTime(chapter.start || 0)} · ${chapter.title || __('Chapter :number', { number: index + 1 })}`"
                                @click="start = chapter.start || 0"
                            />
                        </li>
                    </ol>
                </nav>
            </div>

            <div v-if="$slots.playlist" class="tw:w-full" :style="{ maxWidth }">
                <slot name="playlist" />
            </div>
        </div>
    </Stack>
</template>

<script setup>
import { computed, ref, useSlots, watch } from 'vue';
import { Badge, Button, Stack } from '@statamic/cms/ui';
import BunnyPlayer from './BunnyPlayer.vue';
import { formatTime } from '@/utils/time.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    src: { type: String, default: null },
    title: { type: String, default: null },
    duration: { type: Number, default: null },
    width: { type: Number, default: null },
    height: { type: Number, default: null },
    chapters: { type: Array, default: () => [] },
});
defineEmits(['update:open']);

const slots = useSlots();
// As wide as the stack allows while the player, details and any playlist stay on screen.
const maxWidth = computed(() => `calc((100svh - ${slots.playlist ? 22 : 13}rem) * 16 / 9)`);

const start = ref(null);
// Every opening starts from the beginning.
watch(() => props.open, (open) => { if (open) start.value = null; });
</script>
