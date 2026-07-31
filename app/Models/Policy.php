<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** السياسات والإقرارات (Policies) */
class Policy extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'policies';
    public const MODULE = 'policies';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'eff_date' => 'date',
        'review_date' => 'date',
        'ack_required' => 'boolean',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    /**
     * مراقب النسخة: تعليق هجرة `policy_acks` يعِد بأن «تحديث نسخة السياسة
     * يُبطل الإقرار القديم» — والحالة «منتهية بتحديث النسخة» موجودة في السجل
     * ولا شيء يكتبها أبداً. تغيّر `ver` الآن يُنهي إقرارات النسخة السابقة
     * ويولّد إقرارات معلّقة جديدة لمن أقرّوها (فيُعاد سؤالهم عن الجديد).
     */
    protected static function booted(): void
    {
        static::updated(function (self $p) {
            if (! $p->wasChanged('ver')) return;
            $old = (string) $p->getOriginal('ver');
            if ($old === '') return;

            $stale = \App\Models\PolicyAck::where('policy_id', $p->id)
                ->where('ver', $old)->where('status', 'مُقرّة')->get();
            if ($stale->isEmpty()) return;

            foreach ($stale as $ack) {
                $ack->forceFill(['status' => 'منتهية بتحديث النسخة'])->saveQuietly();

                // إقرارٌ معلّق بالنسخة الجديدة لمن أقرّ القديمة — الامتثال يُعاد طلبه لا يُفترض
                if (! $p->ack_required || ! $ack->user_id) continue;
                $exists = \App\Models\PolicyAck::where('policy_id', $p->id)
                    ->where('user_id', $ack->user_id)->where('ver', (string) $p->ver)->exists();
                if ($exists) continue;

                \App\Models\PolicyAck::create([
                    'title' => $p->title . ' — نسخة ' . $p->ver,
                    'policy_id' => $p->id, 'user_id' => $ack->user_id, 'ver' => (string) $p->ver,
                    'status' => 'بانتظار الإقرار', 'company_id' => $p->company_id,
                    'notes' => 'أُعيد طلب الإقرار بعد تحديث النسخة من ' . $old . ' إلى ' . $p->ver,
                ]);
                hub_notify($ack->user_id, 'policy',
                    '📜 حُدّثت سياسة «' . \Illuminate\Support\Str::limit($p->title, 60) . '» — إقرارك مطلوب على النسخة ' . $p->ver,
                    'policies', $p->id);
            }
        });
    }
}
