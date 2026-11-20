<script setup>
import { computed, shallowRef, watch } from 'vue';
import { usePage } from '@statamic/cms/inertia';
import { Button } from '@statamic/cms/ui';
import { openAssetUpload } from '../utils/assetUpload.js';

const page = usePage();
const toolbar = shallowRef(null);
const container = computed(() => page.component === 'assets/Browse' && page.props.container);
const action = computed(() => container.value?.actions?.find(({ handle }) => handle === 'upload_video'));

watch([container, action], ([browser, upload], _, onCleanup) => {
    toolbar.value = null;
    if (!browser?.can_upload || !upload) return;

    // Asset Browser 6 renders its default toolbar only after the listing loads.
    // Keep the native container action as a fallback if Statamic changes its header markup.
    function findToolbar() {
        const heading = [...document.querySelectorAll('header[data-ui-header] > h1')]
            .find((element) => element.textContent.trim() === __(browser.title));
        toolbar.value = heading?.nextElementSibling ?? null;
    }

    const observer = new MutationObserver(findToolbar);
    observer.observe(document.body, { childList: true, subtree: true });
    findToolbar();
    onCleanup(() => observer.disconnect());
}, { immediate: true, flush: 'post' });

function upload() {
    openAssetUpload({ container: container.value.id, endpoint: cp_url('bunny/asset-upload') });
}
</script>

<template>
    <Teleport v-if="toolbar && action" :to="toolbar">
        <Button variant="primary" :icon="action.icon" :text="__(action.title)" @click="upload" />
    </Teleport>
</template>
