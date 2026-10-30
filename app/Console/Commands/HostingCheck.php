<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HostingCheck extends Command
{
    protected $signature = 'sunrise:hosting-check {--production : Check HTTPS and production configuration} {--database : Check MySQL and installed schema}';
    protected $description = 'Check shared hosting requirements without changing data or printing secrets';

    public function handle(): int
    {
        $failures = 0;
        $check = function (bool $ok, string $label) use (&$failures): void {
            $this->line(($ok ? '[OK] ' : '[FAIL] ').$label);
            $failures += $ok ? 0 : 1;
        };
        $check(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80600, 'PHP 8.4 / 8.5 (locked dependencies)');
        foreach (['ctype','curl','dom','fileinfo','filter','hash','mbstring','openssl','pcre','pdo_mysql','session','tokenizer','xml'] as $extension) {
            $check(extension_loaded($extension), 'PHP extension: '.$extension);
        }
        foreach (['bootstrap/cache','storage/app/private','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs'] as $path) {
            $check(is_dir(base_path($path)) && is_writable(base_path($path)), 'Writable: '.$path);
        }
        $key = (string) config('app.key', '');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $check(is_string($decoded) && strlen($decoded) === 32, 'APP_KEY configured');
        $check(config('database.default') === 'mysql', 'MySQL connection');
        $check(config('session.driver') === 'file', 'File sessions');
        $check(config('cache.default') === 'file', 'File cache');
        $check(in_array(config('queue.default'), ['database','sync'], true), 'Database or synchronous queue');
        $check(config('filesystems.default') === 'local' && config('filesystems.disks.local.serve') === false, 'Private local files');
        if ($this->option('production')) {
            $check(app()->environment('production'), 'APP_ENV=production');
            $check(config('app.debug') === false, 'APP_DEBUG=false');
            $check(str_starts_with(config('app.url'), 'https://'), 'HTTPS APP_URL');
            $check(config('session.secure') === true, 'Secure session cookie');
            $check(config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host')), 'SMTP configured (delivery must be checked separately)');
        }
        if ($this->option('database')) {
            try {
                DB::select('SELECT 1');
                $check(true, 'Database connection');
                foreach (['users','schools','courses','assignments','submissions','jobs','failed_jobs'] as $table) {
                    $check(Schema::hasTable($table), 'Table: '.$table);
                }
                $migrator = app('migrator');
                $pending = array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan());
                $check($pending === [], 'No pending migrations');
            } catch (\Throwable $error) {
                $check(false, 'Database/schema unavailable. Check private server logs and database configuration.');
            }
        }
        $this->comment('Also verify document root, SSL, cron execution and SMTP delivery in cPanel.');
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
