<?php

namespace DriveSnap\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Connection extends Model
{
    protected $table = 'drivesnap_connections';

    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'immutable_datetime', 'schedule_enabled' => 'boolean', 'retention_days' => 'integer'];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['installation_id' => (string) Str::uuid()]);
    }

    public function connected(): bool
    {
        return is_string($this->refresh_token) && $this->refresh_token !== '';
    }
}
