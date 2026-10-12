# Migrating from the Media Browser to Statamic assets

This guide moves a site from the legacy setup to native Statamic assets. In the legacy setup, videos live only in Bunny and entries store a Bunny GUID in a `bunny` field. In the asset setup, each video is an asset in one of your containers, Bunny is the streaming copy, and entries use ordinary Assets fields.

The migration is explicit. Installing or updating the addon never rewrites content. You run each step yourself, every step can be previewed, and content changes can be rolled back.

## Contents

1. [How it works](#1-how-it-works)
2. [Before you start](#2-before-you-start)
3. [Configure Bunny](#3-configure-bunny)
4. [Configure the addon and queue](#4-configure-the-addon-and-queue)
5. [Import the Bunny library as assets](#5-import-the-bunny-library-as-assets)
6. [Update templates](#6-update-templates)
7. [Preview the field migration](#7-preview-the-field-migration)
8. [Run the field migration](#8-run-the-field-migration)
9. [Verify](#9-verify)
10. [Finish up](#10-finish-up)
11. [Rolling back](#11-rolling-back)
12. [Troubleshooting](#12-troubleshooting)

## 1. How it works

The migration has two phases.

**Import (site stays live).** `bunny-stream:import-assets` downloads every video in your Bunny library and registers it as an asset. Each asset is linked to its existing Bunny video by GUID. No new videos are created in Bunny, nothing is re-encoded, and no content changes. You can rerun it as often as you like.

**Field migration (short maintenance window).** `bunny-stream:migrate-fields` makes two changes:
- It turns selected `bunny` fields into single-file Assets fields in your blueprints and fieldsets.
- It replaces each GUID stored in content with the matching asset's path.

Every file it changes is recorded byte for byte in a journal, so `bunny-stream:rollback` can restore it exactly.

What changes, using a field called `bunny_video` as the example:

| | Before | After |
|---|---|---|
| Field type | `type: bunny` | `type: assets`, `container: videos`, `max_files: 1`, `bunny_stream_migrated: '<library id>'` |
| Stored value | `bunny_video: 0e2b…-GUID` | `bunny_video: bunny-stream/<library id>/0e2b…-GUID.mp4` |
| Template value | a `BunnyVideo` (stream URL, embed, thumbnail) | an asset, whose `bunny_stream` field is the `BunnyVideo` |

The field handle, display name, validation, conditions and instructions stay as they are. Other fields, such as the bundled poster field `bunny_poster`, are not touched.

## 2. Before you start

- [ ] **Check versions:** Statamic 6, PHP 8.3+, and this addon at the version containing the asset integration.
- [ ] **Rehearse on staging.** Use a copy of production content, blueprints and asset metadata, and run the whole guide there first.
- [ ] **Allow enough disk space** on the asset disk for every original in the Bunny library, plus temporary space for the largest single video.
- [ ] **Have a persistent queue.** The database, Redis or SQS driver all work; `sync` does not. You also need to be able to run and stop its workers.
- [ ] **Know your content store.** The tool migrates Statamic's flat files: entries, terms, globals, users, asset metadata and entry working copies. If you use the Eloquent driver or a custom repository for any content that contains Bunny fields, those records have to be migrated with your own tooling. The tool refuses rather than skipping them.
- [ ] **Know where content changes land.** If content is versioned in Git, plan to commit what the migration changes. Run the migration where the live content files and the live asset disk are.
- [ ] **Treat video `.meta` files as server-owned.** Queue workers write each video's Bunny record into the asset's `.meta` file on the server. Deploys must not overwrite those files with older copies from Git.

### Find your Bunny fields

List every field definition that uses the legacy fieldtype:

```bash
grep -rn "type: bunny$" resources/blueprints resources/fieldsets
```

Also check whether any blueprint imports the bundled fieldset:

```bash
grep -rn "statamic-bunny-stream::bunny_video" resources/blueprints resources/fieldsets
```

Write down the field handles you find. You will pass them to `--field` later. If a fieldset is imported with a `prefix`, use the unprefixed handle from the fieldset. For example, `clip` imported with `prefix: hero_` is migrated with `--field=clip`.

## 3. Configure Bunny

Open the video library in the Bunny dashboard.

1. **Keep original files** (*Encoding* settings). When this was enabled before a video was uploaded, the import downloads the exact original. Bunny can't recover originals for videos uploaded while it was off.
2. **MP4 fallback** (*Encoding* settings). This is the fallback for videos without a kept original. The import then downloads the highest available MP4 rendition and marks the asset as **Recovered MP4**. You can replace that file with the real original later.
3. **Protected downloads.** If the library's CDN uses token authentication or blocks unknown referrers, set the following in `.env`:

   ```bash
   BUNNY_STREAM_CDN_TOKEN_KEY=...      # Pull Zone > Security > Token authentication key
   BUNNY_STREAM_DOWNLOAD_REFERER=https://www.example.com
   ```

   The CDN token key is not the same as `BUNNY_STREAM_TOKEN_KEY`, which signs embed URLs. The API key is never sent to the CDN.

Neither setting changes videos that are already in Bunny. They only control what the import can download.

## 4. Configure the addon and queue

### Asset container

Create a container for videos, or reuse an existing one. A dedicated container is recommended. If the originals must not be publicly downloadable, use a private disk, because originals follow the disk's visibility. Bunny's embed signing does not protect the asset URL.

Publish the config if you haven't already:

```bash
php artisan vendor:publish --tag=bunny-stream-config
```

Then edit `config/statamic/bunny-stream.php`:

```php
'asset_containers' => ['videos'],   // containers whose videos stream through Bunny
'queue' => 'default',               // queue NAME (not a connection)
'delete_remote' => false,           // keep this off until step 10
'show_dashboard' => true,           // keep the Media Browser during migration
```

Enabling a container has these effects right away:
- New video uploads to it are sent to Bunny.
- Its asset editor shows a **Bunny Stream** panel.
- Images and other files in the container are not affected.
- Legacy `bunny` fields keep working.

### Queue

Uploads and encoding checks run as queued jobs on the default queue connection:

- Use a persistent driver. With `sync`, uploads are recorded as "not synced" and the editor shows the error.
- Run a worker for the configured queue name, and keep it running under Supervisor, systemd or Horizon.
- Uploads may take up to an hour. Give the worker `--timeout=3600`, and set the connection's `retry_after` (or the SQS visibility timeout) above `3660`. Laravel's default of 90 seconds makes long uploads start a second time while the first is still running.
- After uploading, the job checks Bunny's encoding status once a minute for up to 12 hours.

```bash
php artisan queue:work --queue=default --timeout=3600
```

To test, upload a short video to the container. Its **Bunny Stream** panel goes through *queued → uploading → processing → ready* and then shows the player.

## 5. Import the Bunny library as assets

Import can run while the site is live. It doesn't change content, and editors can keep working.

Preview first:

```bash
php artisan bunny-stream:import-assets videos --dry-run
```

This lists every video it would import and every GUID that already has an asset. Then run it:

```bash
php artisan bunny-stream:import-assets videos
```

For each video, the import:

1. Downloads the original, or the best MP4 rendition if Bunny kept no original. Originals in formats Statamic doesn't treat as video (such as AVI or WMV) are also replaced by the MP4 rendition.
2. Checks that the file is a real, readable video in a format Statamic recognises (mp4, mov, webm, mkv or mpeg).
3. Stores it at `bunny-stream/<library id>/<guid>.<ext>` in the container.
4. Creates the asset with the Bunny title. The asset keeps the GUID, thumbnail and chapters, and is marked *ready*. If Bunny is still encoding it, the asset is marked *remote_unready* and a queued job polls Bunny until it's ready.

Things to know:

- **Resumable.** If a video fails, the reason is printed, recorded in `storage/app/bunny-stream/imports-<hash>.json`, and the command exits with a failure code. Fix the cause (see [Troubleshooting](#12-troubleshooting)) and run it again. Videos that are already imported are skipped without being downloaded again.
- **New legacy uploads.** If editors upload videos through the Media Browser after the import, run the import again just before step 8. Otherwise content that references those videos can't be migrated.
- **Already-mapped assets.** Assets are matched by library and GUID, so renamed or replaced assets aren't imported twice.

Check a few imported assets in the CP. Each one should show the player in its **Bunny Stream** panel.

## 6. Update templates

After migration, a migrated field returns an **asset**, not a `BunnyVideo`. Templates written for the legacy field have to change. The `bunny_stream` modifier works with both forms, so you can deploy updated templates **before** the field migration and avoid coordinating a deploy with the maintenance window.

### Antlers

Before:

```antlers
{{ bunny_video:embed }}
<img src="{{ bunny_video:thumbnail }}" alt="">
```

After, working both before and after migration:

```antlers
{{ stream = bunny_video | bunny_stream }}
{{ if stream }}
    {{ stream:embed }}
    <img src="{{ stream:thumbnail }}" alt="">
{{ /if }}
```

Mapping of the legacy accessors:

| Legacy | Compatible form (`stream = field \| bunny_stream`) | Asset-only form |
|---|---|---|
| `{{ bunny_video }}` (HLS URL) | `{{ stream }}` or `{{ stream:url }}` | `{{ bunny_video:bunny_stream }}` |
| `{{ bunny_video:embed }}` | `{{ stream:embed }}` | `{{ bunny_video:bunny_stream:embed }}` |
| `{{ bunny_video:embed_url }}` | `{{ stream:embed_url }}` | `{{ bunny_video:bunny_stream:embed_url }}` |
| `{{ bunny_video:thumbnail }}` | `{{ stream:thumbnail }}` | `{{ bunny_video:bunny_stream:thumbnail }}` |
| `{{ bunny_video:guid }}` | `{{ stream:guid }}` | `{{ bunny_video:bunny_stream:guid }}` |

> **Watch out:** after migration, a bare `{{ bunny_video }}` prints the asset's URL, which points at the stored original, not the HLS stream. Search your templates for every use of each migrated handle.

Inside Bard or Replicator sets, use the same forms on the set's field.

### Blade

```blade
@php($stream = \Noo\BunnyStream\Data\BunnyVideo::from($bunny_video))
@if ($stream)
    {!! $stream->embed(['autoplay' => 'true', 'muted' => 'true']) !!}
@endif
```

`BunnyVideo::from()` accepts a legacy GUID, a legacy `BunnyVideo`, an augmented `Value` or an asset. It returns `null` when there's no stream yet.

### Other consumers

GraphQL queries, the REST API, static exports and front-end code that read the raw GUID all change shape too: they get an asset where they used to get a GUID. Update them in the same way.

Deploy the template changes and check pages that contain videos. With the compatible forms, nothing should look different yet.

## 7. Preview the field migration

### Bundled `bunny_video` fieldset

If your blueprints import `statamic-bunny-stream::bunny_video`, override it first. The addon's copy lives in `vendor/` and is never modified:

```bash
mkdir -p resources/fieldsets/vendor/statamic-bunny-stream
cp vendor/jorisnoo/statamic-bunny-stream/resources/fieldsets/bunny_video.yaml \
   resources/fieldsets/vendor/statamic-bunny-stream/bunny_video.yaml
```

Statamic loads this override instead of the bundled file, and the migration converts the override. Blueprints and fieldsets that live in other addons' `vendor/` directories have to be published or overridden in the same way.

### Dry run

```bash
php artisan bunny-stream:migrate-fields --container=videos --field=bunny_video --dry-run
```

- **`--field`** selects definitions by their raw handle across the whole site: every blueprint, every fieldset, every prefixed import. Repeat it to include more handles, for example `--field=bunny_video --field=clip`. Use `--field='*'` for every Bunny field.
- **`--container`** can be left out when exactly one container is enabled.

The dry run:
- lists every file it would change;
- prints every reference it can't resolve, each with the item and the GUID;
- writes nothing.

Fix any problems it reports, then repeat the dry run until it ends with `N files in this batch.`. The most common problem is a GUID whose video no longer exists in Bunny. Either clear that field or import the video, then run the dry run again.

You can migrate in batches, for example one handle at a time. A shared fieldset is always migrated for **all** of its consumers at once.

## 8. Run the field migration

Plan a short window. Most sites need only a few minutes. Back up first.

1. **Back up:** `content/`, `resources/blueprints`, `resources/fieldsets`, `users/`, `storage/statamic/revisions`, and the video container's `.meta` files.
2. **Freeze writes:**

   ```bash
   php artisan down
   ```

   Also stop the queue workers, the scheduler (`schedule:run`) and anything else that writes content, such as Git automation or imports.
3. **Run the migration** with the same options as the clean dry run, without `--dry-run`:

   ```bash
   php artisan bunny-stream:migrate-fields --container=videos --field=bunny_video
   ```

   Before writing anything, the command prints a journal ID such as `migration-20261012-193301-f4daa9a6`. Copy it. It needs that ID for `--resume` and `bunny-stream:rollback`. Then it applies all files, clears the Stache and asset metadata caches, and clears the static cache if one is configured.
4. **Deploy templates** if you didn't use the compatible forms from step 6.
5. **Spot-check** a few pages while still in maintenance mode, using `php artisan down --secret=…` to get in.
6. **Reopen:** restart the queue workers and the scheduler, then run `php artisan up`.
7. **Commit the changes** to Git if content is versioned.

If the command is interrupted, for example by a crash or a full disk, resume it under the same freeze:

```bash
php artisan bunny-stream:migrate-fields --resume=migration-20261012-193301-f4daa9a6
```

Resume first checks every file. A file is accepted only if it is still exactly in its "before" or "after" state, and the command refuses to continue if anything else changed it.

## 9. Verify

- [ ] Pages with videos play, and their thumbnails show.
- [ ] In the CP, entries show the video as a selected asset in the former Bunny field.
- [ ] Editing and saving an entry works, and so does choosing a different video.
- [ ] A new upload to the container reaches *ready*.
- [ ] Restoring an older revision of an entry works. Saving a restored working copy converts its GUIDs to assets.
- [ ] Bard and Replicator sets, globals, taxonomy terms and users with video fields render correctly.

## 10. Finish up

When every Bunny field and template has been migrated:

1. **Hide the Media Browser:** set `'show_dashboard' => false`. Its endpoints stay available for anything that still uses them.
2. **Keep the markers.** Leave the `bunny_stream_migrated` keys in your blueprints and fieldsets. Old revisions store GUIDs, and these markers let a restored revision be converted when it's saved.
3. **Turn on remote cleanup (optional).** Once nothing else uses the Bunny library (other sites, apps or legacy fields), set `'delete_remote' => true`. From then on:
   - deleting an asset deletes its Bunny video;
   - replacing a video deletes the old stream once the new one is ready.

   Delete jobs never remove a GUID that another asset still owns. Videos kept while cleanup was off are not deleted afterwards.
4. **Keep the journals.** They are in `storage/app/bunny-stream`, and contain full before and after copies of the migrated files, possibly including private content. Keep them private and backed up until you're confident you won't roll back, then delete them.

## 11. Rolling back

A rollback restores every blueprint, fieldset and content file in the batch to its exact previous bytes. Imported assets and Bunny videos are left as they are.

```bash
php artisan down
# stop workers, scheduler and other writers
php artisan bunny-stream:rollback migration-20261012-193301-f4daa9a6
# restore the previous templates if you used asset-only forms
php artisan up
```

Rollback refuses to run if any file in the batch was edited after the migration. Restore or undo those edits first, or roll back from your backup. If you migrated several batches, roll them back newest first.

To undo the whole move, also remove the container from `asset_containers`. Imported assets can stay; they don't affect legacy fields.

## 12. Troubleshooting

### Import

| Message | Cause and fix |
|---|---|
| `Configure Bunny and enable the target asset container first.` | The container isn't in `asset_containers`, or the library ID, API key or hostname is missing. |
| `Another import is running.` | Another import holds the lock. If a previous run crashed, the lock expires after 24 hours. To release it now, run `php artisan tinker --execute="Cache::lock('bunny-stream:import')->forceRelease();"`. |
| `No downloadable MP4 is available for <guid>…` | No original was kept and MP4 fallback is off. Enable MP4 fallback in Bunny, wait for the renditions, and rerun. If you have the original file elsewhere, upload it to the container instead. |
| HTTP 403 while downloading | CDN token authentication or referrer protection is on. Set `BUNNY_STREAM_CDN_TOKEN_KEY` and/or `BUNNY_STREAM_DOWNLOAD_REFERER`. |
| `Download is not a supported video file (…)` / `…corrupt or cannot be inspected.` | Bunny returned something that isn't a usable video, often an error page, and no MP4 rendition was available as a fallback. Check the file in the Bunny dashboard, enable MP4 fallback if needed, and rerun. |
| `Refusing to overwrite an existing file: …` | A different file already exists at the import path. Move it away and rerun. |
| `Duplicate assets for GUID …` | Two assets claim the same Bunny video. Delete or reupload one of them. |

### Field migration

| Message | Cause and fix |
|---|---|
| `Enter maintenance mode and stop content writers/queue workers…` | Run `php artisan down` first. Only `--dry-run` works while the site is up. |
| `Select --field=handle …` | Pass at least one `--field`. |
| `Specify an enabled --container.` | Pass `--container`. It has to be listed in `asset_containers`. |
| `Override the addon fieldset statamic-bunny-stream::bunny_video…` | Copy the bundled fieldset to the override path printed in the message (see step 7). |
| `Publish the blueprint/fieldset before migration: …` | The definition lives in `vendor/`. Publish or override it into `resources/`. |
| `Unresolved or ambiguous Bunny reference at <field>: <guid>` | No imported asset matches the GUID. Run the import again (step 5), or clear the field if the video was deleted from Bunny. |
| `Missing migrated asset at …` | The content already holds an asset path, but that asset no longer exists. Restore the asset or clear the field. |
| `Expected one video reference at …` | The field holds a list or other non-GUID data. Fix the value by hand. |
| `Remove conflicting fieldset overrides for …` | A blueprint overrides the imported field's `type` or `max_files`. Remove the override. |
| `Unsupported nested fieldtype …` / `Unsupported content owner: …` | A Bunny field sits inside a fieldtype or owner the tool can't safely traverse, such as a form, navigation or a third-party fieldtype. Migrate those values by hand, or move the field. |
| `Unsupported content store for …` | The content lives in a database or custom repository. Migrate it with that store's own tooling. |
| `File changed since migration was prepared: …` | Something wrote to the file during the window. Find the writer, stop it, then resume or roll back. |

### After migration

- **A video shows the original file instead of the player.** The template still prints the field directly. Use `bunny_stream` (step 6).
- **"Not synced" on a video that was already streaming.** Its stream record was lost, for example because a deploy overwrote the `.meta` file. Don't click **Retry sync**, which uploads a duplicate. Run `php artisan bunny-stream:repair` to see which Bunny video it matches, then `--apply` to restore the record.
- **"Not synced" on a new upload.** The queue isn't running or uses `sync`. Fix the queue, then click **Retry sync**.
- **Stuck in *creating* with an error.** The request that created the Bunny video was interrupted. **Retry sync** is hidden in this state, because retrying would fail the same way. Find the video in Bunny whose title ends with the generation ID shown in the error, paste its GUID into **Attach and retry**, and the upload resumes without creating a duplicate.
- **Saving content fails with "Unresolved or ambiguous Bunny reference".** A restored old revision contains a GUID that no imported asset matches. Import the video, or clear the field in that revision before saving.
- **"Recovered MP4" on an imported asset.** Bunny kept no original, so the asset holds a rendition. If you have the original, use *Replace* or *Reupload* on the asset. A new stream is created, and the old one is kept until the new one is ready.
