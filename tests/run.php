<?php

// Standalone integration checks against the installed Laravel and Statamic versions.
require __DIR__.'/../vendor/autoload.php';
spl_autoload_register(function ($class) {
    $prefix = 'Noo\\BunnyStream\\';
    // Missing files are left to other loaders, as Composer does (Laravel probes guessed policy names).
    if (str_starts_with($class, $prefix) && is_file($file = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php')) { require $file; }
});
$root = sys_get_temp_dir().'/bunny-tests-'.bin2hex(random_bytes(6));
mkdir($root, 0700, true);
foreach (['bootstrap/cache', 'config', 'routes', 'resources/blueprints', 'resources/fieldsets', 'storage/framework/views', 'storage/framework/cache', 'storage/logs', 'public/assets', 'content'] as $dir) { mkdir($root.'/'.$dir, 0700, true); }
file_put_contents($root.'/composer.json', '{"name":"tests/bunny","autoload":{"psr-4":{"App\\\\":"app/"}},"extra":{"laravel":{"dont-discover":["*"]}}}');
file_put_contents($root.'/config/app.php', '<?php return ["name"=>"Test", "env"=>"testing", "key"=>"base64:'.base64_encode(random_bytes(32)).'", "url"=>"http://localhost", "timezone"=>"UTC", "locale"=>"en"];');
symlink(realpath(__DIR__.'/../vendor'), $root.'/vendor');
$app = Illuminate\Foundation\Application::configure(basePath: $root)
    ->withMiddleware(fn ($middleware) => $middleware->group('api', []))
    ->withExceptions()
    ->withProviders([Statamic\Providers\StatamicServiceProvider::class])
    ->create();
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['statamic.users.repository' => 'file', 'cache.default' => 'array', 'queue.default' => 'database', 'queue.connections.database.driver' => 'database',
    'filesystems.disks.assets' => ['driver' => 'local', 'root' => $root.'/public/assets', 'url' => '/assets', 'throw' => false],
    'statamic.bunny-stream' => array_merge(require __DIR__.'/../config/bunny-stream.php', ['asset_containers' => ['videos'], 'library_id' => '123', 'api_key' => 'fake', 'hostname' => 'example.test'])]);
Noo\BunnyStream\Fieldtypes\Bunny::register();
Noo\BunnyStream\Fieldtypes\BunnyStream::register();
$app['events']->subscribe(Noo\BunnyStream\Assets\Subscriber::class);
$app['events']->subscribe(Noo\BunnyStream\Migration\RestoreSubscriber::class);
Noo\BunnyStream\ServiceProvider::protectAssetMetadata();
function check($condition, $message) { if (! $condition) { throw new RuntimeException($message); } echo 'PASS '.explode(': ', $message, 2)[0]."\n"; }
check(true, 'Statamic application boots');
set_exception_handler(function ($e) { fwrite(STDERR, $e."\n"); exit(1); });
register_shutdown_function(function () use ($root) { (new Illuminate\Filesystem\Filesystem)->deleteDirectory($root); });
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\Jobs\SyncVideo;
use Noo\BunnyStream\BunnyClient;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Noo\BunnyStream\Migration\Journal;

function fakeHttp($callback) {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake($callback);
}

Queue::fake();
$container = AssetContainer::make('videos')->disk('assets')->title('Videos');
$container->save();
$container->disk()->put('one.mp4', 'fake-video');
$asset = Asset::make()->container($container)->path('one.mp4');
$asset->saveQuietly();
Streams::queue($asset);
check(Queue::pushed(SyncVideo::class)->count() === 1, 'video is queued once');
Streams::queue($asset);
check(Queue::pushed(SyncVideo::class)->count() === 1, 'metadata saves do not duplicate upload');
$guid = '11111111-1111-4111-8111-111111111111';
Http::preventStrayRequests();
fakeHttp(fn ($request) => Http::response($request->method() === 'POST' ? ['guid' => $guid] : ['guid' => $guid, 'status' => 4, 'chapters' => []]));
$job = Queue::pushed(SyncVideo::class)->first();
$job->handle(new BunnyClient);
$asset = Asset::find($asset->id());
check($asset->get('bunny_stream')['guid'] === $guid && $asset->get('bunny_stream')['state'] === 'ready', 'queued video becomes ready');
$count = Http::recorded()->count();
$job->handle(new BunnyClient);
check(Http::recorded()->count() === $count, 'completed job redelivery does not upload again');
Streams::queue($asset, true);
$newJob = Queue::pushed(SyncVideo::class)->last();
check($asset->get('bunny_stream')['guid'] === $guid, 'replacement retains the previous stream');
$count = Http::recorded()->count();
$job->handle(new BunnyClient);
check(Http::recorded()->count() === $count, 'stale generation cannot upload');
$asset->rename('renamed');
$newJob->handle(new BunnyClient);
check(Asset::find('videos::renamed.mp4')->get('bunny_stream')['state'] === 'ready', 'pending job follows asset rename');

$bp = Blueprint::make('test')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
    ['handle' => 'video', 'field' => ['type' => 'bunny']],
    ['handle' => 'text', 'field' => ['type' => 'text']],
    ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => ['video' => ['fields' => [['handle' => 'clip', 'field' => ['type' => 'bunny']]]]]]],
    ['handle' => 'body', 'field' => ['type' => 'bard', 'sets' => ['video' => ['fields' => [['handle' => 'clip', 'field' => ['type' => 'bunny']]]]]]],
]]]]]]);
$bp->setContents(Noo\BunnyStream\Migration\Definitions::convert($bp->contents(), ['*'], 'videos', '123'));
$item = new class($bp, $guid) {
    private $values;
    public function __construct(private $bp, $guid) { $this->values = collect(['video' => $guid, 'text' => $guid,
        'blocks' => [['type' => 'video', 'clip' => $guid]], 'body' => [['type' => 'set', 'attrs' => ['values' => ['type' => 'video', 'clip' => $guid]]]]]); }
    public function blueprint() { return $this->bp; }
    public function data($data = null) { if (func_num_args()) { $this->values = collect($data); return $this; } return $this->values; }
};
check(Noo\BunnyStream\Migration\References::item($item)->convert(), 'native field traversal converts references');
check($item->data()['video'] === 'renamed.mp4' && $item->data()['text'] === $guid, 'conversion only touches blueprint-defined Bunny values');
check($item->data()['blocks'][0]['clip'] === 'renamed.mp4' && $item->data()['body'][0]['attrs']['values']['clip'] === 'renamed.mp4', 'Bard and Replicator references convert');
check(! Noo\BunnyStream\Migration\References::item($item)->convert(), 'reference conversion is idempotent');

$file = $root.'/content/journal.yaml';
file_put_contents($file, 'before');
Journal::write('test-run', ['files' => [Journal::change(['path' => $file], 'after')]]);
Journal::apply('test-run');
Journal::apply('test-run');
check(file_get_contents($file) === 'after', 'journal apply can resume');
file_put_contents($file, 'editor change');
try { Journal::apply('test-run', true); throw new Exception('Rollback should refuse edit'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'File changed'), 'rollback refuses subsequent edits'); }
file_put_contents($file, 'after');
Journal::apply('test-run', true);
check(file_get_contents($file) === 'before', 'rollback restores exact bytes');

$metadataPath = $container->disk()->path('.meta/journal-test.yaml');
file_put_contents($metadataPath, 'asset metadata before');
$openReader = fopen($metadataPath, 'rb');
try {
    Journal::write('asset-meta-run', ['files' => [Journal::change(['disk' => 'assets', 'path' => '.meta/journal-test.yaml'], 'asset metadata after')]]);
    Journal::apply('asset-meta-run');
    check(stream_get_contents($openReader) === 'asset metadata before' && file_get_contents($metadataPath) === 'asset metadata after', 'asset metadata journal atomically replaces content for concurrent readers');
    Journal::apply('asset-meta-run', true);
    check(file_get_contents($metadataPath) === 'asset metadata before', 'asset metadata journal rollback restores the original file');
} finally {
    fclose($openReader);
}


