<p align="center">
  <img src="public/icons/hetzner-cloud-mcp-256.png" alt="Hetzner Cloud MCP" width="80" height="80">
</p>

# Hetzner Cloud MCP Server

**The only Hetzner MCP with SSH server management. API + SSH in one tool.**

[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-8892BF?logo=php&logoColor=white)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![MCP Protocol](https://img.shields.io/badge/MCP-2025--11--25-blue)](https://modelcontextprotocol.io)

Manage your Hetzner Cloud infrastructure from **Claude.ai, Claude Desktop, VS Code, Cursor**, or any MCP-compatible client. Two management layers give you complete control:

- **Layer 1 — Hetzner Cloud API:** Server power, metrics, snapshots, backups, firewalls, DNS zones and records, rescue mode, server rebuild and rescale. Works even when the server OS is unresponsive.
- **Layer 2 — SSH:** Services, logs, Nginx, MySQL, supervisor, cron, UFW, disk/memory/CPU monitoring. Real sysadmin tools, not just API wrappers.

60 tools. Dynamic multi-server configuration. Self-hosted and open source.

---

## Why This MCP?

Every existing Hetzner MCP only wraps the Cloud API. This one adds a full SSH management layer — the tools you actually need when managing production servers. Two layers, 60 tools, self-hosted.

| Feature | Included |
|---------|:--------:|
| **Cloud API** (server power, metrics, snapshots, backups, firewalls, rescue, rebuild) | Yes |
| **SSH Management** (services, logs, Nginx, MySQL, system health) | Yes |
| **DNS Management** (zones, records, CRUD) | Yes |
| **Multi-Server** (1 to N servers from a single instance) | Yes |
| **Destructive Guards** (confirm required for dangerous ops) | Yes |
| **Transport** | SSE + Streamable HTTP |
| **Language** | PHP 8.1+ |

---

## Quick Start

### Prerequisites

- PHP 8.1+ with `curl` extension
- Composer
- A Hetzner Cloud API token ([Console > Security > API Tokens](https://console.hetzner.com))
- An SSH key for server access (Layer 2 tools)

### 1. Clone and install

```bash
git clone https://github.com/wbf-solutions/hetzner-cloud-mcp.git
cd hetzner-cloud-mcp
composer install
```

### 2. Configure

```bash
cp .env.example .env
```

Edit `.env` with your details:

```env
HETZNER_API_TOKEN=your-cloud-api-token

SERVERS=web
SERVER_WEB_ID=12345678
SERVER_WEB_IP=1.2.3.4
SERVER_WEB_SSH_USER=root

SSH_KEY_PATH=/root/.ssh/id_ed25519

MCP_API_KEY=your-random-key    # generate with: openssl rand -hex 32
```

### 3. Set up the SSH key

```bash
ssh-keygen -t ed25519 -f /root/.ssh/id_ed25519 -N ""
ssh-copy-id -i /root/.ssh/id_ed25519.pub root@1.2.3.4
```

### 4. Configure Nginx

```nginx
server {
    listen 443 ssl;
    server_name mcp.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/mcp.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/mcp.yourdomain.com/privkey.pem;

    root /var/www/hetzner-cloud-mcp;
    index api.php;

    location / {
        try_files $uri /api.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffering off;
        fastcgi_read_timeout 600;
    }
}
```

### 5. Connect to Claude.ai

**Settings > Connectors > Add custom connector:**

- **Name:** Hetzner Cloud MCP
- **URL:** `https://mcp.yourdomain.com/api.php`

If you set `MCP_API_KEY`, pass it via the URL: `?mcp=sse&key=YOUR_MCP_API_KEY`
or configure the API key in the connector's Advanced Settings as a Bearer token.

---

## Available Tools (60)

### Layer 1 — Hetzner Cloud API (25 tools)

| Tool | Description | Destructive |
|------|-------------|:-----------:|
| `server_info` | Server details: status, IP, type, datacenter | |
| `server_metrics` | CPU, disk, or network metrics | |
| `server_power_on` | Power on | |
| `server_power_off` | Hard power off | Confirm |
| `server_shutdown` | Graceful ACPI shutdown | |
| `server_reboot` | Soft reboot | |
| `server_reset` | Hard reset | Confirm |
| `server_reset_password` | Reset root password | Confirm |
| `server_rescue_enable` | Enable rescue mode | |
| `server_rescue_disable` | Disable rescue mode | |
| `server_rebuild` | Rebuild from image (wipes data) | Confirm |
| `server_change_type` | Rescale server plan | Confirm |
| `snapshot_create` | Create snapshot | |
| `snapshot_list` | List snapshots | |
| `snapshot_delete` | Delete snapshot | Confirm |
| `backup_enable` | Enable backups (+20% cost) | |
| `backup_disable` | Disable backups | Confirm |
| `firewall_list` | List firewalls | |
| `firewall_get` | Get firewall rules | |
| `firewall_set_rules` | Replace all firewall rules | Confirm |
| `firewall_apply_to_server` | Apply firewall to server | |
| `firewall_remove_from_server` | Remove firewall from server | |
| `project_servers_list` | List all servers | |
| `ssh_keys_list` | List SSH keys | |
| `action_status` | Check async action status | |

### DNS (8 tools, requires `HETZNER_DNS_TOKEN`)

| Tool | Description | Destructive |
|------|-------------|:-----------:|
| `dns_zones_list` | List DNS zones | |
| `dns_zone_get` | Get zone details | |
| `dns_zone_create` | Create DNS zone | |
| `dns_zone_delete` | Delete DNS zone | Confirm |
| `dns_records_list` | List records in zone | |
| `dns_record_add` | Add DNS record | |
| `dns_record_update` | Update DNS record | |
| `dns_record_delete` | Delete DNS record | Confirm |

### Layer 2 — SSH (27 tools)

| Tool | Description |
|------|-------------|
| `ssh_service_status` | Check systemd service status |
| `ssh_service_start` | Start a service |
| `ssh_service_stop` | Stop a service |
| `ssh_service_restart` | Restart a service |
| `ssh_services_list` | List running services |
| `ssh_disk_usage` | Disk space (`df -h`) |
| `ssh_memory_usage` | RAM usage (`free -h`) |
| `ssh_cpu_load` | CPU load + top processes |
| `ssh_process_list` | Top processes by mem/CPU |
| `ssh_uptime` | Server uptime |
| `ssh_nginx_test` | Test Nginx config syntax |
| `ssh_nginx_reload` | Reload Nginx (tests first) |
| `ssh_nginx_sites_list` | List enabled sites |
| `ssh_nginx_site_config` | View site Nginx config |
| `ssh_logs_nginx_error` | Tail Nginx error log |
| `ssh_logs_nginx_access` | Tail Nginx access log |
| `ssh_logs_syslog` | Tail system log |
| `ssh_logs_journal` | View systemd journal |
| `ssh_logs_supervisor` | View supervisor logs |
| `ssh_mysql_databases` | List MySQL databases |
| `ssh_mysql_processlist` | Show MySQL processes |
| `ssh_mysql_query` | Read-only SQL query |
| `ssh_cron_list` | List crontab entries |
| `ssh_supervisor_status` | Supervisor program statuses |
| `ssh_supervisor_restart` | Restart supervisor program |
| `ssh_ufw_status` | Check UFW firewall |
| `ssh_exec` | Run command (dangerous cmds blocked) |

---

## Authentication

Choose the mode that fits your deployment:

| Mode | Config | Best for |
|------|--------|----------|
| **No auth** | `MCP_API_KEY=` (empty), no `OAUTH_*` | Behind VPN/firewall, local dev |
| **API key** | `MCP_API_KEY=your-key` | Self-hosted, single user/team |
| **API key + OAuth** | Set `MCP_API_KEY` + `OAUTH_*` vars | Multi-user, Connectors Directory |

### API Key (recommended for self-hosting)

Generate a key and set it in `.env`:

```bash
openssl rand -hex 32
```

Clients pass the key as `?key=XXX` or `Authorization: Bearer XXX`.

### OAuth 2.1 (optional)

For advanced deployments or [Anthropic Connectors Directory](https://claude.com/connectors) submission, you can add OAuth 2.1 token introspection alongside the static API key. This requires an external OAuth authorization server with an introspection endpoint (RFC 7662). See `.env.example` for the `OAUTH_*` variables.

---

## Security

- **Authentication:** API key via query param or `Authorization: Bearer` header. Optional OAuth 2.1 introspection. Timing-safe validation.
- **Destructive guards:** All dangerous operations require `confirm=true`.
- **Tool annotations:** All tools include `readOnlyHint` and `destructiveHint` per MCP spec.
- **SSH safety:** 29 blocked command patterns (rm -rf, dd, mkfs, curl|sh, passwd, fdisk, etc.).
- **Read-only SQL:** Only SELECT, SHOW, DESCRIBE, EXPLAIN allowed.
- **Rate limiting:** Per-IP with atomic `flock()`.

---

## Configuration

Define any number of servers in `.env`:

```env
SERVERS=web,staging
SERVER_WEB_ID=12345678
SERVER_WEB_IP=1.2.3.4
SERVER_WEB_SSH_USER=root
SERVER_WEB_ALIASES=production,prod
SERVER_STAGING_ID=87654321
SERVER_STAGING_IP=5.6.7.8
DEFAULT_SERVER=web
```

SSH and DNS are optional — tools are auto-disabled when not configured.

See `.env.example` for the full reference.

---

## Client Configuration

| Client | Connection |
|--------|-----------|
| **Claude.ai** | Settings > Connectors > Add custom connector with SSE URL |
| **Claude Desktop** | Add to `claude_desktop_config.json` |
| **Claude Code** | `claude mcp add --transport http hetzner URL --header "Authorization: Bearer KEY"` |
| **VS Code / Cursor** | VS Code extension — coming soon |

---

## Deployment

Works with [VitoDeploy](https://vitodeploy.com) or manual Nginx + PHP-FPM setup. Requires `fastcgi_buffering off` for SSE streaming. See the full deployment guide in the [Quick Start](#quick-start) section.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security vulnerabilities: labs@wbf.solutions.

## Links

- **Landing page:** [hetzner-cloud-mcp.wbf.tools](https://hetzner-cloud-mcp.wbf.tools)
- **Built by:** [WBF Solutions](https://wbf.solutions)
- **Contact:** labs@wbf.solutions

## License

MIT — [WBF Solutions](https://wbf.solutions) | labs@wbf.solutions
