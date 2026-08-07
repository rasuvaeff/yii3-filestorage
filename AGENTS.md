# AGENTS.md — yii3-filestorage

Guidance for AI agents working on this package. Read before changing code.

## What this is

The core of a file-storage family for Yii3: one facade over a swappable
physical backend (`StoreInterface`) and a swappable metadata backend
(`RepositoryInterface`). Namespace `Rasuvaeff\Yii3Filestorage`.

Public API: `StorageInterface` / `Storage` (facade), `File` (immutable metadata
VO), `Upload` (ingress VO), `Store\StoreInterface` plus the optional
capabilities `StoreUrlProviderInterface`, `RangeReadableStoreInterface`,
`ContentAddressableStoreInterface`, `MaintenanceStoreInterface`,
`DerivativeAwareStoreInterface`, `DerivativeUrlProviderInterface`;
`Store\StoreRegistry`, `StoreResult`, `StoredObjectId`, `BlobId`,
`DerivativeDescriptor`, `DerivativeObject`; `Store\FileSystem\FileSystemStore`
and `PublicFileSystemStore`; `Repository\RepositoryInterface` and
`MaintenanceRepositoryInterface`; `Path\PathGeneratorInterface` with
`RandomPathGenerator` / `DatePathGenerator`, and
`ContentAddressedKeyGeneratorInterface` with `Sha256KeyGenerator`;
`Mime\MimeTypeDetectorInterface` / `FinfoMimeTypeDetector` / `ExtensionMap`;
`Id\IdGeneratorInterface` with `Uuid7IdGenerator` (default),
`SymfonyUidIdGenerator` and `RamseyUuidIdGenerator`; `Policy\UploadPolicy`,
`PolicyRegistry`, `DeliveryPolicy`, `DeliveryPolicyRegistry`, `DeliveryOptions`;
`Url\UrlSignerInterface` / `HmacUrlSigner` / `SigningKeyRing` / `SignedPayload` /
`ProxyUrlGeneratorInterface`; `Stream\LimitedStream`; `Command\CheckCommand`,
`Command\GcCommand`, `Command\VerifyCommand`, `Command\BackfillHashCommand`
and `Command\StatCommand`;
`Store\BlobLedgerInterface` with `BlobState`, `BlobToken`, `BlobReservation`,
`BlobLease` and `BlobRecord`; `Repository\FileScopeProviderInterface` and
`ScopedFileResolverInterface`;
the `Exception\*` family behind the `FilestorageException` marker; and the
`Test\InMemoryStore` / `Test\MemoryRepository` / `Test\MemoryBlobLedger` doubles.

**No clock double ships here.** Everything takes a PSR-20 clock, and a Yii
application already has `Yiisoft\Test\Support\Clock\StaticClock`. Tests that
need time to *move* within one test use `tests/Support/MovableClock`, which is
`@internal` on purpose — a movable clock is a test concern, not public API.

