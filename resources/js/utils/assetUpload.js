import Uppy from '@uppy/core';
import Dashboard from '@uppy/dashboard';
import * as api from './api.js';
import UppyBunnyCreator from './UppyBunnyCreator.js';
import { useBunnyTus } from './bunnyTus.js';

let uppy = null;
let target = null;

// The folder open in the asset browser, read from its URL (assets/browse/{container}/{folder}).
function currentFolder(container) {
    const base = new URL(`${Statamic.$config.get('cpUrl')}/assets/browse/${container}`, window.location.origin).pathname.replace(/\/+/g, '/');
    if (!window.location.pathname.startsWith(base)) {
        return '';
    }
    const segments = decodeURIComponent(window.location.pathname.slice(base.length)).split('/').filter(Boolean);

    // An open asset editor appends "{file}/edit".
    return (segments.at(-1) === 'edit' ? segments.slice(0, -2) : segments).join('/');
}

/**
 * Upload videos into an asset container. Files go from the browser straight to Bunny,
 * then the server copies each one into the container as an asset.
 */
export function openAssetUpload({ container, endpoint }) {
    target = { container, endpoint };

    if (!uppy) {
        uppy = new Uppy({ restrictions: { allowedFileTypes: ['video/*', '.mkv'] } })
            .use(Dashboard, {
                inline: false,
                width: 'auto',
                proudlyDisplayPoweredByUppy: false,
                closeAfterFinish: true,
            })
            .use(UppyBunnyCreator, {
                create: (file) => api.post(file.meta.endpoint, {
                    container: file.meta.container,
                    folder: file.meta.folder,
                    filename: file.name,
                }),
            });

        useBunnyTus(uppy);

        // Remember where each file was added; the modal can be reopened from another folder.
        uppy.on('file-added', (file) => uppy.setFileMeta(file.id, { ...target, folder: currentFolder(target.container) }));

        uppy.on('upload-success', (file) => {
            api.post(`${file.meta.endpoint}/${file.meta.bunnyId}`, {}).catch((error) => {
                console.error('Bunny asset import failed', error);
                Statamic.$toast.error(__('Upload failed for :file: :details', { file: file.name, details: error.message }));
            });
        });

        uppy.on('complete', (result) => {
            if (result.successful.length > 0) {
                Statamic.$toast.success(__('Upload complete. The video appears here once it has been copied from Bunny; reload in a few minutes.'));
            }
        });
    }

    uppy.getPlugin('Dashboard').openModal();
}
