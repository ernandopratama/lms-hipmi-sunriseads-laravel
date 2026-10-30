<?php

declare(strict_types=1);

// One-time CLI/cron installer; never exposed as a browser route.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$output = new Symfony\Component\Console\Output\ConsoleOutput();

if ($app->configurationIsCached()) {
    fwrite(STDERR, "Clear config cache before first installation.\n");
    exit(1);
}
if (!$app->environment('production') || !is_file(base_path('.env'))) {
    fwrite(STDERR, "Prepare a private production .env before installation.\n");
    exit(1);
}
// Validate bootstrap input before running migrations; no default credentials.
$validator = Illuminate\Support\Facades\Validator::make(config('setup'), [
    'name'=>'required|string|max:150','email'=>'required|email|max:255',
    'organization'=>'required|string|max:200','school'=>'required|string|max:200',
    'password'=>'required|string|min:12|max:72',
]);
if ($validator->fails()) {
    fwrite(STDERR, "Configure SETUP_ADMIN_NAME, SETUP_ADMIN_EMAIL, SETUP_ADMIN_PASSWORD (12–72 bytes), SETUP_ORGANIZATION and SETUP_SCHOOL.\n");
    exit(1);
}
if (Illuminate\Support\Facades\Schema::hasTable('users') && App\Models\User::exists()) {
    fwrite(STDERR, "Installation refused: users already exist. Remove this one-time cron.\n");
    exit(1);
}
if (empty(config('app.key'))) {
    $code = Illuminate\Support\Facades\Artisan::call('key:generate', ['--force'=>true], $output);
    if ($code !== 0) exit($code);
    // Refresh the in-memory key for configuration checks without printing it.
    $values = Dotenv\Dotenv::parse(file_get_contents(base_path('.env')));
    config(['app.key'=>$values['APP_KEY']]);
}
foreach ([
    ['sunrise:hosting-check', ['--production'=>true]],
    ['migrate', ['--force'=>true, '--no-interaction'=>true]],
    ['sunrise:install-admin', ['--no-interaction'=>true]],
] as [$command, $arguments]) {
    $code = Illuminate\Support\Facades\Artisan::call($command, $arguments, $output);
    if ($code !== 0) exit($code);
}
$output->writeln('Setup complete. Remove SETUP_* from .env and remove the setup cron. Then run php artisan optimize.');
