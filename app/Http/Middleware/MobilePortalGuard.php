<?php

namespace App\Http\Middleware;

use App\Support\Api;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **حارسُ بوّابة الجوال** (تطبيق العميل · §12/§47) — نظيرُ `PortalGuard` الويبيّ
 * على سطح `/api/mobile/v1`، بالعقيدة نفسِها حرفاً: حسابُ العميل الخارجيّ
 * (`account_type=client`) يبلغ **قائمةً بيضاءَ محدودةً** فحسب، وكلُّ ما عداها
 * `RESOURCE_NOT_FOUND` 404 (لا 403: لا نُثبت وجودَ ما لا يخصّه).
 *
 * **لماذا فوق المصفوفة (درسُ C1 نفسُه):** دورُ عميلٍ مُساءُ الضبط يُمنح
 * `servers:v` — أو المصفوفةَ كاملةً — كان قبل هذا الحارس يقرأ الوحداتِ الداخليةَ
 * كلَّها عبر CRUD الجوال العام و`sync/{module}` والبحث، بينما الويبُ يردّه.
 * سطحٌ خامسٌ بلا سياجٍ = «NO weaker parallel login» منقوضةٌ من بابها الخلفيّ.
 * الحارسُ يُغلقها بنيويّاً قبل أن تُستشار المصفوفةُ أصلاً.
 *
 * والمستخدمُ الداخليّ لا يمسّه الحارسُ بتاتاً — يمرّ كما كان (لا انحدارَ على
 * أيّ مسارٍ قائم). مساراتُ `mobile.portal.*` (مساحةُ العميل) عكسُها: للعميل
 * وحدَه، والداخليُّ يُردّ عنها `FORBIDDEN` (له لوحتُه — نظيرُ تحويلةِ الويب).
 *
 * لا محرّكَ عزلٍ ثانٍ: التصنيفُ من `hub_is_client` (العمودُ الصلب)، والعزلُ
 * الأدقُّ للمسموح (أيُّ صفوفٍ/أعمدةٍ) يبقى في `hub_scope`/`hub_field_mode`
 * والقرّاءِ العميليّين بعد الحارس.
 */
class MobilePortalGuard
{
    /**
     * أسماءُ المسارات التي يبلغها حسابُ العميل (أنماطُ `routeIs`) — عدا مساراتِ
     * الوحدات العامة `mobile.resource.*`/`mobile.sync` المحكومةِ بقائمة الوحدات
     * أدناه. متحفِّظةٌ عمداً (الافتراضُ منع):
     *  • الجلسةُ والهويّة: خروج/خروج شامل/جلساتي/تصعيد — كلُّها على النفس.
     *  • الإقلاعُ والسياق: bootstrap/context/navigation/schema — مُكيَّفةٌ للعميل
     *    في متحكّماتها (هندسةُ بوّابةٍ لا شجرةَ إدارة، ومخطّطٌ مقصوصٌ على المسموح).
     *  • الإشعاراتُ: منطَّقةٌ بهويّته الخاصة (`user_id = auth`) بنيويّاً.
     *  • البيتُ والبحث: يتفرّعان عميليّاً في متحكّميهما (بيتُ بوّابةٍ، وبحثٌ
     *    مقصوصٌ على وحدات القائمة).
     *  • التعليقاتُ: حارسُ الهدف يُشدَّد عميليّاً في المتحكّم (وحدةٌ مسموحةٌ أو
     *    محادثةٌ بجمهورٍ عميليّ — والداخليُّ `internal` محجوبٌ قراءةً وكتابةً).
     *  • الملفّاتُ: رفعٌ/تنزيلٌ على سجلاتِ الوحدات المسموحة فقط (تُشدَّد في
     *    المتحكّم فوق `guardRecord`).
     *  • الدفعُ: تسجيلُ رمزِ جهازه وإلغاؤه — ذاتيٌّ صرف. (إدارةُ الدفع للمالك
     *    خارجَ القائمة أصلاً.)
     *  • بوّابةُ العميل `mobile.portal.*`: سطحُه المُكرَّس.
     *
     * المحجوبُ عمداً: `mobile.dm.*` (مراسلاتُ الموظفين الداخلية — نظيرُ الويب)،
     * `mobile.approvals.*` (محرّكُ اعتمادٍ داخليّ)، `mobile.identity.resolve`
     * (ماسحُ أصولٍ داخلية)، `mobile.tracking.*` (تتبّعٌ ميدانيٌّ للموظفين)،
     * `mobile.prefs.*` (مثبّتاتُ وجهاتٍ داخلية)، `mobile.push.admin.*`.
     */
    protected const NAME_ALLOW = [
        'mobile.auth.logout',
        'mobile.auth.logout_all',
        'mobile.auth.sessions.*',
        'mobile.auth.step_up',
        'mobile.context',
        'mobile.bootstrap',
        'mobile.navigation',
        'mobile.schema',
        'mobile.schema.*',
        'mobile.home',
        'mobile.search',
        'mobile.notifications.*',
        'mobile.comments.*',
        'mobile.files.*',
        'mobile.push.register',
        'mobile.push.unregister',
        'mobile.portal.*',
        // «إدارةُ أعضاء العميل» (mobile.clients.members.*) خارجُ القائمة عمداً:
        // لوحةٌ داخليّةٌ لمدير الحساب — العقيدةُ منعٌ فوق المصفوفة، لا اتّكالَ
        // على حارس hub_can(clients,'e') وحدَه في المتحكّم.
    ];

