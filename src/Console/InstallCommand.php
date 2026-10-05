<?php

namespace FLAIRUK\SumUp\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'sumup:install')]
class InstallCommand extends Command
{
    protected $signature = 'sumup:install';

    protected $description = 'Publish the SumUp config and add its environment variables to .env';

    /** @var list<string> */
    protected array $variables = ['SUMUP_API_KEY', 'SUMUP_MERCHANT_CODE', 'SUMUP_CURRENCY', 'SUMUP_CLIENT_ID', 'SUMUP_CLIENT_SECRET', 'SUMUP_REDIRECT_URI'];

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'sumup-config']);

        foreach ([$this->laravel->environmentFilePath(), base_path('.env.example')] as $path) {
            if (! $files->exists($path)) {
                continue;
            }

            $contents = $files->get($path);
            $missing = array_filter($this->variables, fn (string $key) => ! preg_match("/^{$key}=/m", $contents));

            if ($missing) {
                $files->append($path, PHP_EOL.implode(PHP_EOL, array_map(fn ($key) => "{$key}=", $missing)).PHP_EOL);
                $this->components->info('Added '.implode(', ', $missing).' to '.basename($path).'.');
            }
        }

        $this->components->info('Fill in your SumUp API key and merchant code, then run `php artisan sumup:status` to check the connection.');

        return self::SUCCESS;
    }
}
