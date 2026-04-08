# Contributing to Hetzner Cloud MCP Server

Thank you for your interest in contributing! This guide will help you get started.

## Getting Started

1. **Fork** the repository on GitHub
2. **Clone** your fork locally:
   ```bash
   git clone https://github.com/YOUR_USERNAME/hetzner-cloud-mcp.git
   cd hetzner-cloud-mcp
   ```
3. **Install dependencies:**
   ```bash
   composer install
   ```
4. **Copy and configure** the environment file:
   ```bash
   cp .env.example .env
   # Edit .env with your Hetzner API token and server details
   ```

## Development Workflow

1. Create a feature branch from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```
2. Make your changes
3. Test locally (see Testing below)
4. Commit with a clear message:
   ```bash
   git commit -m "Add: brief description of your change"
   ```
5. Push to your fork and open a Pull Request

## Code Style

- **PSR-12** coding standard
- **PHP 8.1+** features: constructor promotion, match expressions, named arguments
- **Strict types** on all files: `declare(strict_types=1);`
- **DocBlocks** on all public methods
- **No hardcoded values** - everything configurable via `.env`

## Adding New Tools

New tools follow the provider pattern. To add a tool:

1. **Choose the right provider** (or create a new one in `src/Tools/`)
2. **Add a tool entry** in the provider's `getTools()` method:
   ```php
   [
       'name' => 'your_tool_name',
       'description' => 'Clear description of what this tool does.',
       'inputSchema' => [
           'type' => 'object',
           'properties' => [
               'param' => ['type' => 'string', 'description' => 'What this param does'],
               'server' => self::SERVER_PROP,
           ],
           'required' => ['param'],
       ],
       'handler' => fn(array $args) => $this->yourMethod($args),
   ]
   ```
3. **Implement the handler** method
4. **Add input validation** for all user-provided parameters
5. **Add a confirm guard** if the tool is destructive:
   ```php
   if (empty($args['confirm']) || $args['confirm'] !== true) {
       throw new \InvalidArgumentException('This destructive action requires confirm=true');
   }
   ```
6. **Register the provider** in `ToolRegistry.php` if it's a new class

## Security Guidelines

Security is critical for a server management tool. When contributing:

- **Never hardcode** tokens, keys, passwords, or IP addresses
- **Validate all input** with strict regex patterns before passing to SSH or API calls
- **Use `escapeshellarg()`** for any value interpolated into shell commands
- **Add to the blocked commands list** in `SshMiscTools.php` if you identify dangerous patterns
- **Use confirm guards** (`confirm=true`) on any tool that modifies or deletes data
- **Keep SQL read-only** - do not add write capabilities to database tools
- **Report security vulnerabilities** privately to labs@wbf.solutions (do not open a public issue)

## Testing

Before submitting a PR, verify:

1. **PHP syntax is clean:**
   ```bash
   find src/ -name "*.php" -exec php -l {} \;
   ```
2. **All tools register correctly** - connect to the MCP and call `tools/list`
3. **SSH optional mode works** - remove `SSH_KEY_PATH` from `.env` and verify only API tools register
4. **DNS optional mode works** - remove `HETZNER_DNS_TOKEN` from `.env` and verify DNS tools are skipped

## Commit Message Format

Use clear, descriptive commit messages:

- `Add: new tool for X` - new features
- `Fix: correct Y behavior` - bug fixes
- `Docs: update Z documentation` - documentation changes
- `Security: block dangerous pattern` - security improvements
- `Refactor: simplify X logic` - code improvements without behavior change

## Reporting Issues

- Use GitHub Issues for bug reports and feature requests
- Include your PHP version, OS, and relevant `.env` configuration (without secrets)
- For security vulnerabilities, email labs@wbf.solutions directly

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
