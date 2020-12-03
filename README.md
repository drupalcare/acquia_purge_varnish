INTRODUCTION:
--------------------------------------------------------------------------------

Purge Varnish Cache on Acquia:
- Install the module.
- Add Acquia credentials in the form.
- Enable the permissions for any drupal roles.
- If you like to insert the credentials in settings.php and it will disable
the form you can use
copy this code in your setting.sphp and add
          $settings['acquia_purge_varnish_credentials'] = [
          'api_key' => 'insert-acquia-api-key',
          'api_secret' => 'insert-acquia-api-secret'
          ];
