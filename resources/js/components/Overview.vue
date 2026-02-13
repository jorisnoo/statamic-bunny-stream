<script setup>
import { provide } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { DocsCallout } from '@statamic/cms/ui';
import VideoBrowser from './VideoBrowser.vue';
import Uploader from './Uploader.vue';
import Affiliate from './Affiliate.vue';

const props = defineProps({
    title: String,
    addon: Object,
    bunny: Object,
});

provide('bunnyApiKey', props.bunny.apiKey);
provide('bunnyHostname', props.bunny.hostname);
provide('bunnyLibrary', props.bunny.library);
</script>

<template>
    <Head :title="title" />

    <template v-if="bunny.apiKey && bunny.hostname && bunny.library">
        <div class="flex gap-2 justify-between">
            <h1 class="flex-grow mb-4">{{ title }}</h1>
            <Uploader />
        </div>
        <VideoBrowser />
    </template>
    <template v-else>
        <Affiliate />
    </template>

    <DocsCallout :topic="addon.name" :url="addon.url" />
</template>
