# Acquia Purge Varnish

Clear Varnish cache on Acquia Cloud Platform directly from your Drupal site.

## Features

-  **Manual Varnish purge** via web UI or Drush commands
-  **Acquia Cloud API v2** integration (recommended by Acquia)
-  **Guzzle HTTP client** for secure API communication
-  **CSRF protection** for all operations
-  **OAuth2 authentication** for API requests
-  **Drush 13 commands** for CLI operations
-  **Environment-aware** - automatically detects current Acquia environment
-  **Flexible domain purging** - purge current domain or all domains
-  **Settings.php override** for credentials management
-  **Permission-based access control**

## Requirements

- **Drupal:** 10.x or 11.x
- **Environment:** Acquia Cloud Platform (dev, stage, or prod)
- **Credentials:** Acquia Cloud API Key and API Secret with appropriate permissions
- **Drush:** 13.x (optional, for CLI commands)

## Installation

Install via Composer (recommended):

```bash
composer require drupal/acquia_purge_varnish
drush en acquia_purge_varnish -y
```

Or install manually:
1. Download and extract to `modules/contrib/acquia_purge_varnish`
2. Enable the module: `drush en acquia_purge_varnish -y`

## Configuration

### Method 1: Web UI

Navigate to: **Configuration → Acquia Purge Varnish → Settings**

`/admin/config/acquia-purge/settings`

Configure:
1. **API Key** - Your Acquia Cloud Platform API Key
2. **API Secret** - Your Acquia Cloud Platform API Secret
3. **Application Name** - (Optional) Leave empty to auto-detect from `AH_SITE_GROUP`
4. **Domain Setting** - Choose to purge all domains or current domain only
5. **Log Requests** - Enable to log API requests for debugging

### Method 2: Drush Commands

```bash
drush apv-config --api-key="YOUR_KEY" --api-secret="YOUR_SECRET"
drush apv-config --domain=all
drush apv-config --log-requests=1
```

### Method 3: Settings.php Override

For production environments, you can override credentials in `settings.php`:

```php
$settings['acquia_purge_varnish_credentials'] = [
  'api_key' => 'your-acquia-api-key',
  'api_secret' => 'your-acquia-api-secret',
  'application_name' => 'your-app-name', // Optional
];
```

When using settings.php override, the form fields will be disabled and show an override message.

## Getting API Credentials

