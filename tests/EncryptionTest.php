<?php

namespace DriveSnap\Tests;

use DriveSnap\EncryptedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class EncryptionTest extends TestCase
{
    public function test_large_archives_round_trip_without_a_64_mib_limit(): void
    {
        $disk = Storage::disk('drivesnap');
        $source = $disk->path('large.sql');
        $out = fopen($source, 'wb');
        $chunk = str_repeat('x', 1024 * 1024);
        foreach (range(1, 65) as $number) {
            fwrite($out, $chunk);
        }
        fclose($out);
        unset($chunk);
        $cipher = $disk->path('large.enc');
        $restored = $disk->path('large-restored.sql');
        app(EncryptedFile::class)->encrypt($source, $cipher);
        app(EncryptedFile::class)->decrypt($cipher, $restored);
        $this->assertGreaterThan(64 * 1024 * 1024, filesize($cipher));
        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $restored));
        $this->assertSame(0600, fileperms($cipher) & 0777);
    }

    public function test_tampered_and_truncated_archives_are_rejected_and_partial_plaintext_is_removed(): void
    {
        $disk = Storage::disk('drivesnap');
        $disk->put('input.sql', 'private database data');
        app(EncryptedFile::class)->encrypt($disk->path('input.sql'), $disk->path('valid.enc'));
        $bytes = $disk->get('valid.enc');
        foreach (['tamper' => substr_replace($bytes, chr(ord($bytes[-1]) ^ 1), -1), 'truncated' => substr($bytes, 0, -1)] as $name => $invalid) {
            $disk->put($name.'.enc', $invalid);
            try {
                app(EncryptedFile::class)->decrypt($disk->path($name.'.enc'), $disk->path($name.'.sql'));
                $this->fail('Invalid archive accepted.');
            } catch (RuntimeException) {
                $disk->assertMissing($name.'.sql');
            }
        }
    }

    public function test_previous_keys_can_restore_old_archives_and_existing_outputs_are_never_overwritten(): void
    {
        $disk = Storage::disk('drivesnap');
        $disk->put('input.sql', 'original');
        $key = config('app.key');
        app(EncryptedFile::class)->encrypt($disk->path('input.sql'), $disk->path('valid.enc'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'app.previous_keys' => [$key]]);
        app(EncryptedFile::class)->decrypt($disk->path('valid.enc'), $disk->path('restored.sql'));
        $this->assertSame('original', $disk->get('restored.sql'));
        $this->artisan('drivesnap:decrypt', ['archive' => $disk->path('valid.enc'), '--output' => $disk->path('restored.sql')])->assertFailed();
        $this->assertSame('original', $disk->get('restored.sql'));
    }
}
