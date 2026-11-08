<?php

namespace Omniplay\Edgegap\Tests;

use Omniplay\Edgegap\EdgegapFactory;
use Omniplay\Edgegap\EdgegapHosting;
use Omniplay\Model\Service;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Against Edgegap itself: a deployment of EDGEGAP_APPLICATION version
 * EDGEGAP_VERSION near EDGEGAP_PLAYER_IP (203.0.113.7 when unset), listed,
 * stopped. The token in EDGEGAP_API_TOKEN. Skipped without them; never run
 * by the default suite (group live). It deploys a server, which Edgegap bills.
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    public function testADeploymentIsStartedListedAndStopped(): void
    {
        foreach (['EDGEGAP_API_TOKEN', 'EDGEGAP_APPLICATION', 'EDGEGAP_VERSION'] as $variable) {
            if (!\is_string(getenv($variable)) || '' === getenv($variable)) {
                self::markTestSkipped($variable.' is not set.');
            }
        }
        $hosting = (new EdgegapFactory())->create(['services' => ['live' => ['application' => (string) getenv('EDGEGAP_APPLICATION'), 'version' => (string) getenv('EDGEGAP_VERSION')]], 'wait' => 120]);
        self::assertInstanceOf(EdgegapHosting::class, $hosting);
        $ip = getenv('EDGEGAP_PLAYER_IP');

        $server = $hosting->near([\is_string($ip) && '' !== $ip ? $ip : '203.0.113.7'])->start(new Service('live', capacity: 2), 'not-a-real-secret');
        try {
            self::assertContains($server->id, array_map(static fn ($s) => $s->id, $hosting->running('live')));
        } finally {
            $hosting->stop($server);
        }
    }
}
