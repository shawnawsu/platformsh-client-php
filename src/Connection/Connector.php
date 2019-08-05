<?php

namespace Platformsh\Client\Connection;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Collection;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Subscriber\Cache\CacheSubscriber;
use GuzzleHttp\Url;
use Platformsh\Client\OAuth2\ApiToken;
use Platformsh\Client\OAuth2\PasswordCredentialsWithTfa;
use Platformsh\Client\Session\Session;
use Platformsh\Client\Session\SessionInterface;
use Platformsh\Client\Session\Storage\File;

class Connector implements ConnectorInterface
{
    /** @var array */
    protected $config = [];

    /** @var ClientInterface */
    protected $client;

    /** @var callable|null */
    protected $oauthMiddleware;

    /** @var AbstractProvider */
    protected $provider;

    /** @var SessionInterface */
    protected $session;

    /**
     * @var array $storageKeys
     *
     * These keys are used for token storage for backwards compatibility with
     * the commerceguys/guzzle-oauth2-plugin package. The left-hand side is
     * the key in the AccessToken constructor. The right-hand side is the key
     * that will be stored.
     */
    private $storageKeys = [
        'access_token' => 'accessToken',
        'refresh_token' => 'refreshToken',
        'token_type' => 'tokenType',
        'scope' => 'scope',
        'expires' => 'expires',
        'expires_in' => 'expiresIn',
        'resource_owner_id' => 'resourceOwnerId,'
    ];

    /**
     * @param array            $config
     *     Possible configuration keys are:
     *     - accounts (string): The endpoint URL for the accounts API.
     *     - client_id (string): The OAuth2 client ID for this client.
     *     - debug (bool): Whether or not Guzzle debugging should be enabled
     *       (default: false).
     *     - verify (bool): Whether or not SSL verification should be enabled
     *       (default: true).
     *     - user_agent (string): The HTTP User-Agent for API requests.
     *     - proxy (array|string): A proxy setting, passed to Guzzle directly.
     *       Use a string to specify an HTTP proxy, or an array to specify
     *       different proxies for different protocols.
     * @param SessionInterface $session
     */
    public function __construct(array $config = [], SessionInterface $session = null)
    {
        $defaults = [
          'accounts' => 'https://accounts.platform.sh/api/v1/',
          'client_id' => 'platformsh-client-php',
          'client_secret' => '',
          'debug' => false,
          'verify' => true,
          'user_agent' => null,
          'cache' => false,
          'revoke_url' => '/oauth2/revoke',
          'token_url' => '/oauth2/token',
          'proxy' => null,
          'api_token' => null,
          'api_token_type' => 'access',
          'gzip' => extension_loaded('zlib'),
        ];
        $this->config = $config + $defaults;

        if (!isset($this->config['user_agent'])) {
            $this->config['user_agent'] = $this->defaultUserAgent();
        }

        if (!isset($this->config['user_agent'])) {
            $this->config['user_agent'] = $this->defaultUserAgent();
        }

        if (isset($session)) {
            $this->session = $session;
        } else {
            if ($this->config['api_token'] && $this->config['api_token_type'] === 'access') {
                // If an access token is set directly, default to a session
                // with no storage.
                $this->session = new Session();
            } else {
                // Otherwise, assign file storage to the session by default.
                // This reduces unnecessary access token refreshes.
                $this->session = new Session();
                $this->session->setStorage(new File());
            }
        }
    }

    /**
     * @return string
     */
    private function defaultUserAgent()
    {
        $version = trim(file_get_contents(__DIR__ . '/../../version.txt')) ?: '0.x.x';

        return sprintf(
            '%s/%s (%s; %s; PHP %s)',
            'Platform.sh-Client-PHP',
            $version,
            php_uname('s'),
            php_uname('r'),
            PHP_VERSION
        );
    }

    /**
     * @return string
     */
    private function defaultUserAgent()
    {
        $version = trim(file_get_contents(__DIR__ . '/../../version.txt')) ?: '2.0.x';

        return sprintf(
            '%s/%s (%s; %s; PHP %s)',
            'Platform.sh-Client-PHP',
            $version,
            php_uname('s'),
            php_uname('r'),
            PHP_VERSION
        );
    }

    /**
     * @inheritdoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException if tokens cannot be revoked.
     */
    public function logOut()
    {
        $this->oauth2Plugin = null;

        try {
            $this->revokeTokens();
        } catch (BadResponseException $e) {
            // Retry the request once.
            if ($e->getResponse() && $e->getResponse()->getStatusCode() < 500) {
                $this->revokeTokens();
            }
        } finally {
            $this->session->clear();
            $this->session->save();
        }
    }

    /**
     * Revokes the access and refresh tokens saved in the session.
     */
    private function revokeTokens()
    {
        $revocations = array_filter([
            'refresh_token' => $this->session->get('refreshToken'),
            'access_token' => $this->session->get('accessToken'),
        ]);
        $url = Url::fromString($this->config['accounts'])
            ->combine($this->config['revoke_url'])
            ->__toString();
        foreach ($revocations as $type => $token) {
            $this->getClient()->post($url, [
                'body' => [
                    'token' => $token,
                    'token_type_hint' => $type,
                ],
            ]);
        }
    }

