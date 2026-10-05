<?php

namespace FLAIRUK\SumUp\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \FLAIRUK\SumUp\SumUp forMerchant(string $merchantCode, \FLAIRUK\SumUp\OAuth\AccessToken|string|null $token = null)
 * @method static \FLAIRUK\SumUp\SumUp withToken(\FLAIRUK\SumUp\OAuth\AccessToken|string $token)
 * @method static \SumUp\SumUp sdk()
 * @method static string|null merchantCode()
 * @method static string requireMerchantCode()
 * @method static string currency()
 * @method static \FLAIRUK\SumUp\Money money(string|int|float $amount, ?string $currency = null)
 * @method static \FLAIRUK\SumUp\Resources\Checkouts checkouts()
 * @method static \FLAIRUK\SumUp\Resources\Transactions transactions()
 * @method static \FLAIRUK\SumUp\Resources\Customers customers()
 * @method static \FLAIRUK\SumUp\Resources\Readers readers()
 * @method static \SumUp\Types\Merchant merchant(?string $merchantCode = null)
 * @method static list<\SumUp\Types\Membership> memberships()
 * @method static \FLAIRUK\SumUp\OAuth\OAuth oauth()
 * @method static \FLAIRUK\SumUp\Webhooks\WebhookHandler webhooks()
 * @method static string webhookUrl(array $parameters = [])
 * @method static mixed request(string $method, string $path, array $body = [])
 *
 * @see \FLAIRUK\SumUp\SumUp
 */
class SumUp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \FLAIRUK\SumUp\SumUp::class;
    }
}
