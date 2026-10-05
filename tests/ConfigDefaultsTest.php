<?php

namespace FLAIRUK\SumUp\Tests;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * sumup:install writes its keys to .env empty, and env() reads an empty value as "",
 * so every default has to survive an empty variable, not only a missing one.
 */
class ConfigDefaultsTest extends TestCase
{
    /**
     * @return list<array{string, string, mixed}>
     */
    public static function defaults(): array
    {
        return [
            ['SUMUP_CURRENCY', 'currency', 'EUR'],
            ['SUMUP_WEBHOOK_PATH', 'webhooks.path', 'sumup/webhook'],
            ['SUMUP_BASE_URL', 'base_url', 'https://api.sumup.com'],
            ['SUMUP_TIMEOUT', 'timeout', 30],
        ];
    }

    #[Test]
    #[DataProvider('defaults')]
    public function an_empty_variable_falls_back_to_the_default(string $variable, string $key, mixed $default): void
    {
        putenv("{$variable}=");

        try {
            $this->assertSame($default, Arr::get(require __DIR__.'/../config/sumup.php', $key));
        } finally {
            putenv($variable);
        }
    }
}
