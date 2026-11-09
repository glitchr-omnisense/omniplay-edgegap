<?php

namespace Omniplay\Edgegap\Tests;

use Omniplay\Edgegap\EdgegapFactory;
use Omniplay\Edgegap\EdgegapHosting;
use Omniplay\Exception\InvalidArgumentException;
use Omniplay\Exception\InvalidConfigException;
use Omniplay\Exception\NotSupportedException;
use Omniplay\Exception\ProviderException;
use Omniplay\Fleet;
use Omniplay\Model\Health;
use Omniplay\Model\SeatRequest;
use Omniplay\Model\Server;
use Omniplay\Model\Service;
use Omniplay\Registry;
use Omniplay\Seating;
use Omniplay\Secret\FixedSecrets;
use Omniplay\Storage\InMemoryStore;
use Omniplay\Tickets;
use PHPUnit\Framework\TestCase;

/**
 * Edgegap's answers written by hand after its OpenAPI specifications
 * (edgegap-v2-openapi.yaml 2026.08.05: POST /v2/deployments;
 * edgegap-v1-openapi.yaml: GET /v1/status/{id}, GET /v1/deployments,
 * DELETE /v1/stop/{id}), on Symfony's MockHttpClient, with no account.
 */
final class EdgegapHostingTest extends TestCase
{
    private const SECRET = 'not-a-real-secret';
    private const TOKEN = 'not-a-real-token';
    private const REQUEST = 'a69f8e3e0555';
    private const API = 'https://api.edgegap.com';