// Exercise the actual command, including imported fieldset prefixes and working copies.
$fieldset = Statamic\Facades\Fieldset::make('shared')->setContents(['fields' => [
    ['handle' => 'clip', 'field' => ['type' => 'bunny', 'display' => 'Clip']],
    ['handle' => 'plain', 'field' => ['type' => 'text']],
]]);
$fieldset->save();
Statamic\Facades\Field::computedDefault('bunny-review-fixture', fn () => 'unrelated default');
$blueprint = Blueprint::make('article')->setNamespace('collections.articles')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
    ['import' => 'shared', 'prefix' => 'hero_'], ['handle' => 'direct_clip', 'field' => 'shared.clip'],
    ['handle' => 'override_clip', 'field' => 'shared.plain', 'config' => ['type' => 'bunny']],
]]]]]]);
$blueprint->save();
$collection = Statamic\Facades\Collection::make('articles')->title('Articles');
$collection->save();
$entry = Statamic\Facades\Entry::make()->collection('articles')->slug('example')->data(['hero_clip' => $guid, 'direct_clip' => $guid, 'override_clip' => $guid]);
$entry->save();
$revision = $entry->makeWorkingCopy();
$revision->save();
$taxonomy = Statamic\Facades\Taxonomy::make('topics')->title('Topics');
$taxonomy->save();
$termBlueprint = Blueprint::make('topic')->setNamespace('taxonomies.topics')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [['import' => 'shared', 'prefix' => 'hero_']]]]]]]);
$termBlueprint->save();
$term = Statamic\Facades\Term::make('example')->taxonomy('topics')->set('hero_clip', $guid);
$term->save();
$termBefore = file_get_contents($term->path());
$globalBlueprint = Blueprint::make('settings')->setNamespace('globals')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [['import' => 'shared', 'prefix' => 'hero_']]]]]]]);
$globalBlueprint->save();
$global = Statamic\Facades\GlobalSet::make('settings')->title('Settings');
$global->save();
$variables = $global->inDefaultSite();
$variables->set('hero_clip', $guid)->save();
$userBlueprint = Blueprint::make('user')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [['import' => 'shared', 'prefix' => 'hero_']]]]]]]);
$userBlueprint->save();
$contentUser = Statamic\Facades\User::make()->email('content@example.test')->set('hero_clip', $guid);
$contentUser->save();
$assetBlueprint = Blueprint::make('videos')->setNamespace('assets')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [['import' => 'shared', 'prefix' => 'hero_']]]]]]]);
$assetBlueprint->save();
Statamic\Facades\Blink::forget('asset-container-blueprint-videos');
$metadataAsset = Asset::find('videos::renamed.mp4');
Statamic\Facades\Blink::forget('asset-videos::renamed.mp4-blueprint');
$metadataAsset->set('hero_clip', $guid)->save();
// The addon's bundled fieldset migrates through Statamic's override location.
$addonFieldsets = $root.'/addon/resources/fieldsets';
mkdir($addonFieldsets, 0700, true);
copy(__DIR__.'/../resources/fieldsets/bunny_video.yaml', $addonFieldsets.'/bunny_video.yaml');
Statamic\Facades\Fieldset::addNamespace('statamic-bunny-stream', $addonFieldsets);
$overridePath = Statamic\Facades\Fieldset::overriddenNamespacedFieldsetPath('statamic-bunny-stream::bunny_video');
mkdir(dirname($overridePath), 0700, true);
copy($addonFieldsets.'/bunny_video.yaml', $overridePath);
Blueprint::make('film')->setNamespace('collections.films')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [['import' => 'statamic-bunny-stream::bunny_video']]]]]]])->save();
Statamic\Facades\Collection::make('films')->save();
$film = Statamic\Facades\Entry::make()->collection('films')->slug('film')->data(['bunny_video' => $guid]);
$film->save();
$filmBefore = file_get_contents($film->path());
// Legacy `sections:` blueprints keep their format, without fields Statamic only adds at runtime.
Statamic\Facades\Collection::make('news')->dated(true)->save();
$newsPath = Blueprint::directory().'/collections/news/story.yaml';
@mkdir(dirname($newsPath), 0700, true);
file_put_contents($newsPath, "title: Story\nsections:\n  main:\n    fields:\n      -\n        handle: clip\n        field:\n          type: bunny\n");
Statamic\Facades\Blink::flush();
$story = Statamic\Facades\Entry::make()->collection('news')->slug('story')->date('2026-01-01')->data(['clip' => $guid]);
$story->save();
$entryPath = $entry->path();
$entryBefore = file_get_contents($entryPath);
$fieldsetBefore = file_get_contents($fieldset->path());
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->registerCommand(new Noo\BunnyStream\Console\Commands\MigrateFieldsCommand);
$kernel->registerCommand(new Noo\BunnyStream\Console\Commands\RollbackCommand);
$result = $kernel->call('bunny-stream:migrate-fields', ['--dry-run' => true, '--field' => ['clip', 'bunny_video', 'override_clip']]);
check($result === 0, 'migration dry run succeeds: '.$kernel->output());
check(file_get_contents($entryPath) === $entryBefore && file_get_contents($fieldset->path()) === $fieldsetBefore, 'dry run leaves content and definitions untouched');
// Reboot blueprint/fieldset memory just as a separate artisan invocation would.
Statamic\Facades\Fieldset::find('shared')->setContents(Statamic\Facades\YAML::parse($fieldsetBefore));
$blueprint->setContents(Statamic\Facades\YAML::parse(file_get_contents($blueprint->path())));
$app->maintenanceMode()->activate([]);
$result = $kernel->call('bunny-stream:migrate-fields', ['--field' => ['clip', 'bunny_video', 'override_clip']]);
check($result === 0, 'migration applies complete shared field batch: '.$kernel->output());
check(str_contains(file_get_contents($entryPath), 'hero_clip: renamed.mp4'), 'prefixed shared field value migrated');
check(str_contains(file_get_contents($entryPath), 'direct_clip: renamed.mp4'), 'individual references migrate even when the field repository was already resolved');
check(str_contains(file_get_contents($entryPath), 'override_clip: renamed.mp4'), 'a reference-level Bunny type override migrates with its value');
check(str_contains(file_get_contents($revision->path()), 'hero_clip: renamed.mp4'), 'working copy migrated with its field definition');
check(str_contains(file_get_contents($film->path()), 'bunny_video: renamed.mp4') && str_contains(file_get_contents($overridePath), 'bunny_stream_migrated'), 'entries using an overridden addon fieldset migrate');
check(file_get_contents($addonFieldsets.'/bunny_video.yaml') === file_get_contents(__DIR__.'/../resources/fieldsets/bunny_video.yaml'), 'the addon fieldset itself is never rewritten');
$storyYaml = Statamic\Facades\YAML::parse(file_get_contents($newsPath));
check(isset($storyYaml['sections']) && ! isset($storyYaml['tabs']) && ($storyYaml['sections']['main']['fields'][0]['field']['type'] ?? null) === 'assets', 'legacy sections blueprints are converted in place');
check(! str_contains(file_get_contents($newsPath), 'slug') && ! str_contains(file_get_contents($newsPath), 'date'), 'runtime-ensured fields are not written to blueprints');
check(str_contains(file_get_contents($story->path()), 'clip: renamed.mp4'), 'entries of legacy blueprints migrate');
check(str_contains(file_get_contents($term->path()), 'hero_clip: renamed.mp4'), 'taxonomy term migrated');
check(str_contains(file_get_contents($variables->path()), 'hero_clip: renamed.mp4'), 'global variables migrated');
check(str_contains(file_get_contents($contentUser->path()), 'hero_clip: renamed.mp4'), 'file-backed users migrated');
check(str_contains($metadataAsset->disk()->get($metadataAsset->metaPath()), 'hero_clip: renamed.mp4'), 'asset metadata references migrated');
// Historical snapshots keep their GUID; restore translates the new working copy only.
$historical = $revision->toWorkingCopy()->action('revision');
$historical->attribute('data', ['hero_clip' => $guid]);
$historical->save();
$historicalBefore = file_get_contents($historical->path());
$restored = $historical->toWorkingCopy();
$restored->save();
check($restored->attributes()['data']['hero_clip'] === 'renamed.mp4', 'historical entry restore translates GUIDs');
check(file_get_contents($historical->path()) === $historicalBefore, 'historical snapshot remains untouched');
// Restore the exact migrated working copy bytes so rollback has no later edit conflict.
$migratedRun = Journal::read(basename(glob(Journal::directory().'/migration-*.json')[0], '.json'));
foreach ($migratedRun['files'] as $change) { if ($change['path'] === $revision->path()) file_put_contents($revision->path(), base64_decode($change['after'])); }

$runs = glob(Journal::directory().'/migration-*.json');
$run = basename(end($runs), '.json');
$result = $kernel->call('bunny-stream:rollback', ['run' => $run]);
check($result === 0 && file_get_contents($entryPath) === $entryBefore, 'command rollback restores entry');
check(file_get_contents($fieldset->path()) === $fieldsetBefore, 'command rollback restores shared fieldset');
check(file_get_contents($film->path()) === $filmBefore && file_get_contents($overridePath) === file_get_contents($addonFieldsets.'/bunny_video.yaml'), 'command rollback restores the fieldset override and its entries');

Statamic\Facades\Collection::make('history')->save();
$blueprintWithClip = fn ($handle, $field) => Blueprint::make($handle)->setNamespace('collections.history')->setContents(['tabs' => [
    'main' => ['sections' => [['fields' => [['handle' => 'clip', 'field' => $field]]]]],
]]);
$blueprintWithClip('current', ['type' => 'text'])->save();
$blueprintWithClip('archived', ['type' => 'assets', 'container' => 'videos', 'max_files' => 1, 'bunny_stream_migrated' => '123'])->save();
$live = Statamic\Facades\Entry::make()->collection('history')->blueprint('current')->slug('record')->published(true)->data(['clip' => 'Editor text']);
$live->save();
$archive = $live->makeRevision()->action('revision')->attribute('blueprint', 'archived')->attribute('data', ['clip' => $guid]);
$archive->save();
$archivedBytes = file_get_contents($archive->path());
$archivedCopy = $archive->toWorkingCopy();
$archivedCopy->save();
check($archivedCopy->attributes()['data']['clip'] === 'renamed.mp4', 'restoring a revision uses its own blueprint, not the live entry blueprint');
$missingGuid = '99999999-1111-4111-8111-999999999999';
$archivedCopy->attribute('data', ['clip' => $missingGuid])->save();
check($archivedCopy->attributes()['data']['clip'] === $missingGuid && file_get_contents($archive->path()) === $archivedBytes, 'unresolved historical GUIDs survive restoration in an editable working copy');
$archivedCopy->delete();

