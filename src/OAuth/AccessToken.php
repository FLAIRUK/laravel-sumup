<?php

namespace FLAIRUK\SumUp\OAuth;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use JsonSerializable;
use Stringable;

/**
 * An OAuth token pair from https://api.sumup.com/token.
 *
 * Store it (encrypted) against the merchant, e.g. with an `encrypted:array` cast on toArray().
 */
final readonly class AccessToken implements JsonSerializable, Stringable
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $token,
        public ?string $refreshToken = null,
        public ?CarbonImmutable $expiresAt = null,
        public array $scopes = [],
        public string $type = 'Bearer',
    ) {}

    /**
     * From the token endpoint's JSON response. A refresh response without a new
     * refresh_token keeps the previous one.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fromResponse(array $response, ?string $previousRefreshToken = null): self
    {
        return new self(
            token: (string) $response['access_token'],
            refreshToken: $response['refresh_token'] ?? $previousRefreshToken,
            expiresAt: isset($response['expires_in']) ? CarbonImmutable::now()->addSeconds((int) $response['expires_in']) : null,
            scopes: self::parseScopes($response['scope'] ?? []),
            type: $response['token_type'] ?? 'Bearer',
        );
    }

    /**
     * From what toArray() returned, e.g. a value read back from the database.
     *
     * @param  array{access_token: string, refresh_token?: ?string, expires_at?: string|DateTimeInterface|null, scopes?: list<string>|string, token_type?: string}  $data
     */
    public static function fromArray(array $data): self
    {
        $expiresAt = $data['expires_at'] ?? null;

        return new self(
            token: $data['access_token'],
            refreshToken: $data['refresh_token'] ?? null,
            expiresAt: $expiresAt === null ? null : CarbonImmutable::parse($expiresAt),
            scopes: self::parseScopes($data['scopes'] ?? []),
            type: $data['token_type'] ?? 'Bearer',
        );
    }

    /**
     * Whether the access token has expired, or will within $leewaySeconds. Tokens without a known expiry never expire.
     */
    public function isExpired(int $leewaySeconds = 60): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->subSeconds($leewaySeconds)->isPast();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_at: ?string, scopes: list<string>, token_type: string}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->token,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'scopes' => $this->scopes,
            'token_type' => $this->type,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->token;
    }

    /**
     * @return list<string>
     */
    private static function parseScopes(mixed $scopes): array
    {
        if (is_string($scopes)) {
            $scopes = preg_split('/[\s,]+/', $scopes, -1, PREG_SPLIT_NO_EMPTY);
        }

        return array_values(array_map('strval', (array) $scopes));
    }
}
