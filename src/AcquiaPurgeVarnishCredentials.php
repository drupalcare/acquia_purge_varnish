<?php

namespace Drupal\acquia_purge_varnish;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;

/**
 * Acquia Credentials.
 */
class AcquiaPurgeVarnishCredentials {

  /**
   * Function to get response.
   *
   * @param string $method
   *   Method is used.
   * @param string $url
   *   Site Url.
   * @param array $credentials
   *   Get stored credentials.
   *
   * @return mixed
   *   Return data after authentication.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public static function getResponse(string $method, string $url, array $credentials) {

    $provider = new GenericProvider([
      'clientId' => $credentials[0],
      'clientSecret' => $credentials[1],
      'urlAuthorize' => '',
      'urlAccessToken' => 'https://accounts.acquia.com/api/auth/oauth/token',
      'urlResourceOwnerDetails' => '',
    ]);

    try {
      // Try to get an access token using the client credentials grant.
      $accessToken = $provider->getAccessToken('client_credentials');

      // Generate a request object using the access token.
      $request = $provider->getAuthenticatedRequest($method, $url, $accessToken);
      // https://cloud.acquia.com/api/applications
      // Send the request.
      $client = \Drupal::httpClient();
      // $client = new Client();
      $response = $client->send($request);
      $data = $response->getBody();
      $data = json_decode($data, TRUE);
      return $data;

    }
    catch (IdentityProviderException $e) {
      // Failed to get the access token.
      watchdog_exception('acquia_purge_varnish', $e);
    }

  }

}
