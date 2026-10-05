<?php

namespace FLAIRUK\SumUp\OAuth;

use FLAIRUK\SumUp\Exceptions\ConfigurationException;
use FLAIRUK\SumUp\Exceptions\InvalidStateException;
use FLAIRUK\SumUp\Exceptions\OAuthException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The OAuth 2.0 authorization-code flow, for apps that act for other SumUp merchants.
 *
 * @see https://developer.sumup.com/tools/authorization/oauth
 */
class OAuth
{
    public const SESSION_KEY = 'sumup.oauth_state';

    /**
     * @param  array{client_id: ?string, client_secret: ?string, redirect_uri: ?string, scopes: list<string>, authorize_url: string, token_url: string}  $config
     */
    public function __construct(
        protected Http $http,
        protected array $config,
        protected int $timeout = 30,
    ) {}

    /**
     * The URL to send the merchant to. Pass the same $state you later check on the callback.
     *
     * @param  list<string>|null  $scopes  null uses the configured scopes; an empty list asks for SumUp's defaults
     */
    public function authorizationUrl(string $state, ?array $scopes = null, ?string $redirectUri = null): string
    {
        $scopes ??= $this->config['scopes'];

        return $this->config['authorize_url'].'?'.http_build_query(array_filter([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri ?? $this->redirectUri(),
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ], fn ($value) => $value !== ''), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Redirect to SumUp's consent screen, keeping a random state in the session for callback().
     *
     * @param  list<string>|null  $scopes
     */
    public function redirect(?array $scopes = null, ?Session $session = null): RedirectResponse
    {
        $state = Str::random(40);

        ($session ?? app('session.store'))->put(self::SESSION_KEY, $state);

        return new RedirectResponse($this->authorizationUrl($state, $scopes));
    }

    /**
     * Handle the redirect back from SumUp: check the state, then exchange the code for tokens.
     *
     * @throws InvalidStateException when the state is missing or does not match the session
     * @throws OAuthException when the merchant declined or the token request failed
     */
    public function callback(Request $request): AccessToken
    {
        $expected = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)) {
            throw new InvalidStateException('The SumUp OAuth state does not match. Start the authorisation again.');
        }

        if (is_string($error = $request->query('error'))) {
            $description = $request->query('error_description');

            throw new OAuthException('SumUp authorisation failed: '.(is_string($description) ? $description : $error), $error);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new OAuthException('The SumUp OAuth callback has no authorisation code.');
        }

        return $this->exchange($code);
    }

    /**
     * Exchange an authorisation code for an access and refresh token.
     */
    public function exchange(string $code, ?string $redirectUri = null): AccessToken
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri ?? $this->redirectUri(),
        ]);
    }

    /**
     * Get a new access token with a refresh token. Access tokens last about an hour.
     */
    public function refresh(AccessToken|string $refreshToken): AccessToken
    {
        $refreshToken = $refreshToken instanceof AccessToken ? $refreshToken->refreshToken : $refreshToken;

        if (! $refreshToken) {
            throw new OAuthException('There is no refresh token to refresh with.');
        }

        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], $refreshToken);
    }

    /**
     * A token for your own account with the client credentials grant (no refresh token is issued).
     *
     * @param  list<string>|null  $scopes
     */
    public function clientCredentials(?array $scopes = null): AccessToken
    {
        $scopes ??= $this->config['scopes'];

        return $this->token(array_filter([
            'grant_type' => 'client_credentials',
            'scope' => implode(' ', $scopes),
        ]));
    }

    /**
     * @param  array<string, string>  $parameters
     */
    protected function token(array $parameters, ?string $previousRefreshToken = null): AccessToken
    {
        $secret = $this->config['client_secret'] ?? null;

        if (! $secret) {
            throw new ConfigurationException('Set SUMUP_CLIENT_SECRET to use SumUp OAuth.');
        }

        try {
            $response = $this->http->asForm()
                ->acceptJson()
                ->timeout($this->timeout)
                ->post($this->config['token_url'], $parameters + [
                    'client_id' => $this->clientId(),
                    'client_secret' => $secret,
                ]);
        } catch (ConnectionException $e) {
            throw new OAuthException('Could not reach SumUp: '.$e->getMessage());
        }

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            throw OAuthException::fromResponse($response);
        }

        return AccessToken::fromResponse($response->json(), $previousRefreshToken);
    }

    protected function clientId(): string
    {
        return $this->config['client_id'] ?: throw new ConfigurationException('Set SUMUP_CLIENT_ID to use SumUp OAuth.');
    }

    protected function redirectUri(): string
    {
        return $this->config['redirect_uri'] ?: throw new ConfigurationException('Set SUMUP_REDIRECT_URI to use SumUp OAuth.');
    }
}
