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
            :model-value="selectedOptions"
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
import { ref, computed, onMounted } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Combobox } from '@statamic/cms/ui';
import axios from 'axios';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, update } = Fieldtype.use(emit, props);
defineExpose(expose);

const input = ref(null);
const loading = ref(true);
const videos = ref([]);
const options = ref([]);

const selectedOptions = computed(() => {
    let selections = props.value || [];

    if (typeof selections === 'string' || typeof selections === 'number') {
        selections = [selections];
    }

    return selections.map(value => {
        return options.value.find(opt => opt.value === value) || { value, label: value };
    });
});

function getVideos() {
    axios
        .request({
            method: 'GET',
            url: `https://video.bunnycdn.com/library/${props.meta.library}/videos?page=1&itemsPerPage=100&orderBy=date`,
            headers: {
                accept: 'application/json',
                AccessKey: props.meta.api,
            },
        })
        .then((response) => {
            videos.value = response.data;
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
    if (value) {
        update(value.value);
    } else {
        update(null);
    }
}

onMounted(() => {
    getVideos();
});
</script>
