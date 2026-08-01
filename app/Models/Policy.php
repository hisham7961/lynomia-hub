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
    public const ACK_FLAG = 'ack_required';
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
    /**
     * محرّكٌ **واحد** لدورة الإقرار، ومكانه النموذج لا المتحكّم: تغيّر النسخة
     * يُسقط إقرارات ما قبلها ويُعيد الإعلان — أياً كان مصدر التغيير (واجهة،
     * API، استيراد، أمر طرفية، بذرة). كان المنطق مكرَّراً في موضعين بمفردتَي
     * حالةٍ مختلفتين، فيرسل إشعارين ويرى كلٌّ نصف السجلات.
     */
    protected static function booted(): void
    {
        static::updated(function (self $m) {
            if (! $m->wasChanged('ver')) return;
            if (! (bool) ($m->{static::ACK_FLAG} ?? false)) return;

            hub_ack_reset(static::MODULE, $m->id, (string) ($m->ver ?: '1.0'));
        });
    }
}
