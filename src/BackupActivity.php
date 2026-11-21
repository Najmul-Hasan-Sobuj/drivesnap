<?php

namespace DriveSnap;

use DriveSnap\Models\Backup;

class BackupActivity
{
    public function __construct(public string $action, public Backup $backup) {}
}
