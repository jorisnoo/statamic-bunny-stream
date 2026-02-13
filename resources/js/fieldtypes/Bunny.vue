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
            @update:model-value="vueSelectUpdated"
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

<script>
import { Combobox } from '@statamic/cms/ui';
import { FieldtypeMixin as Fieldtype } from '@statamic/cms';
import axios from 'axios';

export default {
    mixins: [Fieldtype],
    components: { Combobox },
    data() {
        return {
            loading: true,
            videos: [],
            options: []
        };
    },
    computed: {
        selectedOptions() {
            let selections = this.value || [];
            if (typeof selections === 'string' || typeof selections === 'number') {
                selections = [selections];
            }

            return selections.map(value => {
                return this.options.find(opt => opt.value === value) || { value, label: value };
            });
        },
    },
    created() {
        this.getVideos();
    },
    methods: {
        getVideos() {
            const options = {
                method: 'GET',
                url: `https://video.bunnycdn.com/library/${this.meta.library}/videos?page=1&itemsPerPage=100&orderBy=date`,
                headers: {
                    accept: 'application/json',
                    AccessKey: this.meta.api
                }
            };

            axios
            .request(options)
            .then((response) => {
                this.videos = response.data;
                this.loading = false;

                this.arrangeVideos();
            })
            .catch(function (error) {
                console.error(error);
            });
        },
        arrangeVideos() {
            this.videos.items.forEach((video) => {
                this.options.push({
                    value: video.guid,
                    label: `${video.title} (${new Date(video.dateUploaded).toLocaleString()})`
                });
            });
        },
        focus() {
            this.$refs.input.focus();
        },
        vueSelectUpdated(value) {
            if (value) {
                this.update(value.value)
            } else {
                this.update(null);
            }
        },
    }
};
</script>
