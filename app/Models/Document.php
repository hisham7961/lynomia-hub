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

/** الملفات والمستندات */
class Document extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'documents';
    public const MODULE = 'files';
    public const DISPLAY = 'name';

    /**
     * الجمهورُ (Work OS · الطور B · WP-B.5 · SF-4) — allowlist في التطبيق لا كـenum
     * على القاعدة (درسُ C10). الافتراضُ `internal`: وثيقةٌ بلا جمهورٍ صريحٍ **غيرُ
     * مرئيّةٍ للعميل** بتاتاً — لا تسرّبَ بالسهو.
     *   • `internal` — داخليّةٌ فقط (الافتراض).
     *   • `client`   — يراها العميلُ المنسوبةُ إليه (`client_id`) دون الداخل الحصريّ.
     *   • `both`     — يراها الطرفان.
     */
    public const AUDIENCE_INTERNAL = 'internal';
    public const AUDIENCE_CLIENT = 'client';
    public const AUDIENCE_BOTH = 'both';

    public const AUDIENCES = [self::AUDIENCE_INTERNAL, self::AUDIENCE_CLIENT, self::AUDIENCE_BOTH];

    /** الجماهيرُ التي يراها العميل — أساسُ `scopeVisibleToClient` */
    public const CLIENT_AUDIENCES = [self::AUDIENCE_CLIENT, self::AUDIENCE_BOTH];

    protected $guarded = ['id', 'version', 'created_by'];

    /** الجمهورُ الافتراضيّ على النموذج نفسِه — داخليّ، لا يُترك للقاعدة وحدَها (SF-4) */
    protected $attributes = [
        'audience' => self::AUDIENCE_INTERNAL,
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry' => 'date',
        'alert' => 'decimal:3',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        // حارسُ الجمهور — «enum التطبيق» البديلُ عن DB enum (C10): الفراغُ يعود إلى
        // `internal` (وثيقةٌ بلا جمهورٍ صريحٍ داخليّةٌ لا تسرّبَ للعميل)، وأيُّ قيمةٍ
        // خارج allowlist تُرفض قبل الكتابة. إضافةُ قيمةٍ مستقبلاً سطرٌ هنا لا ALTER.
        static::saving(function (self $doc): void {
            if ($doc->audience === null || $doc->audience === '') {
                $doc->audience = self::AUDIENCE_INTERNAL;
            }
            if (! in_array($doc->audience, self::AUDIENCES, true)) {
                throw new \InvalidArgumentException("جمهورُ وثيقةٍ غيرُ صالح: {$doc->audience}");
            }
        });
    }

    /**
     * الوثائقُ التي يراها عميلٌ (Work OS · الطور B · WP-B.5) — رافدُ العزل لقارئ
     * البوابة: جمهورُها ∈ {client, both} **و**عميلُها ضمن عملاءِ القارئ (`$clientIds`).
     * الشرطان معاً لا أحدُهما: وثيقةٌ منسوبةٌ لعميلٍ لكنها `internal` تبقى محجوبةً
     * عنه، ووثيقةٌ `client` بلا عميلٍ لا يراها أحد. المجموعةُ الفارغةُ = لا شيء.
     */
    public function scopeVisibleToClient($q, array $clientIds)
    {
        $ids = array_values(array_filter(array_map('strval', $clientIds), fn ($v) => $v !== ''));
        if (! $ids) {
            return $q->whereRaw('1 = 0');            // لا عميلَ = لا وثيقة (فشلٌ مغلق)
        }

        return $q->whereIn('audience', self::CLIENT_AUDIENCES)->whereIn('client_id', $ids);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Task::class, 'task_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }

    /** العميلُ الذي تُشارَك معه الوثيقة (WP-B.5) — nullable، الداخليّةُ بلا عميل */
    public function client(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Client::class, 'client_id');
    }
}
