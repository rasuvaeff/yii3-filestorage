# Examples

Runnable scripts. Each one is self-contained: it wires the package by hand, so
you can read the whole flow in one file without a framework in the way.

```bash
composer install
php examples/store-and-read.php
```

| Script | Shows | Needs a server? |
|---|---|---|
| [`store-and-read.php`](store-and-read.php) | Wiring `Storage` over a temporary filesystem store, storing three files, streaming them back, deleting one | No |
| [`policies.php`](policies.php) | Per-group accept rules: an allow-list rejecting a spoofed extension, a byte cap, and a pixel cap catching a decompression bomb | No |
| [`signed-urls.php`](signed-urls.php) | Minting and verifying a download token, tamper rejection, expiry, and key rotation keeping old URLs alive | No |
| [`testing-doubles.php`](testing-doubles.php) | Testing an upload flow with `InMemoryStore`, `MemoryRepository` and a PSR-20 `StaticClock` — no disk, no database, no wall clock | No |

The scripts use `nyholm/psr7` as the PSR-17 implementation and
`yiisoft/test-support` for its `StaticClock`, because both are development
dependencies of this package. Any PSR-17 implementation and any PSR-20 clock
work; the package never names one.
