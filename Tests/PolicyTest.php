<?php

namespace Omniplay\Edgegap\Tests;

use Omniplay\Edgegap\EdgegapFactory;
use Omniplay\Edgegap\EdgegapHosting;
use Omniplay\Exception\OmniplayException;
use Omniplay\Exception\UsageLimitReachedException;
use Omniplay\Registry;
use Omniplay\Secret\FixedSecrets;
use Omniplay\Testing\RecordedHttpClient;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** Edgegap's usage policy, as policy.json declares it, held through omniplay's registry. */
final class PolicyTest extends TestCase
{
    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = self::registry(self::answering())->policy('edgegap');

        self::assertSame('edgegap', $policy->provider);
        self::assertSame(['40/second/application/start'], null === $policy->rateLimits ? null : array_map(static fn (RateLimit $limit) => $limit->id(), $policy->rateLimits));
        self::assertSame(Permission::Contract, $policy->rights->commercial);
        self::assertSame(false, $policy->rights->attribution->required);
        self::assertSame(true, $policy->requirements->key);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame(UsagePolicy::fromFile(EdgegapHosting::POLICY)->toArray(), $policy->toArray(), 'the registry runs the policy the package declares');
        self::assertSame($policy->toArray(), self::registry(self::answering())->get('edgegap')->capabilities()->policy()->toArray(), 'its Capabilities carry it too');
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $http = self::answering();
        $registry = self::registry($http, ['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['running']]]]);

        $registry->hosting('edgegap')->running('game');
        $made = \count($http->requests);
        try {
            $registry->hosting('edgegap')->running('game');
            self::fail('The call over the site\'s limit was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmniplayException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/minute/application/running', $e->limit?->id());
        }
        self::assertCount($made, $http->requests, 'the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $http = RecordedHttpClient::routes(['~~' => [429, '', ['retry-after' => ['30']]]]);
        $registry = self::registry($http);

        try {
            $registry->hosting('edgegap')->running('game');
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(30, $e->retryAfter);
        }
        $made = \count($http->requests);
        try {
            $registry->hosting('edgegap')->running('game');
            self::fail('A call went out while Edgegap asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(29, $e->retryAfter);
        }
        self::assertCount($made, $http->requests, 'the wait held the next request back');
    }

    public function testASiteCannotRaiseTheLimit(): void
    {
        $this->expectException(InvalidPolicyException::class);
        self::registry(self::answering(), ['rateLimits' => [['max' => 100, 'window' => 'second', 'operations' => ['start']]]])->hosting('edgegap');
    }

    /** @param array<string, mixed> $policy */
    private static function registry(RecordedHttpClient $http, array $policy = []): Registry
    {
        return new Registry([new EdgegapFactory($http, new FixedSecrets(['EDGEGAP_API_TOKEN' => 'not-a-real-token']))], ['edgegap' => ['factory' => 'edgegap', 'options' => ['regions' => ['eu' => '198.51.100.20'], 'services' => ['game' => ['application' => 'my-game-server', 'version' => 'v1.4', 'internal_port' => 7777]]]] + ([] === $policy ? [] : ['policy' => $policy])]);
    }

    private static function answering(): RecordedHttpClient
    {
        return RecordedHttpClient::routes(['~^GET https://api\\.edgegap\\.com/v1/deployments~' => [200, '{"data": [], "total_count": 0}']]);
    }
}
