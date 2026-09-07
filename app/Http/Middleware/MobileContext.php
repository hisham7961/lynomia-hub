<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * **حلُّ سياقِ عرضِ الجوال** — `X-Lynomia-Company` / `X-Lynomia-Client` — Mobile
 * Readiness · الطور C · SF-4.
 *
 * ترويستان **لتضييق العرض لا للتخويل** (spec §Headers · INVENTORY §8): تختاران
 * شركةً/عميلاً بعينِه **من داخل** ما يراه المستخدمُ سلفاً — لا توسّعان صلاحيةً
 * أبداً. تُلحَق **بعد** `mobile.session` (التي أرست `Auth::setUser`)، فتقرأ هويّةَ
 * المستخدم المُصادَق ثم تتحقّق:
 *
 *  • القيمةُ ضمن `hub_company_ids()`/`hub_client_ids()` للمستخدم ⇒ تُطبَّق تضييقاً
 *    (سمةُ الطلب `mobile_company`/`mobile_client`).
 *  • القيمةُ **خارج** مجموعته المسموحة ⇒ **تُتجاهَل** (السمةُ `null`) — لا حجبَ
 *    ولا توسيع: يبقى النطاقُ الكامل المسموح، فرمزُ التضييق الفاسد أو البائت لا
 *    يكسر الطلب (خروجٌ/جلساتٌ لا تُشلّ بترويسةٍ بائتة)، و`GET context` (C.1)
 *    يُعلم التطبيقَ بمجموعته الحقيقيّة ليصحّح.
 *  • المستخدمُ غيرُ المقيَّد على هذا البُعد (المالك — يعيد المساعِدُ `null`) ⇒
 *    القيمةُ تُقبَل تضييقاً كما هي: تضييقُ عرضٍ لنفسه، لا يوسّع شيئاً (رمزٌ غيرُ
 *    موجودٍ يُنتج عرضاً فارغاً — لا تسريبَ أبداً).
 *
 * **لا تمسّ `hub_scope`:** العزلُ الصارم (الشركة/العميل/المشروع) يبقى في
 * `hub_scope` كما هو؛ هذا التضييقُ طبقةٌ فوقه (AND) على مجموعةٍ جزئيّةٍ محقَّقةٍ
 * أنها ⊆ المسموح — فبرهانُ «لا توسيع» بنيويّ. عديمةُ الحالة: لا «شركةٌ حاليّة» في
 * جلسة — الترويسةُ تحملها لكلِّ طلب. لا تثق بأيِّ هويّةٍ/دورٍ/ملكيّةٍ يرسلها العميل.
 */
class MobileContext
{
    /** أقصى طولٍ معقولٍ لمعرّفٍ (UUID = 36) — قصٌّ دفاعيٌّ قبل أيّ مطابقة */
    private const ID_MAX = 64;

    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('mobile_company',
            self::resolve($request->header('X-Lynomia-Company'), hub_company_ids()));
        $request->attributes->set('mobile_client',
            self::resolve($request->header('X-Lynomia-Client'), hub_client_ids()));

        return $next($request);
    }

    /**
     * القيمةُ المُضيَّقةُ المُطبَّقة أو `null`: فارغةٌ ⇒ `null`؛ ومقيَّدٌ خارج
     * مجموعته ⇒ `null` (تُتجاهَل، لا توسيع)؛ وإلا القيمةُ نفسُها.
     *
     * @param array<int,string>|null $permitted null = غيرُ مقيَّدٍ على هذا البُعد
     */
    private static function resolve(?string $raw, ?array $permitted): ?string
    {
        $val = trim((string) $raw);
        if ($val === '' || strlen($val) > self::ID_MAX) return null;

        // غيرُ مقيَّد (مالك) ⇒ يقبل القيمةَ تضييقاً لنفسه (لا يوسّع — عرضٌ فارغٌ إن أخطأ)
        if ($permitted === null) return $val;

        // مقيَّد ⇒ لا يُطبَّق إلا ما هو ضمن مجموعته المحقَّقة (⊆ المسموح، فلا توسيع)
        return in_array($val, $permitted, true) ? $val : null;
    }

    // ── قرّاءٌ ومطبِّقٌ مُعادُ الاستعمال للأطوار اللاحقة (C.2/D) ──

    /** الشركةُ النشطةُ المُضيَّقةُ لهذا الطلب (أو null = لا تضييق) */
    public static function company(Request $request): ?string
    {
        $v = $request->attributes->get('mobile_company');

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** العميلُ النشطُ المُضيَّقُ لهذا الطلب (أو null = لا تضييق) */
    public static function client(Request $request): ?string
    {
        $v = $request->attributes->get('mobile_client');

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * **تضييقُ استعلامٍ بالسياق النشط** — طبقةٌ **فوق** `hub_scope` لا بديلٌ عنه:
     * يجب أن يكون الاستعلامُ قد مرّ بـ`hub_scope($q,$module)` أولاً (العزلُ الصارم)،
     * ثم يضيف هذا تضييقَ العرض (شركة/عميل) إن كان نشطاً وللوحدةِ عمودُه. تضييقٌ
     * (AND) على مجموعةٍ محقَّقةٍ ⊆ المسموح — لا يوسّع أبداً.
     */
    public static function apply($query, string $module, ?Request $request = null)
    {
        $request = $request ?? request();

        if (($cid = self::company($request)) !== null && ($ccol = hub_company_col($module))) {
            $query->where($ccol, $cid);
        }
        if (($kid = self::client($request)) !== null && ($kcol = hub_client_col($module))) {
            $query->where($kcol, $kid);
        }

        return $query;
    }
}
