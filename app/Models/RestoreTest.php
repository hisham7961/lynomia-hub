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

/** اختبارات استعادة النسخ الاحتياطية (Restore Tests) */
class RestoreTest extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'restore_tests';
    public const MODULE = 'restores';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'test_date' => 'date',
        'backup_date' => 'date',
        'encrypted' => 'boolean',
        'offsite' => 'boolean',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Server::class, 'server_id');
    }

    public function by(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'by_id');
    }
}
