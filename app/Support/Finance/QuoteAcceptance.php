<?php

namespace App\Support\Finance;

use App\Models\Attachment;
use App\Models\Quote;
use App\Models\SignRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * **محرّكُ قبولِ العرض الواحد** (TECH_DEBT #29 · ARCH-06..09).
 *
 * كان للقبول بابان بقاعدتين: زرُّ «قبول العميل» في الويب (`QuoteController::setStatus`)
 * يحترم قفلَ حقل الحالة ويؤرشف النسخةَ المقبولة، والتوقيعُ الإلكترونيّ
 * (`EsignFinalizer::completeLinked`) لا يؤرشف ولا يحترم القفلَ لكنّه يحفظ رمزَ التحقق
 * ويُشعر المالكين. فقبولٌ واحدٌ يترك أثرين مختلفين بحسب الباب.
 *
 * الآن كلُّ قبولٍ يمرّ بـ`accept()` فيُخرج الأثرَ نفسَه:
 *  - **القفل**: الفاعلُ (المستخدمُ في الويب، ومُصدِرُ طلبِ التوقيع في التوقيع) يملك
 *    تعديلَ العروض وحقلُ الحالة ليس مقفولاً لدوره — وإلا ٤٠٣.
 *  - **معاملةٌ على صفٍّ مقفول**: قلبُ الحالة + ختمُ القبول + سجلُّ «كيف قُبل» في meta
 *    + الأرشيفُ + قيدُ التدقيق + الإشعاراتُ — كلُّها أو لا شيء.
 *  - **متكرّرٌ بلا أثر**: عرضٌ «مقبول/محوّل» لا يُقبل ثانيةً (لا أرشيفَ ولا إشعارَ ولا
 *    تدقيقَ مكرّر، ولا يُعاد «المحوّل» إلى «مقبول»).
 *  - الحدثُ الدلاليّ (`quote.accepted`) يُطلق بعد الالتزام — كما كان في البابين.
 */
final class QuoteAcceptance
{
    /** فعلُ التدقيق الواحد لكلّ قبول — والبابُ في الاسم المرافق (ويب/توقيع) */
    public const AUDIT_ACTION = 'قبول عرض سعر';

    /** نوعُ إشعار المعتمدين بالقبول (عرضُ `notifications_hub.kind` ١٢٠) */
    public const NOTIFY_KIND = 'quote_accepted';

    /** الحالاتُ التي يُعدّ فيها العرضُ مقبولاً فعلاً — القبولُ عليها لا يُعاد */
    public const ACCEPTED = ['مقبول', 'محوّل'];

    public const VIA_WEB = 'web';
    public const VIA_ESIGN = 'esign';

    /** قبولٌ من تطبيق الجوال باسم المستخدم (خطّة التطبيق 4.6) — البابُ نفسُه بوسمٍ صادق */
    public const VIA_MOBILE = 'mobile';

    /**
     * سببُ منع الفاعل من القبول، أو null إن جاز. القاعدةُ عينُها لكلّ باب:
     * صلاحيةُ تعديل العروض + حقلُ الحالة غيرُ مقفولٍ لدوره (hub_field_mode).
     */
    public static function denial(?User $actor): ?string
    {
        if (! $actor) return 'لا جهةَ داخليةً مخوَّلةً بالقبول (مُصدِرُ الطلب غيرُ موجود)';
        if (! hub_can($actor, 'quotes', 'e')) return 'قبولُ العرض يتطلب صلاحيةَ تعديل العروض';
        if (hub_field_mode($actor, 'quotes', 'status') !== '') {
            return 'حقلُ الحالة مقفولٌ لدور ' . $actor->name . ' (قراءة فقط) — لا يُقبل العرضُ باسمه';
        }

        return null;
    }

    /** قبولٌ من زرّ الويب باسم المستخدم الحاليّ */
    public static function byUser(Quote $q, User $user, string $via = self::VIA_WEB): bool
    {
        return self::accept($q, $user, ['via' => $via === self::VIA_MOBILE ? self::VIA_MOBILE : self::VIA_WEB,
            'by' => $user->name]);
    }

    /**
     * قبولُ العميل بالتوقيع الإلكترونيّ: الفاعلُ المخوِّلُ هو **مُصدِرُ الطلب** — فمن لا
     * يملك قلبَ الحالة من الزرّ لا يلتفّ عليه بطلبِ توقيع. المنعُ هنا لا يُفشل التوقيعَ
     * المحفوظ: يُبلَّغ المعتمدون ليقبلوا يدوياً.
     */
    public static function bySignature(Quote $q, SignRequest $req, string $signer): bool
    {
        if (in_array($q->status, self::ACCEPTED, true)) return false;

        $issuer = $req->created_by ? User::whereNull('deleted_at')->find($req->created_by) : null;
        if ($why = self::denial($issuer)) {
            self::notify($q, '✍️ وقّع العميلُ على العرض «' . ($q->title ?: $q->doc_no) . '» (' . $q->doc_no
                . ') [' . $req->verify_code . '] ولم يُقبل آلياً: ' . $why . ' — راجعه واقبله يدوياً.', null);

            return false;
        }

        return self::accept($q, $issuer, [
            'via' => self::VIA_ESIGN, 'by' => $signer,
            'envelope_id' => $req->id, 'verify_code' => $req->verify_code,
            'signer' => $signer, 'issued_by' => $issuer->id,
        ]);
    }

    /**
     * **القبولُ الواحد.** يعيد true إن قُبل العرضُ بهذا النداء، وfalse إن كان مقبولاً
     * من قبل (بلا أيّ أثر). $how: via (web|esign) · by (اسمُ القابل) · وأدلّةُ الباب.
     */
    public static function accept(Quote $q, ?User $actor, array $how): bool
    {
        if ($why = self::denial($actor)) abort(403, $why);
        $via = (string) ($how['via'] ?? self::VIA_WEB);

        $accepted = DB::transaction(function () use (&$q, $actor, $how, $via) {
            $q = Quote::whereKey($q->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($q->status, self::ACCEPTED, true)) return false;

            $meta = (array) $q->meta;
            $record = array_filter([
                'via' => $via,
                'at' => now()->toIso8601String(),
                'user_id' => $via !== self::VIA_ESIGN ? $actor?->id : null,
                'envelope_id' => $how['envelope_id'] ?? null,
                'verify_code' => $how['verify_code'] ?? null,
                'signer' => $how['signer'] ?? null,
                'issued_by' => $how['issued_by'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            $meta['acceptance'] = $record;
            // العقدُ القائم: رمزُ التحقق في accept_sign يبقى كما كان لقارئيه
            if ($via === self::VIA_ESIGN && ! empty($how['verify_code'])) $meta['accept_sign'] = $how['verify_code'];

            $q->status = 'مقبول';
            $q->accepted_at = now();   // ختمُ هذا القبول (وقبولٌ بعد تراجعٍ قبولٌ جديد)
            $q->accepted_by = mb_substr((string) ($how['by'] ?? $actor?->name ?? ''), 0, 200) ?: null;
            $q->meta = $meta;
            $q->save();   // Eloquent: Auditable + HasVersions — لقطةُ النسخة المقبولة

            // أرشفةُ النسخة المقبولة — الوثيقةُ التي وافق عليها العميلُ تُجمَّد كما هي،
            // ومؤشّرُها في meta (حفظٌ هادئ: مؤشّرٌ لا تعديلٌ تجاريّ يرفع النسخة)
            if ($att = self::archive($q, 'مقبول', $actor?->id)) {
                $meta['acceptance']['archive_id'] = $att->id;
                $q->forceFill(['meta' => $meta])->saveQuietly();
            }

            hub_audit(self::AUDIT_ACTION, 'quotes', $q->id, $q->doc_no . ' — '
                . ($via === self::VIA_ESIGN ? 'بتوقيعٍ إلكترونيّ [' . ($how['verify_code'] ?? '') . '] — '
                    : ($via === self::VIA_MOBILE ? 'من تطبيق الجوال — ' : 'من الويب — '))
                . (string) $q->accepted_by);

            self::notify($q, $via === self::VIA_ESIGN
                ? '🎉 قَبِل العميلُ العرضَ «' . ($q->title ?: $q->doc_no) . '» بتوقيعٍ إلكترونيّ [' . ($how['verify_code'] ?? '') . ']'
                : '🎉 قُبل العرضُ «' . ($q->title ?: $q->doc_no) . '» — سجّله ' . (string) $q->accepted_by,
                $via !== self::VIA_ESIGN ? $actor?->id : null);

            return true;
        });

        // الحدثُ الدلاليّ بعد الالتزام — حِزمُ الاستجابة والتنبيهات تقرأ حالةً ملتزَمة
        if ($accepted) \App\Support\Platform\FlowRunner::fire('status', 'quotes', $q, 'مقبول');

        return $accepted;
    }

    /**
     * إشعارُ المعتمدين **المنطَّقين على العرض** (hub_approvers_for): لا يُسرَّب عنوانُ
     * العرض لمعتمدٍ معزولٍ عن شركته/عميله. والقابلُ نفسُه لا يُشعَر بفعله.
     */
    protected static function notify(Quote $q, string $text, ?string $except): void
    {
        foreach (array_unique(hub_approvers_for('quotes', $q->id)) as $uid) {
            if ($uid && $uid !== $except) hub_notify($uid, self::NOTIFY_KIND, $text, 'quotes', $q->id);
        }
    }

    /**
     * أرشفةُ العرض الاحترافيّ كمرفقٍ ثابتٍ على السجل (نمط `archiveSignedCopy`):
     * PDF إن توفّرت المكتبة وإلا HTML — فيبقى أثرٌ للمُصدَر دوماً. لا يُفشل الفعلَ.
     */
    public static function archive(Quote $q, string $tag, ?string $uploadedBy): ?Attachment
    {
        try {
            $html = \App\Support\Documents\Proposal::html($q->fresh());
            $pdf = \App\Support\Documents\DocRenderer::pdf($html, 'عرض ' . $q->doc_no);
            [$blob, $mime, $ext] = $pdf
                ? [$pdf, 'application/pdf', 'pdf']
                : [$html, 'text/html', 'html'];
            $path = 'hub/att/quote-' . $q->doc_no . '-v' . (int) $q->version . '-' . uniqid() . '.' . $ext;
            Storage::disk('local')->put($path, $blob);

            return Attachment::create([
                'module' => 'quotes', 'record_id' => $q->id,
                'field' => 'عرض مؤرشَف — ' . $tag . ' (نسخة ' . (int) $q->version . ')',
                'disk' => 'local', 'path' => $path,
                'original_name' => 'proposal-' . $q->doc_no . '-v' . (int) $q->version . '.' . $ext,
                'mime' => $mime, 'size' => strlen($blob),
                'checksum' => hash('sha256', $blob),
                'uploaded_by' => $uploadedBy,
            ]);
        } catch (\Throwable $e) {
            report($e);   // الأرشفةُ إضافةٌ — فشلُها لا يُفشل الفعل

            return null;
        }
    }
}
