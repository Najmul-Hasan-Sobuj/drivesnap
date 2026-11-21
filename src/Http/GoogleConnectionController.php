<?php

namespace DriveSnap\Http;

use DriveSnap\Authorization;
use DriveSnap\GoogleDrive;
use DriveSnap\Models\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GoogleConnectionController
{
    private function authorize(Request $request): void
    {
        abort_unless(Authorization::allows($request->user(config('drivesnap.guard')), 'manage'), 403);
    }

    public function connect(Request $request): RedirectResponse
    {
        $this->authorize($request);
        if (! config('drivesnap.client_id') || ! config('drivesnap.client_secret')) {
            return redirect()->route('drivesnap.index')->withErrors(['drivesnap' => 'Configure the DriveSnap Google OAuth client before connecting.']);
        }
        $state = Str::random(64);
        $request->session()->put('drivesnap.oauth', ['state' => $state, 'expires' => now()->addMinutes(5)->getTimestamp()]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('drivesnap.client_id'), 'redirect_uri' => route('drivesnap.callback'),
            'response_type' => 'code', 'scope' => GoogleDrive::SCOPE.' openid email',
            'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->authorize($request);
        $saved = $request->session()->pull('drivesnap.oauth');
        abort_unless(is_array($saved) && ($saved['expires'] ?? 0) >= now()->getTimestamp()
            && is_string($request->query('state')) && hash_equals($saved['state'], $request->query('state')), 403);
        if (! is_string($request->query('code')) || $request->query('code') === '') {
            return redirect()->route('drivesnap.index')->withErrors(['drivesnap' => 'Google authorization was cancelled.']);
        }
        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('drivesnap.client_id'), 'client_secret' => config('drivesnap.client_secret'),
                'code' => $request->query('code'), 'grant_type' => 'authorization_code', 'redirect_uri' => route('drivesnap.callback'),
            ]);
            $tokens = $response->json();
            if (! $response->successful() || ! is_array($tokens) || ! is_string($tokens['access_token'] ?? null)
                || ! is_string($tokens['refresh_token'] ?? null) || $tokens['refresh_token'] === ''
                || ! in_array(GoogleDrive::SCOPE, explode(' ', $tokens['scope'] ?? ''), true)) {
                throw new \RuntimeException('Required Google permissions were not granted.');
            }
            $profile = Http::withToken($tokens['access_token'])->connectTimeout(5)->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo');
            $email = $profile->json('email');
            if (! $profile->successful() || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $profile->json('email_verified') !== true) {
                throw new \RuntimeException('A verified Google account is required.');
            }
            $connection = Connection::current();
            if ($connection->email && strcasecmp($connection->email, $email) !== 0) {
                return redirect()->route('drivesnap.index')->withErrors(['drivesnap' => 'Reconnect the original Google account to preserve access to existing backups.']);
            }
            $connection->update(['email' => $email, 'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
                'expires_at' => now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 3600)))]);
        } catch (Throwable) {
            return redirect()->route('drivesnap.index')->withErrors(['drivesnap' => 'Could not connect Google Drive. Check your OAuth configuration and granted permissions.']);
        }

        return redirect()->route('drivesnap.index')->with('success', 'Google Drive connected.');
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->authorize($request);
        $connection = Connection::find(1);
        $revoke = (bool) config('drivesnap.revoke_on_disconnect', true);
        if ($connection?->connected() && $revoke) {
            try {
                $response = Http::asForm()->connectTimeout(5)->timeout(15)->post('https://oauth2.googleapis.com/revoke', ['token' => $connection->refresh_token]);
                if (! $response->successful() && ! ($response->status() === 400 && $response->json('error') === 'invalid_token')) {
                    return response()->json(['message' => 'Google could not revoke access. Please retry.'], 422);
                }
            } catch (Throwable) {
                return response()->json(['message' => 'Google could not revoke access. Please retry.'], 422);
            }
        }
        $connection?->update(['access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'schedule_enabled' => false]);
        $request->session()->forget('drivesnap.oauth');

        return response()->json(['message' => $revoke
            ? 'Google Drive disconnected. Existing backups are preserved.'
            : 'DriveSnap disconnected locally. Google authorization remains active for the shared project.']);
    }
}
