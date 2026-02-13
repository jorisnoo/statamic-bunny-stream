import Field from './fieldtypes/Bunny.vue';
import Overview from './components/Overview.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('BunnyOverview', Overview);
    Statamic.$components.register('bunny-fieldtype', Field);
});
