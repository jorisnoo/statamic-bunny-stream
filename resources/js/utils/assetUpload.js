import Uppy from '@uppy/core';
import { reactive } from 'vue';
import * as api from './api.js';
import UppyBunnyCreator from './UppyBunnyCreator.js';
import { useBunnyTus } from './bunnyTus.js';

let uppy = null;
let target = null;

// What the upload modal (components/AssetUpload.vue) shows; kept here so uploads outlive the modal.
export const assetUploads = reactive({ open: false, files: [] });

// The container and folder open in the asset browser, read from its URL (assets/browse/{container}/{folder}).
export function browseLocation() {
    const base = new URL(`${Statamic.$config.get('cpUrl')}/assets/browse/`, window.location.origin).pathname.replace(/\/+/g, '/');
    if (!window.location.pathname.startsWith(base)) {
        return null;
    }
    const [container, ...segments] = decodeURIComponent(window.location.pathname.slice(base.length)).split('/').filter(Boolean);

    // An open asset editor appends "{file}/edit".
    return container ? { container, folder: (segments.at(-1) === 'edit' ? segments.slice(0, -2) : segments).join('/') } : null;
}

function status(file) {
    if (file.error || file.meta.importError) return 'error';
    if (file.meta.imported) return 'done';
    if (file.progress.uploadComplete) return 'importing';
    return 'uploading';
}

function sync() {
    assetUploads.files = uppy.getFiles().map((file) => ({
        id: file.id,
        name: file.name,
        percent: file.progress.percentage ?? 0,
        status: status(file),
        error: file.meta.importError || file.error,
    }));
}

// Create the asset; the server fills in its file once Bunny serves it.
function importAsset(file) {
    uppy.setFileMeta(file.id, { importError: null });
    api.post(`${file.meta.endpoint}/${file.meta.bunnyId}`, {})
        .then(() => {
            uppy.setFileMeta(file.id, { imported: true });
            if (assetUploads.files.every((upload) => ['done', 'error'].includes(upload.status))) {
                Statamic.$toast.success(__('Upload complete. Reload the page to see the new videos.'));
            }
        })
        .catch((error) => {
            console.error('Bunny asset import failed', error);
            uppy.setFileMeta(file.id, { importError: error.message });
            Statamic.$toast.error(__('Upload failed for :file: :details', { file: file.name, details: error.message }));
        });
}

function setup() {
    uppy = new Uppy({ autoProceed: true, restrictions: { allowedFileTypes: ['video/*', '.mkv'] } })
        .use(UppyBunnyCreator, {
            create: (file) => api.post(file.meta.endpoint, {
                container: file.meta.container,
                folder: file.meta.folder,
                filename: file.name,
            }),
        });

    useBunnyTus(uppy);

    // Remember where each file was added; the modal can be reopened from another folder.
    uppy.on('file-added', (file) => {
        const location = browseLocation();
        uppy.setFileMeta(file.id, { ...target, folder: location?.container === target.container ? location.folder : '' });
    });

    uppy.on('restriction-failed', (file, error) => Statamic.$toast.error(error.message));
    uppy.on('upload-success', (file) => importAsset(uppy.getFile(file.id)));
    uppy.on('state-update', sync);
}

export function addUploadFiles(files) {
    uppy.addFiles([...files].map((data) => ({ name: data.name, type: data.type, data, source: 'Local' })));
}

export function retryUpload(id) {
    const file = uppy.getFile(id);
    file.meta.importError ? importAsset(file) : uppy.retryUpload(id);
}

export function discardUpload(id) {
    uppy.removeFile(id);
}

// Finished uploads have nothing left to show once the modal is closed.
export function clearFinishedUploads() {
    uppy.getFiles().filter((file) => file.meta.imported).forEach((file) => uppy.removeFile(file.id));
}

/**
 * Upload videos into an asset container. Files go from the browser straight to Bunny,
 * then the server copies each one into the container as an asset.
 */
export function openAssetUpload({ container, endpoint }) {
    target = { container, endpoint };

    if (!uppy) {
        setup();
        Statamic.$components.append('bunny-asset-upload', { props: {} });
    }

    assetUploads.open = true;
}
