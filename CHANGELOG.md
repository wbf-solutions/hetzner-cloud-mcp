# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-03-10

### Added

- **Layer 1: Hetzner Cloud API tools (25 tools)**
  - Server management: info, metrics, power on/off, shutdown, reboot, reset, rescue mode, rebuild, change type
  - Snapshot management: create, list, delete
  - Backup management: enable, disable
  - Firewall management: list, get, set rules, apply/remove from server
  - Project tools: server list, SSH keys list, action status

- **Layer 1: Hetzner DNS API tools (8 tools)**
  - Zone management: list, get, create, delete
  - Record management: list, add, update, delete
  - Separate DNS API client targeting dns.hetzner.com/api/v1/

- **Layer 2: SSH server management tools (27 tools)**
  - Service control: status, start, stop, restart, list running services
  - System monitoring: disk usage, memory, CPU load, process list, uptime
  - Nginx management: test config, reload, list sites, view site config
  - Log viewing: nginx error/access, syslog, systemd journal, supervisor logs
  - MySQL: list databases, show processlist, read-only queries
  - Misc: cron list, supervisor status/restart, UFW status, arbitrary command execution

- **Dynamic server configuration** via .env - supports 1 to N servers with custom names and aliases
- **Dual MCP transport** - SSE (Server-Sent Events) and Streamable HTTP
- **SSH optional mode** - SSH tools gracefully skipped when SSH_KEY_PATH is not configured
- **DNS optional mode** - DNS tools skipped when HETZNER_DNS_TOKEN is not set
- **Security features:**
  - Timing-safe API key authentication
  - Confirm guards on all destructive API actions
  - Read-only SQL enforcement (SELECT, SHOW, DESCRIBE, EXPLAIN only)
  - 29 blocked command patterns for ssh_exec
  - Input validation on all user-provided parameters
  - File-based rate limiting with atomic flock
