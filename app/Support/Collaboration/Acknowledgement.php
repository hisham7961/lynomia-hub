<?php

namespace App\Support\Collaboration;

use App\Models\PolicyAck;
use App\Models\SignRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * **بابُ الإقرار الواحد** (TECH_DEBT #29 · ARCH-07 · A01-06 · «توحيدُ الإقرارات»).
 *
 * كان للإقرار محرّكان متوازيان بسجلَّين وجدولين، وكاتبٌ ثالث:
 *  - `Acks` + `config/hub_acks.php` + `record_acks` — المحاضرُ والعهدُ والقراراتُ والحوادث:
 *    لا يُقرّ إلا من طُلب منه، والإقرارُ الأوّل لا يُمحى (`insertOrIgnore`) — لكنّ إعادةَ
 *    الضغط تُكرّر التدقيقَ وإشعارَ صاحب السجل.
 *  - `hub_ack_*` + سجلٌّ مكتوبٌ داخل helpers.php + `policy_acks` — السياساتُ والمعرفة: يُقرّ
 *    كلُّ من يرى السجلّ ولو لم يُخاطَب به، والضغطةُ الثانية **تدهس** وقتَ الإقرار الأوّل
 *    وجهازَه (`firstOrNew` + `fill`)، وتُدقَّق كلَّ مرّة.
 *  - والتوقيعُ الإلكترونيّ (`EsignFinalizer::completeLinked`) يكتب `policy_acks` مباشرةً:
 *    صفٌّ جديدٌ ولو كان للموقّع إقرارٌ معلّق (فيظهر مُقِرّاً ومعلّقاً معاً)، بلا ربطٍ بالطلب،
 *    وبفعلَي تدقيقٍ آخرَين أحدُهما على صفّ الإقرار لا على السياسة.
 *
 * الآن سجلٌّ واحد (`config/hub_acks.php` — `store` يميّز المخزن) وبابٌ واحد `acknowledge()`
 * لكلّ القنوات (ويب · جوال · توقيع)، بقواعدَ واحدة:
 *  - **مَن يُقرّ**: الشخصُ نفسُه، ومن المُخاطَبين وحدهم (`targets`) — أو من فُتح له إقرارٌ
 *    معلّق؛ والتوقيعُ دليلُ هويّته بنفسه (المظروفُ خاطبه).
 *  - **لا يتكرّر ولا يُمحى الأوّل**: إقرارٌ قائمٌ للنسخة نفسِها ⇒ لا كتابةَ ولا تدقيقَ ولا إشعار.
 *  - **الدليل**: الوقتُ والعنوانُ والجهاز (مقصوصةً لعرض أعمدتها)، ورابطُ التوقيع إن وُجد.
 *  - **قيدُ تدقيقٍ واحدٌ بشكلٍ واحد**: الفعلُ من السجلّ، على السجلّ المُقَرّ نفسِه، و`after`:
 *    النسخة · القناة · التحفّظ (· رمز التحقق · الموقّع).
 */
final class Acknowledgement
{
    public const STORE_RECORD = 'record_acks';
    public const STORE_POLICY = 'policy_acks';

    public const VIA_WEB = 'web';
    public const VIA_MOBILE = 'mobile';
    public const VIA_ESIGN = 'esign';

    /** مفرداتُ حالة `policy_acks` — خياراتُ وحدة policyacks نفسُها (مفردةٌ واحدة لا اثنتان) */
    public const PENDING = 'بانتظار الإقرار';
    public const DONE = 'مُقرّة';
    public const EXPIRED = 'منتهية بتحديث النسخة';

    /* ─────────────────────────── السجلّ ─────────────────────────── */

    /** السجلُّ الواحد: `config/hub_acks.php` بمخزنَيه */
    public static function registry(): array
    {
        return (array) config('hub_acks', []);
    }

    public static function def(string $module): ?array
    {
        $d = self::registry()[$module] ?? null;

        return is_array($d) ? $d : null;
    }

    /** المخزنُ: `record_acks` افتراضاً، أو `policy_acks` للسياسات والمعرفة */
    public static function store(string $module): ?string
    {
        $d = self::def($module);

        return $d ? (string) ($d['store'] ?? self::STORE_RECORD) : null;
    }

    /** وحداتُ مخزنٍ بعينه — مفتاحُها ⟵ تعريفُها */
    public static function modules(string $store): array
    {
        return array_filter(self::registry(), fn ($d) => is_array($d) && ($d['store'] ?? self::STORE_RECORD) === $store);
    }

    /** فعلُ التدقيق الواحد للوحدة */
    public static function auditAction(string $module): string
    {
        $d = (array) self::def($module);

        return (string) ($d['audit'] ?? $d['label'] ?? 'إقرار');
    }

    /** النسخةُ التي يُقاس عليها الإقرار — نصّاً في المخزنين */
    public static function version(string $module, $row): string
    {
        if (self::store($module) === self::STORE_POLICY) {
            $col = (string) (self::def($module)['ver'] ?? 'ver');

            return (string) ($row->{$col} ?? '') ?: '1.0';
        }

        return (string) Acks::version($row);
    }

    /** من يلزمه الإقرار — من السجلّ نفسِه (record) أو من جمهوره (policy) */
    public static function targets(string $module, $row): array
    {
        return array_map('strval', self::store($module) === self::STORE_POLICY
            ? hub_ack_targets($module, $row)
            : Acks::targets($module, $row));
    }

    /**
     * سببُ منع المستخدم من الإقرار على السجلّ، أو null إن جاز — القاعدةُ عينُها لكلّ قناة:
     * لا يُقرّ أحدٌ نيابةً عن غيره، ولا يُقرّ إلا من خوطب (أو فُتح له إقرارٌ معلّق).
     */
    public static function denial(string $module, $row, ?string $userId): ?string
    {
        if (! self::def($module) || ! $row) return 'لا إقرار على هذه الوحدة';
        if (! $userId) return 'الإقرارُ شهادةٌ شخصية — لا مُقِرَّ معروف';
        if (in_array($userId, self::targets($module, $row), true)) return null;
        if (self::store($module) === self::STORE_POLICY && self::rows($module, $row, $userId)->isNotEmpty()) return null;

        return 'الإقرار لمن طُلب منه وحده — ولا يُقرّ أحدٌ نيابةً عن غيره';
    }

    /* ─────────────────────────── الكتابة ─────────────────────────── */

    /**
     * **الإقرارُ الواحد.** يعيد `['created' => bool, 'ack' => PolicyAck|null]` — created=false
     * إن كان الإقرارُ قائماً للنسخة نفسِها (بلا أيّ أثر). المنعُ ⟵ 403.
     *
     * @param  array  $ev  via · note · ip · device · sign_request_id · verify_code · signer · title
     */
    public static function acknowledge(string $module, $row, ?string $userId, array $ev = []): array
    {
        $ev['via'] ??= self::viaFromRequest();
        if ($ev['via'] !== self::VIA_ESIGN && ($why = self::denial($module, $row, $userId))) abort(403, $why);

        return self::store($module) === self::STORE_POLICY
            ? self::policyAck($module, $row, $userId, $ev)
            : self::recordAck($module, $row, (string) $userId, $ev);
    }

    /**
     * التوقيعُ على **صفّ إقرارٍ معلّقٍ بعينه** (طلبُ توقيعٍ مربوطٌ بـpolicyacks): يُتَمّ الصفُّ
     * نفسُه بأدلّة التوقيع، ويُدقَّق على السجلّ المُقَرّ لا على الصفّ. مُقَرٌّ سلفاً ⟵ لا أثر.
     */
    public static function completePending(PolicyAck $ack, array $ev): bool
    {
        $ev['via'] ??= self::VIA_ESIGN;

        return DB::transaction(function () use ($ack, $ev) {
            $ack = PolicyAck::whereKey($ack->getKey())->lockForUpdate()->firstOrFail();
            if ($ack->status === self::DONE && $ack->ack_at) return false;

            self::stamp($ack, $ev);
            $ack->save();

            $module = (string) ($ack->src_module ?: 'policies');
            $rid = (string) ($ack->record_id ?: $ack->policy_id);
            $cls = self::modelOf($module);
            $row = $rid && $cls ? $cls::find($rid) : null;
            $row
                ? self::audit($module, $row, (string) ($ack->ver ?: self::version($module, $row)), $ev)
                : hub_audit(self::auditAction('policies'), 'policyacks', $ack->id, (string) $ack->title,
                    ['after' => self::auditAfter((string) $ack->ver, $ev)]);

            return true;
        });
    }

    /** قناةُ الطلب الجاري: سطحُ الـAPI جوّالٌ، وما عداه ويب */
    public static function viaFromRequest(): string
    {
        return request()->is('api/*') ? self::VIA_MOBILE : self::VIA_WEB;
    }

    /** أدلّةُ التوقيع الإلكترونيّ من طلبه — لقناة التوقيع */
    public static function esignEvidence(SignRequest $req, string $signer): array
    {
        return ['via' => self::VIA_ESIGN, 'sign_request_id' => $req->id,
                'verify_code' => (string) $req->verify_code, 'signer' => $signer];
    }

    /* ─────────────────────────── داخلي ─────────────────────────── */

    private static function recordAck(string $module, $row, string $userId, array $ev): array
    {
        if (! Schema::hasTable('record_acks')) return ['created' => false, 'ack' => null];

        $ver = Acks::version($row);
        /*
         * **الإقرارُ الأول لا يُمحى.** كان `updateOrInsert` يكتب فوق الصفّ: فمن أقرّ في يناير
         * من مكتبه ثم ضغط ثانيةً في مارس من هاتفه، صار الدليلُ «مارس، من هذا الجهاز». الإقرارُ
         * شهادةٌ مؤرَّخة: صفٌّ لكلّ نسخة يبقى — والفريدُ (module,record_id,user_id,ver) يحرسه.
         */
        $n = DB::table('record_acks')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'module' => $module, 'record_id' => $row->id, 'user_id' => $userId,
            'ver' => $ver, 'ack_at' => now(),
            'ip' => hub_fit((string) ($ev['ip'] ?? request()->ip()), 60),
            'device' => hub_fit((string) ($ev['device'] ?? request()->userAgent()), 200),
            'note' => ($ev['note'] ?? null) ?: null, 'created_at' => now(),
        ]);
        if (! $n) return ['created' => false, 'ack' => null];   // مُقَرٌّ سلفاً — لا تدقيقَ ولا إشعارَ مكرّر

        // كتابةٌ بمنشئ الاستعلام لا تُطلق أحداث Eloquent — فالختم يُرفع يدوياً
        hub_data_bump('record_acks');
        self::audit($module, $row, (string) $ver, $ev);

        // صاحبُ السجلّ يعرف أنّ إقراراً وقع — الاعتمادُ خبرٌ لا صمت
        $label = (string) (self::def($module)['label'] ?? '');
        $who = (string) (\App\Models\User::whereKey($userId)->value('name') ?? '');
        foreach (array_unique(array_filter([$row->created_by ?? null, $row->owner_id ?? null])) as $uid) {
            if ((string) $uid !== $userId) {
                hub_notify($uid, 'ack', '✅ ' . $who . ' — ' . $label . ': '
                    . Str::limit((string) ($row->title ?? $row->name ?? ''), 50), $module, $row->id);
            }
        }

        return ['created' => true, 'ack' => null];
    }

    private static function policyAck(string $module, $row, ?string $userId, array $ev): array
    {
        $ver = self::version($module, $row);

        return DB::transaction(function () use ($module, $row, $userId, $ev, $ver) {
            // إقرارُ موقّعٍ خارجيّ (بلا حساب): المظروفُ مفتاحُه — توقيعٌ واحدٌ إقرارٌ واحد
            if (! $userId) {
                if (! empty($ev['sign_request_id']) && ($done = PolicyAck::whereNull('deleted_at')
                        ->where('sign_request_id', $ev['sign_request_id'])->orderBy('id')->lockForUpdate()->first())) {
                    return ['created' => false, 'ack' => $done];
                }
                $ack = self::newRow($module, $row, null, $ver, $ev);
            } else {
                $mine = self::rows($module, $row, $userId, $ver, true);
                if ($done = $mine->first(fn ($a) => $a->status === self::DONE && $a->ack_at)) {
                    return ['created' => false, 'ack' => $done];   // الأوّلُ يبقى — لا دهسَ ولا تدقيق
                }
                $ack = $mine->values()->get(0) ?? self::newRow($module, $row, $userId, $ver, $ev);   // المعلّقُ يُتَمّ (ترتيبٌ حتميٌّ بالمعرّف)
            }

            self::stamp($ack, $ev);
            $ack->save();
            self::audit($module, $row, $ver, $ev);

            return ['created' => true, 'ack' => $ack];
        });
    }

    /** صفوفُ إقرار المستخدم على السجلّ (للنسخة إن سُمّيت) — بالمفتاح العامّ أو بـpolicy_id القديم */
    private static function rows(string $module, $row, string $userId, ?string $ver = null, bool $lock = false)
    {
        $q = PolicyAck::whereNull('deleted_at')->where('user_id', $userId)
            ->where(fn ($q) => $q->where('record_id', $row->id)
                ->orWhere(fn ($w) => $w->whereNull('record_id')->where('policy_id', $row->id)))
            ->whereIn('status', [self::PENDING, self::DONE])
            ->orderBy('id');
        if ($ver !== null) $q->where('ver', $ver);

        return ($lock ? $q->lockForUpdate() : $q)->get();
    }

    private static function newRow(string $module, $row, ?string $userId, string $ver, array $ev): PolicyAck
    {
        return new PolicyAck([
            'src_module' => $module, 'record_id' => $row->id, 'user_id' => $userId, 'ver' => $ver,
            'policy_id' => $module === 'policies' ? $row->id : null,
            'title' => Str::limit((string) ($ev['title'] ?? $row->title ?? ''), 200),
        ]);
    }

    /** ختمُ الإقرار بدليله — الحالةُ والوقتُ والعنوانُ والجهازُ ورابطُ التوقيع */
    private static function stamp(PolicyAck $ack, array $ev): void
    {
        $ack->fill([
            'status' => self::DONE, 'ack_at' => now(),
            'ip' => hub_fit((string) ($ev['ip'] ?? request()->ip()), 60),
            'device' => hub_fit((string) ($ev['device'] ?? request()->userAgent()), 200),
            'sign_request_id' => ($ev['sign_request_id'] ?? null) ?: $ack->sign_request_id,
        ]);
        if (($ev['via'] ?? '') === self::VIA_ESIGN) {
            $ack->notes = trim(($ack->notes ? $ack->notes . "\n" : '')
                . 'وُقّع إلكترونياً بواسطة ' . ($ev['signer'] ?? '—') . ' — رمز التحقق ' . ($ev['verify_code'] ?? ''));
        }
    }

    /** قيدُ التدقيق الواحد — الفعلُ من السجلّ، على السجلّ المُقَرّ، بشكل `after` واحد */
    private static function audit(string $module, $row, string $ver, array $ev): void
    {
        $display = hub_mod($module)['display'] ?? 'title';
        hub_audit(self::auditAction($module), $module, $row->id, (string) ($row->{$display} ?? $row->title ?? ''),
            ['after' => self::auditAfter($ver, $ev)]);
    }

    private static function auditAfter(string $ver, array $ev): array
    {
        return array_filter([
            'نسخة السجل' => $ver,
            'القناة' => (string) ($ev['via'] ?? self::VIA_WEB),
            'تحفّظ' => ($ev['note'] ?? null) ?: '—',
            'رمز التحقق' => $ev['verify_code'] ?? null,
            'الموقّع' => $ev['signer'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private static function modelOf(string $module): ?string
    {
        $m = hub_mod($module)['model'] ?? null;

        return $m ? '\\App\\Models\\' . $m : null;
    }
}
