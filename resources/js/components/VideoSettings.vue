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
                </div>
                <div class="px-5 py-3 bg-gray-200 rounded-b-lg border-t flex items-center justify-end text-sm">
                    <button class="text-gray hover:text-gray-900" @click="isOpen = false" v-text="__('Cancel')"/>
                    <button class="ml-4 btn-primary" v-text="__('Save')" @click="save"/>
                </div>
            </div>
        </modal>
    </div>
</template>

<script>
import CogIcon from "../icons/Cog.vue";
import {emitter} from '@/utils/emitter.js';

export default {
    components: {CogIcon},
    inject: ['bunnyApiKey', 'bunnyHostname', 'bunnyLibrary'],
    props: {
        id: String,
        title: String,
    },
    data() {
        return {
            isOpen: false,
            videoTitle: this.title,
        }
    },
    methods: {
        save() {
            if (this.title !== this.videoTitle) {
                this.$progress.start('title');
                this.changeTitle();
            }

            this.isOpen = false;
        },
        changeTitle() {
            fetch(`https://video.bunnycdn.com/library/${this.bunnyLibrary}/videos/${this.id}`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/*+json',
                    AccessKey: this.bunnyApiKey,
                },
                body: JSON.stringify({ title: this.videoTitle }),
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`Failed to update title: ${response.status}`);
                    }

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
    }
}
</script>
