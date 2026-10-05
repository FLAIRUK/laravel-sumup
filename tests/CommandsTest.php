<?php

namespace FLAIRUK\SumUp\Tests;

use FLAIRUK\SumUp\Facades\SumUp;
use FLAIRUK\SumUp\SumUp as Client;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class CommandsTest extends TestCase
{
    #[Test]
    public function install_publishes_config_and_adds_missing_env_keys_once(): void
    {
        $env = $this->app->environmentFilePath();
        $example = base_path('.env.example');
        $originals = [$env => File::exists($env) ? File::get($env) : null, $example => File::exists($example) ? File::get($example) : null];
        File::put($env, "APP_NAME=Test\nSUMUP_API_KEY=existing\n");
        File::put($example, "APP_NAME=Laravel\n");

        try {
            $this->artisan('sumup:install')->assertSuccessful();
            $this->artisan('sumup:install')->assertSuccessful();

            $contents = File::get($env);
            $this->assertSame(1, substr_count($contents, 'SUMUP_API_KEY='));
            $this->assertStringContainsString('SUMUP_API_KEY=existing', $contents);
            foreach (['SUMUP_MERCHANT_CODE', 'SUMUP_CURRENCY', 'SUMUP_CLIENT_ID', 'SUMUP_CLIENT_SECRET', 'SUMUP_REDIRECT_URI'] as $key) {
                $this->assertSame(1, substr_count($contents, "{$key}="), $key);
                $this->assertSame(1, substr_count(File::get($example), "{$key}="), $key);
            }
            $this->assertStringContainsString("\nSUMUP_MERCHANT_CODE=\n", $contents);
            $this->assertFileExists(config_path('sumup.php'));
        } finally {
            foreach ($originals as $path => $original) {
                $original === null ? File::delete($path) : File::put($path, $original);
            }
            File::delete(config_path('sumup.php'));
        }
    }

    #[Test]
    public function status_shows_the_merchant(): void
    {
        $this->fakeApi(['/v1/merchants/MTEST123' => Http::response([
            'merchant_code' => 'MTEST123',
            'country' => 'GB',
            'default_currency' => 'GBP',
            'default_locale' => 'en-GB',
            'sandbox' => true,
            'company' => ['name' => 'Test Shop Ltd'],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ])]);

        $this->artisan('sumup:status')
            ->expectsTable(['Merchant code', 'Name', 'Country', 'Currency', 'Sandbox'], [['MTEST123', 'Test Shop Ltd', 'GB', 'GBP', 'Yes']])
            ->assertSuccessful();
    }

    #[Test]
    public function status_checks_another_merchant(): void
    {
        $this->fakeApi(['/v1/merchants/MOTHER99' => Http::response([
            'merchant_code' => 'MOTHER99',
            'country' => 'IE',
            'default_currency' => 'EUR',
            'default_locale' => 'en-IE',
            'alias' => 'Other Café',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ])]);

        $this->artisan('sumup:status', ['--merchant' => 'MOTHER99'])
            ->expectsTable(['Merchant code', 'Name', 'Country', 'Currency', 'Sandbox'], [['MOTHER99', 'Other Café', 'IE', 'EUR', 'No']])
            ->assertSuccessful();
    }

    #[Test]
    public function status_lists_merchants_when_no_merchant_code_is_set(): void
    {
        config(['sumup.merchant_code' => null]);
        $this->app->forgetInstance(Client::class);
        SumUp::clearResolvedInstances();

        $this->fakeApi(['/v0.1/memberships' => Http::response(['total_count' => 1, 'items' => [[
            'id' => 'mem-1', 'resource_id' => 'MTEST123', 'type' => 'merchant', 'roles' => [], 'permissions' => [],
            'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z', 'status' => 'accepted',
            'resource' => ['id' => 'MTEST123', 'type' => 'merchant', 'name' => 'Test Shop', 'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z'],
        ]]])]);

        $this->artisan('sumup:status')
            ->expectsTable(['Merchant code', 'Name', 'Type', 'Status'], [['MTEST123', 'Test Shop', 'merchant', 'accepted']])
            ->assertSuccessful();
    }

    #[Test]
    public function status_reports_failures(): void
    {
        $this->fakeApi(['/v1/merchants/MTEST123' => Http::response(['type' => 'about:blank', 'title' => 'Unauthorized', 'status' => 401, 'detail' => 'Invalid API key'], 401)]);

        $this->artisan('sumup:status')->assertFailed();
    }

    #[Test]
    public function status_reports_missing_credentials(): void
    {
        config(['sumup.api_key' => null]);
        $this->app->forgetInstance(Client::class);
        SumUp::clearResolvedInstances();

        $this->artisan('sumup:status')
            ->expectsOutputToContain('SUMUP_API_KEY')
            ->assertFailed();
    }
}
