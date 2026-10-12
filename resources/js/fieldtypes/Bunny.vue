<template>
    <div>
        <Combobox
            ref="input"
            class="tw:flex-1"
            :clearable="true"
            :disabled="isReadOnly"
            :options="options"
            :placeholder="__('Select video...')"
            :searchable="true"
            :multiple="false"
            :close-on-select="true"
            :model-value="value"
            @update:model-value="comboboxUpdated"
            @focus="$emit('focus')"
            @blur="$emit('blur')">
                <template #selected-option="{ option }">
                    <img v-if="option.image" :src="option.image" class="tw:w-5 tw:h-5 tw:rounded tw:object-cover tw:mr-1.5" />
                    {{ option.label }}
                </template>
                <template #no-options>
                    <div class="tw:text-sm tw:text-gray-700 tw:text-left tw:py-2 tw:px-4" v-text="__('No options to choose from.')" />
                </template>
        </Combobox>

        <div v-if="value && selectedOption?.image" class="tw:mt-3 tw:max-w-xs">
            <VideoPoster
                :thumbnail="selectedOption.image"
                :duration="preview?.duration ?? selectedOption.duration"
                :label="__('Play :title', { title: selectedOption.title })"
                :disabled="loadingPreview"
                @play="openPreview"
            />
        </div>

        <PlayerStack
            v-model:open="previewOpen"
            :src="preview?.embed_url"
            :title="preview?.title"
            :duration="preview?.duration"
            :width="preview?.width"
            :height="preview?.height"
        />
    </div>
</template>

<script setup>
import { computed, getCurrentInstance, ref, onMounted } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Combobox } from '@statamic/cms/ui';
import PlayerStack from '../components/player/PlayerStack.vue';
import VideoPoster from '../components/player/VideoPoster.vue';
const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { defineReplicatorPreview, expose, isReadOnly, update } = Fieldtype.use(emit, props);
defineExpose(expose);
// Statamic 6 exposes its configured axios (CSRF, session handling) only to components.
const http = getCurrentInstance().appContext.config.globalProperties.$axios;

// Sentinel for "no video selected". The combobox needs a real option value to
// select, but we always store null.
const NONE = '__none__';

const input = ref(null);
const videos = ref(null);

// The video the field was loaded with. It may since have been deleted from the
// library, in which case it won't show up in the fetched list.
const initialValue = props.value;
const initialOption = props.value && props.meta.initialTitle
    ? {
        value: props.value,
        label: props.meta.initialDate
            ? `${props.meta.initialTitle} (${new Date(props.meta.initialDate).toLocaleString()})`
            : props.meta.initialTitle,
        title: props.meta.initialTitle,
        image: props.meta.initialThumbnail,
    }
    : null;

const options = computed(() => {
    const items = videos.value === null
        ? (initialOption ? [initialOption] : [])
        : videos.value.map((video) => ({
            value: video.guid,
            label: `${video.title} (${new Date(video.dateUploaded).toLocaleString()})`,
            title: video.title,
            image: thumbnailUrl(video.guid),
            duration: video.length,
        }));

    // Keep a selected video that's no longer in the library around, otherwise
    // there'd be nothing to show and nothing to replace it with.
    if (props.value && ! items.some((item) => item.value === props.value)) {
        const title = props.value === initialValue && initialOption ? initialOption.title : props.value;

        items.push({
            value: props.value,
            label: `${title} (${__('video not found')})`,
            title,
        });
    }

    return [{ value: NONE, label: __('No video') }, ...items];
});

defineReplicatorPreview(() => {
    if (! props.value) return null;

    return options.value.find((option) => option.value === props.value)?.title ?? props.value;
});

function thumbnailUrl(guid) {
    return props.meta.thumbnailUrl.replace('__GUID__', guid);
}

function getVideos() {
    http.get(props.meta.listUrl)
        .then(({ data }) => {
            videos.value = Array.isArray(data) ? data : [];
        })
        .catch((error) => {
            Statamic.$toast.error(error.response?.data?.message || __('Could not load videos.'));
        });
}

const selectedOption = computed(() => options.value.find((option) => option.value === props.value && option.value !== NONE));
const preview = ref(null);
const previewOpen = ref(false);
const loadingPreview = ref(false);

// Fetch the signed player URL on demand; it can't be built in the browser.
function openPreview() {
    if (preview.value?.guid === props.value) {
        previewOpen.value = true;
        return;
    }
    loadingPreview.value = true;
    http.get(props.meta.previewUrl.replace('__GUID__', props.value))
        .then(({ data }) => {
            preview.value = { ...data, guid: props.value };
            previewOpen.value = true;
        })
        .catch((error) => Statamic.$toast.error(error.response?.data?.message || __('Could not load the video.')))
        .finally(() => (loadingPreview.value = false));
}

function comboboxUpdated(value) {
    update(value === NONE || ! value ? null : value);
}

onMounted(() => {
    getVideos();
});
</script>
