<template>
    <div ref="root">
        <!-- In the asset editor the panel takes over the preview area; this marker hides the emptied field (see bunny-stream.css). -->
        <span v-if="previewArea" data-bunny-stream-moved hidden />
        <Teleport :to="previewArea" :disabled="!previewArea">
            <div
                v-if="meta.endpoint"
                :class="previewArea && ['bunny-asset-preview dark tw:flex tw:min-h-0 tw:text-gray-200 tw:flex-col tw:items-center tw:overflow-y-auto tw:p-8', { 'bunny-asset-preview--replaces tw:flex-1 tw:justify-center': replacesPreview }]"
            >
                <div class="tw:w-full tw:space-y-4" :style="previewArea && { maxWidth: 'calc((100svh - 21rem) * 16 / 9)' }">
                    <BunnyPlayer v-if="playerSrc" :src="playerSrc" :title="stream.title" />

                    <!-- No stream yet: show where the upload is instead of an empty player. -->
                    <div v-else-if="placeholder" class="tw:flex tw:aspect-video tw:flex-col tw:items-center tw:justify-center tw:gap-3 tw:rounded-lg tw:border tw:border-dashed tw:border-gray-500 tw:p-6 tw:text-center">
                        <span class="tw:text-sm tw:font-medium">{{ stateLabel }}</span>
                        <div v-if="stream.progress != null" class="tw:w-full tw:max-w-48">
                            <div class="tw:h-1.5 tw:overflow-hidden tw:rounded-full tw:bg-gray-500/40" role="progressbar" :aria-valuenow="stream.progress" aria-valuemin="0" aria-valuemax="100" :aria-label="__('Encoding progress')">
                                <div class="tw:h-full tw:rounded-full tw:bg-blue-500 tw:transition-[width] tw:duration-500" :style="{ width: `${stream.progress}%` }" />
                            </div>
                            <span class="tw:mt-1 tw:block tw:text-xs tw:text-gray-500 tw:tabular-nums">{{ stream.progress }}%</span>
                        </div>
                        <span v-if="active" class="tw:text-xs tw:text-gray-500">{{ __('The player appears here once Bunny has processed the video.') }}</span>
                    </div>

                    <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2" role="status" aria-live="polite">
                        <Badge :text="stateLabel" :color="stateColor" pill />
                        <Badge v-if="stream.source === 'derivative'" :text="__('Recovered MP4')" color="amber" pill />
                        <span class="tw:text-xs tw:text-gray-500 tw:tabular-nums">{{ details }}</span>
                        <div class="tw:ms-auto tw:flex tw:flex-wrap tw:gap-2">
                            <template v-if="editable && stream.embed_url">
                                <input ref="thumbnailInput" type="file" accept="image/jpeg,image/png" class="tw:sr-only" tabindex="-1" aria-hidden="true" @change="thumbnail" />
                                <Button
                                    size="sm"
                                    icon="upload"
                                    :text="__('Custom thumbnail')"
                                    v-tooltip="__('JPEG or PNG, up to 10 MB.')"
                                    :loading="busy === 'thumbnail'"
                                    :disabled="!!busy"
                                    @click="thumbnailInput.click()"
                                />
                            </template>
                            <Button size="sm" icon="sync" :text="__('Refresh')" :loading="busy === 'refresh'" :disabled="!!busy" @click="load(true)" />
                        </div>
                    </div>

                    <Alert v-if="error" variant="error" :text="error" />
                    <Alert v-else-if="stream.error" variant="error" :heading="__('Bunny sync failed')" :text="stream.error" />
                    <Alert v-if="stopped" variant="warning" :text="__('Still working on it. Refresh to keep checking.')" />
                    <Description v-if="stream.source === 'derivative'" :text="__('Bunny kept no original for this video, so this asset holds its best MP4 rendition. Replace the file to store the original; the stream stays online until the new one is ready.')" />

                    <template v-if="editable">
                        <div v-if="canRetry">
                            <Button icon="sync" :text="__('Retry sync')" :loading="busy === 'retry'" :disabled="!!busy" @click="action('retry')" />
                        </div>

                        <Field
                            v-if="stream.state === 'creating' && stream.error"
                            :label="__('GUID of the video created for this upload')"
                            :instructions="__('Find the video in Bunny whose title ends with this upload\'s generation ID, then paste its GUID.')"
                        >
                            <div class="tw:flex tw:gap-2">
                                <Input v-model="recoveryGuid" placeholder="00000000-0000-0000-0000-000000000000" />
                                <Button :text="__('Attach and retry')" :disabled="!guidValid || !!busy" :loading="busy === 'attach'" @click="action('attach', { guid: recoveryGuid.trim() })" />
                            </div>
                        </Field>

                        <section v-if="stream.embed_url && meta.chapters" class="tw:space-y-2" :aria-label="__('Chapters')">
                            <Description :text="__('Chapters are saved to Bunny directly, not with the asset.')" />
                            <div v-for="(chapter, index) in chapters" :key="index" class="tw:grid tw:grid-cols-[minmax(0,1fr)_6.5rem_6.5rem_auto] tw:items-end tw:gap-2">
                                <Field :label="__('Title')"><Input v-model="chapter.title" /></Field>
                                <Field :label="__('Start (seconds)')"><Input v-model="chapter.start" type="number" min="0" /></Field>
                                <Field :label="__('End (seconds)')"><Input v-model="chapter.end" type="number" :min="chapter.start" /></Field>
                                <Button icon="trash" icon-only variant="ghost" :aria-label="__('Remove chapter :title', { title: chapter.title || index + 1 })" @click="chapters.splice(index, 1)" />
                            </div>
                            <div class="tw:flex tw:flex-wrap tw:gap-2">
                                <Button icon="plus" :text="__('Add chapter')" @click="chapters.push({ title: '', start: 0, end: 0 })" />
                                <Button variant="primary" :text="__('Save chapters')" :loading="busy === 'chapters'" :disabled="!!busy" @click="saveChapters" />
                                <Button :text="__('Generate chapters')" :loading="busy === 'transcribe'" :disabled="!!busy" @click="transcribe" />
                            </div>
                            <Description v-if="generating" :text="__('Bunny is generating chapters. Refresh in a few minutes to load them.')" />
                        </section>
                    </template>
                </div>
            </div>
            <Description v-else-if="!previewArea" :text="__('Bunny Stream is available on videos in enabled asset containers.')" />
        </Teleport>
    </div>
