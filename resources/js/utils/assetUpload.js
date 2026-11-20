import Uppy from '@uppy/core';
import { reactive } from 'vue';
import * as api from './api.js';
import UppyBunnyCreator from './UppyBunnyCreator.js';
import { browseLocation } from './nativeAssets.js';
import { useBunnyTus } from './bunnyTus.js';

let uppy = null;
let target = null;

// What the upload modal (components/AssetUpload.vue) shows; kept here so uploads outlive the modal.
export const assetUploads = reactive({ open: false, files: [], destination: '' });

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

const busy = () => uppy?.getFiles().some((file) => ['uploading', 'importing'].includes(status(file)));

// Once nothing is uploading any more, sum up the files that have just been added.
function settle() {
    const files = uppy.getFiles();
    if (busy()) return;
    const added = files.filter((file) => file.meta.imported && !file.meta.announced);
    if (!added.length) return;
    added.forEach((file) => uppy.setFileMeta(file.id, { announced: true }));
    const failed = files.filter((file) => status(file) === 'error').length;
    if (failed) {
        Statamic.$toast.error(__(':count uploaded, :failed failed. Reload the page to see the new videos.', { count: added.length, failed }));
    } else {
        Statamic.$toast.success(__('Upload complete. Reload the page to see the new videos.'));
    }
}

// Create the asset; the server fills in its file once Bunny serves it.
function importAsset(file) {
    uppy.setFileMeta(file.id, { importError: null });
    api.post(`${file.meta.endpoint}/${file.meta.bunnyId}`, {})
        .then(() => uppy.setFileMeta(file.id, { imported: true }))
        .catch((error) => {
            console.error('Bunny asset import failed', error);
            uppy.setFileMeta(file.id, { importError: error.message });
            Statamic.$toast.error(__('Upload failed for :file: :details', { file: file.name, details: error.message }));
        })
        .finally(settle);
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

    // Each file goes where the modal was opened; it can be reopened from another folder.
    uppy.on('file-added', (file) => uppy.setFileMeta(file.id, { ...target }));

    uppy.on('restriction-failed', (file, error) => Statamic.$toast.error(error.message));
    uppy.on('upload-success', (file) => importAsset(uppy.getFile(file.id)));
    uppy.on('upload-error', settle);
    uppy.on('state-update', sync);

    // Leaving the page ends the uploads; the browser asks first.
    window.addEventListener('beforeunload', (event) => {
        if (busy()) event.preventDefault();
    });
}

export function addUploadFiles(files) {
    uppy.addFiles([...files].map((data) => ({ name: data.name, type: data.type, data, source: 'Local' })));
}

export function retryUpload(id) {
    const file = uppy.getFile(id);
    file.meta.importError ? importAsset(file) : uppy.retryUpload(id);
}

export function discardUpload(id) {
    const file = uppy.getFile(id);
    // Remove the empty video Bunny created for it; the server refuses once the asset exists.
    if (file.meta.bunnyId && !file.meta.imported) {
        api.destroy(`${file.meta.endpoint}/${file.meta.bunnyId}`).catch((error) => console.error('Bunny upload cancel failed', error));
    }
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
    const location = browseLocation();
    target = { container, endpoint, folder: location?.container === container ? location.folder : '' };
    assetUploads.destination = [target.container, target.folder].filter(Boolean).join('/');

    if (!uppy) {
        setup();
        Statamic.$components.append('bunny-asset-upload', { props: {} });
    }

    assetUploads.open = true;
}