**The `Test\` namespace is public API and lives in `src/`.** `.gitattributes`
export-ignores `tests/`, so a double placed there would not be installed by a
consumer. Changing those classes is a BC concern like any other.

DI wiring: core `config/di.php` binds the facade, `StoreRegistry`, path
generator, MIME detector, id generator, `ExtensionMap`, both policy registries
and all five commands. It must **not** bind `StoreInterface` or
`RepositoryInterface` — `yiisoft/config` allows exactly one vendor package per
key, so those belong to `-flysystem`/`-db` or to the application. Two packages
binding one of them is a `Duplicate key` error by design.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **The client media type never crosses a security boundary.**
   `Upload::$clientMediaTypeHint` is diagnostics. Only
   `MimeTypeDetectorInterface::detect()` output may reach an `UploadPolicy`, a
   path extension or a response `Content-Type`. Likewise `File::$originalName`
   is metadata and must never enter a path.
4. **Preserve the public contract.** Update `README.md` **and `README.ru.md`**,
   `llms.txt` and the tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.
`composer.lock` is gitignored (library).

## Frozen decisions

Two things are already handed out to the world and cannot change without
breaking existing data or existing URLs. Treat a change to either as a
whole-family major, not a refactor.

**Path layout `<…>/<key>/original.<ext>` — a directory per stored file.**
`File::$relativePath` is persisted, so changing the layout means moving every
object and rewriting every row. The directory is what makes derivatives work
with no schema change (`<key>/thumb.webp` is a sibling) and why
`StoreInterface::delete()` removes the *directory* — deleting only the object
would leak every preview, indistinguishably from live files. The `<ext>` comes
from the detected media type through `ExtensionMap`; unknown is `original.bin`.

**Token format `v1.<key-id>.<expires>.<b64url payload>.<b64url hmac>`.**
Version, key id, expiry and payload are all covered by the HMAC. The payload is
canonical JSON `{fileId, variant, scopeId}` with a fixed key order, and
`fromCanonicalJson()` re-encodes and compares byte for byte so one signature
pins exactly one string. `variant` is inside the signature, so a token minted
for a redacted rendition cannot be replayed for the original. A future format
uses `v2.`; both may be accepted during a bounded migration window.

## Mutation testing

`minMsi` is **88, not 100, and no mutator is ignored** — the threshold is what
the suite honestly achieves rather than a number propped up by suppressions. It
came down from 89 when the four maintenance commands landed, which added the
last three groups below; the library-side groups are unchanged.
The survivors fall into eight groups, none of which a test can kill without
trading away something real:

| Group | Example | Why no test kills it |
|---|---|---|
| Exception-code arguments | the `0` in `new StoreException($message, 0, $e)` | Nothing asserts an exception code, and asserting one would pin a value that carries no meaning |
| Unreachable defence in depth | `HmacUrlSigner`'s 2048-byte payload cap | `SignedPayload` caps its three fields at ~600 bytes of canonical JSON, so the cap cannot be reached — lowering it to make it reachable would weaken it |
| Psalm-narrowing guards | `$keyId === ''` before a pattern that already rejects `''` | Dead at runtime by construction; it exists so psalm can narrow to `non-empty-string` without a suppression |
| Redundant-but-deliberate rewinds | `FinfoMimeTypeDetector` rewinding after its sniff | `Upload::stream()` rewinds on every call, so the collaborator's own rewind is unobservable — it stays because "leave the stream where you found it" should hold for each collaborator on its own |
| Equivalent arithmetic | `min($length, $size - $offset)` in `streamRange()` | `LimitedStream` re-clamps the window, so a wrong bound produces identical output |
| Invariant-redundant ledger guards | the eight survivors in `Test\MemoryBlobLedger` | The double keeps `blobs` and `fileBlobs` consistent and never lets a lease exist outside `deleting`, so `\|\|`↔`&&`, the `default` match arm, `max(0, refs - 1)` and the counter re-check in `completeDeletion()` are all unreachable. They mirror `DbBlobLedger`, where the same conditions *are* races and live in SQL |
| Page-size constants | the `500` in every `files($after, 500)`, the `262_144` read size, `iterator_to_array(…, false)`, and the `min`/`max` arithmetic around a remaining budget | Killing them needs fixtures of 500+ rows, and even then most produce identical output — a different page boundary is not a different result. `iterator_to_array`'s preserve-keys flag is unobservable when the result is only iterated |
| `break` versus `continue` in a cursor-paged walk | the `continue` in every "skip this row" branch of `gc`, `verify` and `backfill-hash` | The cursor is advanced *before* the branch and the outer `while` re-pages from it, so breaking out of the inner loop resumes at the very next row. The two really are equivalent here; the cursor is what makes them so |
| Console option casts and guards | `(bool) $input->getOption('apply')`, and the `isset() && is_string() && !== ''` chain in each `stringOption()` | Symfony already guarantees the type, so only the `!== ''` half is reachable — and that one *is* tested. The rest exists so psalm can narrow without a suppression |

If a change makes a group disappear, raise `minMsi`. If a change adds an escaped
mutant *outside* these groups the gate fails at 88 — that is the point. Do not
ignore mutators to get past it; strengthen the assertion instead.

Two assertions exist purely to kill subtle mutants and must not be weakened.
`Uuid7IdGeneratorTest::versionAndVariantBytesKeepTheirOwnRandomBits` catches
byte 6 and byte 8 being built from the same random byte — a neighbouring index
still produces something that looks like a UUIDv7. Several exception-message
tests assert a substring that *spans* a concatenation
(`'does not exist and could not be created. Create it and make it writable'`);
asserting only one half lets the operands be swapped undetected.

## Invariants & gotchas

- **Byte caps are enforced during the copy.** `StoreInterface::write()` counts
  bytes as it writes and removes its partial output when the cap is crossed.
  Checking afterwards has already spent the disk, and the whole point is an
  unknown-length body.
- **`Upload::stream()` is always rewound and seekable.** That guarantee is what
  makes sniff → policy → hash → write safe over one input. A non-seekable
  stream is spooled once into `php://temp`, bounded by `maxSpoolBytes`.
- **Publishing is atomic.** Local stores write a `.part` sibling inside the
  freshly created key directory and `rename()` it into place. Staging inside the
  destination is deliberate: `rename()` is only atomic within one filesystem,
  and a shared staging directory is a predictable target.
