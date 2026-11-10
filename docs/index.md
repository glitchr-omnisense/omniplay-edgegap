---
title: omniplay/edgegap
order: 10
---

# omniplay/edgegap

Game servers as Edgegap deployments. The provider implements omnisense/omniplay's
`HostingInterface`: `start()`, `stop()`, `running()`.

## The API

| Call | Edgegap | Reference |
|---|---|---|
| `start()` | `POST https://api.edgegap.com/v2/deployments` (202, `request_id`), then `GET /v1/status/{request_id}` until placed | [v2 Deploy](https://docs.edgegap.com/docs/api/dedicated-servers), [Get Deployment](https://docs.edgegap.com/docs/api) |
| `stop()` | `DELETE /v1/stop/{request_id}` | Stop Deployment |
| `running()` | `GET /v1/deployments?page=&limit=100&query={"filters":[{"field":"application","operator":"eq","value":...}]}` | List Deployments, [filtering](https://docs.edgegap.com/learn/orchestration/deployments) |

Every call carries `Authorization: token <token>`, `Accept` and `Content-Type: application/json`.
The specifications: [v2](https://raw.githubusercontent.com/edgegap/openapi-specification/refs/heads/main/edgegap-v2-openapi.yaml)
(2026.08.05) and [v1](https://raw.githubusercontent.com/edgegap/openapi-specification/refs/heads/main/edgegap-v1-openapi.yaml).

## Where a deployment goes

Edgegap places a deployment near its `users`: each a public IP (`ip_address`) or coordinates
(`geo_coordinates`: latitude, longitude).

- `$edgegap->near(['203.0.113.7', [45.5, -73.56]])`: the same provider, its deployments placed
  near these players - what a match's `Seating` is given. Its capabilities name no region: any
  asked (the players decide); the region asked is kept on the server.
- Without players: near the location of the region asked, from the `regions` option (`'eu' =>
  [48.8566, 2.3522]`, `'na' => '198.51.100.20'`); no region asked, the first configured.
- Neither: the provider starts nothing (`capabilities()->hosting` is false), and `start()` says so.

## A service's version

`services` maps a `Service`'s name to its Edgegap application and version:

| Key | Default | |
|---|---|---|
| `application`, `version` | required | made in Edgegap's dashboard |
| `port` | `gameport` | the name of the version's port players connect to |
| `address` | `{ip}:{port}` | what players are given: `{ip}` the host's public IP, `{fqdn}` the deployment's name, `{port}` the external port (`wss://{fqdn}:{port}/play`) |
| `env` | `[]` | the server's own variables, set after omniplay's and never replacing them |
| `internal_port` | none | handed as `OMNIPLAY_PORT`; Edgegap hands `ARBITRIUM_PORT_<NAME>_INTERNAL` in any case |
| `cpu_units`, `memory_mib` | the version's | together, or neither (1024 units: one vCPU) |
| `require_cached_locations` | false | only locations with the image cached |
| `tags` | `[]` | the deployment's tags besides omniplay's |

## The other options

| Option | Default | |
|---|---|---|
| `token` | `EDGEGAP_API_TOKEN` | the API token's reference, read through the factory's `SecretsInterface` (the environment by default) |
| `regions` | `[]` | by name, a public IP or `[latitude, longitude]` |
| `api` | `https://api.edgegap.com` | |
| `secret_env` | `OMNIPLAY_TICKET_SECRET` | the variable the ticket secret is handed in, `is_hidden` |
| `name_prefix` | `omniplay-` | a server's id is the prefix, the service (cut to fit a tag), a dash and 8 hex digits |
| `heartbeat_url` | none | handed to each server as `OMNIPLAY_HEARTBEAT_URL` |
| `wait`, `poll_interval` | 30, 1 | seconds `start()` waits for an address, and between two reads |
| `user_agent` | `omniplay/1.0 (+...)` | |

## The tags

`op:id=<server id>`, `op:svc=<service>`, `op:cap=<capacity>`, `op:reg=<region>`,
`op:eng=<engine>`, `op:tag=<tag>` (one per tag asked), then the version's own: `running()` reads
them back, a ready deployment as Healthy, another as Starting, its players unknown (0) until its
heartbeat. Edgegap takes 40 characters at most a tag: a longer one is an
`InvalidArgumentException` before anything is sent.

## Errors

- `NotSupportedException`: a service without a version, a region not configured, no player and no
  region.
- `ProviderException`: `Edgegap could not deploy <request_id> (<application> version <version>):
  <error_detail>`, `Edgegap gave the deployment <request_id> no address within 30 seconds: it is
  stopped.`, any error Edgegap answers (`Edgegap answered 422 for POST /v2/deployments. ...`), the
  token masked.
- `UnreachableException`: Edgegap could not be reached.
- `InvalidConfigException`: no `services`, a version without its application or version, an
  address without `{ip}` or `{fqdn}`, `cpu_units` without `memory_mib`, a region that is no IP and
  no coordinates, the token's secret not found.

## Tests

`Tests/EdgegapHostingTest.php` on Symfony's `MockHttpClient`, every request checked (method, URL,
query, headers, body); `Tests/LiveTest.php` (group `live`) against Edgegap, never run (no account):

```sh
cd ../core/docker   # omnisense/omniplay's harness, OMNIPLAY_PLUGINS=../.. in its .env
docker compose run --rm omniplay test
EDGEGAP_API_TOKEN=... EDGEGAP_APPLICATION=... EDGEGAP_VERSION=... docker compose run --rm -e EDGEGAP_API_TOKEN -e EDGEGAP_APPLICATION -e EDGEGAP_VERSION omniplay live --testsuite edgegap
```

## Usage policy

The package declares its usage policy as data, in [policy.json](../policy.json) (omnisense/contracts' schema). Edgegap's API docs (read on 2026-11-10) allow 40 deployment requests a second per organization: the operation `start`, which omniplay's registry counts. Status reads (20 a second) happen inside a start and are in the notes; `stop` and `running` fall in neither limit. Commercial use is under contract (a paid service under its terms and conditions). A 429 and its `Retry-After` hold every call until the wait is over. A site may lower the limit, never raise it. `EdgegapHosting::usagePolicy()` reads it, and its Capabilities carry it (`policy()`).
