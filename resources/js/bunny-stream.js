import Field from './fieldtypes/Bunny.vue';
import AssetField from './fieldtypes/BunnyStream.vue';
import Overview from './components/Overview.vue';
import AssetPlayer from './components/player/AssetPlayer.vue';
import { openAssetUpload } from './utils/assetUpload.js';

// Streamed videos among an Assets field's selection (flagged by the addon's asset hook).
const streams = (meta) => (meta?.data ?? [])
    .filter((asset) => asset.bunny_stream?.playable)
    .map((asset) => ({ id: asset.id, title: asset.basename, thumbnail: asset.thumbnail, endpoint: asset.bunny_stream.endpoint }));

Statamic.booting(() => {
    Statamic.$inertia.register('BunnyOverview', Overview);
    Statamic.$components.register('bunny-fieldtype', Field);
    Statamic.$components.register('bunny_stream-fieldtype', AssetField);
    Statamic.$components.register('bunny-asset-player', AssetPlayer);

    // Run by the "Upload Video" action in the asset browser's container menu.
    Statamic.$callbacks.add('bunnyStreamUpload', openAssetUpload);

    Statamic.$fieldActions.add('assets-fieldtype', {
        title: __('Play video'),
        icon: 'movie-video-clip',
        quick: true,
        visibleWhenReadOnly: true,
        visible: ({ meta }) => streams(meta).length > 0,
        run: ({ meta }) => {
            const player = Statamic.$components.append('bunny-asset-player', { props: { items: streams(meta) } });
            player.on('closed', () => Statamic.$components.destroy(player.id));
        },
    });
});
