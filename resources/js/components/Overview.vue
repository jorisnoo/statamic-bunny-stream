<script setup>
import { provide } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { DocsCallout, Header } from '@statamic/cms/ui';
import MediaBrowser from './MediaBrowser.vue';
import Uploader from './Uploader.vue';
import Affiliate from './Affiliate.vue';

const props = defineProps({
    title: String,
    addon: Object,
    bunny: Object,
});

provide('bunnyEndpoint', props.bunny.endpoint);
provide('bunnyHostname', props.bunny.hostname);
provide('bunnyChaptersEnabled', props.bunny.chapters);
</script>

<template>
    <Head :title="title" />

    <template v-if="bunny.configured">
        <Header :title="title">
            <Uploader />
        </Header>
        <MediaBrowser />
    </template>
    <template v-else>
        <Affiliate />
    </template>

    <DocsCallout :topic="addon.name" :url="addon.url" />
</template>
