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

/** الأصول والعهد */
class Asset extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'assets';
    public const MODULE = 'assets';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'buy_date' => 'date',
        'price' => 'decimal:3',
        'warranty' => 'date',
        'maint' => 'date',
        'life' => 'decimal:3',
        'disposal' => 'date',
        'custom' => 'array',
        'specs' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    /**
     * **كودُ العهدة يُولَّد ولا يُطلَب.**
     *
     * كان تعريفُ الأصل يعتمد `serial` (رقمُ المصنع) أو `tag` (نصٌّ حرّ) — وكلاهما
     * يُترك فارغاً أو يتكرّر أو يذهب مع الجهاز إن استُبدل. فكلُّ أصلٍ يُخلق بكودٍ
     * من صنفه وسنته وتسلسله (`LYN-SV-2026-0001`) — يُطبَع على الملصق ويُمسح
     * بالـQR ويبقى مع العهدة. والتوليدُ على `saving` لا `creating` وحده: كودٌ
     * فُرِّغ لاحقاً (استيرادٌ أو تصحيح) يُملأ من جديد فلا يبقى أصلٌ بلا هويّة.
     */
    protected static function booted(): void
    {
        static::saving(function (self $a) {
            if (! $a->code) $a->code = self::nextCode($a->type);
        });
    }

    /**
     * تخصيصُ الكود يتصادم عند التزامن: معالجان يقرآن أعلى تسلسلٍ معاً فيولّدان
     * الرقم نفسه، وفحصُ الوجود في nextCode يرى المُثبَت لا المُدرَج قيد التنفيذ —
     * فيقع خرقُ الفهرس الفريد (٥٠٠ على MySQL). نعيد المحاولة بكودٍ جديد بدل
     * إفشال الحفظ، على نمط `Contract::save` تماماً.
     */
    public function save(array $options = []): bool
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return parent::save($options);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt >= 5 || ! self::isDupCode($e)) throw $e;
                $this->code = self::nextCode($this->type);   // كودٌ جديد ثم أعِد المحاولة
            }
        }
    }

    /** أهذا خرقٌ للفهرس الفريد على `code`؟ (23000 على المحرّكين، والرسالةُ تسمّي العمود) */
    protected static function isDupCode(\Illuminate\Database\QueryException $e): bool
    {
        return (string) $e->getCode() === '23000'
            && str_contains(mb_strtolower($e->getMessage()), 'code');
    }

    /**
     * الكودُ التالي لصنفٍ في سنته: `{CAT}` بادئةُ الصنف من سجل الأصناف،
     * و`{SEQ}` تسلسلٌ يبدأ من فوق أعلى مستعمَلٍ **بالبادئة نفسها** — فلا
     * يتداخل تسلسلُ اللابتوبات مع السيرفرات ولا سنةٌ مع أخرى.
     */
    public static function nextCode(?string $type = null): string
    {
        $format = (string) (setting('assets.code_format')
            ?: config('hub_assets.code_format', 'LYN-{CAT}-{YEAR}-{SEQ}'));
        $cat = \App\Support\Custody::catCode($type);
        $year = now()->format('Y');

        $prefix = str_replace(['{CAT}', '{YEAR}', '{SEQ}'], [$cat, $year, ''], $format);
        // المحذوفُ يحجز كودَه: أصلٌ في السلة قد يُستعاد، وكودُه مطبوعٌ على ملصقه
        $last = self::withTrashed()->where('code', 'like', $prefix . '%')
            ->orderByDesc('code')->value('code');
        $n = $last ? ((int) preg_replace('/\D/', '', substr((string) $last, strlen($prefix)))) + 1 : 1;

        do {
            $candidate = str_replace(['{CAT}', '{YEAR}', '{SEQ}'], [$cat, $year, sprintf('%04d', $n)], $format);
            $n++;
        } while (self::withTrashed()->where('code', $candidate)->exists());

        return $candidate;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'holder_id');
    }

    /** سجلُّ الحيازة: من حملها ومتى — الأثرُ الذي لا يحمله `holder_id` */
    public function custodyLog(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\AssetCustody::class, 'asset_id');
    }
}
