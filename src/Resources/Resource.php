<?php

namespace FLAIRUK\SumUp\Resources;

use Closure;
use FLAIRUK\SumUp\Exceptions\ApiException;
use FLAIRUK\SumUp\Exceptions\ConfigurationException;
use FLAIRUK\SumUp\Exceptions\ConnectionException;
use FLAIRUK\SumUp\Exceptions\SumUpException;
use FLAIRUK\SumUp\SumUp;
use SumUp\Exception\ApiException as SdkApiException;
use SumUp\Exception\ConfigurationException as SdkConfigurationException;
use SumUp\Exception\ConnectionException as SdkConnectionException;
use SumUp\Exception\SDKException;
use SumUp\SumUp as Sdk;

abstract class Resource
{
    public function __construct(protected SumUp $client) {}

    /**
     * Run an SDK call, turning the SDK's exceptions into this package's.
     *
     * @template TReturn
     *
     * @param  Closure(Sdk): TReturn  $callback
     * @return TReturn
     */
    protected function call(Closure $callback): mixed
    {
        return static::rethrow(fn () => $callback($this->client->sdk()));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function rethrow(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (SdkApiException $e) {
            throw ApiException::fromSdk($e);
        } catch (SdkConnectionException $e) {
            throw new ConnectionException('Could not reach SumUp: '.$e->getMessage(), 0, $e);
        } catch (SdkConfigurationException $e) {
            throw new ConfigurationException($e->getMessage(), 0, $e);
        } catch (SDKException $e) {
            throw new SumUpException($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function merchantCode(): string
    {
        return $this->client->requireMerchantCode();
    }
}
