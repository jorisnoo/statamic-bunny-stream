<template>
    <button
        type="button"
        class="tw:group tw:relative tw:block tw:w-full tw:aspect-video tw:overflow-hidden tw:rounded-lg tw:bg-gray-900 tw:text-white tw:cursor-pointer tw:disabled:cursor-default tw:focus-visible:outline-2 tw:focus-visible:outline-offset-2 tw:focus-visible:outline-blue-500"
        :disabled="disabled"
        :aria-label="label"
        @click="$emit('play')"
    >
        <img
            v-if="thumbnail && !broken"
            :src="thumbnail"
            alt=""
            class="tw:absolute tw:inset-0 tw:size-full tw:object-cover tw:transition-transform tw:duration-300 tw:group-hover:scale-[1.02]"
            @error="broken = true"
        />
        <span class="tw:absolute tw:inset-0 tw:bg-linear-to-t tw:from-black/50 tw:via-transparent tw:to-transparent" />
        <span
            v-if="!disabled"
            class="tw:absolute tw:left-1/2 tw:top-1/2 tw:flex tw:-translate-x-1/2 tw:-translate-y-1/2 tw:items-center tw:justify-center tw:rounded-full tw:bg-black/60 tw:ring-1 tw:ring-white/30 tw:transition tw:group-hover:bg-black/80 tw:group-hover:scale-105"
            :class="compact ? 'tw:size-8' : 'tw:size-14'"
        >
            <svg class="tw:ms-0.5" :class="compact ? 'tw:size-4' : 'tw:size-6'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11.04-6.86a1 1 0 0 0 0-1.72L9.5 4.28A1 1 0 0 0 8 5.14Z" /></svg>
        </span>
        <span v-if="$slots.default" class="tw:absolute tw:inset-x-0 tw:bottom-0 tw:p-3 tw:text-left tw:text-sm">
            <slot />
        </span>
        <span v-if="duration" class="tw:absolute tw:bottom-2 tw:right-2 tw:rounded tw:bg-black/70 tw:px-1.5 tw:py-0.5 tw:text-xs tw:font-medium tw:tabular-nums">
            {{ formatTime(duration) }}
        </span>
    </button>
</template>

<script setup>
import { ref, watch } from 'vue';
import { formatTime } from '@/utils/time.js';

const props = defineProps({
    thumbnail: { type: String, default: null },
    duration: { type: Number, default: null },
    disabled: { type: Boolean, default: false },
    // Smaller play button for thumbnails in a list.
    compact: { type: Boolean, default: false },
    label: { type: String, default: () => __('Play video') },
});
defineEmits(['play']);

const broken = ref(false);
watch(() => props.thumbnail, () => (broken.value = false));
</script>
