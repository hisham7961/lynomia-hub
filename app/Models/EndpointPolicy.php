<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **سياسةُ نقاطٍ طرفية (USB + الوضعيّة)** (Work OS · الطور J · WP-J.2 · §43).
 *
 * **قاعدةُ الصدق (C15):** `enforce` يُولد false («Audit only») — الحجبُ الحقيقيّ
 * لمنافذ USB يتطلب **تسجيلَ MDM** (Jamf/Intune) وهو مؤجَّلٌ صراحةً؛ فقلبُ
 * الراية لا يمرّ صامتاً: `saving` يرميه ما لم يُقِرّ الكاتبُ بمتطلب MDM عبر
 * `acknowledgeMdmRequirement()` — وواجهةُ WP-J.3 تعرض `ENFORCE_NOTICE`
 * («يتطلب MDM / رصدٌ فقط») بجانب الراية، فلا ادّعاءَ حجبٍ زائفاً أبداً.
 *
 * `usb_mode` خمسةُ أوضاعٍ allowlist هنا (لا DB enum — C10)؛ ووضعا الحجب بلا
 * MDM يعنيان: الوكيلُ **يرصد المخالفةَ ويبلّغها** حدثَ `usb` — لا يحجب.
 */
class EndpointPolicy extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'endpoint_policies';

    protected $guarded = ['id'];

    protected $casts = [
        'enforce' => 'boolean',
        'posture_checks' => 'array',
        'approved_ssids' => 'array',   // SSID الشركة المعتمدة لوضعيّة Wi-Fi (§10)
    ];

    /** قائمةُ SSID المعتمدة — مطبَّعةً (قصٌّ + إسقاطُ الفارغ)؛ الغيابُ ⇒ لا حكم */
    public function approvedSsids(): array
    {
        $list = is_array($this->approved_ssids) ? $this->approved_ssids : [];

        return array_values(array_filter(array_map(fn ($s) => trim((string) $s), $list), fn ($s) => $s !== ''));
    }

    /** الأوضاعُ الخمسة — allowlist مفروضٌ في `saving` (لا DB enum · C10) */
    public const USB_MODES = ['allow', 'audit', 'readonly', 'block_storage', 'block_all'];

    /** نصُّ الصدق الذي تعرضه الواجهة بجانب راية الفرض — لا حجبَ زائفاً (C15) */
    public const ENFORCE_NOTICE = 'الفرضُ الحقيقيّ يتطلب تسجيلَ MDM (Jamf/Intune) — بدونه هذه السياسةُ رصدٌ فقط (Audit only)';

    /** إقرارُ متطلب MDM لهذا الحفظ وحدَه — يضبطه سطحُ الويب الصادقُ صراحةً لا افتراضاً */
    protected bool $mdmAcknowledged = false;

    public function acknowledgeMdmRequirement(): static
    {
        $this->mdmAcknowledged = true;

        return $this;
    }

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            $p->name = mb_substr(trim((string) $p->name), 0, 120);

            if (trim((string) $p->usb_mode) === '') $p->usb_mode = 'audit';
            if (! in_array((string) $p->usb_mode, self::USB_MODES, true)) {
                throw new \InvalidArgumentException('وضعُ USB خارج القائمة: ' . $p->usb_mode);
            }

            // C15: enforce=true لا يُكتب صامتاً — إقرارُ MDM شرطُ كل حفظٍ يحمله
            if ((bool) $p->enforce && ! $p->mdmAcknowledged) {
                throw new \InvalidArgumentException(
                    'لا يُفعَّل الفرضُ صامتاً: ' . self::ENFORCE_NOTICE);
            }

            // الإقرارُ لحفظةٍ واحدة — لا يبقى عالقاً على النموذج فيُفعِّل حفظاً لاحقاً خلسة
            $p->mdmAcknowledged = false;
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
