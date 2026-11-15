<template>
    <Button variant="primary" :text="__('Upload Media')" icon="upload" id="bunny-upload" />
</template>

<script>
import { Button } from '@statamic/cms/ui';
import Uppy from '@uppy/core';
import Dashboard from '@uppy/dashboard';
import { markRaw } from 'vue';
import { emitter } from '@/utils/emitter.js';
import UppyBunnyCreator from '@/utils/UppyBunnyCreator.js';
import { useBunnyTus } from '@/utils/bunnyTus.js';

export default {
    components: {
        Button
    },
    inject: ['bunnyEndpoint'],
    data() {
        return {
            uploader: null,
        };
    },
    methods: {
        openDashboard() {
            document.getElementById('bunny-upload')?.click();
        },
        initializeUppy() {
            this.uploader = markRaw(new Uppy()
                .use(Dashboard, {
                    inline: false,
                    trigger: '#bunny-upload',
                    width: 'auto',
                    proudlyDisplayPoweredByUppy: false,
                    closeModalOnClickOutside: true,
                    closeAfterFinish: true,
                    metaFields: [
                        { id: 'name', name: __('Name'), placeholder: __('Filename') },
                        { id: 'thumbTime', name: __('Timestamp'), placeholder: __('hh:mm:ss e.g. 00:01:04 for minute 1, second 4') },
                        {
                            id: 'bunnyId', name: __('Bunny ID'),
                            render: ({ value }, h) => {
                                return h(
                                    'input',
                                    {
                                        type: 'text',
                                        class: 'uppy-u-reset uppy-c-textInput uppy-Dashboard-FileCard-input tw:bg-gray-300',
                                        value: value,
                                        placeholder: __('Bunny ID'),
                                        disabled: true
                                    },
                                    []
                                );
                            }
                        }
                    ],
                })
                .use(UppyBunnyCreator, {
                    endpoint: this.bunnyEndpoint
                }));

            useBunnyTus(this.uploader);

            this.uploader.on('complete', (result) => {
                if (result.successful.length > 0) {
                    const message = result.successful.length === 1 ? __('1 video uploaded successfully.') : __(':count videos successfully uploaded.', { count: result.successful.length });
                    Statamic.$toast.success(message);
                }

                emitter.emit('load');
            });
        }
    },
    created() {
        emitter.on('upload', this.openDashboard);
    },
    mounted() {
        this.initializeUppy();
    },
    beforeUnmount() {
        // Inertia remounts the page on every visit; don't leave handlers or a second dashboard behind.
        emitter.off('upload', this.openDashboard);
        this.uploader?.destroy();
    }
};
</script>
