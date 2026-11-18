<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Admin-managed models: every create/update/delete lands in the audit log with the
 * changed fillable attributes (before → after) and the acting user.
 */
trait Audited
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('admin')
            ->logFillable()
            ->logExcept($this->auditExcept())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return list<string> */
    protected function auditExcept(): array
    {
        return [];
    }
}
