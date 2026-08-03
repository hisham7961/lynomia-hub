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

/** قيود اليومية */
class JournalEntry extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'journal_entries';
    public const MODULE = 'entries';
    public const DISPLAY = 'doc_no';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date' => 'date',
        'odoo_id' => 'decimal:3',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    /** القيد المُرحَّل مقفل: يُعكس بقيدٍ جديد ولا يُعدَّل ولا يُحذف — على كل المنافذ */
    protected static function booted(): void
    {
        $guard = function (self $m) {
            if ($m->getOriginal('state') === 'مرحّل') {
                throw \Illuminate\Validation\ValidationException::withMessages(
                    ['state' => 'القيد المُرحَّل مقفل — أنشئ قيد عكسٍ بدلاً من تعديله أو حذفه']);
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function lines()
    {
        return $this->hasMany(\App\Models\JournalLine::class, 'entry_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function fin(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FinDocument::class, 'fin_id');
    }
}
