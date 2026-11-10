<?php

namespace Omniplay\Edgegap;

use Omniplay\Config;
use Omniplay\Exception\InvalidArgumentException;
use Omniplay\Exception\InvalidConfigException;
use Omniplay\Http\JsonClient;
use Omniplay\ProviderFactory;
use Omniplay\ProviderInterface;
use Omniplay\Secret\EnvironmentSecrets;
use Omniplay\Secret\SecretsInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "edgegap": options services (by service name: application, version,
 * port, address, env, internal_port, cpu_units, memory_mib,
 * require_cached_locations, tags - see AppVersion), token (the reference of
 * the API token: "EDGEGAP_API_TOKEN", read through the secrets given, the
 * environment by default), regions (by name, the location a deployment of
 * that region is placed near: a public IP, or [latitude, longitude]), api
 * ("https://api.edgegap.com"), secret_env ("OMNIPLAY_TICKET_SECRET"),
 * name_prefix ("omniplay-"), heartbeat_url (the application's heartbeat
 * route, handed to each server as OMNIPLAY_HEARTBEAT_URL), wait (30 seconds
 * for a deployment's address), poll_interval (1 second), user_agent.
 */
final class EdgegapFactory extends ProviderFactory
{
    /** @param ?\Closure(float): void $sleep how a start waits between two reads of the status (usleep when null) */
    public function __construct(private readonly ?HttpClientInterface $http = null, private readonly ?SecretsInterface $secrets = null, private readonly ?\Closure $sleep = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omniplay.factory_name' => 'edgegap',
            'omniplay.factory_title' => 'Edgegap',
            'omniplay.required_options' => ['services', 'token'],
            'services' => [],
            'token' => 'EDGEGAP_API_TOKEN',
            'regions' => [],
            'api' => 'https://api.edgegap.com',
            'secret_env' => 'OMNIPLAY_TICKET_SECRET',
            'name_prefix' => 'omniplay-',
            'heartbeat_url' => null,
            'wait' => 30,
            'poll_interval' => 1,
            'user_agent' => 'omniplay/1.0 (+https://github.com/glitchr-omnisense/omniplay)',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $apps = [];
        $services = $c->get('services');
        foreach (\is_array($services) ? $services : [] as $name => $values) {
            $apps[(string) $name] = AppVersion::fromArray((string) $name, \is_array($values) ? $values : []);
        }
        $regions = [];
        $given = $c->get('regions');
        foreach (\is_array($given) ? $given : [] as $name => $location) {
            try {
                $regions[(string) $name] = EdgegapHosting::location($location, \sprintf('The "%s" region', $name));
            } catch (InvalidArgumentException $e) {
                throw new InvalidConfigException($e->getMessage().' (the "edgegap" provider\'s regions)');
            }
        }
        $api = rtrim((string) $c->string('api'), '/');
        if (1 !== preg_match('~^https?://[^/\s]+(/\S*)?$~', $api)) {
            throw new InvalidConfigException(\sprintf('The "edgegap" provider\'s api is an http(s) address, not "%s".', $api));
        }

        return new EdgegapHosting(
            new JsonClient($this->http ?? HttpClient::create(['timeout' => 30]), 'Edgegap', ['Accept' => 'application/json', 'User-Agent' => (string) $c->string('user_agent')], $c->backoff()),
            $this->secrets ?? new EnvironmentSecrets(),
            (string) $c->string('token'),
            $apps,
            $regions,
            $api,
            (string) $c->string('secret_env'),
            (string) $c->string('name_prefix'),
            $c->string('heartbeat_url'),
            max(0, $c->int('wait', 30)),
            max(0.001, $c->float('poll_interval', 1.0)),
            $this->sleep,
        );
    }
}
