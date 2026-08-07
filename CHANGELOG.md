# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- Added four maintenance console commands: `filestorage:gc` (collects
  unreferenced shared blobs, and with `--orphans` sweeps objects no row points
  at), `filestorage:verify` (rows whose object is missing; `--deep` re-reads and
  compares the hash), `filestorage:backfill-hash` (fills `contentHash` on rows
  written before hashing was on) and `filestorage:stat` (counts and sizes by
  group, plus what sharing saved). All page by id and resume from `--after`;
  `gc` and `backfill-hash` report unless given `--apply`, and `verify` has no
  `--apply` at all. `gc` collects shared blobs only when a `BlobLedgerInterface`
  is bound; without one it still sweeps orphans.
- Fixed `Test\MemoryBlobLedger::expireReservations()` rescheduling every blob
  rather than only those that lost a reservation, which pushed each already
  scheduled blob's grace period forward on every collection pass — so nothing
  ever became collectable. `DbBlobLedger` was already correct; both now have a
  test pinning it.
- Lowered `minMsi` from 89 to 88. The four console commands add three new
  survivor groups — page-size constants, `break`/`continue` inside a
  cursor-paged walk, and console option casts — documented in `AGENTS.md`. No
  mutator is ignored.

- Added `Store\BlobLedgerInterface` and its value objects — `BlobState`,
  `BlobToken`, `BlobReservation`, `BlobLease`, `BlobRecord` — declaring how
  deduplicated bytes are owned, reserved, released and collected. The contract
  lives here rather than in `rasuvaeff/yii3-filestorage-db` so the shipped
  double can implement it; the transactional implementation is that package's.
- Added `Repository\FileScopeProviderInterface` (bound by the application) and
  `Repository\ScopedFileResolverInterface` (bound by `-db`), so a signed
  download resolves an authenticated scope instead of disabling the tenant
  filter.
- Added `Exception\LedgerException` and `Exception\BlobBusyException`.
- Added the `Test\MemoryBlobLedger` double: the full ledger state machine in an
  array, minus the concurrency a real database provides.
