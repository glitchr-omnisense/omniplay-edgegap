# omniplay/edgegap

[omnisense/omniplay](https://github.com/glitchr-omnisense/omniplay)'s Edgegap hosting, under any PHP
host: a game server is an Edgegap deployment of an application version, placed by Edgegap near the
players it is given, started, read and stopped through Edgegap's REST API.

- **start**: `POST https://api.edgegap.com/v2/deployments` with the service's application and
  version, the players (`users`: their public IPs, or their coordinates), the server's environment
  and omniplay's tags; then `GET /v1/status/{request_id}` every second until Edgegap gives the
  deployment its host and the external port of the version's port (`gameport`), 30 seconds at
  most. The server returned Starting at `{ip}:{port}` (or the version's `address`). A deployment
  in error, or given no address in time, is stopped and said.
- **stop**: `DELETE /v1/stop/{request_id}` (a graceful SIGTERM); one unknown (404) or terminated
  already (410) is fine.
- **running**: `GET /v1/deployments`, page by page, filtered on the service's application; the
  servers rebuilt from omniplay's tags.

```php
use Omniplay\Edgegap\EdgegapFactory;
use Omniplay\Model\{SeatRequest, Service};
use Omniplay\Seating;

$edgegap = (new EdgegapFactory())->create([
    'token' => 'EDGEGAP_API_TOKEN',            // the token's reference: an environment variable's name
    'heartbeat_url' => 'https://www.example.org/omniplay/heartbeat',
    'regions' => ['eu' => [48.8566, 2.3522], 'na' => '198.51.100.20'],
    'services' => ['game' => ['application' => 'my-game-server', 'version' => 'v1.4', 'internal_port' => 7777]],
]);

// Near the players of the match: their public IPs (the request's), or their coordinates.
$seating = new Seating($fleet, $tickets, $edgegap->near([$request->getClientIp()]), $secrets);
$seat = $seating->seat(new Service('game', secret: 'GAME_TICKET_SECRET', engine: 'unity-mirror'), new SeatRequest('player-42'));

// Or near a region's configured location: start($service, $secret, 'eu').
```

`near()` gives the same provider bound to these players; without players, a deployment is placed
near the location of the region asked (or of the first region configured). With neither, the
provider starts nothing and says so in its capabilities.

The server's environment (Edgegap's `environment_variables`): its ticket secret
(`OMNIPLAY_TICKET_SECRET`, or `secret_env`, sent with `is_hidden`), `OMNIPLAY_SERVER_ID` and
`OMNIPLAY_ROOM` (its id, the room its tickets name), `OMNIPLAY_SERVICE`, `OMNIPLAY_CAPACITY`,
`OMNIPLAY_PORT` (when `internal_port` is configured; Edgegap also hands
`ARBITRIUM_PORT_GAMEPORT_INTERNAL`), `OMNIPLAY_ENGINE` (when the service declares one),
`OMNIPLAY_HEARTBEAT_URL` (when configured), then the version's `env` (which cannot replace
omniplay's). The secret is in the deployment only: not in the `Server` returned, not in its tags.

The API token is read by its reference (`token`, `EDGEGAP_API_TOKEN` by default) through the
`SecretsInterface` given to the factory - the environment by default - at each call, and sent as
`Authorization: token <token>`. It is in no option, no exception, nothing returned.

Options: `services` (required; see [docs/index.md](docs/index.md)), `token`, `regions`, `api`,
`secret_env`, `name_prefix`, `heartbeat_url`, `wait`, `poll_interval`, `user_agent`. The
application and its version are made in Edgegap's dashboard first (the image, its ports, its
resources).

## Install

```sh
composer require omniplay/edgegap
```

It requires `omnisense/omniplay` `^1.0`, installed with it. A real call needs: **an Edgegap
account** (its API token). With no account, its tests run on recorded answers (a `MockHttpClient`),
and [omnisense/omniplay](https://github.com/glitchr-omnisense/omniplay#install-and-a-first-call)'s
README has a first call that runs without one.

## Verified, and not

- Verified (omnisense/omniplay's harness, PHP 8.4, 2026-11-08): the deployment's request field by
  field, the status read until placed, a deployment in error or given no address stopped, stop
  (200, 202, 404, 410, and a 403 said), running page by page, the token masked in an error, the
  factory's refusals, seating through the core starting a deployment near the player - on
  Symfony's `MockHttpClient`, against answers written after Edgegap's OpenAPI specifications
  (`edgegap-v2-openapi.yaml` 2026.08.05 for the deployment, `edgegap-v1-openapi.yaml` for status,
  list and stop); `BareTest`; PHPStan max.
- Not verified: anything live - there is no Edgegap account. `Tests/LiveTest.php` (group `live`)
  deploys, lists and stops a real deployment when `EDGEGAP_API_TOKEN`, `EDGEGAP_APPLICATION` and
  `EDGEGAP_VERSION` are set; it has never run. Whether Edgegap takes `:` and `=` in a tag (its
  specification gives 40 characters at most and no character set).

## Responsibility

An independent client of Edgegap's API, affiliated with Edgegap in no way. The MIT licence covers
this code only. Whoever runs it is responsible for the deployments it starts - Edgegap bills them -
and the secrets it hands them. The authors publish it free of charge and accept no liability for
its use.

## Usage policy

The package declares its usage policy as data, in [policy.json](policy.json) (omnisense/contracts' schema). Edgegap's API docs (read on 2026-11-10) allow 40 deployment requests a second per organization: the operation `start`, which omniplay's registry counts. Status reads (20 a second) happen inside a start and are in the notes; `stop` and `running` fall in neither limit. Commercial use is under contract (a paid service under its terms and conditions). A 429 and its `Retry-After` hold every call until the wait is over. A site may lower the limit, never raise it.

## Licence

MIT.
