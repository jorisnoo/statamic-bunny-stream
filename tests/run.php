<?php

// Standalone integration checks against the installed Laravel and Statamic versions.
require __DIR__.'/../vendor/autoload.php';
spl_autoload_register(function ($class) {
    $prefix = 'Noo\\BunnyStream\\';
    if (str_starts_with($class, $prefix)) { require __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php'; }
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


// Exercise the actual command, including imported fieldset prefixes and working copies.
$fieldset = Statamic\Facades\Fieldset::make('shared')->setContents(['fields' => [['handle' => 'clip', 'field' => ['type' => 'bunny', 'display' => 'Clip']]]]);
$fieldset->save();
$blueprint = Blueprint::make('article')->setNamespace('collections.articles')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [['import' => 'shared', 'prefix' => 'hero_']]]]]]]);
$blueprint->save();
$collection = Statamic\Facades\Collection::make('articles')->title('Articles');
$collection->save();
$entry = Statamic\Facades\Entry::make()->collection('articles')->slug('example')->data(['hero_clip' => $guid]);
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
$result = $kernel->call('bunny-stream:migrate-fields', ['--dry-run' => true, '--field' => ['clip', 'bunny_video']]);
check($result === 0, 'migration dry run succeeds: '.$kernel->output());
check(file_get_contents($entryPath) === $entryBefore && file_get_contents($fieldset->path()) === $fieldsetBefore, 'dry run leaves content and definitions untouched');
// Reboot blueprint/fieldset memory just as a separate artisan invocation would.
Statamic\Facades\Fieldset::find('shared')->setContents(Statamic\Facades\YAML::parse($fieldsetBefore));
$blueprint->setContents(Statamic\Facades\YAML::parse(file_get_contents($blueprint->path())));
$app->maintenanceMode()->activate([]);
$result = $kernel->call('bunny-stream:migrate-fields', ['--field' => ['clip', 'bunny_video']]);
check($result === 0, 'migration applies complete shared field batch: '.$kernel->output());
check(str_contains(file_get_contents($entryPath), 'hero_clip: renamed.mp4'), 'prefixed shared field value migrated');
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
// A dry run reports every unresolved reference at once.
$broken = [];
foreach (['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'] as $missing) {
    $broken[] = tap(Statamic\Facades\Entry::make()->collection('films')->slug('missing-'.$missing[0])->data(['bunny_video' => $missing]))->saveQuietly();
}
$result = $kernel->call('bunny-stream:migrate-fields', ['--dry-run' => true, '--field' => ['bunny_video']]);
$output = $kernel->output();
check($result === 1 && str_contains($output, 'aaaaaaaa-') && str_contains($output, 'bbbbbbbb-') && str_contains($output, '2 item(s)'), 'dry run lists every unresolved reference: '.$output);
foreach ($broken as $brokenEntry) { $brokenEntry->delete(); }


// Import the original when present and a derivative when it is not.
$importGuid = '22222222-2222-4222-8222-222222222222';
$originalGuid = '33333333-3333-4333-8333-333333333333';
$fake = new class($importGuid, $originalGuid) extends BunnyClient {
    public int $downloads = 0;
    public function __construct(private $fallback, private $original) {}
    public function videos(int $page = 1, int $perPage = 100, ?string $search = null): array {
        return ['totalItems' => 2, 'items' => array_map(fn ($guid) => ['guid' => $guid, 'title' => 'Imported', 'status' => 4], [$this->fallback, $this->original])];
    }
    public function downloadOriginal(string $guid, string $sinkPath): void {
        if ($guid === $this->fallback) {
            throw new Illuminate\Http\Client\RequestException(new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(404)));
        }
        $this->downloads++;
        copy(__DIR__.'/fixtures/video.mp4', $sinkPath);
    }
    public function download(string $guid, string $sinkPath, ?array $video = null): void { $this->downloads++; copy(__DIR__.'/fixtures/video.mp4', $sinkPath); }
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

// Signed augmentation remains available through the native Assets field.
config(['statamic.bunny-stream.token_key' => 'signing-key']);
$stream = $imported->augmentedValue('bunny_stream')->value();
check(str_contains($stream->embedUrl(), 'token=') && str_contains($stream->embed(), $importGuid), 'asset augmentation uses signed BunnyVideo playback');
$antlers = (string) Statamic\Facades\Antlers::parse('{{ video:bunny_stream:embed }}', ['video' => $imported]);
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
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Unresolved'), 'deleted asset blocks historical restore'); }
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
$clear = Illuminate\Http\Request::create('/bunny/asset-stream', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['asset' => $chaptered->id(), 'action' => 'chapters', 'chapters' => []]));
$clear->setUserResolver(fn () => $admin);
$controller($clear, new BunnyClient);
check($sent[0] === ['POST', ['chapters' => []]], 'saving an empty chapter list clears chapters');
echo "All checks passed.\n";
