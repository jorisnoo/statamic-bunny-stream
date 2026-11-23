let browser = null;
const chapterDrafts = new WeakMap();

export function configureNativeAssets() {
    const component = Statamic.$app.component('asset-browser');
    if (!component) return;

    // The Inertia Browse page's history path can include an open editor's URL suffix.
    // The browser itself keeps the selected folder separately (including folders named edit).
    component.mixins = [...(component.mixins ?? []), {
        mounted() {
            if (typeof this.$parent?.bindBrowserNavigation === 'function') browser = this;
        },
        beforeUnmount() {
            if (browser === this) browser = null;
        },
    }];

    const methods = component.components?.AssetEditor?.methods;
    if (!methods?.shouldClose) return;

    // Native asset fields share the "asset" dirty flag. Keep the chapter draft out of it,
    // but use the editor's own discard dialog for the close button and Stack dismissal.
    const { shouldClose } = methods;
    methods.shouldClose = function (...args) {
        if (chapterDrafts.get(this)?.()) {
            this.closingWithChanges = true;
            return false;
        }
        return shouldClose.apply(this, args);
    };

    // These paths bypass shouldClose; after consent, let Statamic handle any native edits.
    for (const name of ['navigateToPreviousAsset', 'navigateToNextAsset', 'saveAndClose']) {
        const run = methods[name];
        if (!run) continue;
        methods[name] = function (...args) {
            if (chapterDrafts.get(this)?.() && !window.confirm(__('Discard unsaved chapters?'))) return;
            return run.apply(this, args);
        };
    }
}

export function browseLocation() {
    if (!browser) return null;
    return { container: browser.container.id, folder: browser.path === '/' ? '' : browser.path, root: browser.$el };
}

export function guardAssetEditor(field, isDirty) {
    for (let editor = field.$parent; editor; editor = editor.$parent) {
        if (editor.publishContainer !== 'asset' || !editor.$refs.stack) continue;
        chapterDrafts.set(editor, isDirty);
        return () => chapterDrafts.delete(editor);
    }
}
