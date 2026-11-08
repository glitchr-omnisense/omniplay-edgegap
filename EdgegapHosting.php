<?php

namespace Omniplay\Edgegap;

use Omniplay\Capabilities;
use Omniplay\Exception\InvalidArgumentException;
use Omniplay\Exception\NotSupportedException;
use Omniplay\Exception\OmniplayException;
use Omniplay\Exception\ProviderException;
use Omniplay\HostingInterface;
use Omniplay\Http\Data;
use Omniplay\Http\JsonClient;
use Omniplay\Model\Health;
use Omniplay\Model\Server;
use Omniplay\Model\Service;
use Omniplay\ProviderInterface;
use Omniplay\Secret\SecretsInterface;

/**
 * Game servers as Edgegap deployments, through its REST API: a server is a
 * deployment of its service's application version (POST /v2/deployments),
 * placed by Edgegap near the players it is given - their public IPs or
 * their coordinates (near()) - or near the location of the region asked;
 * its status is read (GET /v1/status/{request_id}) until Edgegap gives it
 * a host and an external port, and it is stopped by its request id
 * (DELETE /v1/stop/{request_id}).
 *
 * The deployment's environment holds its ticket secret (OMNIPLAY_TICKET_SECRET
 * unless configured otherwise, sent hidden: is_hidden), its id - which is
 * its tickets' room - (OMNIPLAY_SERVER_ID, OMNIPLAY_ROOM), its service,
 * capacity, declared engine, the application's heartbeat route and, when
 * configured, its port. The secret goes into the deployment and nowhere
 * else: not into the Server returned, not into its tags. The API token is
 * read by its reference at each call and sent as "Authorization: token ...".
 *
 * Its tags (op:id=, op:svc=, op:cap=, op:reg=, op:eng=, op:tag=; Edgegap
 * takes 40 characters at most) are what running() rebuilds the servers
 * from.
 */
final class EdgegapHosting implements ProviderInterface, HostingInterface
{
    public const TAG = 'op:';
    public const TAG_LENGTH = 40;

