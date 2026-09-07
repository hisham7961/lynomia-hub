<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** السيرفرات */
class Server extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'servers';
    public const MODULE = 'servers';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'expiry' => 'date',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }

    // ── (الطور H · WP-H.1 · §37) حوافُّ البنية — المرجعُ هو الحافّة، لا جدولَ حوافٍّ ثانٍ ──

    /** المقعدُ الفعليُّ الذي يقف عليه العتاد */
    public function station(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Station::class, 'station_id');
    }

    /** سجلُّ العهدة العتاديُّ للجهاز نفسِه */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Asset::class, 'asset_id');
    }

    /** ملفُّ الموظف المسؤول تشغيليّاً (وحدة hr) — منفصلٌ عن owner_id (حسابُ نظام) */
    public function employeeFile(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'hr_id');
    }
}
