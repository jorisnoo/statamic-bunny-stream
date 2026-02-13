<template>
    <div>
        <button @click="isOpen = true">
            <CogIcon class="size-5"/>
        </button>

        <modal
            v-model:open="isOpen"
            name="settings"
        >
            <div class="flex flex-col h-full">
                <header
                    class="text-lg font-semibold px-5 py-3 bg-gray-200 rounded-t-lg flex items-center justify-between border-b">
                    {{ __('Video Settings') }}
                </header>
                <div class="flex-1 px-5 py-6 text-gray">
                    <div class="px-2 m-0 @container form-group publish-field text-fieldtype w-full">
                        <label for="title" class="block">
                            {{ __('Title') }}
                        </label>
                        <div class="input-group">
                            <input type="text" id="title" name="title" v-model="videoTitle" class="input-text">
                        </div>
                    </div>

                    <div class="px-2 m-0 @container form-group publish-field text-fieldtype w-full">
                        <label for="thumbnail" class="block">
                            {{ __('Thumbnail') }}
                        </label>
                        <div class="input-group">
                            <Combobox
                                ref="input"
                                class="flex-1"
                                :clearable="false"
                                :disabled="false"
                                :options="assetOptions"
                                :placeholder="__('Select new thumbnail')"
                                :searchable="true"
                                :multiple="false"
                                :close-on-select="true"
                                :model-value="selectedThumbnails"
                                @update:model-value="selectThumbnail">
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
                    </div>
                </div>
                <div class="px-5 py-3 bg-gray-200 rounded-b-lg border-t flex items-center justify-end text-sm">
                    <button class="text-gray hover:text-gray-900" @click="isOpen = false" v-text="__('Cancel')"/>
                    <button class="ml-4 btn-primary" :class="buttonClass" v-text="__('Save')" @click="save"/>
                </div>
            </div>
        </modal>
    </div>
</template>

<script>
import { Combobox } from '@statamic/cms/ui';
import CogIcon from "../icons/Cog.vue";
import axios from 'axios';
import {emitter} from '@/utils/emitter.js';

export default {
    components: {CogIcon, Combobox},
    inject: ['bunnyApiKey', 'bunnyHostname', 'bunnyLibrary'],
    props: {
        id: String,
        title: String,
        assetOptions: [],
    },
    data() {
        return {
            isOpen: false,
            videoTitle: this.title,
            selectedThumbnails: [],
        }
    },
    methods: {
        save() {
            if (this.title !== this.videoTitle) {
                this.$progress.start('title');
                this.changeTitle();
            }

            if (this.selectedThumbnails.length > 0) {
                this.$progress.start('thumbnail');
                this.changeThumbnail();
            }

            this.isOpen = false;
        },
        changeTitle() {
            const options = {
                method: 'POST',
                url: `https://video.bunnycdn.com/library/${this.bunnyLibrary}/videos/${this.id}`,
                headers: {
                    Accept: 'application/json',
                    'content-type': 'application/*+json',
                    AccessKey: this.bunnyApiKey
                },
                data: '{"title":"' + this.videoTitle + '"}'
            };

            axios
                .request(options)
                .then(() => {
                    this.$toast.success(__('Video title has been updated!'));
                    this.$progress.complete('title');
                    emitter.emit('load');
                })
                .catch((error) => {
                    this.$toast.error(__('An error occured while trying to update the video title.'));
                    this.$progress.complete('title');
                    console.error(error);
                });
        },
        changeThumbnail() {
            const options = {
                method: 'POST',
                url: `https://video.bunnycdn.com/library/${this.bunnyLibrary}/videos/${this.id}/thumbnail?thumbnailUrl=${this.selectedThumbnails[0].url}`,
                headers: {
                    Accept: 'application/json',
                    AccessKey: this.bunnyApiKey
                }
            };

            axios
                .request(options)
                .then(() => {
                    this.$toast.success(__('Thumbnail has been updated!'));
                    this.thumbnailUrl = null;
                    this.$progress.complete('thumbnail');
                    emitter.emit('load');
                })
                .catch((error) => {
                    this.$toast.error(__('An error occured while trying to update the thumbnail.'));
                    this.$progress.complete('thumbnail');
                    console.error(error);
                });
        },
        selectThumbnail(value) {
            this.selectedThumbnails = [value];
        },
    }
}
</script>