</template>

<script setup>
import { computed, getCurrentInstance, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Alert, Badge, Button, Description, Field, Input } from '@statamic/cms/ui';
import BunnyPlayer from '../components/player/BunnyPlayer.vue';
import { formatTime } from '@/utils/time.js';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, isReadOnly } = Fieldtype.use(emit, props);
defineExpose(expose);
// Statamic 6 exposes its configured axios (CSRF, session handling) only to components.
const http = getCurrentInstance().appContext.config.globalProperties.$axios;

const ACTIVE = ['queued', 'creating', 'uploading', 'processing'];
const POLL_MS = 5000;
const MAX_POLLS = 120;

const stream = ref({});
const chapters = ref([]);
const error = ref('');
const busy = ref('');
const recoveryGuid = ref('');
const thumbnailInput = ref(null);
const stopped = ref(false);
const root = ref(null);
const previewArea = ref(null);
const playerSrc = ref(null);
const generating = ref(false);
let timer = null;
let polls = 0;
let failures = 0;
let sequence = 0;
let disposed = false;

const labels = {
    not_synced: __('Not synced'),
    queued: __('Queued'),
    creating: __('Creating video…'),
    uploading: __('Uploading to Bunny…'),
    processing: __('Processing…'),
    ready: __('Ready'),
    remote_unready: __('Waiting for Bunny'),
};
const stateLabel = computed(() => labels[stream.value.state] ?? (stream.value.state ? stream.value.state : __('Loading…')));
const stateColor = computed(() => {
    if (stream.value.error) return 'red';
    if (stream.value.state === 'ready') return 'green';
    if (ACTIVE.includes(stream.value.state)) return 'blue';
    return stream.value.state ? 'amber' : 'default';
});
const active = computed(() => !stream.value.error && ACTIVE.includes(stream.value.state) && !stopped.value);
const editable = computed(() => stream.value.can_edit && !isReadOnly.value);
// A lost create response needs Attach and retry; a plain retry would fail the same way.
const canRetry = computed(() => stream.value.state !== 'creating' && (stream.value.error || ['not_synced', 'remote_unready'].includes(stream.value.state)));
// Before the first status arrives, or while Bunny has no stream yet, hold the player's place.
const placeholder = computed(() => active.value || (!stream.value.state && !error.value));
// Statamic's own <video> preview gives way to the player, or to the placeholder that precedes it.
const replacesPreview = computed(() => !!playerSrc.value || placeholder.value);
const details = computed(() => [
    stream.value.progress != null && active.value && stream.value.embed_url
        ? __('New version encoding: :progress%', { progress: stream.value.progress }) : null,
    stream.value.duration ? formatTime(stream.value.duration) : null,
    stream.value.width && stream.value.height ? `${stream.value.width} × ${stream.value.height}` : null,
].filter(Boolean).join(' · '));
const guidValid = computed(() => /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(recoveryGuid.value.trim()));