    /**
     * وحداتُ CRUD العام/المزامنة التي يبلغها العميل — **نفسُ** قائمة
     * `PortalGuard::MODULE_ALLOW` الويبية (المصدرُ الواحدُ للعقيدة): الرؤيةُ
     * الأدقُّ (صفوفُه هو) تبقى في `hub_scope` بعد الحارس، والعزلُ يُشدُّ لا يُرخى.
     */
    public const MODULE_ALLOW = [
        'engagements',
        'projects',
        'fin',
    ];

    /** مساراتُ بوّابة العميل — للعميل وحدَه (الداخليُّ له لوحتُه) */
    protected const CLIENT_ONLY = ['mobile.portal.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? auth()->user();

        if (! hub_is_client($user)) {
            // الداخليُّ لا يبلغ سطحَ بوّابة العميل — تكافؤُ تحويلةِ الويب، بردٍّ آليّ
            if ($request->routeIs(...self::CLIENT_ONLY)) {
                return Api::error(Api::FORBIDDEN, 403, 'بوّابةُ العميل لحسابات العملاء — استعمل لوحتك');
            }

            return $next($request);
        }

        if ($this->clientMayReach($request)) {
            return $next($request);
        }

        // 404 لا 403: لا نكشف للعميل وجودَ سطحٍ داخليّ (نظيرُ PortalGuard حرفاً)
        return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود');
    }

    /** هل يبلغ حسابُ العميل هذا الطلب؟ قائمةٌ بيضاءُ صريحة، والافتراضُ منع. */
    protected function clientMayReach(Request $request): bool
    {
        $route = $request->route();
        if ($route === null) {
            return false;
        }

        // ١) الوحداتُ العامة (CRUD/الإجراءات/المزامنة): القرارُ على مُعرِّف الوحدة
        if ($request->routeIs('mobile.resource.*', 'mobile.sync')) {
            $module = (string) ($route->parameter('module') ?? '');

            return self::clientModuleAllowed($module);
        }

        // ٢) بقيّةُ المسارات: بالاسم (تطابقٌ تامٌّ أو بادئةٌ عبر أنماط routeIs)
        return $request->routeIs(...self::NAME_ALLOW);
    }

    /**
     * الموضعُ الواحدُ لسؤال «هل هذه الوحدةُ ضمن سطح العميل؟» — تستهلكه الملفّاتُ
     * والتعليقاتُ والبحثُ (تشديدُ الهدف داخل متحكّماتها) إضافةً إلى الحارس نفسه.
     */
    public static function clientModuleAllowed(string $module): bool
    {
        return in_array($module, self::MODULE_ALLOW, true);
    }
}