    /** @var list<array{user_type: string, user_data: array<string, string|float>}> the players a deployment is placed near (near()) */
    private array $players = [];

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param string                                                                       $token   the reference of the API token (an environment variable's name, a vault's key)
     * @param array<string, AppVersion>                                                     $apps    by service
     * @param array<string, array{user_type: string, user_data: array<string, string|float>}> $regions the location each region deploys near, by name
     * @param int                                                                          $wait    seconds a start waits for Edgegap to give the deployment its address
     * @param ?\Closure(float): void                                                       $sleep   how it waits between two reads of the status (usleep when null)
     */
    public function __construct(
        private readonly JsonClient $json,
        private readonly SecretsInterface $secrets,
        private readonly string $token,
        private readonly array $apps,
        private readonly array $regions = [],
        private readonly string $api = 'https://api.edgegap.com',
        private readonly string $secretEnv = 'OMNIPLAY_TICKET_SECRET',
        private readonly string $prefix = 'omniplay-',
        private readonly ?string $heartbeatUrl = null,
        private readonly int $wait = 30,
        private readonly float $interval = 1.0,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1e6));
        };
    }

    public function getName(): string
    {
        return 'edgegap';
    }

    public function getTitle(): string
    {
        return 'Edgegap';
    }

    /**
     * It starts servers when it knows where: near the players given
     * (near()), in any region asked, or near a configured region's location.
     */
    public function capabilities(): Capabilities
    {
        $near = [] !== $this->players;

        return new Capabilities(
            hosting: $near || [] !== $this->regions,
            services: array_keys($this->apps),
            regions: $near ? [] : array_keys($this->regions),
            where: 'Edgegap at '.$this->api.($near ? \sprintf(', near %d player%s', \count($this->players), 1 === \count($this->players) ? '' : 's') : ''),
        );
    }

    /**
     * The same provider, its deployments placed near these players: each a
     * public IP ("203.0.113.7") or coordinates ([45.5, -73.56], or
     * ['latitude' => 45.5, 'longitude' => -73.56]). What a seat's Seating is
     * given, with the players of the match.
     *
     * @param list<mixed> $players
     */
    public function near(array $players): self
    {
        if ([] === $players) {
            throw new InvalidArgumentException('Edgegap places a deployment near one player at least.');
        }
        $near = clone $this;
        $near->players = array_map(static fn (mixed $player) => self::location($player, 'A player'), $players);

        return $near;
    }

    public function start(Service $service, string $secret, ?string $region = null, array $tags = []): Server
    {
        $app = $this->apps[$service->name] ?? throw NotSupportedException::service('Edgegap', $service->name, $region);
        $users = $this->players;
        if ([] === $users) {
            $region ??= array_key_first($this->regions) ?? throw new NotSupportedException(\sprintf('Edgegap places a deployment of the "%s" service near its players: give their addresses (near()) or configure a region\'s location.', $service->name));
            $users = [$this->regions[$region] ?? throw NotSupportedException::service('Edgegap', $service->name, $region)];
        }
        $id = $this->id($service);
        $created = $this->call('POST', '/v2/deployments', ['json' => $this->deployment($id, $service, $app, $secret, $users, $this->tags($id, $service, $app, $region, $tags))]);
        $request = $created?->string('request_id') ?? throw new ProviderException(\sprintf('Edgegap answered no request id for a deployment of "%s" version "%s".', $app->application, $app->version));
        try {
            $status = $this->placed($request, $app);
        } catch (OmniplayException $e) {
            $this->stopDeployment($request);

            throw $e;
        }

        return new Server($id, $service->name, $this->address($app, $status), $service->capacity, 0, Health::Starting, null, $region, $tags, $service->engine, $this->getName(), $request);
    }

    public function stop(Server $server): void
    {
        $this->stopDeployment($server->instance ?? $server->id);
    }

    public function running(?string $service = null): array
    {
        $query = ['page' => 1, 'limit' => 100];
        $app = null !== $service ? $this->apps[$service] ?? null : null;
        if (null !== $app) {
            $query['query'] = (string) json_encode(['filters' => [['field' => 'application', 'operator' => 'eq', 'value' => $app->application]]]);
        }
        $servers = [];
        // At most 100 pages of 100: a fleet beyond that is not one to rebuild by listing.
        for ($page = 1; $page <= 100; ++$page) {
            $answer = $this->call('GET', '/v1/deployments', ['query' => ['page' => $page] + $query]);
            foreach ($answer?->list('data') ?? [] as $deployment) {
                $server = $this->server($deployment);
                if (null !== $server && (null === $service || $service === $server->service)) {
                    $servers[] = $server;
                }
            }
            if (true !== $answer?->bool('pagination.has_next')) {
                break;
            }
        }

        return $servers;
    }

    /**
     * The status, once Edgegap has given the deployment what its address
     * needs; read again every $interval seconds, $wait seconds at most.
     */
    private function placed(string $request, AppVersion $app): Data
    {
        $reads = max(1, (int) ceil($this->wait / max(0.001, $this->interval))) + 1;
        for ($read = 0; $read < $reads; ++$read) {
            if ($read > 0) {
                ($this->sleep)($this->interval);
            }
            $status = $this->call('GET', '/v1/status/'.rawurlencode($request));
            if (null === $status) {
                continue;
            }
            if (true === $status->bool('error')) {
                throw new ProviderException(\sprintf('Edgegap could not deploy %s (%s version %s): %s', $request, $app->application, $app->version, $status->string('error_detail') ?? $status->string('current_status') ?? 'no detail given.'));
            }
            if ($this->addressable($app, $status)) {
                return $status;
            }
        }

        throw new ProviderException(\sprintf('Edgegap gave the deployment %s no address within %d seconds: it is stopped.', $request, $this->wait));
    }

    private function stopDeployment(string $request): void
    {
        try {
            // 200 and 202 stopping, 404 unknown: each is fine.
            $this->call('DELETE', '/v1/stop/'.rawurlencode($request));
        } catch (ProviderException $e) {
            if (410 !== $e->getCode()) {
                throw $e;
            }
            // 410: terminated already.
        }
    }

    /**
     * The body of POST /v2/deployments.
     *
     * @param list<array{user_type: string, user_data: array<string, string|float>}> $users
     * @param list<string>                                                           $tags
     *
     * @return array<string, mixed>
     */
    private function deployment(string $id, Service $service, AppVersion $app, string $secret, array $users, array $tags): array
    {
        $env = [
            'OMNIPLAY_SERVER_ID' => $id,
            'OMNIPLAY_ROOM' => $id,
            'OMNIPLAY_SERVICE' => $service->name,
            'OMNIPLAY_CAPACITY' => (string) $service->capacity,
        ] + (null !== $app->internalPort ? ['OMNIPLAY_PORT' => (string) $app->internalPort] : []) + [
            $this->secretEnv => $secret,
        ] + (null !== $service->engine ? ['OMNIPLAY_ENGINE' => $service->engine] : []) + (null !== $this->heartbeatUrl ? ['OMNIPLAY_HEARTBEAT_URL' => $this->heartbeatUrl] : []) + $app->env;

        return array_filter([
            'application' => $app->application,
            'version' => $app->version,
            'users' => $users,
            'environment_variables' => array_map(fn (string $key, string $value) => ['key' => $key, 'value' => $value, 'is_hidden' => $key === $this->secretEnv], array_keys($env), $env),
            'tags' => $tags,
            'resources' => null !== $app->cpuUnits && null !== $app->memoryMib ? ['cpu_units' => $app->cpuUnits, 'memory_mib' => $app->memoryMib] : null,
            'require_cached_locations' => $app->requireCachedLocations ?: null,
        ], static fn (mixed $value) => null !== $value);
    }

    /**
     * omniplay's tags, then the version's own; each of 40 characters at most.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function tags(string $id, Service $service, AppVersion $app, ?string $region, array $tags): array
    {
        $all = [self::TAG.'id='.$id, self::TAG.'svc='.$service->name, self::TAG.'cap='.$service->capacity];
        if (null !== $region) {
            $all[] = self::TAG.'reg='.$region;
        }
        if (null !== $service->engine) {
            $all[] = self::TAG.'eng='.$service->engine;
        }
        foreach ($tags as $tag) {
            $all[] = self::TAG.'tag='.$tag;
        }
        foreach ($all = array_values(array_unique([...$all, ...$app->tags])) as $tag) {
            if (mb_strlen($tag) > self::TAG_LENGTH) {
                throw new InvalidArgumentException(\sprintf('Edgegap takes tags of %d characters at most: "%s" has %d.', self::TAG_LENGTH, $tag, mb_strlen($tag)));
            }
        }

        return $all;
    }

    /** The server's id: the prefix, the service (cut so that its tag holds in 40 characters), a dash and 8 hex digits. */
    private function id(Service $service): string
    {
        $room = self::TAG_LENGTH - \strlen(self::TAG.'id=') - \strlen($this->prefix) - 9;
        $name = substr(trim((string) preg_replace('~[^a-zA-Z0-9_.-]+~', '-', $service->name), '-'), 0, max(1, $room));

        return $this->prefix.rtrim($name, '-').'-'.bin2hex(random_bytes(4));
    }

    /** Whether the status holds what the version's address needs. */
    private function addressable(AppVersion $app, Data $status): bool
    {
        return null !== $status->data('ports')->data($app->port)->int('external')
            && (!str_contains($app->address, '{ip}') || null !== $status->string('public_ip'))
            && (!str_contains($app->address, '{fqdn}') || null !== $status->string('fqdn'));
    }

    private function address(AppVersion $app, Data $status): string
    {
        return strtr($app->address, ['{ip}' => (string) $status->string('public_ip'), '{fqdn}' => (string) $status->string('fqdn'), '{port}' => (string) $status->data('ports')->data($app->port)->int('external')]);
    }

    /** A deployment of the list GET /v1/deployments answers, from its tags; null when they do not make one. */
    private function server(Data $deployment): ?Server
    {
        $tags = [];
        $own = [];
        foreach ($deployment->strings('tags') as $tag) {
            if (1 === preg_match('~^'.preg_quote(self::TAG, '~').'(id|svc|cap|reg|eng|tag)=(.+)$~', $tag, $match)) {
                if ('tag' === $match[1]) {
                    $own[] = $match[2];
                } else {
                    $tags[$match[1]] = $match[2];
                }
            }
        }
        $id = $tags['id'] ?? null;
        $service = $tags['svc'] ?? null;
        $request = $deployment->string('request_id');
        if (null === $id || null === $service || null === $request) {
            return null;
        }
        $app = $this->apps[$service] ?? null;
        $address = null !== $app && $this->addressable($app, $deployment) ? $this->address($app, $deployment) : null;
        if (null === $address) {
            $port = $deployment->data('ports')->data($app->port ?? 'gameport')->int('external');
            $host = $deployment->string('public_ip') ?? $deployment->string('fqdn');
            if (null === $host) {
                return null;
            }
            $address = $host.(null !== $port ? ':'.$port : '');
        }

        return new Server(
            $id,
            $service,
            $address,
            max(1, is_numeric($tags['cap'] ?? null) ? (int) $tags['cap'] : 1),
            0,
            true === $deployment->bool('ready') ? Health::Healthy : Health::Starting,
            null,
            $tags['reg'] ?? null,
            $own,
            $tags['eng'] ?? null,
            $this->getName(),
            $request,
        );
    }

    /**
     * A location of Edgegap's "users": a public IP, or coordinates.
     *
     * @return array{user_type: string, user_data: array<string, string|float>}
     */
    public static function location(mixed $location, string $what): array
    {
        if (\is_string($location) && false !== filter_var(trim($location), \FILTER_VALIDATE_IP)) {
            return ['user_type' => 'ip_address', 'user_data' => ['ip_address' => trim($location)]];
        }
        if (\is_array($location)) {
            $latitude = $location['latitude'] ?? $location[0] ?? null;
            $longitude = $location['longitude'] ?? $location[1] ?? null;
            if (is_numeric($latitude) && is_numeric($longitude) && abs((float) $latitude) <= 90 && abs((float) $longitude) <= 180) {
                return ['user_type' => 'geo_coordinates', 'user_data' => ['latitude' => (float) $latitude, 'longitude' => (float) $longitude]];
            }
        }

        throw new InvalidArgumentException(\sprintf('%s is located by a public IP or by coordinates ([latitude, longitude]), not %s.', $what, \is_scalar($location) ? '"'.$location.'"' : get_debug_type($location)));
    }

    /** @param array<string, mixed> $options */
    private function call(string $method, string $path, array $options = []): ?Data
    {
        return $this->json->request($method, $this->api.$path, $options, ['Authorization' => 'token '.$this->secrets->secret($this->token), 'Content-Type' => 'application/json']);
    }
}