1. Log in to [Acquia Cloud Platform](https://cloud.acquia.com)
2. Navigate to: [Profile → API Tokens](https://cloud.acquia.com/a/profile/tokens)
3. Click **"Create Token"**
4. Give it a descriptive label (e.g., "Varnish Purge - Production")
5. **Copy the API Key and API Secret immediately** (Secret won't be shown again)
6. Your Acquia user role must have appropriate permissions to clear caches

**Note:** The API credentials are tied to your Acquia user permissions. Ensure your Acquia account has permission to clear caches for the environments you want to manage.

## Permissions

Navigate to: **People → Permissions**

`/admin/people/permissions`

Grant the **"Administer Acquia Purge Varnish"** permission to appropriate roles (e.g., Administrator, Site Manager).

This permission controls access to:
- Configuration form
- Manual purge button
- All Drush commands

## Usage

### Web UI

1. Navigate to: **Configuration → Acquia Purge Varnish → Purge varnish**
   
   `/admin/config/acquia-purge/varnish`

2. Click the purge button
3. Varnish cache will be cleared for the configured domains
4. You'll see a success message with the purge status

### Drush Commands

All web UI functionality is available via Drush:

#### Purge Varnish Cache

```bash
# Purge using configured domain setting
drush apv-purge

# Purge all domains in the environment
drush apv-purge --domain=all

# Purge only current domain
drush apv-purge --domain=current
```

#### Check Status

```bash
drush apv-status
```

Displays:
- Acquia environment information
- Current configuration
- Credential status
- Domain settings

#### List Available Domains

```bash
# Display as table
drush apv-domains

# Output as JSON
drush apv-domains --format=json

# Output as YAML
drush apv-domains --format=yaml
```

#### Test API Credentials

```bash
drush apv-test
```

Verifies:
- API connectivity
- Credential validity
- Application access

#### Configure Settings

```bash
# Set API credentials
drush apv-config --api-key="YOUR_KEY" --api-secret="YOUR_SECRET"

# Set application name
drush apv-config --application-name="myapp"

# Set domain preference
drush apv-config --domain=all

# Enable logging
drush apv-config --log-requests=1
```

### Command Aliases

All commands have short aliases:

| Full Command | Short Alias | Alternative |
|-------------|-------------|-------------|
| `acquia-purge-varnish:purge` | `apv-purge` | `apv:purge` |
| `acquia-purge-varnish:status` | `apv-status` | `apv:status` |
| `acquia-purge-varnish:list-domains` | `apv-domains` | `apv:domains` |
| `acquia-purge-varnish:test-credentials` | `apv-test` | `apv:test` |
| `acquia-purge-varnish:config` | `apv-config` | `apv:config` |

## Domain Purging Options

### Current Domain Only

Purges Varnish cache only for the domain you're currently accessing.

**Use case:** When you have separate admin and public domains and only want to clear the current one.

```bash
drush apv-purge --domain=current
```

### All Domains

Purges Varnish cache for all domains in the current environment.

**Use case:** When you want to ensure all domains are cleared (recommended for most deployments).

```bash
drush apv-purge --domain=all
```

**Note:** Check your domains in Acquia Cloud → Domains Management to see which domains will be affected.

## Automation & Integration

### Post-Deployment Hook

Create an Acquia Cloud Hook to automatically purge after deployments:

**File:** `hooks/common/post-code-deploy/purge-varnish.sh`

```bash
#!/bin/bash
site="$1"
target_env="$2"

drush @$site.$target_env apv-purge --domain=all
```

### Cron Job

Schedule regular cache purges:

```bash
# Purge every hour
0 * * * * /path/to/drush @prod apv-purge --domain=all
```

### CI/CD Pipeline

Integrate into your deployment pipeline:

```bash
# After successful deployment
drush @prod apv-purge --domain=all || echo "Failed to purge Varnish"
```

## Troubleshooting

### Command Not Found

```bash
drush cr
drush cache:rebuild-container
```

### Permission Denied

- Ensure you have the "Administer Acquia Purge Varnish" permission
- Check that your Drupal user has appropriate permissions

### API Authentication Failed

```bash
# Test your credentials
drush apv-test

# Check configuration
drush apv-status
```

Common causes:
- Invalid API Key or Secret
- Insufficient Acquia account permissions
- Expired API token

### Not on Acquia Environment

The purge functionality only works on Acquia Cloud Platform environments. You can still:
- Configure settings on local development
- Test credentials if you have valid API keys
- View module status with `drush apv-status`

### Application Not Found

```bash
# List available applications
drush apv-test

# Set application name explicitly
drush apv-config --application-name="your-app-name"
```

### Enable Logging for Debugging

```bash
drush apv-config --log-requests=1
drush apv-purge
drush watchdog:show --type=acquia_purge_varnish
```

## Security

### How is Acquia Purge Varnish Secure?

-  **CSRF Protection** - Custom CSRF tokens on all routes
-  **OAuth2 Authentication** - Secure API authentication flow
-  **Guzzle HTTP Client** - Industry-standard HTTP client with security best practices
-  **Permission-Based Access** - Only authorized users can purge
-  **No Credential Exposure** - API secrets never appear in logs or UI (unless explicitly enabled)
-  **Settings.php Override** - Production credentials can be managed outside the database

### Best Practices

1. **Use settings.php override** for production credentials
2. **Restrict permissions** to trusted roles only
3. **Rotate API tokens** regularly
4. **Enable logging** only for debugging, disable in production
5. **Never commit credentials** to version control

## Dependencies

This module uses the following external libraries:

- **league/oauth2-client** (^2.0) - OAuth2 authentication
- **guzzlehttp/guzzle** (via Drupal core) - HTTP client

These are automatically installed via Composer.

## Development

### Running Tests

```bash
# Code standards
vendor/bin/phpcs --standard=Drupal,DrupalPractice modules/contrib/acquia_purge_varnish/

# Static analysis
vendor/bin/phpstan analyse modules/contrib/acquia_purge_varnish/
```

### Module Architecture

- **Service-based architecture** with dependency injection
- **Type-safe** with strict type declarations throughout
- **Drupal 11 standards** compliant
- **Drush 13 attribute** support
- **Comprehensive error handling**

## Upgrade Notes

### From Previous Versions

If upgrading from an older version:

1. **Permission name changed** from `Acquia Purge Varnish` to `administer acquia purge varnish`
2. An update hook automatically migrates permissions for all roles
3. All existing configuration is preserved
4. New Drush commands are automatically available after `drush cr`

## Support

- **Issue queue:** https://drupal.org/project/issues/acquia_purge_varnish
- **Project page:** https://www.drupal.org/project/acquia_purge_varnish
- **Acquia API documentation:** https://cloudapi-docs.acquia.com/

## Maintainer

- **Alaa Haddad** ([flashwebcenter](https://www.drupal.org/u/flashwebcenter))
  - Website: https://www.alaahaddad.com
  - Company: https://www.flashwebcenter.com

## Legal

This module has **not** been built, maintained, or supported by Acquia Inc.

This is an open-source project with **no association** with Acquia Inc. The module uses the Acquia Cloud Platform API as documented at https://cloudapi-docs.acquia.com/

**License:** GPL-2.0-or-later

## Resources

- **Acquia Cloud API v2 Documentation:** https://cloudapi-docs.acquia.com/
- **Acquia Cloud Platform:** https://cloud.acquia.com
- **Drupal Guzzle Documentation:** https://www.drupal.org/node/1862446

## Contributing

Contributions are welcome! Please:

1. Open an issue first to discuss proposed changes
2. Follow Drupal coding standards
3. Include tests where applicable
4. Update documentation as needed

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.
