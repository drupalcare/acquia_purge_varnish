<?php

declare(strict_types=1);

namespace Drupal\acquia_purge_varnish;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for handling Acquia Cloud Platform API authentication and requests.
 */
class AcquiaPurgeVarnishApiClient {

  /**
   * Constructs an AcquiaPurgeVarnishApiClient object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected LoggerChannelInterface $logger,
    protected ConfigFactoryInterface $configFactory,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Makes an authenticated API request to Acquia Cloud Platform.
   *
   * @param string $method
   *   The HTTP method (GET, POST, etc.).
   * @param string $url
   *   The API endpoint URL.
   * @param array $credentials
   *   Array containing [api_key, api_secret].
   * @param array $options
   *   Additional request options (e.g., json body).
   *
   * @return array|null
   *   The decoded JSON response, or NULL on failure.
   */
  public function makeRequest(string $method, string $url, array $credentials, array $options = []): ?array {
    [$apiKey, $apiSecret] = $credentials;

    $provider = new GenericProvider([
      'clientId' => $apiKey,
      'clientSecret' => $apiSecret,
      'urlAuthorize' => '',
      'urlAccessToken' => 'https://accounts.acquia.com/api/auth/oauth/token',
      'urlResourceOwnerDetails' => '',
    ]);

    try {
      // Get an access token using the client credentials grant.
      $accessToken = $provider->getAccessToken('client_credentials');

      // Generate an authenticated request.
      $request = $provider->getAuthenticatedRequest($method, $url, $accessToken);

      // Send the request.
      $response = $this->httpClient->send($request, $options);
      $data = $response->getBody()->getContents();

      return json_decode($data, TRUE);
    }
    catch (IdentityProviderException $e) {
      $this->logger->error('Acquia API authentication failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Acquia API request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets the current environment name from Acquia environment variables.
   *
   * @return string|null
   *   The environment name, or NULL if not on Acquia.
   */
  public function getAcquiaEnvironment(): ?string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return NULL;
    }

    return $request->server->get('AH_SITE_ENVIRONMENT');
  }

  /**
   * Gets the current site group from Acquia environment variables.
   *
   * @return string|null
   *   The site group name, or NULL if not on Acquia.
   */
  public function getAcquiaSiteGroup(): ?string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return NULL;
    }

    return $request->server->get('AH_SITE_GROUP');
  }

  /**
   * Gets the current HTTP host.
   *
   * @return string|null
   *   The HTTP host, or NULL if not available.
   */
  public function getCurrentHost(): ?string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return NULL;
    }

    return $request->server->get('HTTP_HOST');
  }

  /**
   * Checks if the current environment is on Acquia Cloud Platform.
   *
   * @return bool
   *   TRUE if on Acquia, FALSE otherwise.
   */
  public function isAcquiaEnvironment(): bool {
    return $this->getAcquiaEnvironment() !== NULL;
  }

}
