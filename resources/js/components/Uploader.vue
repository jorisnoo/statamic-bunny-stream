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

const noResumeStorage = {
    listAllUploads: () => Promise.resolve([]),
    findUploadsByFingerprint: () => Promise.resolve([]),
    removeUpload: () => Promise.resolve(),
    addUpload: () => Promise.resolve(null),
};

export default {
    components: {
        Button
    },
    inject: ['bunnyEndpoint'],
    data() {
        return {
            uploader: null,
            openUploadHandler: null,
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
                    allowedMetaFields: ['filetype', 'title'],
                    limit: 3,
                    retryDelays: [0, 3000, 5000, 10000, 20000, 60000, 60000],
                    storeFingerprintForResuming: false,
                    urlStorage: noResumeStorage,
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

            this.uploader.on('error', (error) => {
                console.error('Bunny upload setup failed', error);
                Statamic.$toast.error(error.message || __('Unknown error'));
            });

            this.uploader.on('upload-error', (file, error, response) => {
                const bunnyResponse = error?.originalResponse;
                const status = bunnyResponse?.getStatus?.() ?? response?.status;
                const body = bunnyResponse?.getBody?.();
                const responseText = typeof body === 'string' ? body.trim() : '';
                const details = [status ? `HTTP ${status}` : null, responseText || error?.message]
                    .filter(Boolean)
                    .join(': ');

                console.error('Bunny upload failed', { file, error, status, response: responseText });
                Statamic.$toast.error(__('Upload failed for :file: :details', {
                    file: file?.name || __('Unknown file'),
                    details: details || __('Unknown error'),
                }));
            });

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
        this.openUploadHandler = () => document.getElementById('bunny-upload')?.click();
        emitter.on('upload', this.openUploadHandler);
    },
    mounted() {
        this.initializeUppy();
    },
    beforeUnmount() {
        emitter.off('upload', this.openUploadHandler);
        this.uploader?.destroy();
    }
};
</script>
