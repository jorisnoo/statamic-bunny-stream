<template>
    <div v-if="meta.endpoint" class="tw:space-y-3">
        <p role="status">{{ stream.state || 'Loading…' }}<span v-if="stream.source === 'derivative'"> · Recovered MP4 (not the original upload)</span></p>
        <p v-if="error || stream.error" role="alert" class="tw:text-red-600">{{ error || stream.error }}</p>
        <iframe v-if="stream.embed_url" :src="stream.embed_url" title="Bunny video preview" class="tw:w-full tw:aspect-video tw:border-0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen />
        <img v-if="stream.thumbnail" :src="stream.thumbnail" alt="Video thumbnail" class="tw:w-40" />
        <button type="button" class="btn" :disabled="busy" @click="load(true)">Refresh</button>
        <template v-if="stream.can_edit">
            <button v-if="stream.error || ['not_synced', 'remote_unready'].includes(stream.state)" type="button" class="btn" :disabled="busy" @click="action('retry')">Retry sync</button>
            <div v-if="stream.state === 'creating' && stream.error">
                <label>GUID of the video created for this upload <input v-model="recoveryGuid" class="input" /></label>
                <button type="button" class="btn" :disabled="busy" @click="action('attach', { guid: recoveryGuid })">Attach and retry</button>
            </div>
            <template v-if="stream.embed_url">
                <label class="tw:block">Custom thumbnail <input type="file" accept="image/jpeg,image/png" :disabled="busy" @change="thumbnail" /></label>
                <template v-if="meta.chapters">
                    <div v-for="(chapter, index) in chapters" :key="index" class="tw:flex tw:gap-2">
                        <label>Title <input v-model="chapter.title" class="input" /></label>
                        <label>Start (seconds) <input v-model.number="chapter.start" type="number" min="0" class="input" /></label>
                        <label>End (seconds) <input v-model.number="chapter.end" type="number" :min="chapter.start" class="input" /></label>
                        <button type="button" class="btn" @click="chapters.splice(index, 1)">Remove</button>
                    </div>
                    <button type="button" class="btn" @click="chapters.push({title: '', start: 0, end: 0})">Add chapter</button>
                    <button type="button" class="btn" :disabled="busy" @click="action('chapters', { chapters })">Save chapters</button>
                    <button type="button" class="btn" :disabled="busy" @click="action('transcribe')">Generate chapters</button>
                    <p>Refresh to retrieve generated chapters.</p>
                </template>
            </template>
        </template>
    </div>
    <p v-else>Bunny Stream is available on videos in enabled asset containers.</p>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Fieldtype } from '@statamic/cms';
const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose } = Fieldtype.use(emit, props);
defineExpose(expose);
const stream = ref({});
const chapters = ref([]);
const error = ref('');
const busy = ref(false);
const recoveryGuid = ref('');
let timer;
let polls = 0;
let disposed = false;
async function load(refresh = false) {
    if (!props.meta.endpoint) return;
    try {
        const { data } = await Statamic.$axios.get(props.meta.endpoint, { params: { asset: props.meta.asset, refresh: refresh ? 1 : 0 } });
        stream.value = data;
        if (refresh || !polls) chapters.value = structuredClone(data.chapters);
        error.value = '';
        clearTimeout(timer);
        if (!disposed && !data.error && ['queued', 'creating', 'uploading', 'processing'].includes(data.state) && ++polls < 120) timer = setTimeout(load, 5000);
    } catch (e) { error.value = e.response?.data?.message || 'Could not load Bunny status.'; }
}
async function action(name, values = {}) {
    busy.value = true;
    error.value = '';
    try {
        const payload = values instanceof FormData ? values : { ...values };
        if (payload instanceof FormData) { payload.append('asset', props.meta.asset); payload.append('action', name); }
        else { payload.asset = props.meta.asset; payload.action = name; }
        await Statamic.$axios.post(props.meta.endpoint, payload);
        polls = 0;
        await load(true);
    } catch (e) { error.value = e.response?.data?.message || 'Bunny action failed.'; }
    finally { busy.value = false; }
}
function thumbnail(event) {
    const file = event.target.files[0];
    if (!file) return;
    const data = new FormData();
    data.append('thumbnail', file);
    action('thumbnail', data);
}
onMounted(() => load());
onBeforeUnmount(() => { disposed = true; clearTimeout(timer); });
</script>
