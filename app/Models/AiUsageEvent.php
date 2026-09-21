<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * **محاولةُ توليدٍ واحدة** (المرحلة ٤ · P4-W2) — لا طلبٌ منطقيٌّ واحد.
 *
 * **ولا `Auditable`:** هذا **هو** السجلّ، فأثرُ تدقيقٍ عليه تكرارٌ محض.
 * **ولا `SoftDeletes`:** سجلٌّ محاسبيٌّ لا يُحذَف — يُقصّ بعمرٍ مُعلَنٍ أو يبقى.
 *
 * **ولا نصَّ سؤالٍ ولا جوابٍ ولا حرفاً منه** — القرارُ من المرحلة ٣ محفوظ:
 * هذا الجدولُ يُقرَأ بصلاحيّةِ الرقابةِ لا بصلاحيّةِ السائل.
 */
class AiUsageEvent extends Model
{
    use HasUuid;

    protected $table = 'ai_usage_events';
    public const MODULE = 'ai_usage_events';

    protected $guarded = ['id'];

    protected $casts = [
        'attempt'        => 'integer',
        'http_status'    => 'integer',
        'input_tokens'   => 'integer',
        'output_tokens'  => 'integer',
        'total_tokens'   => 'integer',
        'cached_tokens'  => 'integer',
        'cost_micro'     => 'integer',
        'reserved_micro' => 'integer',
        'latency_ms'     => 'integer',
        'started_at'     => 'datetime',
        'settled_at'     => 'datetime',
    ];

    public function model(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}
