import Tus from '@uppy/tus';

const noResumeStorage = {
    listAllUploads: () => Promise.resolve([]),
    findUploadsByFingerprint: () => Promise.resolve([]),
    removeUpload: () => Promise.resolve(),
    addUpload: () => Promise.resolve(null),
};

// Resumable upload straight to Bunny, authorized per file by UppyBunnyCreator.
export function useBunnyTus(uppy) {
    uppy.use(Tus, {
        endpoint: 'https://video.bunnycdn.com/tusupload',
        allowedMetaFields: ['filetype', 'title'],
        limit: 3,
        retryDelays: [0, 3000, 5000, 10000, 20000, 60000, 60000],
        storeFingerprintForResuming: false,
        urlStorage: noResumeStorage,
        onBeforeRequest: (req, file) => {
            const upload = uppy.getFile(file.id).meta.bunnyUpload;
            if (!upload) {
                throw new Error('Missing Bunny upload authorization');
            }

            req.setHeader('AuthorizationSignature', upload.signature);
            req.setHeader('AuthorizationExpire', upload.expires);
            req.setHeader('VideoId', upload.videoId);
            req.setHeader('LibraryId', upload.libraryId);
        },
    });

    uppy.on('error', (error) => {
        console.error('Bunny upload setup failed', error);
        Statamic.$toast.error(error.message || __('Unknown error'));
    });

    uppy.on('upload-error', (file, error, response) => {
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

    return uppy;
}
