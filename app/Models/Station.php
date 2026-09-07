<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **المحطة = المقعدُ الدائم** (Work OS · الطور F · WP-F.1 · §25–27).
 *
 * كيانٌ داخليٌّ مُدارٌ بالبيانات فوق `ModuleController` (وحدةُ `stations` في
 * `config/hub.php` تمنحه CRUD/scope/board/export). **داخليٌّ فقط** — بلا `client_id`،
 * ممنوعٌ على حساب العميل عبر `PortalGuard`.
 *
 * **الكودُ يُولَّد ولا يُطلَب** (نمطُ `Asset` حرفاً): يُخلَق من بادئةٍ وسنته وتسلسله
 * (`ST-2026-0001`) على `saving`، ويُعاد توليدُه عند تصادمِ الفهرس الفريد في `save`
 * — لا يُفشِل الحفظُ بخرق فهرسٍ عند التزامن.
 *
 * `current_employee_id` **مقفلٌ** (locked في السجل): يُكتَب عبر مسار الإسناد/الإخلاء
 * المقفل وحدَه (`StationController` على نمط `Custody::move`)، لا من نموذج CRUD العامّ.
 */
class Station extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'stations';
    public const MODULE = 'stations';
    public const DISPLAY = 'code';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    /**
     * الكودُ يُولَّد على `saving` (لا `creating` وحده): كودٌ فُرِّغ لاحقاً (استيرادٌ
     * أو تصحيح) يُملأ من جديد فلا تبقى محطةٌ بلا هويّة — نظيرُ `Asset::booted`.
     */
    protected static function booted(): void
    {
        static::saving(function (self $s) {
            if (! $s->code) $s->code = self::nextCode();
        });
    }

    /**
     * تخصيصُ الكود يتصادم عند التزامن: معالجان يقرآن أعلى تسلسلٍ معاً فيولّدان
     * الرقمَ نفسه، وفحصُ الوجود يرى المُثبَت لا المُدرَج قيد التنفيذ — فيقع خرقُ
     * الفهرس الفريد. نعيد المحاولةَ بكودٍ جديد بدل إفشال الحفظ (نمطُ `Asset::save`).
     */
    public function save(array $options = []): bool
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return parent::save($options);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt >= 5 || ! self::isDupCode($e)) throw $e;
                $this->code = self::nextCode();   // كودٌ جديد ثم أعِد المحاولة
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
     * الكودُ التالي: بادئةٌ + سنةٌ + تسلسلٌ يبدأ من فوق أعلى مستعمَلٍ بالبادئة
     * نفسها. المحذوفُ يحجز كودَه (كودُه مطبوعٌ على ملصقه). نمطُ `Asset::nextCode`.
     */
    public static function nextCode(): string
    {
        $format = (string) (setting('stations.code_format') ?: 'ST-{YEAR}-{SEQ}');
        $year = now()->format('Y');

        $prefix = str_replace(['{YEAR}', '{SEQ}'], [$year, ''], $format);
        $last = self::withTrashed()->where('code', 'like', $prefix . '%')
            ->orderByDesc('code')->value('code');
        $n = $last ? ((int) preg_replace('/\D/', '', substr((string) $last, strlen($prefix)))) + 1 : 1;

        do {
            $candidate = str_replace(['{YEAR}', '{SEQ}'], [$year, sprintf('%04d', $n)], $format);
            $n++;
        } while (self::withTrashed()->where('code', $candidate)->exists());

        return $candidate;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** المقعدُ الآن بيدِ من — `current_employee_id` يشير إلى حسابِ المستخدم */
    public function currentEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_employee_id');
    }

    /** سجلُّ الإسناد: من جلس ومتى أُخلي — الأثرُ الذي لا يحمله `current_employee_id` */
    public function assignments(): HasMany
    {
        return $this->hasMany(StationAssignment::class, 'station_id');
    }
}
