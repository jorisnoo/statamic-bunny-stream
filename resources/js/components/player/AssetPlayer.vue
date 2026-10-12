<template>
    <PlayerStack
        v-model:open="open"
        :src="stream?.embed_url"
        :title="stream?.title || current.title"
        :duration="stream?.duration"
        :width="stream?.width"
        :height="stream?.height"
        :chapters="stream?.chapters || []"
    >
        <template v-if="error" #aside>
            <Alert variant="error" :text="error" />
        </template>
        <template v-if="items.length > 1" #playlist>
            <ol class="tw:flex tw:gap-3 tw:overflow-x-auto tw:p-1" :aria-label="__('Videos')">
                <li v-for="(item, index) in items" :key="item.id" class="tw:w-40 tw:shrink-0">
                    <VideoPoster
                        :thumbnail="item.thumbnail"
                        compact
                        :label="__('Play :title', { title: item.title })"
                        :class="index === position ? 'tw:ring-2 tw:ring-blue-500 tw:ring-offset-2' : 'tw:opacity-80 tw:hover:opacity-100'"
                        @play="position = index"
                    >
                        <span class="tw:block tw:truncate tw:pe-12 tw:text-xs">{{ item.title }}</span>
                    </VideoPoster>
                </li>
            </ol>
        </template>
    </PlayerStack>
</template>

<script setup>
import { computed, getCurrentInstance, ref, watch } from 'vue';
import { Alert } from '@statamic/cms/ui';
import PlayerStack from './PlayerStack.vue';
import VideoPoster from './VideoPoster.vue';

// Opened from the Assets field's "Play video" action with the field's streamed assets.
const props = defineProps({
    items: { type: Array, required: true },
});
const emit = defineEmits(['closed']);
const http = getCurrentInstance().appContext.config.globalProperties.$axios;

const open = ref(true);
const position = ref(0);
const stream = ref(null);
const error = ref('');
const current = computed(() => props.items[position.value]);

async function load() {
    const item = current.value;
    stream.value = null;
    error.value = '';
    try {
        const { data } = await http.get(item.endpoint, { params: { asset: item.id } });
        if (item === current.value) stream.value = data;
    } catch (e) {
        error.value = e.response?.data?.message || __('Could not load the video.');
    }
}

watch(position, load, { immediate: true });
watch(open, (value) => { if (!value) emit('closed'); });
</script>