function apply(data, { syncChapters = false } = {}) {
    const becameReady = data.state === 'ready' && stream.value.state !== 'ready';
    stream.value = data;
    if (syncChapters || becameReady) chapters.value = structuredClone(data.chapters ?? []);
    clearTimeout(timer);
    if (disposed || data.error || !ACTIVE.includes(data.state)) return;
    if (++polls >= MAX_POLLS) {
        stopped.value = true;
        return;
    }
    timer = setTimeout(() => load(), POLL_MS);
}

async function load(refresh = false) {
    if (!props.meta.endpoint) return;
    const id = ++sequence;
    clearTimeout(timer);
    if (refresh) {
        busy.value = 'refresh';
        polls = 0;
        stopped.value = false;
    }
    try {
        const { data } = await http.get(props.meta.endpoint, { params: { asset: props.meta.asset, refresh: refresh ? 1 : 0 } });
        if (id !== sequence || disposed) return;
        failures = 0;
        error.value = '';
        apply(data, { syncChapters: refresh || !stream.value.state });
    } catch (e) {
        if (id !== sequence || disposed) return;
        error.value = e.response?.data?.message || __('Could not load the Bunny status.');
        // Keep watching an active transfer through brief outages, backing off each time.
        if (ACTIVE.includes(stream.value.state) && ++failures <= 5) {
            timer = setTimeout(() => load(), POLL_MS * 2 ** failures);
        }
    } finally {
        if (refresh && busy.value === 'refresh') busy.value = '';
    }
}

async function action(name, values = {}, success = null) {
    const id = ++sequence;
    clearTimeout(timer);
    busy.value = name;
    error.value = '';
    try {
        const payload = values instanceof FormData ? values : { ...values };
        if (payload instanceof FormData) {
            payload.append('asset', props.meta.asset);
            payload.append('action', name);
        } else {
            payload.asset = props.meta.asset;
            payload.action = name;
        }
        // The response is the fresh status; no second request needed.
        const { data } = await http.post(props.meta.endpoint, payload);
        if (disposed) return;
        polls = 0;
        stopped.value = false;
        if (id === sequence) apply(data, { syncChapters: ['chapters', 'thumbnail'].includes(name) });
        if (success) Statamic.$toast.success(success);
        return true;
    } catch (e) {
        error.value = e.response?.data?.message || __('The Bunny action failed.');
        Statamic.$toast.error(error.value);
        return false;
    } finally {
        busy.value = '';
    }
}

function saveChapters() {
    const payload = chapters.value.map((chapter) => ({ title: chapter.title, start: Number(chapter.start) || 0, end: Number(chapter.end) || 0 }));
    action('chapters', { chapters: payload }, __('Chapters saved.'));
}

async function thumbnail(event) {
    const file = event.target.files[0];
    if (!file) return;
    const data = new FormData();
    data.append('thumbnail', file);
    await action('thumbnail', data, __('Thumbnail updated.'));
    // Allow choosing the same file again.
    if (thumbnailInput.value) thumbnailInput.value.value = '';
}

async function transcribe() {
    if (await action('transcribe')) generating.value = true;
}

// Signed embed URLs change on every request; reload the player only for a different video.
watch(() => stream.value.embed_url, (url) => {
    if (!url || url.split('?')[0] !== playerSrc.value?.split('?')[0]) playerSrc.value = url || null;
});

onMounted(() => {
    previewArea.value = root.value?.closest('.asset-editor')?.querySelector('.editor-preview') ?? null;
    load();
});
onBeforeUnmount(() => {
    disposed = true;
    clearTimeout(timer);
});
</script>
