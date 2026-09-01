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

/** أمرُ تغييرٍ تجاريّ — يمدّد خطَّ أساس المشروع بعد اعتماده، بلا مسّ العرض المقبول. */
class ChangeOrder extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'change_orders';
    public const MODULE = 'changeorders';
    public const DISPLAY = 'doc_no';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'value_delta' => 'decimal:3',
        'cost_delta' => 'decimal:3',
        'timeline_days' => 'integer',
        'approved_at' => 'datetime',
        'applied_at' => 'datetime',
        'custom' => 'array',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            if (! $c->doc_no) $c->doc_no = self::nextDocNo();
        });
    }

    /** إعادةُ المحاولة عند تصادم الرقم الفريد — نمطُ العقد/العرض */
    public function save(array $options = []): bool
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return parent::save($options);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($this->exists || $attempt >= 5 || ! self::isDupDocNo($e)) throw $e;
                $this->doc_no = self::nextDocNo();
            }
        }
    }

    protected static function isDupDocNo(\Illuminate\Database\QueryException $e): bool
    {
        return (string) $e->getCode() === '23000'
            && str_contains(mb_strtolower($e->getMessage()), 'doc_no');
    }

    /** الرقم التالي: CO-{سنة}-{تسلسل} */
    public static function nextDocNo(): string
    {
        $year = now()->format('Y');
        $prefix = 'CO-' . $year . '-';
        $last = static::withTrashed()->where('doc_no', 'like', $prefix . '%')
            ->orderByDesc('doc_no')->value('doc_no');
        $n = $last ? ((int) preg_replace('/\D/', '', substr((string) $last, strlen($prefix))) + 1) : 1;
        do {
            $candidate = $prefix . sprintf('%04d', $n);
            $n++;
        } while (static::withTrashed()->where('doc_no', $candidate)->exists());

        return $candidate;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }
}
