<?php

namespace App\Traits;

use App\Models\AuditEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/** يسجّل كل إضافة/تعديل/حذف مع صورة قبل وبعد وسبب التعديل والجهاز والـIP */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(fn ($m) => $m->writeAudit('إضافة', null, $m->auditable()));
        static::updated(function ($m) {
            $before = collect($m->getOriginal())->only(array_keys($m->getDirty()))->all();
            $after  = collect($m->getDirty())->except(['updated_at', 'search_vec'])->all();
            if ($after) $m->writeAudit('تعديل', $before, $after);
        });
        static::deleted(fn ($m) => $m->writeAudit('حذف', $m->auditable(), null));
    }

    public function auditable(): array
    {
        return collect($this->getAttributes())
            ->except(['search_vec', 'custom', 'meta'])
            ->map(fn ($v) => is_string($v) && strlen($v) > 300 ? substr($v, 0, 300) . '…' : $v)
            ->all();
    }

    public function writeAudit(string $action, ?array $before, ?array $after): void
    {
        AuditEntry::create([
            'user_id'    => Auth::id(),
            'action'     => $action,
            'module'     => static::MODULE ?? $this->getTable(),
            'record_id'  => $this->getKey(),
            'project_id' => $this->project_id ?? null,
'name' => (string) (
    $this->{defined('static::DISPLAY') ? static::DISPLAY : 'name'} ?? ''
),
            'before'     => $before,
            'after'      => $after,
            'reason'     => Request::input('_reason') ?: Request::header('X-Change-Reason'),
            'device'     => substr((string) Request::header('X-Device', Request::userAgent()), 0, 200),
            'ip'         => Request::ip(),
            'created_at' => now(),
        ]);
    }
}