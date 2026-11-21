<?php

namespace DriveSnap\Http;

use DriveSnap\Authorization;
use DriveSnap\BackupActivity;
use DriveSnap\GoogleDrive;
use DriveSnap\Jobs\CreateBackup;
use DriveSnap\Models\Backup;
use DriveSnap\Models\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DashboardController
{
    private function authorize(Request $request, string $action = 'view'): void
    {
        abort_unless(Authorization::allows($request->user(config('drivesnap.guard')), $action), 403);
    }

    public function index(Request $request): Response
    {
        $this->authorize($request);

        return Inertia::render(config('drivesnap.page'), ['drivesnap' => $this->data($request)]);
    }

    public function status(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json($this->data($request))->header('Cache-Control', 'private, no-store');
    }

    private function data(Request $request): array
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $connection = Connection::find(1);
        $backups = Backup::latest('created_at')->orderByDesc('id')->paginate(10);
        $backups->through(fn (Backup $backup): array => [
            ...$backup->only(['id', 'status', 'driver', 'size', 'uploaded_bytes', 'upload_to_drive', 'error_code', 'created_at', 'completed_at']),
            'local' => $backup->path && Storage::disk('drivesnap')->exists($backup->path),
            'remote' => $backup->drive_file_id !== null && $backup->status === 'completed',
            'download_url' => route('drivesnap.download', $backup, false),
            'delete_url' => route('drivesnap.destroy', $backup, false),
            'retry_url' => route('drivesnap.retry', $backup, false),
        ]);

        return [
            'backups' => $backups,
            'stats' => ['completed' => Backup::where('status', 'completed')->count(), 'failed' => Backup::where('status', 'failed')->count(),
                'bytes' => (int) Backup::where('status', 'completed')->sum('size'), 'last_completed_at' => Backup::where('status', 'completed')->latest('completed_at')->value('completed_at')],
            'connection' => ['connected' => $connection?->connected() ?? false, 'email' => $connection?->email,
                'configured' => (bool) config('drivesnap.client_id') && (bool) config('drivesnap.client_secret'),
                'schedule_enabled' => $connection?->schedule_enabled ?? false, 'retention_days' => $connection?->retention_days ?? 30,
                'daily_at' => config('drivesnap.daily_at'), 'timezone' => config('drivesnap.timezone')],
            'can' => collect(['view', 'create', 'download', 'delete', 'manage'])->mapWithKeys(fn (string $action): array => [$action => Authorization::allows($request->user(config('drivesnap.guard')), $action)])->all(),
            'endpoints' => collect(['index', 'status', 'store', 'settings', 'connect', 'disconnect'])->mapWithKeys(fn (string $name): array => [$name => route('drivesnap.'.$name, [], false)])->all(),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize($request, 'create');
        $data = $request->validate(['upload_to_drive' => ['required', 'boolean']]);
        if ($data['upload_to_drive'] && ! Connection::find(1)?->connected()) {
            return response()->json(['message' => 'Connect Google Drive first or choose Local only.'], 422);
        }
        $backup = DB::transaction(function () use ($request, $data): Backup {
            $backup = new Backup(['upload_to_drive' => $data['upload_to_drive']]);
            $backup->requestedBy()->associate($request->user(config('drivesnap.guard')));
            $backup->save();
            event(new BackupActivity('requested', $backup));
            DB::afterCommit(fn () => CreateBackup::dispatch($backup->id));

            return $backup;
        });

        return response()->json(['message' => 'Backup queued. Keep your backup worker running.', 'id' => $backup->id], 202);
    }

    public function retry(Request $request, Backup $backup): JsonResponse
    {
        $this->authorize($request, 'create');
        if ($backup->status !== 'failed') {
            return response()->json(['message' => 'Only failed backups can be retried.'], 422);
        }
        $backup->update(['status' => 'queued', 'error_code' => null]);
        CreateBackup::dispatch($backup->id);
        event(new BackupActivity('retried', $backup));

        return response()->json(['message' => 'Backup queued for retry.'], 202);
    }

    public function download(Request $request, Backup $backup, GoogleDrive $drive): BinaryFileResponse
    {
        $this->authorize($request, 'download');
        abort_unless($backup->status === 'completed' && $backup->path, 404);
        if (! Storage::disk('drivesnap')->exists($backup->path)) {
            try {
                $drive->download($backup);
            } catch (\Throwable) {
                abort(422, 'The Drive archive could not be downloaded or verified. Reconnect Google Drive and retry.');
            }
        }
        $path = Storage::disk('drivesnap')->path($backup->path);
        abort_unless(is_string($backup->sha256) && hash_equals($backup->sha256, hash_file('sha256', $path)), 409, 'Archive integrity check failed.');
        event(new BackupActivity('downloaded', $backup));

        return response()->download($path, $backup->id.'.'.$backup->driver.'.enc', ['Cache-Control' => 'private, no-store', 'Content-Type' => 'application/octet-stream'])->setPrivate();
    }

    public function destroy(Request $request, Backup $backup, GoogleDrive $drive): JsonResponse
    {
        $this->authorize($request, 'delete');
        $lock = Cache::lock('drivesnap:backup:'.$backup->id, 360);
        if (! $lock->get()) {
            return response()->json(['message' => 'This backup is busy. Retry when it finishes.'], 409);
        }
        try {
            $drive->delete($backup);
            if ($backup->path) {
                Storage::disk('drivesnap')->delete($backup->path);
            }
            $backup->delete();
            event(new BackupActivity('deleted', $backup));
        } catch (\Throwable) {
            return response()->json(['message' => 'Could not delete the backup. Its record has been preserved for retry.'], 422);
        } finally {
            $lock->release();
        }

        return response()->json(['message' => 'Backup deleted.']);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate(['schedule_enabled' => ['required', 'boolean'], 'retention_days' => ['required', 'integer', 'between:1,365']]);
        Connection::current()->update($data);

        return response()->json(['message' => 'Backup settings saved.']);
    }
}
