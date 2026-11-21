<?php

namespace DriveSnap\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Backup extends Model
{
    use HasUuids;

    protected $table = 'drivesnap_backups';

    protected $guarded = ['id'];

    protected $hidden = ['path', 'upload_url'];

    protected function casts(): array
    {
        return ['upload_to_drive' => 'boolean', 'size' => 'integer', 'uploaded_bytes' => 'integer', 'upload_url' => 'encrypted', 'completed_at' => 'immutable_datetime'];
    }

    public function requestedBy(): MorphTo
    {
        return $this->morphTo('requested_by');
    }

    public function auditLogValues(): array
    {
        return $this->only(['id', 'status', 'driver', 'size', 'sha256', 'upload_to_drive']);
    }
}
