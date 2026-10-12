<template>
    <div class="tw:relative tw:w-full tw:aspect-video tw:overflow-hidden tw:rounded-lg tw:bg-black">
        <iframe
            v-if="src"
            :key="playerUrl"
            :src="playerUrl"
            :title="title || __('Video preview')"
            class="tw:absolute tw:inset-0 tw:size-full tw:border-0"
            allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen"
            allowfullscreen
        />
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    // Signed Bunny embed URL from the server; player options are appended here.
    src: { type: String, default: null },
    title: { type: String, default: null },
    autoplay: { type: Boolean, default: false },
    // Start position in seconds.
    start: { type: Number, default: null },
});

const playerUrl = computed(() => {
    if (!props.src) return null;
    const url = new URL(props.src);
    url.searchParams.set('autoplay', props.autoplay ? 'true' : 'false');
    url.searchParams.set('preload', 'true');
    if (props.start) url.searchParams.set('t', `${Math.floor(props.start)}s`);
    return url.toString();
});
</script>
