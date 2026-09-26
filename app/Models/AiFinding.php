<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * نتيجةُ كاشفٍ من كواشفِ المدقّق على موضوعٍ واحد (`App\Support\Ai\Auditor`).
 * حالةُ **الشرط** (`open`/`resolved`) هنا، وتصرّفُ المستخدم في `signal_states`.
 */
class AiFinding extends Model
{
    use HasUuid;

    protected $table = 'ai_findings';

    protected $guarded = ['id'];

    protected $casts = [
        'evidence' => 'array',
        'fields' => 'array',
        'detected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * مفتاحُ الإشارة الثابت — من هويّةِ **الشرط** لا من الموضوع: الموضوعُ قد ينتقل إلى
     * أحدثِ تقرير، والمفتاحُ يبقى، فيبقى تصرّفُ المدير به ما بقي الشرط.
     */
    public function signalKey(): string
    {
        return 'audit:' . $this->detector . ':' . $this->dedup_key;
    }
}