// A dry run reports every unresolved reference at once.
$broken = [];
foreach (['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'] as $missing) {
    $broken[] = tap(Statamic\Facades\Entry::make()->collection('films')->slug('missing-'.$missing[0])->data(['bunny_video' => $missing]))->saveQuietly();
}
$result = $kernel->call('bunny-stream:migrate-fields', ['--dry-run' => true, '--field' => ['bunny_video']]);
$output = $kernel->output();
check($result === 1 && str_contains($output, 'aaaaaaaa-') && str_contains($output, 'bbbbbbbb-') && str_contains($output, '2 item(s)'), 'dry run lists every unresolved reference: '.$output);
foreach ($broken as $brokenEntry) { $brokenEntry->delete(); }
$multiple = Statamic\Facades\Entry::make()->collection('articles')->slug('missing-several')->data([
    'hero_clip' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'direct_clip' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
]);
$multiple->saveQuietly();
$result = $kernel->call('bunny-stream:migrate-fields', ['--dry-run' => true, '--field' => ['clip']]);
$output = $kernel->output();
check($result === 1 && str_contains($output, 'hero_clip: aaaaaaaa-') && str_contains($output, 'direct_clip: bbbbbbbb-'), 'dry-run reports both unresolved GUIDs in the same entry');
$multiple->delete();
$defaultBlueprint = $blueprintWithClip('legacy_default', ['type' => 'bunny', 'default' => $guid]);
$defaultBlueprint->save();
$result = $kernel->call('bunny-stream:migrate-fields', ['--dry-run' => true, '--field' => ['clip']]);
$output = $kernel->output();
check($result === 1 && str_contains($output, ':tabs.main.sections.0.fields.0.field.default') && str_contains($output, $defaultBlueprint->path()), 'migration refuses an active legacy default before applying definitions');
$defaultBlueprint->delete();

$articles = Statamic\Facades\Collection::findByHandle('articles');
$articles->cascade(['hero_clip' => $guid])->save();
$result = $kernel->call('bunny-stream:migrate-fields', ['--dry-run' => true, '--field' => ['clip']]);
check($result === 1 && str_contains($kernel->output(), 'inject.hero_clip'), 'migration refuses inherited collection GUIDs before applying definitions');
$articles->cascade([])->save();

$defaultBlueprintPath = Blueprint::directory();
$navigationBlueprints = $root.'/navigation-blueprints';
mkdir($navigationBlueprints);
file_put_contents($navigationBlueprints.'/main.yaml', "title: Navigation\nsections:\n  main:\n    fields:\n      - import: shared\n");
$paths = ['default' => $defaultBlueprintPath, 'navigation' => $navigationBlueprints];
Blueprint::setDirectories($paths);
config(['statamic.system.blueprints_path' => $paths]);
$result = $kernel->call('bunny-stream:migrate-fields', ['--dry-run' => true, '--field' => ['clip']]);
$output = $kernel->output();
check($result === 1 && str_contains($output, 'Unsupported content owner') && str_contains($output, $navigationBlueprints), 'migration detects unsupported consumers outside the default blueprint directory: '.$output);
Blueprint::setDirectories($defaultBlueprintPath);
config(['statamic.system.blueprints_path' => $defaultBlueprintPath]);


// Import the original when present and a derivative when it is not.
$importGuid = '22222222-2222-4222-8222-222222222222';
$originalGuid = '33333333-3333-4333-8333-333333333333';
$fake = new class($importGuid, $originalGuid) extends BunnyClient {
    public int $downloads = 0;
    public array $extra = [];
    public int $polls = 0;
    public int $uploads = 0;
    public function __construct(private $fallback, private $original) {}
    public function videos(int $page = 1, int $perPage = 100, ?string $search = null): array {
        return ['totalItems' => 2 + count($this->extra), 'items' => array_merge(
            array_map(fn ($guid) => ['guid' => $guid, 'title' => 'Imported', 'status' => 4], [$this->fallback, $this->original]),
            $this->extra,
        )];
    }
    public function downloadOriginal(string $guid, string $sinkPath): void {
        if ($guid === $this->fallback) {
            throw new Illuminate\Http\Client\RequestException(new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(404)));
        }
        $this->downloads++;
        copy(__DIR__.'/fixtures/video.mp4', $sinkPath);
    }
    public function download(string $guid, string $sinkPath, ?array $video = null): void { $this->downloads++; copy(__DIR__.'/fixtures/video.mp4', $sinkPath); }
    public function video(string $guid): array { $this->polls++; return ['guid' => $guid, 'status' => 5]; }
    public function upload(string $guid, $stream): void { $this->uploads++; }
    public function create(string $title, int $thumbnailTime = 0): array { throw new Exception('Import must not create a remote video'); }
};
$app->instance(BunnyClient::class, $fake);
$kernel->registerCommand(new Noo\BunnyStream\Console\Commands\ImportAssetsCommand);
$result = $kernel->call('bunny-stream:import-assets', ['container' => 'videos', '--dry-run' => true]);
check($result === 0 && $fake->downloads === 0, 'import dry run does not download');
$result = $kernel->call('bunny-stream:import-assets', ['container' => 'videos']);
check($result === 0 && $fake->downloads === 2, 'original and fallback imports succeed: '.$kernel->output());
$imported = Asset::find('videos::bunny-stream/123/'.$importGuid.'.mp4');
check($imported->get('bunny_stream')['guid'] === $importGuid && $imported->get('bunny_stream')['source'] === 'derivative', 'import preserves GUID and records derivative provenance');
$result = $kernel->call('bunny-stream:import-assets', ['container' => 'videos']);
check($result === 0 && $fake->downloads === 2, 'rerunning import does not download or duplicate assets');
$pendingImportGuid = '55555555-1111-4111-8111-555555555555';
$encodingImportGuid = '66666666-1111-4111-8111-666666666666';
$container->disk()->put('native-pending.mp4', file_get_contents(__DIR__.'/fixtures/video.mp4'));
Asset::make()->container($container)->path('native-pending.mp4')->set('bunny_stream', [
    'key' => 'native-pending', 'library_id' => '123', 'generation' => 'native-pending', 'state' => 'processing', 'pending_guid' => $pendingImportGuid,
])->saveQuietly();
$fake->extra = [['guid' => $pendingImportGuid, 'status' => 3], ['guid' => $encodingImportGuid, 'status' => 3]];
$beforePolls = Queue::pushed(SyncVideo::class)->count();
$result = $kernel->call('bunny-stream:import-assets', ['container' => 'videos']);
$pollJob = Queue::pushed(SyncVideo::class)->last();
check($result === 0 && $fake->downloads === 3 && ! Asset::find('videos::bunny-stream/123/'.$pendingImportGuid.'.mp4')
    && $pollJob->assetId === 'videos::bunny-stream/123/'.$encodingImportGuid.'.mp4'
    && Queue::pushed(SyncVideo::class)->count() === $beforePolls + 1, 'live import reserves pending GUIDs and schedules polling on its first apply');
$result = $kernel->call('bunny-stream:import-assets', ['container' => 'videos', '--dry-run' => true]);
check($result === 0 && Queue::pushed(SyncVideo::class)->count() === $beforePolls + 1, 'dry-run does not dispatch encoding jobs for existing imports');
for ($attempt = 0; $attempt < 2; $attempt++) {
    try { $pollJob->handle($fake); }
    catch (RuntimeException $e) { if (! str_contains($e->getMessage(), 'status 5')) { throw $e; } }
}
check($fake->polls === 2 && $fake->uploads === 0 && Asset::find($pollJob->assetId)->get('bunny_stream')['state'] !== 'ready', 'failed encoding polls never re-upload an existing Bunny video');

