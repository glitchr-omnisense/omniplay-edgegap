<?php

namespace Omniplay\Edgegap;

use Omniplay\Exception\InvalidConfigException;

/**
 * What a server of one service runs on Edgegap, as the configuration gives
 * it: the application and its version (made in Edgegap's dashboard: the
 * image, its ports, its resources), the name of the version's port players
 * connect to ("gameport"), the address they are given ("{ip}" the host's
 * public IP, "{fqdn}" the deployment's name, "{port}" the external port
 * Edgegap assigned), the server's own environment and tags.
 */
final readonly class AppVersion
{
    /**
     * @param array<string, string> $env          the server's own variables (omniplay's are set after them)
     * @param ?int                  $internalPort the port the server listens on, handed as OMNIPLAY_PORT; not handed when null (Edgegap hands ARBITRIUM_PORT_<NAME>_INTERNAL)
     * @param ?int                  $cpuUnits     overrides the version's (1024 units: one vCPU), with $memoryMib
     * @param list<string>          $tags         the deployment's tags besides omniplay's
     */
    public function __construct(
        public string $application,
        public string $version,
        public string $port = 'gameport',
        public string $address = '{ip}:{port}',
        public array $env = [],
        public ?int $internalPort = null,
        public ?int $cpuUnits = null,
        public ?int $memoryMib = null,
        public bool $requireCachedLocations = false,
        public array $tags = [],
    ) {
    }

    /**
     * A service's entry of the "services" option.
     *
     * @param array<mixed> $values
     */
    public static function fromArray(string $service, array $values): self
    {
        $string = static fn (string $key): string => \is_scalar($values[$key] ?? null) ? trim((string) $values[$key]) : '';
        $int = static fn (string $key): ?int => is_numeric($values[$key] ?? null) ? (int) $values[$key] : null;
        foreach (['application', 'version'] as $key) {
            if ('' === $string($key)) {
                throw new InvalidConfigException(\sprintf('The "%s" service of the "edgegap" provider names its %s.', $service, $key));
            }
        }
        $address = '' !== $string('address') ? $string('address') : '{ip}:{port}';
        if (!str_contains($address, '{ip}') && !str_contains($address, '{fqdn}')) {
            throw new InvalidConfigException(\sprintf('The "%s" service\'s address holds "{ip}" or "{fqdn}", not "%s".', $service, $address));
        }
        [$cpu, $memory] = [$int('cpu_units'), $int('memory_mib')];
        if ((null === $cpu) !== (null === $memory)) {
            throw new InvalidConfigException(\sprintf('The "%s" service gives cpu_units and memory_mib together, or neither.', $service));
        }
        $env = [];
        foreach (\is_array($values['env'] ?? null) ? $values['env'] : [] as $key => $value) {
            if (\is_scalar($value)) {
                $env[(string) $key] = \is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }
        $tags = \is_array($values['tags'] ?? null) ? array_values(array_map('strval', array_filter($values['tags'], 'is_scalar'))) : [];

        return new self(
            $string('application'),
            $string('version'),
            '' !== $string('port') ? $string('port') : 'gameport',
            $address,
            $env,
            $int('internal_port'),
            $cpu,
            $memory,
            true === ($values['require_cached_locations'] ?? false),
            $tags,
        );
    }
}
