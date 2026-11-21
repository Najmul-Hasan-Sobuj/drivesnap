<?php

namespace DriveSnap\Console;

use DriveSnap\EncryptedFile;
use Illuminate\Console\Command;

class DecryptBackup extends Command
{
    protected $signature = 'drivesnap:decrypt {archive : Path to a DriveSnap .enc archive} {--output= : New plaintext .sql or .sqlite destination}';

    protected $description = 'Authenticate and decrypt an archive for an operator-controlled restore; never modifies a live database';

    public function handle(EncryptedFile $encrypted): int
    {
        $source = $this->argument('archive');
        $destination = $this->option('output');
        if (! is_string($destination) || $destination === '' || file_exists($destination) || ! is_file($source)) {
            $this->error('Supply an existing archive and a new --output path. Existing files are never overwritten.');

            return self::FAILURE;
        }
        try {
            $encrypted->decrypt($source, $destination);
        } catch (\Throwable) {
            $this->error('The archive could not be authenticated or decrypted. Check the encryption key and file integrity.');

            return self::FAILURE;
        }
        $this->info('Authenticated plaintext written with private permissions. Restore it into an isolated database first.');

        return self::SUCCESS;
    }
}
