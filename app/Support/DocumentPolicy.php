<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **سياسةُ الوصولِ للوثائق على مستوى المورد** (Permissions 360 · وثائق · المستوى 5/6).
 *
 * **لا محرّكُ توثيقٍ ثانٍ:** القرارُ الأساسُ يبقى في المحرّكِ القائم — رؤيةُ السجلِّ الأمِّ
 * ونطاقُه وحدُّ العميل عبر `AttachmentService::guardRecord` (يُستدعى قبلَ هذه الطبقةِ في
 * سكّةِ التنزيلِ/المعاينة). هنا **طبقةُ استثناءٍ صريحةٍ فوقَه فقط**: يمنحُ المالكُ سماحاً أو
 * منعاً **لوثيقةٍ بعينها** لدورٍ أو مستخدمٍ — مربوطاً بمعرِّفِ الموردِ المستقرّ (لا باسم الملف).
 *
 * **الأسبقيّةُ الحتميّة** (تُطبَّق بعدَ نجاحِ guardRecord):
 *   1) المالك ⇒ سماح.
 *   2) قاعدةُ المستخدمِ الصريحة (الأخصّ): منعٌ يعلو سماحاً.
 *   3) قاعدةُ الدورِ الصريحة: منعٌ يعلو سماحاً.
 *   4) الافتراض ⇒ سماح (السجلُّ الأمُّ مرئيٌّ أصلاً؛ حواجزُ الفئةِ الحسّاسةِ في طورٍ لاحق).
 *
 * فلا توسيعٌ صامتٌ: بلا قاعدةٍ صريحة، يبقى السلوكُ كما كان (وصولٌ بصلاحيةِ السجل).
 */
class DocumentPolicy
{
    /** مذكّرةُ قواعدِ المرفقاتِ للطلبِ الواحد — منعُ N+1 عند سردِ مرفقاتٍ كثيرة */
    protected static array $memo = [];

