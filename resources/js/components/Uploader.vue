<template>
    <Button variant="primary" :text="__('Upload Media')" icon="upload" id="bunny-upload" />
</template>

<script>
import { Button } from '@statamic/cms/ui';
import Uppy from '@uppy/core';
import Dashboard from '@uppy/dashboard';
import Tus from '@uppy/tus';
import { markRaw } from 'vue';
import { emitter } from '@/utils/emitter.js';
import UppyBunnyCreator from '@/utils/UppyBunnyCreator.js';

export default {
    components: {
        Button
    },
    inject: ['bunnyEndpoint'],
    data() {
        return {
            uploader: null
        };
    },
    methods: {
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
                })
                .use(Tus, {
                    endpoint: 'https://video.bunnycdn.com/tusupload',
                    retryDelays: [0, 30, 50, 3000, 5000, 10000, 60000],
                    onBeforeRequest: (req, file) => {
                        const upload = this.uploader.getFile(file.id).meta.bunnyUpload;
                        if (!upload) {
                            throw new Error('Missing Bunny upload authorization');
                        }

                        req.setHeader('AuthorizationSignature', upload.signature);
                        req.setHeader('AuthorizationExpire', upload.expires);
                        req.setHeader('VideoId', upload.videoId);
                        req.setHeader('LibraryId', upload.libraryId);
                    }
                }));

            this.uploader.on('complete', (result) => {
                if (result.successful.length > 0) {
                    const message = result.successful.length === 1 ? __('1 video uploaded successfully.') : __(':count videos successfully uploaded.', { count: result.successful.length });
                    console.log(message); // Replace with toast if needed
                }

                if (result.failed.length > 0) {
                    console.log('Failed files: ', result.failed);
                }

                emitter.emit('load');
            });
        }
    },
    created() {
        emitter.on('upload', () => document.getElementById('bunny-upload').click());
    },
    mounted() {
        this.initializeUppy();
    }
};
</script>
