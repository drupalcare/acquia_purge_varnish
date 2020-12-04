# INTRODUCTION
Clear varnish on Acquia from drupal site. Uses Guzzle HTTP client Instead of
cURL https://www.drupal.org/node/1862446
Acquia recommends you build with Cloud API v2.
https://cloudapi-docs.acquia.com/

This module provides any drupal user with permissions to manually purge Acquia
Cloud varnish cache.  Purge varnish cache for each environment that you have.
It uses the acquia cloud API V2 as Acquia recommend.

How is Acquia Purge Varnish secure?
It uses custom CSRF token in Drupal.  It also uses Guzzle HTTP client request
for all API calls. So it's not vulnerable to CSRF exploits and avoids security
risk.

# DEPENDENCIES
None

# INSTALLATION
Install as any other contrib module.

# REQUIREMENTS
1- It only works with any acquia's environment.
2- You must have Acquia's (API Key && API Secret) with the right permissions
from Acquia's role.
Ex: if you create API Key & API secret and you don't have the right permissions
the module will not work.

# CONFIGURATION
Where can I enter the API Key & API Secret?
Go to your-site/admin/config/acquia-purge-varnish-form

# Note
I added an extra feature for developers/site admin who like to add Acquia's
credentials in settings.php and can't be changed in admin form.
The two fields will be disabled.


copy this code in your settings.php and add your (API Key & API Secret)
$settings['acquia_purge_varnish_credentials'] = [
'api_key' => 'insert-acquia-api-key',
'api_secret' => 'insert-acquia-api-secret'
];

# PERMISSIONS
If you are an admin you can give permissions to any drupal role to clear cache.

# Legal
This module has not been built, maintained or supported by Acquia Inc.
This is an open source project with no association with Acquia Inc.
The module uses their API, that's all.

* Read more about Acquia's API V2:
   https://cloudapi-docs.acquia.com/

* Project page: https://www.drupal.org/project/acquia_purge_varnish

* To submit bug reports and feature suggestions, or to track changes:
   https://drupal.org/project/issues/acquia_purge_varnish


