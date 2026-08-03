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

/** قاعدة المعرفة والسياسات */
class KbArticle extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'kb_articles';
    public const MODULE = 'kb';
    public const ACK_FLAG = 'must_read';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'must_read' => 'boolean',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }

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
