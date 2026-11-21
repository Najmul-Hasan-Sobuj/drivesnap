<?php

namespace DriveSnap;

use DriveSnap\Models\Backup;
use DriveSnap\Models\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GoogleDrive
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private const API = 'https://www.googleapis.com/drive/v3';

    public function client(Connection $connection): PendingRequest
    {
        $connection->refresh();
        if (! $connection->connected()) {
            throw new RuntimeException('Connect Google Drive first.');
        }
        if (! $connection->access_token || ! $connection->expires_at || $connection->expires_at->subMinute()->isPast()) {
            Cache::lock('drivesnap:token', 30)->block(5, function () use ($connection): void {
                $connection->refresh();
                if ($connection->access_token && $connection->expires_at?->subMinute()->isFuture()) {
                    return;
                }
                $response = Http::asForm()->connectTimeout(5)->timeout(20)->post('https://oauth2.googleapis.com/token', [
                    'client_id' => config('drivesnap.client_id'), 'client_secret' => config('drivesnap.client_secret'),
                    'refresh_token' => $connection->refresh_token, 'grant_type' => 'refresh_token',
                ]);
                $token = $response->json('access_token');
                if (! $response->successful() || ! is_string($token) || $token === '') {
                    throw new RuntimeException('Google authorization expired. Reconnect Drive.');
                }
                $connection->update(['access_token' => $token, 'expires_at' => now()->addSeconds(max(60, (int) $response->json('expires_in', 3600)))]);
            });
        }

        return Http::withToken($connection->access_token)->acceptJson()->connectTimeout(5)->timeout(45)->withOptions(['allow_redirects' => false]);
    }

    public function upload(Backup $backup): void
    {
        $connection = Connection::current();
        $client = $this->client($connection);
        if (! $connection->folder_id) {
            $folder = $client->post(self::API.'/files', ['name' => 'DriveSnap backups', 'mimeType' => 'application/vnd.google-apps.folder', 'appProperties' => ['drivesnap' => $connection->installation_id]]);
            if (! $folder->successful() || ! is_string($folder->json('id'))) {
                throw new RuntimeException('Cannot create the DriveSnap folder.');
            }
            $connection->update(['folder_id' => $folder->json('id')]);
        }
        $disk = Storage::disk('drivesnap');
        $absolute = $disk->path($backup->path);
        if (! hash_equals($backup->sha256, hash_file('sha256', $absolute))) {
            throw new RuntimeException('Local archive integrity check failed.');
        }
        if (! $backup->drive_file_id) {
            $ids = $client->get(self::API.'/files/generateIds', ['count' => 1, 'space' => 'drive']);
            if (! $ids->successful() || ! is_string($ids->json('ids.0'))) {
                throw new RuntimeException('Cannot reserve a Drive file identifier.');
            }
            $backup->update(['drive_file_id' => $ids->json('ids.0')]);
        }
        $existing = $client->get(self::API.'/files/'.$backup->drive_file_id, ['fields' => 'id,size,md5Checksum,trashed,appProperties']);
        if ($existing->successful()) {
            if ($existing->json('trashed') || $existing->json('appProperties.backup') !== $backup->id || $existing->json('appProperties.drivesnap') !== $connection->installation_id || $existing->json('md5Checksum') !== hash_file('md5', $absolute)) {
                throw new RuntimeException('Remote archive integrity check failed.');
            }
            $backup->update(['uploaded_bytes' => $backup->size, 'upload_url' => null]);

            return;
        }
        if ($existing->status() !== 404) {
            throw new RuntimeException('Cannot inspect the remote backup.');
        }
        if (! $backup->upload_url) {
            $response = $client->withHeaders(['X-Upload-Content-Type' => 'application/octet-stream', 'X-Upload-Content-Length' => (string) $backup->size])
                ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', [
                    'id' => $backup->drive_file_id, 'name' => $backup->id.'.'.$backup->driver.'.enc', 'parents' => [$connection->folder_id],
                    'appProperties' => ['drivesnap' => $connection->installation_id, 'backup' => $backup->id, 'sha256' => $backup->sha256],
                ]);
            $url = $response->header('Location');
            if (! $response->successful() || ! $this->safeUploadUrl($url)) {
                throw new RuntimeException('Cannot start a Google Drive upload.');
            }
            $backup->update(['upload_url' => $url]);
        }
        if (! $this->safeUploadUrl($backup->upload_url)) {
            throw new RuntimeException('Invalid Google upload session.');
        }
        $probe = $this->client($connection)->withHeaders(['Content-Range' => 'bytes */'.$backup->size])->withBody('', 'application/octet-stream')->put($backup->upload_url);
        if ($probe->successful()) {
            $backup->update(['uploaded_bytes' => $backup->size, 'upload_url' => null]);

            return;
        }
        if ($probe->status() !== 308) {
            if (in_array($probe->status(), [404, 410], true)) {
                $backup->update(['upload_url' => null]);
            }
            throw new RuntimeException('The upload session must be retried.');
        }
        $offset = 0;
        if (preg_match('/^bytes=0-([0-9]+)$/', $probe->header('Range') ?? '', $range)) {
            $offset = (int) $range[1] + 1;
        }
        if ($offset > $backup->size) {
            throw new RuntimeException('Invalid Google upload offset.');
        }
        $input = fopen($absolute, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot read the archive.');
        }
        try {
            if (fseek($input, $offset) !== 0) {
                throw new RuntimeException('Cannot resume archive upload.');
            }
            while ($offset < $backup->size) {
                $chunk = fread($input, min(8 * 1024 * 1024, $backup->size - $offset));
                if (! is_string($chunk) || $chunk === '') {
                    throw new RuntimeException('Cannot read archive upload data.');
                }
                $end = $offset + strlen($chunk) - 1;
                $response = $this->client($connection)->withHeaders(['Content-Range' => 'bytes '.$offset.'-'.$end.'/'.$backup->size])
                    ->withBody($chunk, 'application/octet-stream')->put($backup->upload_url);
                if (! $response->successful() && $response->status() !== 308) {
                    throw new RuntimeException('Google Drive upload failed.');
                }
                $offset = $end + 1;
                $backup->update(['uploaded_bytes' => $offset]);
            }
        } finally {
            fclose($input);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Google has not confirmed the completed upload.');
        }
        $backup->update(['upload_url' => null]);
    }

    public function download(Backup $backup): void
    {
        if (! $backup->drive_file_id || ! $backup->path || ! $backup->sha256) {
            throw new RuntimeException('No remote archive is available.');
        }
        $disk = Storage::disk('drivesnap');
        $disk->makeDirectory('backups');
        $path = $disk->path($backup->path);
        $temporary = $path.'.download';
        $file = fopen($temporary, 'xb');
        if ($file === false) {
            throw new RuntimeException('Another download is already running.');
        }
        chmod($temporary, 0600);
        fclose($file);
        try {
            $response = $this->client(Connection::current())->withOptions(['sink' => $temporary, 'progress' => static function (int $total, int $downloaded) use ($backup): void {
                if ($downloaded > $backup->size) {
                    throw new RuntimeException('Remote archive exceeds its recorded size.');
                }
            }])->get(self::API.'/files/'.$backup->drive_file_id, ['alt' => 'media']);
            if (! $response->successful() || filesize($temporary) !== $backup->size || ! hash_equals($backup->sha256, hash_file('sha256', $temporary))) {
                throw new RuntimeException('The remote archive could not be verified.');
            }
            if (! rename($temporary, $path)) {
                throw new RuntimeException('Cannot store the downloaded archive.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function delete(Backup $backup): void
    {
        if (! $backup->drive_file_id) {
            return;
        }
        $response = $this->client(Connection::current())->delete(self::API.'/files/'.$backup->drive_file_id);
        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Cannot delete the Google Drive copy.');
        }
    }

    private function safeUploadUrl(?string $url): bool
    {
        return is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'www.googleapis.com'
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null
            && in_array(parse_url($url, PHP_URL_PORT), [null, 443], true) && parse_url($url, PHP_URL_FRAGMENT) === null
            && parse_url($url, PHP_URL_PATH) === '/upload/drive/v3/files';
    }
}
