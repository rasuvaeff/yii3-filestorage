---
name: rasuvaeff-yii3-filestorage
description: >-
  DI-native file storage for Yii3 with rasuvaeff/yii3-filestorage — Storage
  facade, File and Upload value objects, StoreInterface plus its optional
  URL/range/content-addressable/maintenance/derivative capabilities,
  RepositoryInterface, path generators, finfo MIME detection, per-group upload
  and delivery policies, HMAC signed tokens, the BlobLedgerInterface dedup
  contracts, the filestorage:check/stat/verify/backfill-hash/gc/import console
  commands, and the InMemoryStore, MemoryRepository and MemoryBlobLedger test
  doubles. Use when writing, reviewing or debugging file
  upload, storage or download code in a project that has this package installed.
---

# rasuvaeff/yii3-filestorage

One facade over a swappable physical store and a swappable metadata repository.
Namespace `Rasuvaeff\Yii3Filestorage\`. Full API reference: `llms.txt`.

## Safety rules — verify these on every change

1. **The client media type is never authoritative.** `Upload::$clientMediaTypeHint`
   exists for diagnostics. Only `MimeTypeDetectorInterface::detect()` output may
   reach an `UploadPolicy`, a path extension or a response `Content-Type`. An
   attacker controls the hint completely.

2. **Paths are generated, never taken from input.** They come from a
   `PathGeneratorInterface`; `StoredObjectId` rejects `..`, NUL, backslashes and
   absolute paths, and local stores re-check containment with `realpath()` after
   resolving. `File::$originalName` is metadata — it must not enter a path.

3. **Two things are frozen and cannot change.** The default layout
   `<…>/<key>/original.<ext>` (`DirectoryLayout`: a directory per file, so
   `delete()` removes the directory and derivatives cannot leak) and the token
   format `v1.<key-id>.<expires>.<b64url payload>.<b64url hmac>`. `relativePath`
   is persisted and tokens are already in browsers. `FlatSiblingLayout`
   (`<base>.<ext>` + `<base>-<name>.<ext>`, with `FlatSha256KeyGenerator`) is an
   opt-in chosen once per store before it holds data — never switch a store's
   layout, and derive derivative paths from `$store->layout()`, never from
   `File::directory()`. **A key generator must fit the layout:** wherever a
   generator meets a store, call
   `Store\Layout\LayoutCompatibility::assertContentKeys($keys, $store)` /
   `assertPaths($paths, $store, $streams)` — flat keys under `DirectoryLayout`
   delete a whole fan-out bucket (`DirectoryLayout` now refuses names other than
   `original[.ext]`). `FlatSha256KeyGenerator(prefix: 'blobs')` gives
   `blobs/ab/cd/<sha>.<ext>` and ignores the scope (single-tenant only);
   `LayoutCompatibility::tenantProblem($keys[, $scopeA, $scopeB])` detects that,
   and `check` warns about it while a scope provider is bound.

4. **`urlFor()`, not `url()`.** `url()` is raw and ignores delivery policy;
   `temporaryUrl()` does pass the group's `DeliveryOptions` to the store, so a
   presigned URL cannot serve active content inline. What neither consults is
   `allowDirectPublicUrl`. `urlFor()` applies it: a permanent public URL
   only where the group allows one, otherwise presigned, otherwise the signed
   proxy route. Direct public URLs bypass every response header `-web` enforces.
   Outside the tenant's request (a job, a mail) mint with a pinned generator:
   `$storage->withProxyUrls($proxyUrls->forScope($tenantId))->urlFor($file)` —
   a method of the concrete `Storage`, never a scope field on `File`.

5. **Direct public delivery plus active content is a stored-XSS hole.** HTML,
   SVG, XML, XSLT, JavaScript and feeds served from your own origin execute as
   your site. `Mime\ActiveMediaTypes` is the family's one list (scriptable tier
   + plugin tier: PDF, Flash; any `+xml` type is scriptable) — never write a
   second one, and never `new` it where a decision is made: take the instance
   core DI binds from the `activeMediaTypes` parameter (`extra`, `allowInline`
   for plugin types), or `DeliveryPolicyRegistry::activeMediaTypes()` /
   `optionsFor($file)`. `DeliveryOptions` forces `attachment` for every active
   type, `ExtensionMap` stores scriptable ones as `.bin`, and `filestorage:check` fails a direct-public group accepting a
   scriptable type, or any permissive/scriptable group when a publicly
   readable store is registered (`Store\PubliclyReadableStoreInterface::isPubliclyReadable()`
   true: `PublicFileSystemStore` always, a public bucket store when configured
   so; it serves by path regardless of `allowDirectPublicUrl`) — do not work
   around it. It also
   fails a bound `FileScopeProviderInterface` with no `ScopedFileResolverInterface`:
   a signed download would then resolve by id alone.

6. **Byte caps are enforced while copying, not after.** An unknown-length body
   must be stopped mid-write and its partial output removed. A check after the
   fact has already spent the disk. A store crossing the group's cap throws
   `UploadSizeException`; every size refusal implements
   `SizeLimitExceededException` — catch that, not one concrete class.

6a. **The pixel guard fails closed.** `maxPixels` walks a JPEG marker by marker
   (seek by segment length, never scan for `FFC0` — the EXIF thumbnail has its
   own frame header; stray bytes *between* segments are skipped as libjpeg
   does; it walks on to the scan and refuses a second frame header), walks a GIF block by block (a frame larger than the logical screen is
   undetermined — the screen alone is a 1×1 lie), walks a TIFF's IFD chain,
   and refuses a JPEG/PNG/GIF/WebP/TIFF whose dimensions cannot be established.
   Other formats are measured from a 256 KiB prefix only — `requireImageDimensions`
   for groups whose files reach a decoder. Prefixes are read with a loop, never
   one `read()`.

6b. **`add()` writes nothing it cannot keep.** Metadata, id and clock are
   validated before `store->write()`; anything failing after it is an
   `AddException`. A decorating `add()` must keep the same order.

7. **Maintenance commands report before they act.** `filestorage:gc` and
   `filestorage:backfill-hash` are dry-run unless given `--apply`, and nothing
   in `gc` — sweeping expired reservations included — runs under a dry run,
   because the sweep writes. `filestorage:verify` never has `--apply`: what to
   do about a missing object is not a decision a command makes for you.

8. **`gc --orphans` refuses under tenancy, and that refusal must not be
   removed.** An object is an orphan when no row anywhere points at it; the
   referenced-set comes from a tenant-filtered repository while the object
   listing is physical, so the difference is other tenants' live files. There is
   no tenant to run it "as". Run the sweep with `FileScopeProviderInterface`
   unbound. `filestorage:stat` splits on the same line one step milder: its
   logical counts stay (labelled `Group (current scope)`), its physical
   "Distinct objects" and sharing savings are withheld under a bound provider
   rather than estimated from a partial view.

9. **`filestorage:import` is only re-runnable because of its manifest.** `add()`
   has no natural key, so a second run without the JSON Lines manifest writes a
   second row *and* a second object per file. Never "simplify" it away, and
   never record a file the policy rejected — the run that widens the policy has
   to retry exactly those.

10. **`gc --apply` is the only thing here that deletes shared bytes.** It takes
   an exclusive, expiring lease per blob and removes the ledger row only if the
   blob is still unreferenced in the statement that acts. Never delete a shared
   object anywhere else, and never inside a request. Deletion is two-phase:
   quarantine → `completeDeletion()` → purge, or restore (put-if-absent) when
   the ledger refuses — so a collector paused past its lease cannot destroy
   bytes a writer re-published. A store without a quarantine
   (`FileSystemStore`'s `quarantinePath`) cannot be collected from;
   `filestorage:check` warns. A store implementation must keep the quarantine
   out of `objects()` and out of public reach (`PublicFileSystemStore(webRoot:)`
   checks the document root), and roll back a quarantine that fails half-way.
   Every run asks rows and the reference checker about every quarantine entry
   the ledger does not know and restores a referenced one at once — a release
   interrupted before its row was deleted is undone by the next run; under a
   bound scope provider that answers a scope nothing the ledger does not know is
   purged.
   **The store root belongs to the store:** `gc --orphans` takes every row-less
   file under it (and stale `.part` leftovers). Hidden entries are never listed;
   under a shared web root pass `ownedPrefixes` to `FileSystemStore` — with
   `RandomPathGenerator(prefix: 'files')` one entry covers every group. A
   deduplicated object on a public store is addressed by its content hash:
   anyone with the same bytes computes its URL.

11. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.

12. **Verification is mandatory.** Never claim "done" without a fresh green
    `composer build`.

## Gotchas

- `Upload::stream()` is always rewound. Read it to the end freely; the next call
  rewinds again. Do not call `moveTo()` on a PSR-7 uploaded file yourself — it
  invalidates the stream the `Upload` holds.
- Capabilities are `instanceof` checks against separate interfaces, not nullable
  methods on `StoreInterface`. The exception is `StoreUrlProviderInterface`,
  whose methods return null because one `FlysystemStore` class must wrap
  adapters that differ at runtime.
- `Test\InMemoryStore` deliberately implements neither URLs nor ranges. That is
  how you test what your code does when a store cannot presign.
- No clock double ships with the package. Everything takes a PSR-20 clock —
  use `Yiisoft\Test\Support\Clock\StaticClock` rather than adding another.
- Timestamps serialise as `Y-m-d\TH:i:s.uP`. `DateTimeInterface::ATOM` drops
  microseconds and breaks the `File` round-trip against a PSR-20 clock.
- The maintenance commands need a backend implementing
  `MaintenanceRepositoryInterface`. All six are always registered; without one,
  running any of them is a container error naming that interface — loud, not
  silent.
- `verify`, `backfill-hash` and `stat` page by id and print where they stopped;
  resume with `--after=<id>`. `import` resumes through its manifest.
  `gc --orphans` walks every store unless `--store=` narrows it, `--limit` is a
  budget for the whole pass, `--max-scan` bounds objects examined, and
  `--store=<name> --after=<path>` resumes (JSON `nextCursor`). An orphan must be
  older than `--min-age` (default `PT1H`) measured from its page's reference
  lookup; an object without a timestamp is never swept. Swept orphans stay in
  the quarantine for `--retention` (default `P7D`). The cursor is per store —
  cron one sweep per `--store`. Intervals come from params `gc.*` and must be
  positive. With `-db` 0.4, a bound scope provider answering null makes the
  maintenance commands throw `MissingScopeException` (unbind it, or set `-db`'s
  `globalMaintenanceAccess` param).
- `FileSystemStore`/`PublicFileSystemStore` are content-addressable
  (`putIfAbsent()` via `link()`), so `-db` deduplication runs on a local disk.
- Groups with `referencesOwnedBy: application` need a bound
  `ReferenceCheckerInterface` (by path, batched): `gc` releases their rows that
  are older than `--min-age` and no longer referenced, and re-asks before
  deleting a blob.
  A text-column scan must skip the package's own tables (they name every file,
  so nothing is released) and match every form a reference takes (URL and
  in-store path).
- `add()` is all-or-nothing per file. There is no `addMany()`, because an atomic
  batch is impossible over a filesystem or an object store.