    public function testAServerIsADeploymentNearItsPlayersReadUntilEdgegapGivesItsAddress(): void
    {
        $recorder = new Recorder();
        $sleeps = [];
        $hosting = self::hosting($recorder->client(self::api()), [], $sleeps)->near(['203.0.113.7', [45.508888, -73.561668]]);

        $server = $hosting->start(self::service(), self::SECRET, null, ['ranked']);

        self::assertMatchesRegularExpression('~^omniplay-game-[0-9a-f]{8}$~', $server->id);
        self::assertSame(['game', '192.53.120.48:30345', 8, Health::Starting, null, ['ranked'], 'godot', 'edgegap', self::REQUEST], [$server->service, $server->address, $server->capacity, $server->health, $server->region, $server->tags, $server->engine, $server->provider, $server->instance]);
        self::assertSame(['POST '.self::API.'/v2/deployments', 'GET '.self::API.'/v1/status/'.self::REQUEST, 'GET '.self::API.'/v1/status/'.self::REQUEST], $recorder->calls());
        self::assertSame([1.0], $sleeps, 'one wait between the status without ports and the one with them');
        foreach ($recorder->requests as $request) {
            self::assertSame(['token '.self::TOKEN, 'application/json', 'application/json'], [$request['headers']['authorization'] ?? null, $request['headers']['accept'] ?? null, $request['headers']['content-type'] ?? null]);
        }
        self::assertSame([
            'application' => 'my-game-server',
            'version' => 'v1.4',
            'users' => [
                ['user_type' => 'ip_address', 'user_data' => ['ip_address' => '203.0.113.7']],
                ['user_type' => 'geo_coordinates', 'user_data' => ['latitude' => 45.508888, 'longitude' => -73.561668]],
            ],
            'environment_variables' => [
                ['key' => 'OMNIPLAY_SERVER_ID', 'value' => $server->id, 'is_hidden' => false],
                ['key' => 'OMNIPLAY_ROOM', 'value' => $server->id, 'is_hidden' => false],
                ['key' => 'OMNIPLAY_SERVICE', 'value' => 'game', 'is_hidden' => false],
                ['key' => 'OMNIPLAY_CAPACITY', 'value' => '8', 'is_hidden' => false],
                ['key' => 'OMNIPLAY_PORT', 'value' => '7777', 'is_hidden' => false],
                ['key' => 'GAME_TICKET_SECRET', 'value' => self::SECRET, 'is_hidden' => true],
                ['key' => 'OMNIPLAY_ENGINE', 'value' => 'godot', 'is_hidden' => false],
                ['key' => 'OMNIPLAY_HEARTBEAT_URL', 'value' => 'https://www.example.org/omniplay/heartbeat', 'is_hidden' => false],
                ['key' => 'MAP', 'value' => 'harbour', 'is_hidden' => false],
            ],
            'tags' => ['op:id='.$server->id, 'op:svc=game', 'op:cap=8', 'op:eng=godot', 'op:tag=ranked', 'production'],
            'resources' => ['cpu_units' => 1536, 'memory_mib' => 3072],
        ], $recorder->requests[0]['body']);
        self::assertSame([], $recorder->requests[0]['query']);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($server->toArray()), 'the secret is in the deployment only');
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($server->toArray()));
        self::assertSame(['contracts' => ['hosting'], 'lookupBy' => [], 'prices' => false, 'player' => null, 'attribution' => null, 'terms' => [], 'keyless' => false, 'services' => ['game'], 'regions' => [], 'where' => 'Edgegap at https://api.edgegap.com, near 2 players'], $hosting->capabilities()->toArray());
    }

    public function testARegionDeploysNearItsConfiguredLocationAndTheFqdnMayBeTheAddress(): void
    {
        $recorder = new Recorder();
        $hosting = self::hosting($recorder->client(self::api()), ['services' => ['game' => ['application' => 'my-game-server', 'version' => 'v1.4', 'address' => 'wss://{fqdn}:{port}/play']]]);

        self::assertSame(['contracts' => ['hosting'], 'lookupBy' => [], 'prices' => false, 'player' => null, 'attribution' => null, 'terms' => [], 'keyless' => false, 'services' => ['game'], 'regions' => ['eu', 'na'], 'where' => 'Edgegap at https://api.edgegap.com'], $hosting->capabilities()->toArray());
        $server = $hosting->start(self::service(), self::SECRET, 'na');
        $first = $hosting->start(self::service(), self::SECRET);

        self::assertSame(['wss://c0653765de3b.pr.edgegap.net:30345/play', 'na', 'eu'], [$server->address, $server->region, $first->region]);
        $body = $recorder->requests[0]['body'];
        self::assertIsArray($body);
        self::assertSame([['user_type' => 'geo_coordinates', 'user_data' => ['latitude' => 45.508888, 'longitude' => -73.561668]]], $body['users']);
        self::assertSame(['op:id='.$server->id, 'op:svc=game', 'op:cap=8', 'op:reg=na', 'op:eng=godot'], $body['tags']);
        self::assertArrayNotHasKey('resources', $body);
        self::assertIsArray($body['environment_variables']);
        self::assertNotContains('OMNIPLAY_PORT', array_column($body['environment_variables'], 'key'), 'no internal port configured: Edgegap hands ARBITRIUM_PORT_GAMEPORT_INTERNAL');
        $other = $recorder->requests[3]['body'];
        self::assertIsArray($other);
        self::assertSame([['user_type' => 'ip_address', 'user_data' => ['ip_address' => '198.51.100.20']]], $other['users'], 'no region asked: the first configured');
    }

    public function testADeploymentInErrorIsStoppedAndSaid(): void
    {
        $recorder = new Recorder();
        $http = $recorder->client([
            ['~^POST .+/v2/deployments$~', 202, '{"request_id": "'.self::REQUEST.'"}'],
            ['~^GET .+/v1/status/~', 200, '{"request_id": "'.self::REQUEST.'", "fqdn": "", "public_ip": "", "app_name": "my-game-server", "app_version": "v1.4", "current_status": "Status.ERROR", "running": false, "error": true, "error_detail": "Pull image timeout exceeded", "start_time": "", "elapsed_time": 0, "max_duration": 1440}'],
            ['~^DELETE .+/v1/stop/~', 202, '{"message": "'.self::REQUEST.' - Delete request will be processed soon"}'],
        ]);
        try {
            self::hosting($http)->near(['203.0.113.7'])->start(self::service(), self::SECRET);
            self::fail('Started.');
        } catch (ProviderException $e) {
            self::assertSame('Edgegap could not deploy a69f8e3e0555 (my-game-server version v1.4): Pull image timeout exceeded', $e->getMessage());
        }
        self::assertSame('DELETE '.self::API.'/v1/stop/'.self::REQUEST, $recorder->calls()[2]);
    }

    public function testADeploymentGivenNoAddressInTimeIsStopped(): void
    {
        $recorder = new Recorder();
        $sleeps = [];
        $http = $recorder->client([
            ['~^POST .+/v2/deployments$~', 202, '{"request_id": "'.self::REQUEST.'"}'],
            ['~^GET .+/v1/status/~', 200, self::deploymentStatus(false)],
            ['~^DELETE .+/v1/stop/~', 410, '{"message": "Instance already terminated"}'],
        ]);
        try {
            self::hosting($http, ['wait' => 2], $sleeps)->near(['203.0.113.7'])->start(self::service(), self::SECRET);
            self::fail('Started.');
        } catch (ProviderException $e) {
            self::assertSame('Edgegap gave the deployment a69f8e3e0555 no address within 2 seconds: it is stopped.', $e->getMessage());
        }
        self::assertSame([1.0, 1.0], $sleeps);
        self::assertSame(['POST', 'GET', 'GET', 'GET', 'DELETE'], array_column($recorder->requests, 'method'));
    }

    public function testAServerIsStoppedByItsRequestIdAndOneGoneAlreadyIsFine(): void
    {
        $server = new Server('omniplay-game-1', 'game', '192.53.120.48:30345', 8, provider: 'edgegap', instance: self::REQUEST);
        foreach ([[200, '{"message": "Delete request will be processed soon"}'], [202, '{"message": "accepted"}'], [404, '{"message": "Not found"}'], [410, '{"message": "Instance already terminated"}']] as [$status, $body]) {
            $recorder = new Recorder();
            self::hosting($recorder->client([['~^DELETE~', $status, $body]]))->stop($server);
            self::assertSame(['DELETE '.self::API.'/v1/stop/'.self::REQUEST], $recorder->calls());
        }

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Edgegap answered 403 for DELETE /v1/stop/a69f8e3e0555. {"message": "Forbidden"}');
        self::hosting((new Recorder())->client([['~^DELETE~', 403, '{"message": "Forbidden"}']]))->stop($server);
    }

    public function testTheRunningServersAreReadBackFromTheirTagsPageByPage(): void
    {
        $recorder = new Recorder();
        $page = static fn (string $deployments, bool $next): string => '{"data": ['.$deployments.'], "total_count": 3, "pagination": {"number": 1, "next_page_number": null, "previous_page_number": null, "paginator": {"num_pages": 2}, "has_next": '.($next ? 'true' : 'false').', "has_previous": false}}';
        $ours = '{"request_id": "'.self::REQUEST.'", "fqdn": "c0653765de3b.pr.edgegap.net", "ready": true, "start_time": "2026-04-22 12:00:46.444265", "public_ip": "192.53.120.48", "ports": {"gameport": {"name": "gameport", "link": "c0653765de3b.pr.edgegap.net:30345", "internal": 7777, "external": 30345, "protocol": "UDP", "tls_upgrade": false}}, "tags": ["op:id=omniplay-game-1", "op:svc=game", "op:cap=8", "op:reg=eu", "op:eng=godot", "op:tag=ranked", "op:tag=v1.4", "production"]}';
        $starting = '{"request_id": "b0b0b0b0b0b0", "fqdn": "b0b0b0b0b0b0.pr.edgegap.net", "ready": false, "start_time": "", "public_ip": "198.51.100.9", "tags": ["op:id=omniplay-game-2", "op:svc=game", "op:cap=8"]}';
        $foreign = '{"request_id": "c1c1c1c1c1c1", "fqdn": "x.pr.edgegap.net", "ready": true, "start_time": "", "public_ip": "198.51.100.10", "tags": ["production"]}';
        $hosting = self::hosting($recorder->client([
            ['~^GET .+/v1/deployments\?(?=.*page=1)~', 200, $page($ours.', '.$foreign, true)],
            ['~^GET .+/v1/deployments\?(?=.*page=2)~', 200, $page($starting, false)],
        ]));

        $servers = $hosting->running('game');

        self::assertSame([
            ['omniplay-game-1', 'game', '192.53.120.48:30345', 8, Health::Healthy, 'eu', ['ranked', 'v1.4'], 'godot', self::REQUEST],
            ['omniplay-game-2', 'game', '198.51.100.9', 8, Health::Starting, null, [], null, 'b0b0b0b0b0b0'],
        ], array_map(static fn (Server $s) => [$s->id, $s->service, $s->address, $s->capacity, $s->health, $s->region, $s->tags, $s->engine, $s->instance], $servers));
        self::assertSame(['GET '.self::API.'/v1/deployments', 'GET '.self::API.'/v1/deployments'], $recorder->calls());
        self::assertSame([
            ['page' => '1', 'limit' => '100', 'query' => '{"filters":[{"field":"application","operator":"eq","value":"my-game-server"}]}'],
            ['page' => '2', 'limit' => '100', 'query' => '{"filters":[{"field":"application","operator":"eq","value":"my-game-server"}]}'],
        ], array_column($recorder->requests, 'query'));

        $all = new Recorder();
        self::hosting($all->client([['~^GET~', 200, $page($foreign, false)]]))->running();
        self::assertSame([['page' => '1', 'limit' => '100']], array_column($all->requests, 'query'), 'no service named: no filter');
    }

    public function testWhatItDoesNotStartIsRefused(): void
    {
        $hosting = self::hosting((new Recorder())->client(self::api()), ['regions' => []]);

        self::assertSame(['contracts' => [], 'lookupBy' => [], 'prices' => false, 'player' => null, 'attribution' => null, 'terms' => [], 'keyless' => false, 'services' => ['game'], 'regions' => [], 'where' => 'Edgegap at https://api.edgegap.com'], $hosting->capabilities()->toArray(), 'no player, no region: it starts nothing yet');
        foreach ([
            [$hosting, new Service('relay'), null, 'Edgegap starts no server of the "relay" service.'],
            [$hosting, self::service(), null, 'Edgegap places a deployment of the "game" service near its players: give their addresses (near()) or configure a region\'s location.'],
            [self::hosting((new Recorder())->client(self::api())), self::service(), 'ap', 'Edgegap starts no server of the "game" service in the "ap" region.'],
        ] as [$provider, $service, $region, $message]) {
            try {
                $provider->start($service, self::SECRET, $region);
                self::fail($message);
            } catch (NotSupportedException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        try {
            $hosting->near(['203.0.113.7'])->start(self::service(), self::SECRET, null, ['a-tag-far-too-long-for-edgegap-to-take']);
            self::fail('Tagged.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Edgegap takes tags of 40 characters at most: "op:tag=a-tag-far-too-long-for-edgegap-to-take" has 45.', $e->getMessage());
        }
        foreach ([[], ['not-an-ip'], [[91, 0]]] as $players) {
            try {
                $hosting->near($players);
                self::fail((string) json_encode($players));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testWhatTheFactoryRefuses(): void
    {
        foreach ([
            [['services' => []], 'The "edgegap" provider needs: services.'],
            [['services' => ['game' => ['version' => 'v1']]], 'The "game" service of the "edgegap" provider names its application.'],
            [['services' => ['game' => ['application' => 'a', 'version' => 'v1', 'address' => 'localhost:{port}']]], 'The "game" service\'s address holds "{ip}" or "{fqdn}", not "localhost:{port}".'],
            [['services' => ['game' => ['application' => 'a', 'version' => 'v1', 'cpu_units' => 1024]]], 'The "game" service gives cpu_units and memory_mib together, or neither.'],
            [['services' => ['game' => ['application' => 'a', 'version' => 'v1']], 'regions' => ['eu' => 'paris']], 'The "eu" region is located by a public IP or by coordinates ([latitude, longitude]), not "paris". (the "edgegap" provider\'s regions)'],
            [['services' => ['game' => ['application' => 'a', 'version' => 'v1']], 'api' => 'ftp://example.org'], 'The "edgegap" provider\'s api is an http(s) address, not "ftp://example.org".'],
        ] as [$options, $message]) {
            try {
                (new EdgegapFactory((new Recorder())->client([])))->create($options);
                self::fail($message);
            } catch (InvalidConfigException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testTheTokenIsReadByItsReferenceAndNeverQuoted(): void
    {
        $http = (new Recorder())->client([['~~', 401, '{"message": "Invalid token not-a-real-token"}']]);
        try {
            self::hosting($http)->running();
            self::fail('Listed.');
        } catch (ProviderException $e) {
            self::assertSame('Edgegap answered 401 for GET /v1/deployments. {"message": "Invalid ***"}', $e->getMessage());
        }

        $hosting = (new EdgegapFactory($http, new FixedSecrets([])))->create(['token' => 'OTHER_TOKEN'] + self::options());
        self::assertInstanceOf(EdgegapHosting::class, $hosting);
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('No secret "OTHER_TOKEN".');
        $hosting->running();
    }

    public function testSeatingDeploysNearThePlayerWhenNoServerHasRoom(): void
    {
        $recorder = new Recorder();
        $registry = new Registry([new EdgegapFactory($recorder->client(self::api()), self::secrets(), static function (float $seconds): void {})], ['cloud' => ['factory' => 'edgegap', 'options' => self::options()]]);
        $edgegap = $registry->get('cloud');
        self::assertInstanceOf(EdgegapHosting::class, $edgegap);
        $fleet = new Fleet(new InMemoryStore());
        $seating = new Seating($fleet, new Tickets(self::secrets()), $edgegap->near(['203.0.113.7']), self::secrets());

        $seat = $seating->seat(self::service(), new SeatRequest('player-42'));

        self::assertNotNull($seat);
        self::assertSame(['192.53.120.48:30345', true, 1, self::REQUEST], [$seat->server->address, $seat->started, $fleet->get($seat->server->id)?->players, $seat->server->instance]);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<float>          $sleeps
     */
    private static function hosting(\Symfony\Contracts\HttpClient\HttpClientInterface $http, array $options = [], array &$sleeps = []): EdgegapHosting
    {
        $hosting = (new EdgegapFactory($http, self::secrets(), static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        }))->create($options + self::options());
        self::assertInstanceOf(EdgegapHosting::class, $hosting);

        return $hosting;
    }

    /** @return array<string, mixed> */
    private static function options(): array
    {
        return [
            'secret_env' => 'GAME_TICKET_SECRET',
            'heartbeat_url' => 'https://www.example.org/omniplay/heartbeat',
            'regions' => ['eu' => '198.51.100.20', 'na' => [45.508888, -73.561668]],
            'services' => ['game' => ['application' => 'my-game-server', 'version' => 'v1.4', 'internal_port' => 7777, 'env' => ['MAP' => 'harbour', 'GAME_TICKET_SECRET' => 'never this one'], 'cpu_units' => 1536, 'memory_mib' => 3072, 'tags' => ['production']]],
        ];
    }

    private static function secrets(): FixedSecrets
    {
        return new FixedSecrets(['EDGEGAP_API_TOKEN' => self::TOKEN, 'GAME_SECRET' => self::SECRET]);
    }

    private static function service(): Service
    {
        return new Service('game', secret: 'GAME_SECRET', capacity: 8, engine: 'godot');
    }

    /** A deployment's status, as GET /v1/status/{id} answers it (V1DeploymentStatus): placed or not yet. */
    private static function deploymentStatus(bool $placed): string
    {
        return '{"request_id": "'.self::REQUEST.'", "fqdn": "c0653765de3b.pr.edgegap.net", "public_ip": "'.($placed ? '192.53.120.48' : '').'", "app_name": "my-game-server", "app_version": "v1.4", "current_status": "'.($placed ? 'Status.READY' : 'Status.SEEKING').'", "running": '.($placed ? 'true' : 'false').', "start_time": "2026-04-22 12:00:46.444265", "elapsed_time": 0, "max_duration": 1440, "error": false'.($placed ? ', "ports": {"gameport": {"name": "gameport", "link": "c0653765de3b.pr.edgegap.net:30345", "internal": 7777, "external": 30345, "protocol": "UDP", "tls_upgrade": false}}' : '').', "tags": []}';
    }

    /** @return list<array{0: string, 1: int, 2: string|list<string>}> */
    private static function api(): array
    {
        return [
            ['~^POST https://api\.edgegap\.com/v2/deployments$~', 202, '{"request_id": "'.self::REQUEST.'"}'],
            ['~^GET https://api\.edgegap\.com/v1/status/'.self::REQUEST.'$~', 200, [self::deploymentStatus(false), self::deploymentStatus(true), self::deploymentStatus(false), self::deploymentStatus(true)]],
            ['~^DELETE https://api\.edgegap\.com/v1/stop/~', 200, '{"message": "Delete request will be processed soon"}'],
        ];
    }
}
