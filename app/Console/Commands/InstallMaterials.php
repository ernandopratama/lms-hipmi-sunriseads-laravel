<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallMaterials extends Command
{
    protected $signature = 'sunrise:install-materials {--force : Overwrite files that already exist}';
    protected $description = 'Copy the bundled learning material files (slide decks and previews) into the private materials directory';

    public function handle(): int
    {
        $source = database_path('materials');
        $target = rtrim(env('MATERIALS_PATH') ?: storage_path('app/private/materials'), '/');
        if (! is_dir($source)) {
            $this->error('Folder database/materials tidak ada.');
            return self::FAILURE;
        }
        $copied = $skipped = 0;
        foreach (File::allFiles($source) as $file) {
            $destination = $target.'/'.$file->getRelativePathname();
            if (file_exists($destination) && ! $this->option('force')) {
                $skipped++;
                continue;
            }
            File::ensureDirectoryExists(dirname($destination), 0755);
            File::copy($file->getPathname(), $destination);
            $copied++;
        }
        $this->info("Materi: {$copied} berkas disalin, {$skipped} sudah ada ({$target}).");
        return self::SUCCESS;
    }
}