- **Reads re-check containment.** `realpath()` then `str_starts_with($root . '/')`.
  The relative path was already validated, but a symlink planted inside the tree
  is a separate problem.
- **Capabilities are `instanceof` checks against separate interfaces.** The one
  exception is `StoreUrlProviderInterface`, whose methods return null: a single
  `FlysystemStore` class has to wrap adapters that differ at runtime, and PHP
  interfaces do not appear conditionally.
- **`temporaryUrl()` returns null when it cannot honour `DeliveryOptions`.** A
  presigned URL that serves an HTML file inline from your bucket is worse than
  no presigned URL — falling through to the proxy is the correct answer.
- **Timestamps serialise as `Y-m-d\TH:i:s.uP`, never `ATOM`.** PSR-20 clocks
  report microseconds; ATOM truncates them and `fromArray(toArray($f))` would
  stop equalling `$f`.
- **`Storage` is unique-only.** Compensation may delete the object it just wrote
  precisely because no other row can point at it. Content-addressed sharing has
  a different lifecycle and belongs in `-db`, not behind a flag here.
- **Deduplication ownership is a `BlobId`, never a hash count.** A hash is
  content identity; it is identical across stores, groups and tenants, so a
  hash-keyed reference count lets one tenant delete bytes another still uses.
- **Shared bytes are never deleted inside a request.** `BlobLedgerInterface`
  removal only *schedules*: the last release marks a blob `pending_delete`, and
  only a collector holding a lease deletes, only while both counters are still
  zero in the statement that claims it. `commit()` owns the joint file-row and
  reference transaction for the same reason — sequencing the two in the caller
  makes one of them an orphan or a leak whenever the other fails.
- **A signed download must not turn the tenant filter off.** The scope rides in
  the token (`SignedPayload::$scopeId`) and `ScopedFileResolverInterface` matches
  it as a second predicate. Disabling the filter is a cross-tenant read of every
  file whose id leaks.
- **`gc --orphans` refuses when a `FileScopeProviderInterface` is bound, and
  that refusal is load-bearing.** The referenced-set comes from the repository,
  which filters by the current tenant; the object listing is physical and
  filters by nothing. Under tenancy the difference between them is every other
  tenant's live files, and `--apply` would delete them. There is no scope value
  that fixes it — no single tenant's rows can prove an object unreferenced — so
  the command refuses rather than warning. Blob collection is unaffected: the
  ledger is keyed by physical identity. `stat` has the same scoped-read
  asymmetry but only misreports, so it is documented rather than refused.
- **Two packages contributing `params['yiisoft/yii-console']['commands']` is
  only safe because the runner merges `params` recursively.** Core names five
  commands there and `-db` names `filestorage:deduplicate`; without recursion
  `yiisoft/config` refuses the *top-level* key, not the leaf. Every Yii3
  application gets the recursion — `ApplicationRunner` constructs `Config` with
  `RecursiveMerge::groups(...$paramsGroups, ...)` — so this holds in practice,
  but a harness that builds `Config` by hand must pass the same modifier or it
  will report a `Duplicate key` that no application would ever see. Verified
  against real `yiisoft/config` with a fake vendor layout, 2026-08-07.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types, named arguments, trailing commas.
- Every validation regex ends with `\z`, never `$` — `$` also matches before a
  trailing newline (`docs/evolved-rules.md` ER-001).
- Psalm runs at level 1 over `src/` only. Where a docblock already declares
  `non-empty-string`, psalm treats a runtime `=== ''` check as dead code: put
  the narrow type on the *property* and accept the wide type in the constructor
  whenever the value can come from configuration or a decoded token.
- `config/di.php` is covered by neither cs, nor psalm, nor the tests. Keep
  branching logic out of it — that is why `PolicyRegistry::fromArray()` and
  `DeliveryPolicyRegistry::fromArray()` exist. Verify changes with `php -l` and
  a real `yiisoft/config` merge harness.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment. Never revert
  to floating `@vN` tags; updates go through Dependabot. Workflows carry
  `permissions: { contents: read }` and `persist-credentials: false` on every
  checkout. Verify with `zizmor --persona=auditor .github/`.
- **The property-testing corpus uses `actions/cache/restore` + an explicit
  `actions/cache/save` with `if: ${{ !cancelled() }}`.** The combined
  `actions/cache` declares `post-if: "success()"`, and the run that records a
  counterexample is the failing one — so the combined action would never save
  it. The key includes `github.run_attempt` so a re-run does not collide with an
  already-written key.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit), plus
  `llms.txt`, `resources/skills/*/SKILL.md` and `examples/` if usage changed;
  update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
