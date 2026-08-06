<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Policy;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DeliveryOptions::class)]
final class DeliveryOptionsTest
{
    public function carriesTheFilesNameAndTypeAndTheGroupsDisposition(): void
    {
        $options = DeliveryOptions::fromFile(
            $this->file(originalName: 'report.pdf', mimeType: 'application/pdf'),
            new DeliveryPolicy(forceDownload: true),
        );

        Assert::same($options->downloadName, 'report.pdf');
        Assert::same($options->responseMediaType, 'application/pdf');
        Assert::true($options->forceDownload);
    }

    /**
     * An unknown type is delivered as an opaque download rather than as
     * whatever a browser decides to sniff it into.
     */
    public function anUnknownTypeBecomesAnOpaqueStream(): void
    {
        $options = DeliveryOptions::fromFile($this->file(mimeType: null), new DeliveryPolicy());

        Assert::same($options->responseMediaType, 'application/octet-stream');
    }

    public function forceDownloadFollowsThePolicy(): void
    {
        Assert::false(
            DeliveryOptions::fromFile($this->file(), new DeliveryPolicy(forceDownload: false))->forceDownload,
        );
    }

    /**
     * A filename reaches a response header, and a header cannot contain a line
     * break without becoming two headers.
     */
    #[DataProvider('injectionProvider')]
    public function lineBreaksAndNulsAreStrippedFromTheName(string $name, string $expected): void
    {
        $options = DeliveryOptions::fromFile($this->file(originalName: $name), new DeliveryPolicy());

        Assert::same($options->downloadName, $expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function injectionProvider(): iterable
    {
        yield 'CRLF header injection' => ["a.txt\r\nX-Evil: 1", 'a.txtX-Evil: 1'];
        yield 'bare LF' => ["a\nb.txt", 'ab.txt'];
        yield 'bare CR' => ["a\rb.txt", 'ab.txt'];
        yield 'NUL byte' => ["a\0b.txt", 'ab.txt'];
    }

    /**
     * A name made up entirely of stripped characters must still yield a usable
     * filename rather than an empty one.
     */
    public function aNameThatStripsToNothingFallsBack(): void
    {
        $options = DeliveryOptions::fromFile($this->file(originalName: "\r\n\0"), new DeliveryPolicy());

        Assert::same($options->downloadName, 'file');
    }

    private function file(string $originalName = 'thing.bin', ?string $mimeType = 'text/plain'): File
    {
        return File::create(
            id: 'f-1',
            storeName: 'upload',
            groupName: 'common',
            relativePath: 'common/a/b/key/original.bin',
            originalName: $originalName,
            size: 1,
            createdAt: new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00'),
            mimeType: $mimeType,
        );
    }
}