    /** قواعدُ الوصولِ لهذا المرفق (مصفوفةٌ من صفوفٍ خام) — مخزّنةٌ للطلب */
    public static function rulesFor(string $attachmentId): array
    {
        if (array_key_exists($attachmentId, self::$memo)) return self::$memo[$attachmentId];
        if (! Schema::hasTable('document_access_rules')) return self::$memo[$attachmentId] = [];

        return self::$memo[$attachmentId] = DB::table('document_access_rules')
            ->where('resource_type', 'attachment')->where('resource_id', $attachmentId)
            ->get(['principal_type', 'principal_id', 'effect', 'action'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * **تحميلٌ دفعيٌّ** لقواعدِ مجموعةِ مرفقاتٍ في استعلامٍ واحد — يملأ المذكّرةَ مسبقاً
     * كي لا يضربَ سردُ قائمةٍ (فلترةُ الرؤية/العدّ) قاعدةَ البيانات مرّةً لكلِّ مرفق.
     * المعرِّفاتُ التي لا قاعدةَ لها تُخزَّن مصفوفةً فارغةً فلا تُعاد استعلاماً.
     *
     * @param  iterable<string>  $attachmentIds
     */
    public static function primeMemo(iterable $attachmentIds): void
    {
        $ids = [];
        foreach ($attachmentIds as $id) {
            $id = (string) $id;
            if ($id !== '' && ! array_key_exists($id, self::$memo)) $ids[$id] = true;
        }
        if (! $ids) return;
        if (! Schema::hasTable('document_access_rules')) {
            foreach ($ids as $id => $_) self::$memo[$id] = [];

            return;
        }

        $grouped = DB::table('document_access_rules')
            ->where('resource_type', 'attachment')
            ->whereIn('resource_id', array_keys($ids))
            ->get(['resource_id', 'principal_type', 'principal_id', 'effect', 'action'])
            ->groupBy('resource_id');

        foreach ($ids as $id => $_) {
            self::$memo[$id] = ($grouped[$id] ?? collect())
                ->map(fn ($r) => ['principal_type' => $r->principal_type, 'principal_id' => $r->principal_id,
                    'effect' => $r->effect, 'action' => $r->action])->all();
        }
    }

    /** يُبطِل المذكّرةَ لمرفقٍ (يُستدعى بعدَ إضافةِ/حذفِ قاعدة) */
    public static function forget(?string $attachmentId = null): void
    {
        if ($attachmentId === null) self::$memo = [];
        else unset(self::$memo[$attachmentId]);
    }

    /**
     * **مرئيٌّ في القوائم؟** الوثيقةُ تُعرَض إن أمكن للمستخدمِ معاينتُها **أو** تنزيلُها.
     * تُخفى فقط حين يُمنَع كلاهما صراحةً (قاعدةُ منعٍ `*`) — فلا يُكشَف اسمُها ولا وجودُها
     * ولا تُعدُّ. (المالكُ يتجاوز، فيرى الكلَّ ويُدير القواعد.)
     */
    public static function listable(?User $user, Attachment $a): bool
    {
        return self::allows($user, $a, 'preview') || self::allows($user, $a, 'download');
    }

    /**
     * يُرشِّح مجموعةَ مرفقاتٍ إلى ما يجوزُ لهذا المستخدمِ **رؤيتُه في قائمة** — بتحميلٍ
     * دفعيٍّ للقواعدِ أوّلاً (بلا N+1). يُعيد نوعَ المجموعةِ نفسَه (Eloquent/Support).
     */
    public static function filterListable(?User $user, $attachments)
    {
        // بلا مستخدمٍ لا قاعدةَ طرفٍ تُقيَّم (الطبقةُ لكلِّ طرفٍ بعينه)، والمحتوى نفسُه
        // يظلّ محروساً بالمصادقةِ عند att.dl/att.view. فلا نُخفي القائمةَ كلَّها لغياب طرف.
        if (! $user) return $attachments;
        self::primeMemo($attachments->pluck('id'));

        return $attachments->filter(fn ($a) => self::listable($user, $a))->values();
    }

    /**
     * **القرارُ الاستثنائيُّ المجرَّد** (لا يُغني عن guardRecord — يُطبَّق فوقَه).
     * @return array{allowed:bool, state:string, reason:string}
     */
    public static function decide(?User $user, Attachment $a, string $action = 'download'): array
    {
        if (! $user) return ['allowed' => false, 'state' => 'DENIED_NO_USER', 'reason' => 'لا مستخدم'];
        if (hub_is_owner($user)) {
            return ['allowed' => true, 'state' => 'OWNER', 'reason' => 'المالكُ يتجاوز قواعدَ الوثائق'];
        }

        $rules = self::rulesFor((string) $a->id);
        $match = fn (array $r) => in_array($r['action'], ['*', $action], true);

        // 1) قواعدُ المستخدمِ الصريحة (الأخصّ) — المنعُ يعلو
        $userRules = array_filter($rules, fn ($r) => $r['principal_type'] === 'user'
            && (string) $r['principal_id'] === (string) $user->id && $match($r));
        if ($userRules) {
            foreach ($userRules as $r) if ($r['effect'] === 'deny') {
                return ['allowed' => false, 'state' => 'DENIED_DOC_USER', 'reason' => 'منعٌ صريحٌ لهذه الوثيقةِ على المستخدم'];
            }
            return ['allowed' => true, 'state' => 'ALLOWED_DOC_USER', 'reason' => 'سماحٌ صريحٌ لهذه الوثيقةِ على المستخدم'];
        }

        // 2) قواعدُ الدورِ الصريحة — المنعُ يعلو
        $roleId = $user->role_id ?? ($user->role->id ?? null);
        if ($roleId !== null) {
            $roleRules = array_filter($rules, fn ($r) => $r['principal_type'] === 'role'
                && (string) $r['principal_id'] === (string) $roleId && $match($r));
            if ($roleRules) {
                foreach ($roleRules as $r) if ($r['effect'] === 'deny') {
                    return ['allowed' => false, 'state' => 'DENIED_DOC_ROLE', 'reason' => 'منعٌ صريحٌ لهذه الوثيقةِ على دورِ المستخدم'];
                }
                return ['allowed' => true, 'state' => 'ALLOWED_DOC_ROLE', 'reason' => 'سماحٌ صريحٌ لهذه الوثيقةِ على دورِ المستخدم'];
            }
        }

        // 3) الافتراض: السجلُّ الأمُّ مرئيٌّ (guardRecord مرّ) ⇒ سماح
        return ['allowed' => true, 'state' => 'ALLOWED_INHERITED', 'reason' => 'وراثةٌ من رؤيةِ السجلِّ الأمِّ (لا قاعدةَ وثيقةٍ صريحة)'];
    }

    /** قرارٌ منطقيٌّ مجرَّد (للواجهةِ/البحثِ/العدّ) — لا يرمي */
    public static function allows(?User $user, Attachment $a, string $action = 'download'): bool
    {
        return self::decide($user, $a, $action)['allowed'];
    }

    /**
     * **يفرض** الطبقةَ الاستثنائيّة — يُستدعى **بعدَ** `guardRecord` في سكّةِ التنزيل/المعاينة.
     * منعٌ صريحٌ ⇒ ٤٠٣ (الوجودُ مُثبَتٌ أصلاً بمرورِ guardRecord، فلا داعيَ لإخفائه).
     */
    public static function authorize(?User $user, Attachment $a, string $action = 'download'): void
    {
        $d = self::decide($user, $a, $action);
        abort_unless($d['allowed'], 403, 'وصولُ هذه الوثيقةِ مقيَّدٌ بقاعدةٍ صريحة');
    }
}
