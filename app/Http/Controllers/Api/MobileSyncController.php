<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\MobileContext;
use App\Support\Api;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * **مزامنةُ الجوال التزايُديّة (Mobile Readiness · الطور G · G.1/G.2)** —
 * نقطةٌ واحدةٌ مركّزة `GET sync/{module}` تقود سلوكَها **خريطةُ التصنيف**
 * (`hub_sync_class` · الطور C · SF-5)، ولا محرّكَ ثانٍ فيها: تُعيد استعمالَ
 * نطاقِ `V1Controller` (`hub_scope`)، وتضييقِ السياق (`MobileContext::apply`)،
 * و**مُشكّلِ السجل نفسِه** (`shape` · قناعُ الحقول) الذي يستعمله `apiIndex/apiShow`.
 *
 * **العقدُ حسب التصنيف:**
 *  • `CACHEABLE_INCREMENTAL` — مزامنةٌ تزايُديّةٌ كاملةٌ: سجلّاتٌ مُقنَّعةٌ + شواهدُ
 *    حذفٍ (tombstones) من `deleted_at` + رمزُ تعارُضٍ (كلُّ سجلٍّ يحمل `version`،
 *    والكتابةُ تستعمل If-Match ⇒ VERSION_CONFLICT · G.3).
 *  • `CACHEABLE_READ_ONLY` — قراءةٌ تزايُديّةٌ بمؤشّرٍ **بلا رمز تعارُض** (لا عمودَ
 *    `version` ⇒ لا قفلَ تفاؤليّ)؛ شواهدُ الحذفِ إن كانت الوحدةُ تحذف حذفاً ناعماً.
 *  • `ONLINE_ONLY` / `SENSITIVE_NO_PERSIST` / `NOT_APPLICABLE` — **سياسةٌ صادقةٌ بلا
 *    سجلّات**: `{cacheable:false, records:[]}` — لا استعلامَ، ولا يُبَثُّ سجلٌّ حسّاسٌ
 *    قطّ (خزنةٌ/شرائحُ/بوّاباتٌ لا تُكتَب على خبيئة الجهاز أبداً · spec §Sync).
 *
 * **الأمانُ (لا يُوثَق بالعميل):**
 *  1. المسارُ القابلُ للتخبئة يمرّ بـ`resolveApi` — بوّابةُ v1 نفسُها (`hub_can('v')` +
 *     نطاقُ المفتاح المُعطَّلُ للجوال) — فيخدم **مجموعةَ v1 نفسَها**؛ و`users`
 *     (يستثنيه عقدُ v1 المجمّد ⇒ RESOURCE_NOT_FOUND) لا تُبَثُّ صفوفُه الخام عبر
 *     مُشكّلٍ لم يُصمّم لقناعِ أعمدتها الداخليّة (allowed_ips/prefs/...).
 *  2. الاستعلامُ يمرّ بـ`hub_scope` (عزلُ المستأجر الصارم) **دائماً، كلَّ صفحة** ثم
 *     تضييقِ `MobileContext` (طبقةُ AND ⊆ المسموح) — فلا تُسرَّب سجلٌّ خارجَ نطاق
 *     المستخدم/الشركة/العميل.
 *  3. المؤشّرُ (cursor) **معتِمٌ** (base64 لـ`updated_at`+`id`) يحمل **موضعاً فقط**؛
 *     يُعادُ اشتقاقُ النطاقِ خادميّاً كلَّ صفحة، فمؤشّرٌ مُلاعَبٌ قد يزحزح موضعَ
 *     الاستئنافِ لكنّه **لا يوسّع النطاقَ أبداً**.
 *  4. القناعُ: كلُّ سجلٍّ يمرّ بـ`shape` (كـ`apiIndex` تماماً — الحقلُ المخفيُّ
 *     غائبٌ، وقيمةُ حقلِ `sec` لا تُعادُ لغيرِ المخوَّل)، **وزيادةً** يُجرَّد أيُّ حقلِ
 *     `sec` من قيمته حتى للمخوَّل — فلا سرَّ يبلغ خبيئةَ الجهاز (Phase-C INFO#3).
 *
 * **الحتميّة (CLAUDE.md):** الترتيبُ `orderBy(updated_at)->orderBy(id)` — فلا صفٌّ
 * يُفقَد أو يتكرّر عبر صفحات المؤشّر (قرعةُ ترتيب MySQL/SQLite تُسقِط الصفوفَ صامتاً
 * دونه). والحذفُ الناعمُ يرفع `updated_at` (Eloquent · runSoftDelete) فتركب شواهدُ
 * الحذفِ المؤشّرَ نفسَه — مشيةٌ واحدةٌ لا تفوّت حذفاً.
 *
 * كلُّ ردٍّ يحمل `sync_class` كي يخبّئ العميلُ صحيحاً، و`sync_version` (نسخةُ العقد
 * المشتركة · `MobileContextController::schemaVersion`) كي يعرف متى يعيد المزامنةَ كاملةً.
 */
class MobileSyncController extends V1Controller
{
    /** الأصنافُ التي لا تُبَثُّ سجلّاً قطّ — سياسةٌ صادقةٌ بلا خبيئة */
    private const NON_CACHEABLE = ['ONLINE_ONLY', 'SENSITIVE_NO_PERSIST', 'NOT_APPLICABLE'];

    /**
     * `GET sync/{module}?updated_since=&cursor=&limit=` — المزامنةُ التزايُديّة (G.1).
     */
    public function sync(Request $r, string $module): JsonResponse
    {
        $r->attributes->set('request_source', 'mobile');   // وسمٌ لا تخويل (كبقيّة الجوال)

        $syncClass = hub_sync_class($module);

        // ١) الأصنافُ غيرُ القابلةِ للتخبئة ⇒ سياسةٌ صادقةٌ فوراً: لا استعلامَ، لا سجلّ،
        //    ولا يُلمَسُ نموذجُ وحدةٍ حسّاسة (خزنة/شرائح) البتّة (spec §Sync · G.2).
        //    **بوّابةُ العرض قبل السياسة (Hardener G · Finding#2):** على وحدةٍ حقيقيّةٍ
        //    في السجلّ، مَن لا يملك `hub_can(...,'v')` لا يستبطن حتى تصنيفَها — اتّساقاً
        //    مع `buildSchemaModules` الذي يُسقِط ما لا يراه المستخدم (لا نافذةَ استطلاعٍ
        //    ثانيةٌ أوسعَ من المخطّط). الوحدةُ المجهولةُ (خارجَ السجلّ) تبقى على السياسةِ
        //    الافتراضيّة الحميدة (ONLINE_ONLY) — لا تكشف شيئاً سوى الافتراض العامّ.
        if (in_array($syncClass, self::NON_CACHEABLE, true)) {
            if (hub_mod($module) && ! hub_can(auth()->user(), $module, 'v')) {
                Api::abort(Api::FORBIDDEN, 403, 'لا تملك صلاحيةَ عرضِ هذه الوحدة', ['module' => $module, 'op' => 'v']);
            }

            return $this->policyResponse($module, $syncClass);
        }

        // ٢) الأصنافُ القابلةُ للتخبئة (INCREMENTAL / READ_ONLY): بوّابةُ v1 نفسُها
        //    (سجلُّ الوحدات + `hub_can('v')` + نطاقُ المفتاح المُعطَّلُ للجوال). تستثني
        //    `users` بعقدِ v1 المجمّد ⇒ RESOURCE_NOT_FOUND — فلا تُبَثُّ صفوفُ المستخدمين
        //    الخام عبر مُشكّلِ الأعمال (دليلُ المستخدمين يأتي من context/bootstrap · C).
        [$def, $class] = $this->resolveApi($module, 'v');
        $def['key'] = $module;

        // استبطانُ قدرات النموذج (دفاعيّاً): يقود الشواهدَ ورمزَ التعارُض بلا افتراض.
        $traits      = class_uses_recursive($class);
        $inst        = new $class;
        $softDeletes = in_array(SoftDeletes::class, $traits, true);
        $hasVersions = in_array(\App\Traits\HasVersions::class, $traits, true);
        if (! $inst->usesTimestamps()) {
            // وحدةٌ صُنّفت قابلةً للتخبئة لكنّها بلا `updated_at` (سوءُ تصنيف) — لا مشيةَ
            // تزايُديّةَ لها؛ نسقط إلى سياسةٍ آمنة بدل بثٍّ غيرِ حتميّ.
            return $this->policyResponse($module, 'ONLINE_ONLY');
        }
        // رمزُ التعارُض (G.3) لا يُشتقُّ من **لافتةِ** الصنف وحدَها بل من واقعِ النموذج:
        // INCREMENTAL **و**حاملٌ لصفةِ `HasVersions` فعلاً (Hardener G · Finding#4). فلو
        // أُسيءَ تصنيفُ نموذجٍ بلا نسخةٍ INCREMENTAL، لن يَعِدَ العقدُ بـ`conflict_token:true`
        // ثم تخلو سجلّاتُه من `version` — صدقٌ ببنيةٍ لا باتّساقِ الخريطةِ مع الصفة.
        $incremental = ($syncClass === 'CACHEABLE_INCREMENTAL') && $hasVersions;
        $updatedCol  = $inst->getUpdatedAtColumn();

        // حجمُ الصفحة: افتراضٌ معقولٌ وسقفٌ صلبٌ (تدفّقٌ عالي الحجم لا يُغرق الخادم).
        $default = (int) config('hub.mobile.sync.default_limit', 100);
        $cap     = (int) config('hub.mobile.sync.max_limit', 500);
        $limit   = min($cap, max(1, (int) $r->query('limit', $default)));

        // المؤشّرُ (موضعٌ محتوم) يغلب `updated_since` (أرضيّةٌ أوّليّة). كلاهما معتِمٌ عن النطاق.
        [$curU, $curId] = $this->decodeCursor(hub_str($r->query('cursor')));
        $sinceFloor = null;
        if ($curU === null && ($since = hub_str($r->query('updated_since'))) !== '') {
            try {
                // نُطبّعُ المدخلَ إلى منطقةِ التطبيق (كتخزينِ الأعمدة) فتصحُّ المقارنةُ عبر المحرّكَين
                $sinceFloor = Carbon::parse($since)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return Api::error(Api::VALIDATION_FAILED, 422, 'updated_since ليس تاريخاً صالحاً');
            }
        }

        // الاستعلامُ المُنطَّقُ المُضيَّقُ الحتميّ — السجلّاتُ والشواهدُ في مشيةٍ واحدة.
        // `withTrashed` (للحذفِ الناعم) كي تركب الشواهدُ المؤشّرَ نفسَه؛ وإلا لا شواهد.
        $q = $softDeletes ? $class::withTrashed() : $class::query();
        $q = hub_scope($q, $module);                     // عزلُ المستأجر — يُعادُ كلَّ صفحة (لا يوسّعه مؤشّر)
        $q = MobileContext::apply($q, $module, $r);      // تضييقُ العرض (AND ⊆ المسموح)

        if ($sinceFloor !== null) {
            $q->where($updatedCol, '>=', $sinceFloor);   // أرضيّةٌ شاملةٌ: أفضلُ إعادةُ حدٍّ من فقدِه
        }
        if ($curU !== null) {                            // مفتاحُ المجموعة: ما بعد (updated_at,id) حصراً
            $q->where(function ($w) use ($updatedCol, $curU, $curId) {
                $w->where($updatedCol, '>', $curU)
                  ->orWhere(fn ($w2) => $w2->where($updatedCol, '=', $curU)->where('id', '>', $curId));
            });
        }

        // الحتميّةُ (CLAUDE.md): (updated_at, id) — فاصلُ id يحسم تساويَ الطابعِ بالثانية.
        $q->orderBy($updatedCol)->orderBy('id');

        $rows    = $q->limit($limit + 1)->get();         // +1 لكشفِ has_more دون عدٍّ ثانٍ
        $hasMore = $rows->count() > $limit;
        if ($hasMore) $rows = $rows->slice(0, $limit)->values();

        // تقسيمٌ: شاهدُ حذفٍ (id+deleted_at فقط — لا حقول) أو سجلٌّ مُقنَّع.
        $records    = [];
        $tombstones = [];
        $deletedCol = $softDeletes ? $inst->getDeletedAtColumn() : null;
        foreach ($rows as $row) {
            if ($softDeletes && $row->{$deletedCol} !== null) {
                $tombstones[] = [
                    'id'         => (string) $row->id,
                    'deleted_at' => optional($row->{$deletedCol})->toISOString(),
                ];

                continue;
            }
            $records[] = $this->syncShape($def, $row);
        }

        // المؤشّرُ التالي من موضعِ آخرِ صفٍّ مُعاد (موضعٌ واحدٌ رتيبٌ عبر الحيّ والمحذوف).
        $nextCursor = null;
        if ($rows->isNotEmpty()) {
            $last = $rows->last();
            $nextCursor = $this->encodeCursor((string) $last->getRawOriginal($updatedCol), (string) $last->id);
        } elseif (($raw = hub_str($r->query('cursor'))) !== '') {
            $nextCursor = $raw;   // صفحةٌ فارغةٌ ⇒ نُعيدُ المؤشّرَ الوارد كي يستأنف استطلاعٌ لاحقٌ من هنا
        }

        return response()->json(['data' => [
            'module'         => $module,
            'sync_class'     => $syncClass,
            'cacheable'      => true,
            'conflict_token' => $incremental,        // INCREMENTAL: السجلُّ يحمل version، والكتابةُ If-Match (G.3)
            'records'        => $records,
            'tombstones'     => $tombstones,
            'has_tombstones' => $softDeletes,        // false ⇒ وحدةُ حذفٍ صلب: الغيابُ = إسقاطٌ من الخبيئة
            'next_cursor'    => $nextCursor,
            'has_more'       => $hasMore,
            'sync_version'   => MobileContextController::schemaVersion(),   // نسخةُ العقد المشتركة (C.4)
            'server_time'    => now()->toISOString(),
        ], 'request_id' => Api::requestId()], 200);
    }

    /** سياسةٌ صادقةٌ لصنفٍ غيرِ قابلٍ للتخبئة — الصنفُ يُعادُ كي يخبّئ العميلُ صحيحاً، بلا سجلّ */
    private function policyResponse(string $module, string $syncClass): JsonResponse
    {
        return response()->json(['data' => [
            'module'     => $module,
            'sync_class' => $syncClass,
            'cacheable'  => false,
            'records'    => [],
        ], 'request_id' => Api::requestId()], 200);
    }

    /**
     * قناعُ السجل للمزامنة: يعيد استعمالَ مُشكّلِ v1 (`shape`) — قناعُ الحقول نفسُه
     * الذي في `apiIndex/apiShow` (مخفيٌّ يُسقَط، وقيمةُ `sec` لا تُعادُ لغيرِ المخوَّل،
     * والتاريخُ يوماً) — **وزيادةً** يُجرَّد أيُّ حقلِ `sec` من قيمته حتى للمخوَّل: لا
     * سرَّ يُكتَب على خبيئةِ الجهاز البتّة (Phase-C INFO#3). قناعٌ يزيد لا يُسقِط أبداً.
     */
    private function syncShape(array $def, Model $row): array
    {
        $arr = $this->shape($def, $row);
        $arr = is_array($arr) ? $arr : (array) $arr;
        foreach ($def['fields'] as $f) {
            if (($f['type'] ?? '') === 'sec') unset($arr[$f['col']]);
        }

        return $arr;
    }

    /** ترميزُ مؤشّرٍ معتِم: base64url لـ`{u: updated_at الخام, i: id}` — موضعٌ لا نطاق */
    private function encodeCursor(string $u, string $i): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['u' => $u, 'i' => $i])), '+/', '-_'), '=');
    }

    /**
     * فكُّ المؤشّر — يُتحقَّقُ من بنيته؛ فاسدٌ ⇒ [null,null] (بدايةٌ آمنة، لا خطأ). لا
     * يحمل النطاقَ — فمهما لُوعِبَ يزحزح الموضعَ فقط، والنطاقُ يُعادُ اشتقاقُه خادميّاً.
     *
     * @return array{0:?string,1:?string} [updated_at الخام, id] أو [null,null]
     */
    private function decodeCursor(string $raw): array
    {
        if ($raw === '') return [null, null];
        $json = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($json === false) return [null, null];
        $d = json_decode($json, true);
        if (! is_array($d) || ! isset($d['u'], $d['i'])) return [null, null];

        return [(string) $d['u'], (string) $d['i']];
    }
}
