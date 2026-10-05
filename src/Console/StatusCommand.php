<?php

namespace FLAIRUK\SumUp\Console;

use FLAIRUK\SumUp\Exceptions\SumUpException;
use FLAIRUK\SumUp\SumUp;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'sumup:status')]
class StatusCommand extends Command
{
    protected $signature = 'sumup:status
                            {--merchant= : Check this merchant code instead of SUMUP_MERCHANT_CODE}';

    protected $description = 'Check the SumUp credentials and show the merchant they connect to';

    public function handle(SumUp $sumUp): int
    {
        if ($merchantCode = $this->option('merchant')) {
            $sumUp = $sumUp->forMerchant($merchantCode);
        }

        try {
            if ($sumUp->merchantCode() === null) {
                return $this->memberships($sumUp);
            }

            $merchant = $sumUp->merchant();
        } catch (SumUpException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Connected to SumUp.');

        $this->table(['Merchant code', 'Name', 'Country', 'Currency', 'Sandbox'], [[
            $merchant->merchantCode ?? $sumUp->merchantCode(),
            $merchant->company?->name ?? $merchant->alias ?? '',
            $merchant->country ?? '',
            $merchant->defaultCurrency ?? '',
            ($merchant->sandbox ?? false) ? 'Yes' : 'No',
        ]]);

        return self::SUCCESS;
    }

    /**
     * Without a merchant code, list the merchants the key can act for.
     */
    protected function memberships(SumUp $sumUp): int
    {
        $memberships = $sumUp->memberships();

        $this->components->info('Connected to SumUp. Set SUMUP_MERCHANT_CODE to one of these merchant codes:');

        $this->table(['Merchant code', 'Name', 'Type', 'Status'], array_map(fn ($membership) => [
            $membership->resource->id ?? '',
            $membership->resource->name ?? '',
            $membership->resource->type ?? '',
            $membership->status->value ?? '',
        ], $memberships));

        return self::SUCCESS;
    }
}
