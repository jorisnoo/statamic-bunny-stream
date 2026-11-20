import { h, render } from 'vue';
import { Button } from '@statamic/cms/ui';
import { openAssetUpload } from './assetUpload.js';
import { browseLocation } from './nativeAssets.js';

const hosts = new Set();

function unmount(host) {
    render(null, host);
    host.remove();
    hosts.delete(host);
}

function sync({ containers, endpoint }) {
    const location = browseLocation();
    const container = location?.container;

    // Drop buttons whose header is gone, or that belong to another container: the browser
    // stays mounted when switching containers.
    for (const host of hosts) {
        if (!host.isConnected || host.dataset.bunnyUpload !== container) unmount(host);
    }
    if (!location || !containers.includes(container)) return;

    for (const header of location.root.querySelectorAll('[data-ui-header]')) {
        if (header.querySelector('[data-bunny-upload]')) continue;

        const upload = [...header.querySelectorAll('button')].find((button) => button.textContent.trim() === __('Upload'));
        if (!upload) continue;

        const host = document.createElement('span');
        host.style.display = 'contents';
        host.dataset.bunnyUpload = container;
        const vnode = h(Button, {
            variant: 'primary',
            icon: 'upload-cloud',
            text: __('Upload Video'),
            onClick: () => openAssetUpload({ container, endpoint }),
        });
        vnode.appContext = Statamic.$app._context;
        render(vnode, host);
        upload.after(host);
        hosts.add(host);
    }
}

/**
 * Put a primary "Upload Video" button next to the asset browser's Upload button. Statamic's
 * header has no slot for addons, so the button is added whenever the header (re)renders.
 */
export function addUploadButton() {
    const config = Statamic.$config.get('bunnyStreamUpload');
    if (!config?.containers?.length) return;

    let queued = false;
    const schedule = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            sync(config);
        });
    };
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    schedule();
}