    /**
     * Revokes the access and refresh tokens saved in the session.
     *
     * @see Connector::logOut()
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function revokeTokens()
    {
        if ($this->oauth2Plugin) {
            // Save the access token for future requests.
            $token = $this->getOauth2Plugin()->getAccessToken(false);
            if ($token !== null) {
                $this->saveToken($token);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function getSession()
    {
        return $this->session;
    }

    /**
     * {@inheritdoc}
     */
    public function getAccountsEndpoint()
    {
        return $this->config['accounts'];
    }

    /**
     * {@inheritdoc}
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
     */
    public function logIn($username, $password, $force = false, $totp = null)
    {
        if (!$force && $this->isLoggedIn() && $this->session->get('username') === $username) {
            return;
        }
        $this->logOut();
        $client = $this->getGuzzleClient([
          'base_url' => $this->config['accounts'],
          'defaults' => [
            'debug' => $this->config['debug'],
            'verify' => $this->config['verify'],
            'proxy' => $this->config['proxy'],
          ],
        ]);
        $grantType = new PasswordCredentialsWithTfa(
          $client, [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'username' => $username,
            'password' => $password,
            'totp' => $totp,
        ]);
        $this->session->set('username', $username);
        $this->saveToken($token);
    }

    private function getProvider()
    {
        return $this->provider ? $this->provider : new Platformsh([
          'clientId' => $this->config['client_id'],
          'clientSecret' => $this->config['client_secret'],
          'base_uri' => $this->config['accounts'],
          'debug' => $this->config['debug'],
          'verify' => $this->config['verify'],
          'proxy' => $this->config['proxy'],
        ]);
    }

    /**
     * Save an access token to the session.
     *
     * @param AccessTokenInterface $token
     */
    protected function saveToken(AccessTokenInterface $token)
    {
        if ($this->config['api_token'] && $this->config['api_token_type'] === 'access') {
            return;
        }
        foreach ($token->jsonSerialize() as $name => $value) {
            if (isset($this->storageKeys[$name])) {
                $this->session->set($this->storageKeys[$name], $value);
            }
        }
        $this->session->save();
    }

    /**
     * Load the current access token.
     *
     * @return AccessToken|null
     */
    protected function loadToken()
    {
        if ($this->config['api_token'] && $this->config['api_token_type'] === 'access') {
            return new AccessToken([
                'access_token' => $this->config['api_token'],
                // Skip local expiry checking.
                'expires' => 2147483647,
            ]);
        }
        if (!$this->session->get($this->storageKeys['access_token'])) {
            return null;
        }

        // These keys are used for saving in the session for backwards
        // compatibility with the commerceguys/guzzle-oauth2-plugin package.
        $values = [];
        foreach ($this->storageKeys as $tokenKey => $sessionKey) {
            $value = $this->session->get($sessionKey);
            if ($value !== null) {
                $values[$tokenKey] = $value;
            }
        }

        return new AccessToken($values);
    }

    /**
     * @inheritdoc
     */
    public function isLoggedIn()
    {
        return $this->session->get($this->storageKeys['access_token']) || $this->config['api_token'];
    }

    /**
     * Get an OAuth2 middleware to add to Guzzle clients.
     *
     * @throws \RuntimeException
     *
     * @return GuzzleMiddleware
     */
    protected function getOauthMiddleware()
    {
        if (!$this->oauthMiddleware) {
            if (!$this->isLoggedIn()) {
                throw new \RuntimeException('Not logged in');
            }

            $grant = new ClientCredentials();
            $grantOptions = [];

            // Set up the "exchange" (normal) API token type.
            if ($this->config['api_token'] && $this->config['api_token_type'] !== 'access') {
                $grant = new ApiToken();
                $grantOptions['api_token'] = $this->config['api_token'];
            }

            $this->oauthMiddleware = new GuzzleMiddleware($this->getProvider(), $grant, $grantOptions);
            $this->oauthMiddleware->setTokenSaveCallback(function (AccessToken $token) {
                $this->saveToken($token);
            });

            // If an access token is already available (via an API token or via
            // the session) then set it in the middleware in advance.
            if ($accessToken = $this->loadToken()) {
                $this->oauthMiddleware->setAccessToken($accessToken);
            }

            $this->oauth2Plugin->setTokenSaveCallback(function (AccessToken $token) {
                $this->saveToken($token);
            });
        }

        return $this->oauthMiddleware;
    }

    /**
     * @inheritdoc
     */
    public function setApiToken($token, $type)
    {
        $this->config['api_token'] = $token;
        if (!in_array($type, ['access', 'exchange'])) {
            throw new \InvalidArgumentException('Invalid API token type: ' . $type);
        }
        $this->config['api_token_type'] = $type;
        if (isset($this->oauthMiddleware)) {
            $this->oauthMiddleware = null;
        }
    }

    /**
     * @inheritdoc
     */
    public function getClient()
    {
        if (!isset($this->client)) {
            $stack = HandlerStack::create();
            $stack->push($this->getOauthMiddleware());

            $config = [
                'handler' => $stack,
                'headers' => ['User-Agent' => $this->config['user_agent']],
                'debug' => $this->config['debug'],
                'verify' => $this->config['verify'],
                'proxy' => $this->config['proxy'],
                'auth' => 'oauth2',
            ];

            if ($this->config['gzip']) {
                $options['defaults']['decode_content'] = true;
                $options['defaults']['headers']['Accept-Encoding'] = 'gzip';
            }

            $client = $this->getGuzzleClient($options);

            if ($this->config['gzip']) {
                $config['decode_content'] = true;
                $config['headers']['Accept-Encoding'] = 'gzip';
            }

            $this->client = new Client($config);
        }

        return $this->client;
    }
}
