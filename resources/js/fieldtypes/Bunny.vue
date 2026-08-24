<template>
    <div>
        <Combobox
            ref="input"
            class="tw:flex-1"
            :clearable="true"
            :disabled="false"
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
    </div>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Combobox } from '@statamic/cms/ui';
const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { defineReplicatorPreview, expose, update } = Fieldtype.use(emit, props);
defineExpose(expose);

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
    fetch(props.meta.listUrl)
        .then((response) => response.json())
        .then((items) => {
            videos.value = Array.isArray(items) ? items : [];
        })
        .catch((error) => {
            console.error(error);
        });
}

function comboboxUpdated(value) {
    update(value === NONE || ! value ? null : value);
}

onMounted(() => {
    getVideos();
});
</script>
