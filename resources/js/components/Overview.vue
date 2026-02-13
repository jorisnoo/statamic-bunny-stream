<script setup>
import { provide } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { DocsCallout, Header } from '@statamic/cms/ui';
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
        <Header :title="title">
            <Uploader />
        </Header>
        <VideoBrowser />
    </template>
    <template v-else>
        <Affiliate />
    </template>

    <DocsCallout :topic="addon.name" :url="addon.url" />
</template>