// Signed augmentation remains available through the native Assets field.
config(['statamic.bunny-stream.token_key' => 'signing-key']);
$stream = $imported->augmentedValue('bunny_stream')->value();
check(str_contains($stream->embedUrl(), 'token=') && str_contains($stream->embed(), $importGuid), 'asset augmentation uses signed BunnyVideo playback');
$antlers = (string) Statamic\Facades\Antlers::parse('{{ video:bunny_stream:embed }}', ['video' => $imported]);
require __DIR__.'/../routes/web.php';
app('router')->getRoutes()->refreshNameLookups();
check($imported->augmentedValue('bunny_stream')->value()->url() === route('bunny.playlist', $importGuid), 'native assets retain the Safari playlist route from main');
config(['statamic.bunny-stream.playlist_start_resolution' => null]);
check(Noo\BunnyStream\Data\BunnyVideo::make($importGuid)->url() === 'https://example.test/'.$importGuid.'/playlist.m3u8', 'disabled playlist reordering links straight to Bunny');
config(['statamic.bunny-stream.playlist_start_resolution' => 720]);
check(str_contains($antlers, '<iframe') && str_contains($antlers, $importGuid), 'Antlers streams through a native asset');
// One template works before and after migration.
Noo\BunnyStream\Modifiers\BunnyStream::register();
$legacyValue = new Statamic\Fields\Value($importGuid, 'video', new Noo\BunnyStream\Fieldtypes\Bunny);
// Template files, unlike Antlers::parse() content, may use any registered modifier.
@mkdir($root.'/resources/views', 0700, true);
file_put_contents($root.'/resources/views/stream.antlers.html', '{{ stream = video | bunny_stream }}{{ if stream }}{{ stream:embed }}{{ else }}none{{ /if }}');
check(view('stream', ['video' => null])->render() === 'none', 'bunny_stream modifier tolerates empty fields');
foreach (['GUID' => $importGuid, 'legacy field' => $legacyValue, 'asset' => $imported] as $label => $value) {
    $output = view('stream', ['video' => $value])->render();
    check(str_contains($output, '<iframe') && str_contains($output, $importGuid), 'bunny_stream modifier resolves a '.$label.': '.$output);
    check(Noo\BunnyStream\Data\BunnyVideo::from($value)?->guid() === $importGuid, 'BunnyVideo::from resolves a '.$label);
}
check(Noo\BunnyStream\Data\BunnyVideo::from(null) === null && Noo\BunnyStream\Data\BunnyVideo::from('not-a-guid') === null, 'BunnyVideo::from ignores empty values');
$before = $imported->get('bunny_stream');
$imported->set('bunny_stream', ['guid' => 'forged'])->save();
check(Asset::find($imported->id())->get('bunny_stream') === $before, 'ordinary asset saves cannot forge stream metadata');
// A failed transfer retries the same remote video; a lost create response cannot create another.
Queue::fake();
$container->disk()->put('retry.mp4', file_get_contents(__DIR__.'/fixtures/video.mp4'));
$retryAsset = Asset::make()->container($container)->path('retry.mp4');
$retryAsset->saveQuietly();
Streams::queue($retryAsset);
$retryJob = Queue::pushed(SyncVideo::class)->first();
$requests = [];
fakeHttp(function ($request) use (&$requests) {
    $requests[] = $request->method();
    return Http::response($request->method() === 'POST' ? ['guid' => '44444444-4444-4444-8444-444444444444'] : [], $request->method() === 'PUT' ? 500 : 200);
});
for ($attempt = 0; $attempt < 2; $attempt++) {
    try { $retryJob->handle(new BunnyClient); } catch (Illuminate\Http\Client\RequestException $e) {}
}
check(count(array_filter($requests, fn ($method) => $method === 'POST')) === 1, 'transfer retry preserves the created GUID');
$retryJob->failed(new RuntimeException('Transfer failed'));
check(Asset::find($retryAsset->id())->get('bunny_stream')['error'] === 'Transfer failed', 'terminal queue failures appear on the asset');
$retryAsset->delete();
$count = count($requests);
$retryJob->handle(new BunnyClient);
check(count($requests) === $count, 'deleted asset cancels queued transfer');



// Native asset permissions gate every action, independently of legacy dashboard permission.
Statamic\Facades\Role::make('viewer')->title('Viewer')->permissions(['view videos assets'])->save();
$viewer = Statamic\Facades\User::make()->email('viewer@example.test')->assignRole('viewer');
$viewer->save();
$app['auth']->setUser($viewer);
$get = Illuminate\Http\Request::create('/bunny/asset-stream', 'GET', ['asset' => $imported->id()]);
$get->setUserResolver(fn () => $viewer);
$controller = new Noo\BunnyStream\Http\Controllers\Cp\AssetStreamController($get);
$view = $controller($get, new BunnyClient);
check($view['can_edit'] === false && $view['state'] === 'ready', 'asset viewer can read status without editing');
$post = Illuminate\Http\Request::create('/bunny/asset-stream', 'POST', ['asset' => $imported->id(), 'action' => 'retry']);
$post->setUserResolver(fn () => $viewer);
try { $controller($post, new BunnyClient); throw new RuntimeException('Unauthorized write accepted'); }
catch (Statamic\Exceptions\AuthorizationException $e) { check(true, 'asset viewer cannot mutate streams'); }
// Deletion retries regard an already absent remote video as success.
config(['statamic.bunny-stream.delete_remote' => true]);
fakeHttp(fn () => Http::response([], 404));
$delete = new Noo\BunnyStream\Jobs\DeleteVideo('55555555-5555-4555-8555-555555555555', '123');
$delete->handle(new BunnyClient);
check(true, 'remote deletion is idempotent on 404');
$count = Http::recorded()->count();
(new Noo\BunnyStream\Jobs\DeleteVideo($importGuid, '123'))->handle(new BunnyClient);
check(Http::recorded()->count() === $count, 'deletion preserves GUIDs still owned by an asset');


