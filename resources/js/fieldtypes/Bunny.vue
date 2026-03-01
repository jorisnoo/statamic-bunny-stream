<template>
    <div>
        <Combobox
            ref="input"
            class="tw:flex-1"
            :clearable="true"
            :disabled="false"
            :options="options"
            :placeholder="__('Select Media...')"
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
import { ref, onMounted } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Combobox } from '@statamic/cms/ui';
const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, update } = Fieldtype.use(emit, props);
defineExpose(expose);

const input = ref(null);
const loading = ref(true);
const videos = ref([]);
const options = ref([]);

if (props.value && props.meta.initialTitle) {
    const label = props.meta.initialDate
        ? `${props.meta.initialTitle} (${new Date(props.meta.initialDate).toLocaleString()})`
        : props.meta.initialTitle;
    options.value = [{ value: props.value, label, image: props.meta.initialThumbnail }];
}

function thumbnailUrl(guid) {
    return props.meta.thumbnailUrl.replace('__GUID__', guid);
}

function getVideos() {
    fetch(props.meta.listUrl)
        .then((response) => response.json())
        .then((items) => {
            videos.value = { items };
            loading.value = false;
            arrangeVideos();
        })
        .catch((error) => {
            console.error(error);
        });
}

function arrangeVideos() {
    options.value = videos.value.items.map((video) => ({
        value: video.guid,
        label: `${video.title} (${new Date(video.dateUploaded).toLocaleString()})`,
        image: thumbnailUrl(video.guid),
    }));
}

function comboboxUpdated(value) {
    update(value);
}

onMounted(() => {
    getVideos();
});
</script>
