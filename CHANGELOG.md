# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release.

### Release

- Requires `omnisense/contracts: ^1.0`, `omnisense/omniplay: ^1.0`: stable versions, so `composer
  require omniplay/edgegap` installs in a project at Composer's default minimum-stability.
  `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's
  php-package.yml) is callable, and `release.yml` runs it on a tag before glitchr-studio/ci's
  php-release.yml.

### Added
- 2026-11-10: the usage policy declared as data, in `policy.json` (omnisense/contracts' schema, named in `composer.json`'s `extra.omnisense.policy`). The provider implements `DeclaresUsagePolicyInterface` and gives the policy to its Capabilities. The factory hands the provider's Backoff to its JsonClient, so that a 429 and its Retry-After hold every call. `Tests/PolicyTest.php`.
- `omniplay/edgegap`: omnisense/omniplay's `HostingInterface` over Edgegap's REST API.
  `EdgegapFactory` (factory `edgegap`), `EdgegapHosting`, `AppVersion` (a service's application,
  version, port name, address, environment, internal port, resources, cached locations, tags).
  A deployment placed near the players given (`near()`: public IPs or coordinates) or near a
  region's location (`POST /v2/deployments`), its status read until it has an address
  (`GET /v1/status/{request_id}`), stopped by its request id (`DELETE /v1/stop/{request_id}`),
  rebuilt from omniplay's tags (`GET /v1/deployments`). The server's ticket secret (hidden), id,
  room, service, capacity, port, engine and heartbeat route in its environment; the API token read
  by its reference at each call.
