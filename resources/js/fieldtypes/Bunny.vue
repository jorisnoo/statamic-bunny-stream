<template>
    <div>
        <Combobox
            ref="input"
            class="flex-1"
            :clearable="false"
            :disabled="false"
            :options="options"
            :placeholder="__('Select Video...')"
            :searchable="true"
            :multiple="false"
            :close-on-select="true"
            :model-value="value"
            @update:model-value="comboboxUpdated"
            @focus="$emit('focus')"
            @blur="$emit('blur')">
                <template #option="{ label }">
                    {{ label }}
                </template>
                <template #selected-option="{ label }">
                    {{ label }}
                </template>
                <template #no-options>
                    <div class="text-sm text-gray-700 text-left py-2 px-4" v-text="__('No options to choose from.')" />
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

function getVideos() {
    fetch(`https://video.bunnycdn.com/library/${props.meta.library}/videos?page=1&itemsPerPage=100&orderBy=date`, {
        headers: {
            Accept: 'application/json',
            AccessKey: props.meta.api,
        },
    })
        .then((response) => response.json())
        .then((data) => {
            videos.value = data;
            loading.value = false;
            arrangeVideos();
        })
        .catch((error) => {
            console.error(error);
        });
}

function arrangeVideos() {
    videos.value.items.forEach((video) => {
        options.value.push({
            value: video.guid,
            label: `${video.title} (${new Date(video.dateUploaded).toLocaleString()})`,
        });
    });
}

function comboboxUpdated(value) {
    update(value);
}

onMounted(() => {
    getVideos();
});
</script>