// Historical GUID aliases survive replacement, and cannot resurrect deleted assets.
config(['statamic.bunny-stream.delete_remote' => false]);
Streams::queue($imported, true);
$replacement = Queue::pushed(SyncVideo::class)->last();
$newGuid = '66666666-6666-4666-8666-666666666666';
fakeHttp(fn ($request) => Http::response(['guid' => $newGuid, 'status' => 4]));
$replacement->handle(new BunnyClient);
$aliasItem = clone $item;
$aliasItem->data(['video' => $importGuid]);
check(Noo\BunnyStream\Migration\References::item($aliasItem)->convert() && str_contains($aliasItem->data()['video'], $importGuid), 'old GUID resolves to the asset after stream replacement');
$replacedAsset = Asset::find($imported->id());
$replacedAsset->delete();
$aliasItem->data(['video' => $importGuid]);
try { Noo\BunnyStream\Migration\References::item($aliasItem)->convert(); throw new Exception('Deleted mapping accepted'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Unresolved'), 'deleted asset is unresolved in strict migration'); }
// Creation failures cannot produce duplicates when the response is uncertain.
$container->disk()->put('uncertain.mp4', file_get_contents(__DIR__.'/fixtures/video.mp4'));
$uncertain = Asset::make()->container($container)->path('uncertain.mp4');
$uncertain->saveQuietly();
Streams::queue($uncertain);
$uncertainJob = Queue::pushed(SyncVideo::class)->last();
fakeHttp(fn () => Http::response([], 500));
try { $uncertainJob->handle(new BunnyClient); } catch (Illuminate\Http\Client\RequestException $e) {}
$count = Http::recorded()->count();
try { $uncertainJob->handle(new BunnyClient); throw new Exception('Ambiguous create retried'); }
catch (RuntimeException $e) { check(Http::recorded()->count() === $count && str_contains($e->getMessage(), 'interrupted'), 'uncertain create response requires recovery without another POST'); }
// Reject synchronous queue configuration before dispatching a transfer.
config(['queue.connections.database.driver' => 'sync']);
try { Streams::requireQueue(); throw new Exception('Sync queue accepted'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'persistent'), 'persistent queue is required'); }
foreach (['deferred', 'background'] as $driver) {
    config(['queue.connections.database.driver' => $driver]);
    try { Streams::requireQueue(); throw new Exception(ucfirst($driver).' queue accepted'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'persistent'), $driver.' queues are rejected: they ignore delays and releases'); }
}
config(['queue.connections.database.driver' => 'sync']);
// Upload events keep the stored file and record the problem; retry starts a clean attempt.
$container->disk()->put('sync.mp4', file_get_contents(__DIR__.'/fixtures/video.mp4'));
$syncAsset = Asset::make()->container($container)->path('sync.mp4');
$syncAsset->saveQuietly();
Statamic\Events\AssetUploaded::dispatch($syncAsset, 'sync.mp4');
$syncData = Asset::find($syncAsset->id())->get('bunny_stream');
check($syncData['state'] === 'not_synced' && str_contains($syncData['error'], 'persistent'), 'upload events record queue misconfiguration without failing');
config(['queue.connections.database.driver' => 'database']);
$admin = Statamic\Facades\User::make()->email('admin@example.test')->makeSuper();
$admin->save();
$app['auth']->setUser($admin);
$retry = fn () => tap(Illuminate\Http\Request::create('/bunny/asset-stream', 'POST', ['asset' => $syncAsset->id(), 'action' => 'retry']), fn ($r) => $r->setUserResolver(fn () => $admin));
$pushed = Queue::pushed(SyncVideo::class)->count();
$response = $controller($retry(), new BunnyClient);
check($response['state'] === 'queued' && $response['error'] === null && Queue::pushed(SyncVideo::class)->count() === $pushed + 1, 'retry queues an unsynced upload');
Asset::find($syncAsset->id())->set('bunny_stream', array_merge(Asset::find($syncAsset->id())->get('bunny_stream'), ['state' => 'uploading', 'error' => 'Transfer failed']))->saveQuietly();
$response = $controller($retry(), new BunnyClient);
check($response['state'] === 'uploading' && $response['error'] === null, 'retry clears the previous error so the editor keeps polling');
// Statamic's own Reupload and Replace flows keep the old stream online until the new one is ready.
config(['statamic.bunny-stream.asset_containers' => ['videos']]);
$fixture = file_get_contents(__DIR__.'/fixtures/video.mp4');
$readyAs = fn ($guid) => fakeHttp(fn ($request) => Http::response($request->method() === 'POST' ? ['guid' => $guid] : ['guid' => $guid, 'status' => 4, 'chapters' => []]));
$container->disk()->put('swap.mp4', $fixture);
$swap = Asset::make()->container($container)->path('swap.mp4');
$swap->saveQuietly();
Statamic\Events\AssetUploaded::dispatch($swap, 'swap.mp4');
$firstGuid = '88888888-8888-4888-8888-888888888888';
$readyAs($firstGuid);
Queue::pushed(SyncVideo::class)->last()->handle(new BunnyClient);
Illuminate\Support\Facades\Storage::disk(config('statamic.system.file_uploads_disk', 'local'))->put('reuploads/swap.mp4', $fixture);
$pushed = Queue::pushed(SyncVideo::class)->count();
Asset::find($swap->id())->reupload(new Statamic\Assets\ReplacementFile('reuploads/swap.mp4'));
$data = Asset::find($swap->id())->get('bunny_stream');
check($data['state'] === 'queued' && $data['guid'] === $firstGuid && Queue::pushed(SyncVideo::class)->count() === $pushed + 1, 'reupload queues a new stream and keeps the old one playing');
$secondGuid = '99999999-9999-4999-8999-999999999999';
$readyAs($secondGuid);
Queue::pushed(SyncVideo::class)->last()->handle(new BunnyClient);
$data = Asset::find($swap->id())->get('bunny_stream');
check($data['state'] === 'ready' && $data['guid'] === $secondGuid && in_array($firstGuid, $data['legacy_guids'], true), 'reupload swaps to the new stream once ready');
$container->disk()->put('newer.mp4', $fixture);
$newer = Asset::make()->container($container)->path('newer.mp4');
$newer->saveQuietly();
Statamic\Events\AssetUploaded::dispatch($newer, 'newer.mp4');
$newer = Asset::find($newer->id());
$newer->replace(Asset::find($swap->id()), true);
$data = Asset::find($newer->id())->get('bunny_stream');
check(! Asset::find($swap->id()) && $data['guid'] === $secondGuid && $data['state'] === 'queued', 'replace hands the old stream to the new asset while it encodes');
$thirdGuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$readyAs($thirdGuid);
Queue::pushed(SyncVideo::class)->last()->handle(new BunnyClient);
$data = Asset::find($newer->id())->get('bunny_stream');
check($data['state'] === 'ready' && $data['guid'] === $thirdGuid && ! array_diff([$firstGuid, $secondGuid], $data['legacy_guids']), 'replace swaps to the new stream and keeps old GUIDs resolvable');
// The Eloquent driver's metaPath() is the media file itself, which must never be parsed as metadata.
$dbAsset = new class extends Statamic\Assets\Asset { public function metaPath() { return $this->path(); } };
$dbAsset->container($container)->path('newer.mp4')->set('bunny_stream', ['state' => 'stale'])->syncOriginal();
check(Streams::persisted($dbAsset) === Asset::find('videos::newer.mp4')->get('bunny_stream'), 'database-backed assets read their stored stream, not the media file');
// Without asset containers the dashboard keeps deleting immediately.
config(['statamic.bunny-stream.asset_containers' => [], 'statamic.bunny-stream.delete_remote' => false]);
$deletes = [];
fakeHttp(function ($request) use (&$deletes) { $deletes[] = $request->method(); return Http::response([], 200); });
(new Noo\BunnyStream\Http\Controllers\Cp\VideosController($retry()))->destroy(new BunnyClient, app(Noo\BunnyStream\Repositories\VideoRepository::class), '77777777-7777-4777-8777-777777777777');
check($deletes === ['DELETE'], 'dashboard delete is unchanged without asset containers');
config(['statamic.bunny-stream.asset_containers' => ['videos']]);
// Backups keep the uploaded original and use a rendition only when none was kept.
$backupFake = new class extends BunnyClient {
    public array $sources = [];
    public function __construct() {}
    public function videos(int $page = 1, int $perPage = 100, ?string $search = null): array {
        return ['totalItems' => 2, 'items' => [['guid' => 'kept', 'title' => 'Kept', 'status' => 4], ['guid' => 'lost', 'title' => 'Lost', 'status' => 4]]];
    }
    public function downloadOriginal(string $guid, string $sinkPath): void {
        if ($guid === 'lost') { throw new Illuminate\Http\Client\RequestException(new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(404))); }
        $this->sources[$guid] = 'original';
        file_put_contents($sinkPath, 'original');
    }
    public function download(string $guid, string $sinkPath, ?array $video = null): void { $this->sources[$guid] = 'derivative'; file_put_contents($sinkPath, 'rendition'); }
};
config(['filesystems.disks.backups' => ['driver' => 'local', 'root' => $root.'/backups'], 'statamic.bunny-stream.backup_disk' => 'backups']);
$app->instance(BunnyClient::class, $backupFake);
$kernel->registerCommand(new Noo\BunnyStream\Console\Commands\BackupCommand);
$result = $kernel->call('bunny-stream:backup');
check($result === 0 && $backupFake->sources === ['kept' => 'original', 'lost' => 'derivative'], 'backup prefers originals: '.$kernel->output());
// Ordinary saves never fail because a migrated field points at an asset deleted since.
Blueprint::make('clip')->setNamespace('collections.clips')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [['handle' => 'clip', 'field' => ['type' => 'assets', 'container' => 'videos', 'max_files' => 1, 'bunny_stream_migrated' => '123']]]]]]]])->save();
Statamic\Facades\Collection::make('clips')->save();
$gone = Statamic\Facades\Entry::make()->collection('clips')->slug('gone')->data(['clip' => 'gone.mp4']);
$gone->save();
check(str_contains(file_get_contents($gone->path()), 'clip: gone.mp4'), 'saving content with a deleted video asset succeeds');
// Editors can remove every chapter.
config(['statamic.bunny-stream.chapters' => true]);
$chaptered = Asset::make()->container($container)->path('chaptered.mp4');
$container->disk()->put('chaptered.mp4', file_get_contents(__DIR__.'/fixtures/video.mp4'));
$chaptered->set('bunny_stream', ['key' => 'k', 'generation' => 'g', 'library_id' => '123', 'guid' => '88888888-8888-4888-8888-888888888888', 'state' => 'ready'])->saveQuietly();
$sent = [];
fakeHttp(function ($request) use (&$sent) { $sent[] = [$request->method(), $request->data()]; return Http::response(['chapters' => []]); });
$clear = Illuminate\Http\Request::create('/bunny/asset-stream', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
    'asset' => $chaptered->id(), 'action' => 'chapters', 'guid' => '88888888-8888-4888-8888-888888888888', 'chapters' => [],
]));
$clear->setUserResolver(fn () => $admin);
$controller($clear, new BunnyClient);
check($sent[0] === ['POST', ['chapters' => []]], 'saving an empty chapter list clears chapters');
$refresh = Illuminate\Http\Request::create('/bunny/asset-stream', 'GET', ['asset' => $chaptered->id(), 'refresh' => 1]);
$refresh->setUserResolver(fn () => $viewer);
$generated = [['title' => 'Generated', 'start' => 0, 'end' => 5]];
fakeHttp(fn () => Http::response(['chapters' => $generated]));
$controller($refresh, new BunnyClient);
$reopen = Illuminate\Http\Request::create('/bunny/asset-stream', 'GET', ['asset' => $chaptered->id()]);
$reopen->setUserResolver(fn () => $viewer);
check($controller($reopen, new BunnyClient)['chapters'] === $generated, 'chapters found on refresh survive reopening the editor');
// The legacy picker sees every page of the library and never caches a failed listing.
$pages = new class extends BunnyClient {
    public int $calls = 0;
    public bool $fail = true;
    public function __construct() {}
    public function videos(int $page = 1, int $perPage = 100, ?string $search = null): array {
        $this->calls++;
        if ($this->fail) { throw new RuntimeException('Bunny unavailable'); }
        return ['totalItems' => 150, 'items' => array_map(fn ($i) => ['guid' => (string) $i], range(($page - 1) * 100, min($page * 100, 150) - 1))];
    }
};
$repository = new Noo\BunnyStream\Repositories\VideoRepository($pages);
$repository->forget();
check($repository->fetchAll() === [], 'a failed listing returns nothing');
$pages->fail = false;
check(count($repository->fetchAll()) === 150, 'listing is retried after a failure and includes every page');
// Only people who can edit content may browse the library from the legacy picker.
Noo\BunnyStream\ServiceProvider::registerGates();
Statamic\Facades\Role::make('writer')->title('Writer')->permissions(['access cp', 'edit articles entries'])->save();
$writer = Statamic\Facades\User::make()->email('writer@example.test')->assignRole('writer');
$writer->save();
check(Illuminate\Support\Facades\Gate::forUser($writer)->allows('use bunny fieldtype') && Illuminate\Support\Facades\Gate::forUser($viewer)->denies('use bunny fieldtype'), 'legacy picker requires an editing permission');
Statamic\Facades\Role::make('creator')->title('Creator')->permissions(['access cp', 'create articles entries'])->save();
$creator = Statamic\Facades\User::make()->email('creator@example.test')->assignRole('creator');
$creator->save();
$creatorGate = Illuminate\Support\Facades\Gate::forUser($creator);
check($creatorGate->allows('use bunny fieldtype') && $creatorGate->denies('manage bunny videos'), 'create-only editors can use the picker but not manage videos');
// Streamed videos show Bunny's thumbnail wherever the CP lists assets.
Noo\BunnyStream\ServiceProvider::registerAssetHooks();
$chaptered = Asset::find($chaptered->id());
$chaptered->set('bunny_stream', array_merge($chaptered->get('bunny_stream'), ['metadata' => ['length' => 90, 'width' => 1920, 'height' => 1080, 'thumbnailFileName' => 'custom.jpg'], 'pending_metadata' => ['encodeProgress' => 40]]))->saveQuietly();
// The harness has no CP routes, so stand in for the one Statamic's ffmpeg thumbnails use
// and call the resource step that runs the hook.
Illuminate\Support\Facades\Route::get('cp/thumbnails/{encoded_asset}/{size?}', fn () => '')->name('statamic.cp.assets.thumbnails.show');
Illuminate\Support\Facades\Route::get('cp/bunny/asset-stream', fn () => '')->name('statamic.cp.bunny.cp.asset');
app('router')->getRoutes()->refreshNameLookups();
$thumbnails = fn ($asset, $resource = Statamic\Http\Resources\CP\Assets\AssetsFieldtypeAsset::class) => (new ReflectionMethod($resource, 'thumbnails'))->invoke(new $resource($asset));
$listed = $thumbnails($chaptered);
check(($listed['thumbnail'] ?? null) === 'https://example.test/88888888-8888-4888-8888-888888888888/custom.jpg', 'Assets fields show the Bunny thumbnail');
$plain = Asset::make()->container($container)->path('notes.txt');
$container->disk()->put('notes.txt', 'text');
$plain->saveQuietly();
check(($listed['bunny_stream']['playable'] ?? null) === true && $listed['bunny_stream']['state'] === 'ready', 'Assets fields know which selections are playable streams');
check(($thumbnails($plain)['thumbnail'] ?? null) === null && ! isset($thumbnails($plain)['bunny_stream']), 'other files keep their thumbnails');
check(($thumbnails($chaptered, Statamic\Http\Resources\CP\Assets\FolderAsset::class)['thumbnail'] ?? null) === $listed['thumbnail'], 'the asset browser shows the Bunny thumbnail');
// The status response carries what the player needs.
$status = Illuminate\Http\Request::create('/bunny/asset-stream', 'GET', ['asset' => $chaptered->id()]);
$status->setUserResolver(fn () => $admin);
$response = $controller($status, new BunnyClient);
check($response['duration'] === 90 && $response['width'] === 1920 && $response['progress'] === 40 && $response['title'] === 'chaptered.mp4', 'status includes title, duration, resolution and progress');
// The legacy field previews a selected video with a signed player URL.
fakeHttp(fn () => Http::response(['guid' => '99999999-9999-4999-8999-999999999999', 'title' => 'Legacy', 'length' => 12, 'width' => 640, 'height' => 360]));
app(Noo\BunnyStream\Repositories\VideoRepository::class)->forget('99999999-9999-4999-8999-999999999999');
$preview = (new Noo\BunnyStream\Http\Controllers\Cp\Preview(request()))('99999999-9999-4999-8999-999999999999', app(Noo\BunnyStream\Repositories\VideoRepository::class));
check($preview['title'] === 'Legacy' && str_contains($preview['embed_url'], 'token=') && $preview['duration'] === 12, 'legacy preview returns a signed player URL');
// Listing an asset whose stored duration is null must not erase its data.
check(Asset::make() instanceof Noo\BunnyStream\Assets\SafeAsset, 'Statamic assets use the metadata-safe class');
$container->disk()->put('clip.webm', file_get_contents(__DIR__.'/fixtures/video.mp4'));
$clip = Asset::make()->container($container)->path('clip.webm');
$clip->set('alt', 'Keep me')->set('bunny_stream', ['key' => 'kc', 'guid' => 'keep-guid', 'state' => 'ready'])->saveQuietly();
$clipMeta = Statamic\Facades\YAML::parse($container->disk()->get($clip->metaPath()));
$clipMeta['duration'] = null;
$container->disk()->put($clip->metaPath(), Statamic\Facades\YAML::dump($clipMeta));
Illuminate\Support\Facades\Cache::forget($clip->metaCacheKey());
Statamic\Facades\Blink::flush();
$listed = $container->queryAssets()->where('path', 'clip.webm')->first();
$listed->duration();
$clipMeta = Statamic\Facades\YAML::parse($container->disk()->get($clip->metaPath()));
check(($clipMeta['data']['alt'] ?? null) === 'Keep me' && ($clipMeta['data']['bunny_stream']['guid'] ?? null) === 'keep-guid', 'metadata regeneration keeps asset data');
config(['statamic.assets.cache_meta' => false]);
$uncached = Asset::find($clip->id());
check($uncached->get('alt') === 'Keep me' && $uncached->get('bunny_stream')['guid'] === 'keep-guid', 'managed asset data survives uncached metadata generation');
$uncached->set('alt', 'Edited without caching')->save();
check(Asset::find($clip->id())->get('alt') === 'Edited without caching', 'managed asset edits persist without metadata caching');
config(['statamic.assets.cache_meta' => true, 'statamic.bunny-stream.asset_containers' => []]);
$custom = Asset::find($plain->id());
$custom->set('bunny_stream', 'Site-defined value')->save();
check(Asset::find($plain->id())->get('bunny_stream') === 'Site-defined value', 'unmanaged assets can save an unrelated field named bunny_stream');
config(['statamic.bunny-stream.asset_containers' => ['videos']]);
// Lost stream records are recovered from the Bunny library, never guessed.
$repairGuid = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
$titledGuid = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';
$staleRepairGuid = 'cccccccc-1111-4111-8111-cccccccccccc';
$duplicateRepairGuid = 'dddddddd-1111-4111-8111-dddddddddddd';
$staleRepairPath = 'bunny-stream/123/'.$staleRepairGuid.'.mp4';
foreach (['bunny-stream/123/'.$repairGuid.'.mp4', 'film-lost.mp4', 'twice.mp4', $staleRepairPath, 'a/shared.mp4', 'b/shared.mp4'] as $path) {
    $container->disk()->put($path, file_get_contents(__DIR__.'/fixtures/video.mp4').($path === $staleRepairPath ? 'changed local bytes' : ''));
    Asset::make()->container($container)->path($path)->saveQuietly();
}
$repairFake = new class($repairGuid, $titledGuid, $staleRepairGuid, $duplicateRepairGuid) extends BunnyClient {
    public function __construct(private $imported, private $titled, private $stale, private $duplicate) {}
    public function downloadOriginal(string $guid, string $sinkPath): void { copy(__DIR__.'/fixtures/video.mp4', $sinkPath); }
    public function isConfigured(): bool { return true; }
    public function videos(int $page = 1, int $perPage = 100, ?string $search = null): array {
        return ['totalItems' => 6, 'items' => [
            ['guid' => $this->imported, 'title' => 'Anything', 'status' => 4],
            ['guid' => $this->titled, 'title' => 'film-lost.mp4 [cccccccc-3333-4333-8333-cccccccccccc]', 'status' => 4],
            ['guid' => 'dddddddd-4444-4444-8444-dddddddddddd', 'title' => 'twice.mp4 [eeeeeeee-5555-4555-8555-eeeeeeeeeeee]', 'status' => 4],
            ['guid' => 'ffffffff-6666-4666-8666-ffffffffffff', 'title' => 'twice.mp4 [99999999-7777-4777-8777-999999999999]', 'status' => 4],
            ['guid' => $this->stale, 'title' => 'Stale', 'status' => 4],
            ['guid' => $this->duplicate, 'title' => 'shared.mp4 [22222222-1111-4111-8111-222222222222]', 'status' => 4],
        ]];
    }
};
$app->instance(BunnyClient::class, $repairFake);
$kernel->registerCommand(new Noo\BunnyStream\Console\Commands\RepairCommand);
$kernel->call('bunny-stream:repair');
check(Asset::find('videos::film-lost.mp4')->get('bunny_stream') === null, 'repair dry run writes nothing');
$kernel->call('bunny-stream:repair', ['--apply' => true]);
$output = $kernel->output();
check(Asset::find('videos::bunny-stream/123/'.$repairGuid.'.mp4')->get('bunny_stream')['guid'] === $repairGuid, 'repair restores imported videos by path');
$recovered = Asset::find('videos::film-lost.mp4')->get('bunny_stream');
check($recovered['guid'] === $titledGuid && $recovered['generation'] === 'cccccccc-3333-4333-8333-cccccccccccc', 'repair restores synced videos by title and generation');
check(Asset::find('videos::twice.mp4')->get('bunny_stream') === null && str_contains($output, 'twice.mp4: 2 possible'), 'repair leaves ambiguous matches alone: '.$output);
check(Asset::find('videos::'.$staleRepairPath)->get('bunny_stream') === null, 'repair does not link a replaced source to the old import-path GUID');
check(Asset::find('videos::a/shared.mp4')->get('bunny_stream') === null && Asset::find('videos::b/shared.mp4')->get('bunny_stream') === null, 'repair does not resolve reverse collisions by iteration order');
// Large uploads go from the browser to Bunny; a queued job then copies the video into the container.
Statamic\Facades\Role::make('uploader')->title('Uploader')->permissions(['access cp', 'view videos assets', 'upload videos assets'])->save();
$uploader = Statamic\Facades\User::make()->email('uploader@example.test')->assignRole('uploader');
$uploader->save();
$uploadGuid = '44444444-4444-4444-8444-444444444444';
$uploadFake = new class($uploadGuid) extends BunnyClient {
    public array $titles = [];
    public int $status = 3;
    public bool $original = false;
    public int $downloads = 0;
    public array $deleted = [];
    public $onDownload = null;
    public function __construct(private $guid) {}
    public function delete(string $guid): void { $this->deleted[] = $guid; }
    public function create(string $title, int $thumbnailTime = 0): array { $this->titles[] = $title; return ['guid' => $this->guid]; }
    public function video(string $guid): array { return ['guid' => $guid, 'status' => $this->status, 'availableResolutions' => '']; }
    public function downloadOriginal(string $guid, string $sinkPath): void {
        $this->downloads++;
        if ($this->onDownload) { ($this->onDownload)(); }
        if (! $this->original) {
            throw new Illuminate\Http\Client\RequestException(new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(404)));
        }
        copy(__DIR__.'/fixtures/video.mp4', $sinkPath);
    }
};
$container->disk()->makeDirectory('films');
$uploadRequest = function ($user, $input) use ($uploadFake) {
    $app = app();
    $app['auth']->setUser($user);
    $request = Illuminate\Http\Request::create('/bunny/asset-upload', 'POST', $input);
    $request->setUserResolver(fn () => $user);

    return (new Noo\BunnyStream\Http\Controllers\Cp\AssetUploadController($request))->store($request, $uploadFake);
};
try { $uploadRequest($viewer, ['container' => 'videos', 'folder' => 'films', 'filename' => 'My Film.mov']); throw new RuntimeException('Unauthorized upload accepted'); }
catch (Statamic\Exceptions\AuthorizationException $e) { check($uploadFake->titles === [], 'large uploads require the container upload permission'); }
try { $uploadRequest($uploader, ['container' => 'videos', 'folder' => '../outside', 'filename' => 'My Film.mov']); throw new RuntimeException('Traversal accepted'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { check($e->getStatusCode() === 422 && $uploadFake->titles === [], 'large uploads reject folders outside the container'); }
$ticket = $uploadRequest($uploader, ['container' => 'videos', 'folder' => 'films', 'filename' => 'My Film.mov']);
check($ticket['guid'] === $uploadGuid && isset($ticket['upload']['signature']) && preg_match('/^my-film\.mov \[[0-9a-f-]{36}\]$/', $ticket['title']), 'large upload creates a signed Bunny video titled for repair');
$uploads = new Noo\BunnyStream\Http\Controllers\Cp\AssetUploadController(Illuminate\Http\Request::create('/'));
$as = fn ($user) => tap(Illuminate\Http\Request::create('/'), fn ($request) => $request->setUserResolver(fn () => $user));
$pushed = Queue::pushed(Noo\BunnyStream\Jobs\ImportVideo::class)->count();
$created = $uploads->complete($as($uploader), $uploadGuid);
$placeholder = Asset::find('videos::films/my-film.mov');
check($created['asset'] === 'videos::films/my-film.mov' && $placeholder->get('bunny_stream')['placeholder'] && $placeholder->get('bunny_stream')['guid'] === $uploadGuid
    && $placeholder->disk()->exists($placeholder->path()) && $placeholder->disk()->size($placeholder->path()) === 0, 'a finished upload creates its asset at once, with an empty placeholder file');
check(Queue::pushed(Noo\BunnyStream\Jobs\ImportVideo::class)->count() === $pushed + 1 && Queue::pushed(SyncVideo::class)->filter(fn ($job) => $job->assetId === $placeholder->id())->isEmpty(), 'the placeholder queues its copy from Bunny, not an upload');
check(($thumbnails($placeholder, Statamic\Http\Resources\CP\Assets\FolderAsset::class)['thumbnail'] ?? null) === Noo\BunnyStream\ServiceProvider::processingThumbnail(), 'the placeholder shows a processing thumbnail until Bunny has one');
$placeholderStatus = Illuminate\Http\Request::create('/bunny/asset-stream', 'GET', ['asset' => $placeholder->id()]);
$placeholderStatus->setUserResolver(fn () => $viewer);
check(Noo\BunnyStream\Data\BunnyVideo::from($placeholder) === null && $controller($placeholderStatus, $uploadFake)['embed_url'] === null, 'an unencoded upload is not playable in templates or the CP');
$imports = Queue::pushed(Noo\BunnyStream\Jobs\ImportVideo::class)->count();
check($uploads->complete($as($uploader), $uploadGuid) === $created && Queue::pushed(Noo\BunnyStream\Jobs\ImportVideo::class)->count() === $imports
    && count($container->disk()->getFiles('films')) === 1, 'a retried completion returns the same asset');
try { $uploads->complete($as($admin), $uploadGuid); throw new RuntimeException('Foreign completion accepted'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { check($e->getStatusCode() === 403, 'only the uploader can complete an upload'); }
$importJob = Queue::pushed(Noo\BunnyStream\Jobs\ImportVideo::class)->last();
$importJob->handle($uploadFake);
check(Asset::find('videos::films/my-film.mp4') === null && Asset::find($placeholder->id())->get('bunny_stream')['placeholder'], 'the copy waits while Bunny has no source to serve');
$uploadFake->original = true;
$pushed = Queue::pushed(SyncVideo::class)->count();
$events = [];
$moves = [];
Illuminate\Support\Facades\Event::listen(Statamic\Events\AssetUploaded::class, function ($e) use (&$events) { $events[] = $e->asset->id(); });
// Statamic's (queued) reference updater reads the move from the saved event's original path.
Illuminate\Support\Facades\Event::listen(Statamic\Events\AssetSaved::class, function ($e) use (&$moves) { $moves[] = $e->asset->getOriginal('path').' > '.$e->asset->path(); });
$importJob->handle($uploadFake);
$uploaded = Asset::find('videos::films/my-film.mp4');
$record = $uploaded?->get('bunny_stream');
check($uploaded && $record['guid'] === $uploadGuid && $record['source'] === 'original' && $record['state'] === 'remote_unready' && empty($record['placeholder']), 'the placeholder becomes the uploaded video and keeps its stream');
check($uploaded->disk()->get($uploaded->path()) === file_get_contents(__DIR__.'/fixtures/video.mp4') && $uploaded->duration() > 0, 'the asset stores the original file and reads its metadata');
check(Asset::find('videos::films/my-film.mov') === null && $container->disk()->getFiles('films', true)->filter(fn ($file) => str_contains($file, 'my-film.mov'))->isEmpty(), 'an MP4 source moves the MOV placeholder to its own extension');
check(in_array('films/my-film.mov > films/my-film.mp4', $moves, true), 'the move is saved as one, so references to the placeholder follow it');
check(Queue::pushed(SyncVideo::class)->count() === $pushed + 1 && $events === [$uploaded->id()], 'the filled asset announces its upload and polls encoding once');
$importJob->handle($uploadFake);
check($container->disk()->getFiles('films')->count() === 1, 'a redelivered copy does not duplicate the asset');
$uploadFake->status = 4;
Queue::pushed(SyncVideo::class)->last()->handle($uploadFake);
check(Asset::find($uploaded->id())->get('bunny_stream')['state'] === 'ready', 'the uploaded asset becomes ready without a second upload');
$uploadRequest($uploader, ['container' => 'videos', 'folder' => 'films', 'filename' => 'Gone.mp4']);
$removed = Asset::find($uploads->complete($as($uploader), $uploadGuid)['asset']);
$removed->delete();
Queue::pushed(Noo\BunnyStream\Jobs\ImportVideo::class)->last()->handle($uploadFake);
check(! $container->disk()->exists('films/gone.mp4'), 'a deleted placeholder is not brought back');
$complete = function ($filename) use ($uploadRequest, $uploader, $uploads, $as, $uploadGuid) {
    $uploadRequest($uploader, ['container' => 'videos', 'folder' => 'films', 'filename' => $filename]);

    return Asset::find($uploads->complete($as($uploader), $uploadGuid)['asset']);
};
$lastImport = fn () => Queue::pushed(Noo\BunnyStream\Jobs\ImportVideo::class)->last();
// Names taken by files or by earlier uploads are never reused or overwritten.
$container->disk()->put('films/clash.mp4', 'taken');
$container->disk()->put('films/clash-1.mp4', 'keep');
check($complete('Clash.mp4')->path() === 'films/clash-2.mp4' && $complete('Clash.mp4')->path() === 'films/clash-3.mp4'
    && $container->disk()->get('films/clash-1.mp4') === 'keep', 'uploads with a taken name get the next free number');
// A placeholder deleted while its source downloads is not brought back, and leaves no partial file.
$doomed = $complete('Doomed.mp4');
$uploadFake->onDownload = fn () => Asset::find($doomed->id())?->delete();
$lastImport()->handle($uploadFake);
$uploadFake->onDownload = null;
check(! $container->disk()->exists('films/doomed.mp4') && ! collect($container->disk()->getFiles('.meta'))->contains(fn ($file) => str_ends_with($file, '.part')), 'a placeholder deleted during the download stays deleted');
// A listing loaded before the import finished cannot put the placeholder back.
$listedPlaceholder = $complete('Listed.mp4');
$listed = $container->queryAssets()->where('path', 'films/listed.mp4')->get()->first();
$listed->get('bunny_stream');
$lastImport()->handle($uploadFake);
$listed->height();
check(empty(Asset::find($listedPlaceholder->id())->get('bunny_stream')['placeholder']), 'a stale listing does not undo a finished import');
// An original Statamic can't show is not downloaded while Bunny is still encoding.
$uploadFake->status = 3;
$avi = $complete('Clip.avi');
$downloads = $uploadFake->downloads;
$lastImport()->handle($uploadFake);
check($avi->path() === 'films/clip.mp4' && $uploadFake->downloads === $downloads && Asset::find($avi->id())->get('bunny_stream')['placeholder'], 'an unusable original is not downloaded before encoding ends');
$lateFailure = $complete('Late encoding failure.mp4');
$lastImport()->handle($uploadFake);
$latePoll = Queue::pushed(SyncVideo::class)->last();
$uploadFake->status = 5;
try { $latePoll->handle($uploadFake); }
catch (RuntimeException $e) { if (! str_contains($e->getMessage(), 'status 5')) { throw $e; } }
$lateRetry = Illuminate\Http\Request::create('/bunny/asset-stream', 'POST', ['asset' => $lateFailure->id(), 'action' => 'retry']);
$lateRetry->setUserResolver(fn () => $admin);
$app['auth']->setUser($admin);
$controller($lateRetry, $uploadFake);
$newGeneration = Asset::find($lateFailure->id())->get('bunny_stream');
check($newGeneration['state'] === 'queued' && $newGeneration['generation'] !== $latePoll->generation, 'an editor can explicitly retry a direct upload whose encoding failed after copying the source');
// When Bunny cannot encode a video, its original is kept so Retry can upload it again.
$uploadFake->status = 5;
$rejected = $complete('Rejected.mp4');
$lastImport()->handle($uploadFake);
$record = Asset::find($rejected->id())->get('bunny_stream');
check($record['state'] === 'not_synced' && str_contains($record['error'], 'status 5') && empty($record['placeholder'])
    && $container->disk()->size('films/rejected.mp4') > 0, 'a video Bunny cannot encode keeps its original for a retry');
$uploadFake->status = 4;
// Discarded uploads remove their empty Bunny video; finished ones cannot be cancelled.
$uploadRequest($uploader, ['container' => 'videos', 'folder' => 'films', 'filename' => 'Discarded.mp4']);
$uploads->cancel($as($uploader), $uploadFake, $uploadGuid);
try { $uploads->complete($as($uploader), $uploadGuid); throw new RuntimeException('Cancelled upload completed'); }
catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) { check($uploadFake->deleted === [$uploadGuid], 'a cancelled upload deletes its Bunny video'); }
$complete('Kept.mp4');
try { $uploads->cancel($as($uploader), $uploadFake, $uploadGuid); throw new RuntimeException('Finished upload cancelled'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { check($e->getStatusCode() === 409 && count($uploadFake->deleted) === 1, 'a finished upload cannot be cancelled'); }
$container->validationRules(['max:1'])->save();
$tooBig = $complete('Over limit.mp4');
$validationFailure = null;
try { $lastImport()->handle($uploadFake); }
catch (Illuminate\Validation\ValidationException $e) { $validationFailure = $e; }
check(isset($validationFailure?->errors()['file']) && $tooBig->disk()->size($tooBig->path()) === 0
    && Asset::find($tooBig->id())->get('bunny_stream')['placeholder'], 'container file limits apply before direct import replaces the placeholder');
$container->validationRules([])->save();

$storage = Illuminate\Support\Facades\Storage::getFacadeRoot();
$normalDisk = $storage->disk('assets');
$failingAdapter = new class($root.'/public/assets') extends League\Flysystem\Local\LocalFilesystemAdapter {
    public function move(string $source, string $destination, League\Flysystem\Config $config): void {
        throw League\Flysystem\UnableToMoveFile::fromLocationTo($source, $destination);
    }
};
$storage->set('assets', new Illuminate\Filesystem\FilesystemAdapter(new League\Flysystem\Filesystem($failingAdapter), $failingAdapter, ['throw' => false]));
try {
    foreach (['Same extension.mp4' => 'films/same-extension.mp4', 'Different extension.mov' => 'films/different-extension.mp4'] as $filename => $destination) {
        $unfinished = $complete($filename);
        $failedMove = null;
        try { $lastImport()->handle($uploadFake); }
        catch (RuntimeException $e) { $failedMove = $e; }
        check($failedMove !== null && Asset::find($unfinished->id())->get('bunny_stream')['placeholder']
            && $unfinished->disk()->size($unfinished->path()) === 0
            && ($destination === $unfinished->path() || ! $unfinished->disk()->exists($destination)), 'failed disk move keeps the selectable placeholder: '.$filename);
    }
} finally {
    $storage->set('assets', $normalDisk);
}
// Empty videos of abandoned uploads are pruned; anything an asset refers to, recent, or with a file stays.
$keptGeneration = Asset::find($rejected->id())->get('bunny_stream')['generation'];
$pruneFake = new class($keptGeneration) extends BunnyClient {
    public array $deleted = [];
    public function __construct(private string $kept) {}
    public function isConfigured(): bool { return true; }
    public function videos(int $page = 1, int $perPage = 100, ?string $search = null): array {
        $old = now()->subDays(3)->toIso8601String();
        $items = $page > 1 ? [] : [
            ['guid' => 'a1111111-1111-4111-8111-111111111111', 'title' => 'gone.mp4 [b1111111-1111-4111-8111-111111111111]', 'status' => 0, 'dateUploaded' => $old],
            ['guid' => 'a2222222-2222-4222-8222-222222222222', 'title' => 'fresh.mp4 [b2222222-2222-4222-8222-222222222222]', 'status' => 0, 'dateUploaded' => now()->toIso8601String()],
            ['guid' => 'a3333333-3333-4333-8333-333333333333', 'title' => 'full.mp4 [b3333333-3333-4333-8333-333333333333]', 'status' => 4, 'dateUploaded' => $old],
            ['guid' => 'a4444444-4444-4444-8444-444444444444', 'title' => 'attach.mp4 ['.$this->kept.']', 'status' => 0, 'dateUploaded' => $old],
            ['guid' => 'a5555555-5555-4555-8555-555555555555', 'title' => 'Hand-made title', 'status' => 0, 'dateUploaded' => $old],
        ];
        return ['totalItems' => 5, 'items' => $items];
    }
    public function delete(string $guid): void { $this->deleted[] = $guid; }
};
$app->instance(BunnyClient::class, $pruneFake);
$kernel->registerCommand(new Noo\BunnyStream\Console\Commands\PruneUploadsCommand);
$kernel->call('bunny-stream:prune-uploads', ['--dry-run' => true]);
check($pruneFake->deleted === [], 'pruning has a dry run');
$kernel->call('bunny-stream:prune-uploads');
check($pruneFake->deleted === ['a1111111-1111-4111-8111-111111111111'], 'only old, empty, unowned upload videos are pruned');
$action = new Noo\BunnyStream\Actions\UploadVideo;
$app->instance(BunnyClient::class, new BunnyClient);
check($action->visibleTo($container) && ! $action->visibleTo($uploaded) && $action->authorize($uploader, $container) && ! $action->authorize($viewer, $container), 'the upload action shows on enabled containers for users who may upload');
Illuminate\Support\Facades\Route::post('cp/bunny/asset-upload', fn () => '')->name('statamic.cp.bunny.cp.assetUpload');
app('router')->getRoutes()->refreshNameLookups();
Noo\BunnyStream\ServiceProvider::provideUploadButton();
$app['auth']->setUser($uploader);
$uploadButton = Statamic\Statamic::jsonVariables(request())['bunnyStreamUpload'];
$app['auth']->setUser($viewer);
check($uploadButton['containers'] === ['videos'] && Statamic\Statamic::jsonVariables(request())['bunnyStreamUpload'] === null, 'the upload button is offered only where the user may upload');
echo "All checks passed.\n";
