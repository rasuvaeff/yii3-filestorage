<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Policy;

use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DeliveryPolicy::class)]
final class DeliveryPolicyTest
{
    /**
     * The safe combination is the default: no permanent public URL, and a
     * forced download when the application does serve the file itself.
     */
    public function defaultsToTheSafeCombination(): void
    {
        $policy = new DeliveryPolicy();

        Assert::false($policy->allowDirectPublicUrl);
        Assert::true($policy->forceDownload);
    }

    public function bothSwitchesAreIndependent(): void
    {
        $policy = new DeliveryPolicy(allowDirectPublicUrl: true, forceDownload: false);

        Assert::true($policy->allowDirectPublicUrl);
        Assert::false($policy->forceDownload);
    }
}
