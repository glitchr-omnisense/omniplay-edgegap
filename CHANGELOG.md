# Changelog

## Unreleased (1.x)

### Added
- `omniplay/edgegap`: omnisense/omniplay's `HostingInterface` over Edgegap's REST API.
  `EdgegapFactory` (factory `edgegap`), `EdgegapHosting`, `AppVersion` (a service's application,
  version, port name, address, environment, internal port, resources, cached locations, tags).
  A deployment placed near the players given (`near()`: public IPs or coordinates) or near a
  region's location (`POST /v2/deployments`), its status read until it has an address
  (`GET /v1/status/{request_id}`), stopped by its request id (`DELETE /v1/stop/{request_id}`),
  rebuilt from omniplay's tags (`GET /v1/deployments`). The server's ticket secret (hidden), id,
  room, service, capacity, port, engine and heartbeat route in its environment; the API token read
  by its reference at each call.
