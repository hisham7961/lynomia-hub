<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Attachment;

/**
 * **استوديو التطبيق: ما يُرى في المتجر — لقطاتُه ووصفُه وجاهزيتُه.**
 *
 * وحدةُ التطبيقات كانت تُتقن ما لا يُرى: أرقامُ الحزم والبناء والشهادات
 * والمستودع. أمّا ما يقرّر عليه المستخدمُ في المتجر — **الصورةُ الأولى
 * والوصف** — فكان حقلَ ملفٍ واحدٍ اسمه «Screenshots / App Store Assets» يقبل
 * ملفاً واحداً يدهسه التالي، ووصفاً في مربّع نصٍّ يُعرض سطراً في جدول حقول.
 *
 * هنا ثلاثة أشياء:
 *
 *   · **المعرض**: لقطاتُ السجل صوراً مرتَّبةً (كلُّ لقطةٍ مرفقٌ حقيقيّ بصلاحياته
 *     وسجل تنزيله) — تُعرض منصّةً كبيرةً وشريطَ مصغّرات، لا قائمةَ ملفات.
 *   · **الوصف**: بحدود المتاجر الفعلية — فوصفٌ يتجاوز أربعة آلاف حرف يُرفض عند
 *     الرفع لا عند الكتابة، ووصفٌ من سطرٍ لا يبيع شيئاً.
 *   · **الجاهزية**: ما ينقص قبل النشر، بندٌ بندٌ وبسببه. المتاجرُ ترفض على
 *     أشياءَ صغيرةٍ معروفة (لقطاتٌ أقلُّ من المطلوب، سياسةُ خصوصيةٍ غائبة،
 *     رابطُ حذف حساب) — ومعرفتُها قبل الرفض أرخصُ من دورة مراجعةٍ ضائعة.
 */
class AppStudio
{
    /** حدودُ المتاجر الحقيقية للوصف (App Store وGoogle Play كلاهما ٤٠٠٠) */
    public const DESC_MAX = 4000;
    public const DESC_MIN = 200;

    /** أقلُّ لقطاتٍ يقبلها كل متجر — أشهرُ أسباب الرفض الشكليّة */
    public const SHOTS_APPLE = 3;
    public const SHOTS_PLAY = 2;

    /** نوعُ الوثيقة الذي تُصنَّف به اللقطات في ملف التطبيق (`config/hub_docs.php`) */
    public const SHOT_KIND = 'screens';

    /**
     * لقطاتُ التطبيق: الصورُ المرفقة على سجله — المصنَّفةُ «لقطات المتاجر»
     * أولاً، ومعها الصورُ غيرُ المصنَّفة (رُفعت قبل أن يوجد التصنيف فلا تُهمَل).
     * الترتيبُ ترتيبُ العرض: `sort` ثم الأقدم فالمفتاح — ثابتٌ على المحرّكين.
     */
    public static function shots(Application $app)
    {
        return Attachment::where('module', 'apps')->where('record_id', $app->id)
            ->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', self::SHOT_KIND))
            ->where('mime', 'like', 'image/%')
            // المصابُ لا يُعرض: بطاقةُ المعرض تُحمّل الصورة تلقائياً في <img>
            ->where(fn ($w) => $w->whereNull('av_status')->orWhere('av_status', '!=', 'infected'))
            ->orderBy('sort')->orderBy('created_at')->orderBy('id')
            ->get();
    }

    /** قراءةُ الوصف بحدود المتاجر — طولُه وما يعنيه */
    public static function description(Application $app): array
    {
        $text = trim((string) ($app->description ?? ''));
        $len = mb_strlen($text);

        return [
            'text'  => $text,
            'len'   => $len,
            'max'   => self::DESC_MAX,
            'over'  => max(0, $len - self::DESC_MAX),
            'tone'  => $len === 0 ? 'bad' : ($len > self::DESC_MAX ? 'bad' : ($len < self::DESC_MIN ? 'wn' : 'ok')),
            'hint'  => match (true) {
                $len === 0 => 'لا وصف — وصفحةُ المتجر بلا وصفٍ لا تُقنع أحداً ولا يجدها بحثُ المتجر.',
                $len > self::DESC_MAX => 'أطولُ من حدّ المتاجر (٤٠٠٠ حرف) بـ' . ($len - self::DESC_MAX)
                    . ' حرفاً — سيُرفض عند الرفع، اختصره الآن لا هناك.',
                $len < self::DESC_MIN => 'قصيرٌ جداً (' . $len . ' حرفاً) — اذكر ماذا يحلّ التطبيقُ ولمن، وأهمَّ ثلاث مزايا.',
                default => 'ضمن حدود المتاجر.',
            },
        ];
    }

    /**
     * جاهزيةُ النشر: بنودٌ يقول كلٌّ منها ماذا ينقص **ولماذا يهمّ**.
     * لا رقمَ مجرّد: النسبةُ تُحسب على البنود الإلزامية وحدها كملف الكيان.
     */
    public static function readiness(Application $app, $shots = null): array
    {
        $shots = $shots ?? self::shots($app);
        $n = $shots->count();
        $desc = self::description($app);
        $plat = mb_strtolower((string) ($app->platform ?? ''));
        // «آيفون/آيباد/iOS» أو منصّةٌ موحّدة (Flutter/React Native) = آبل معنيّة
        $apple = $plat === '' || str_contains($plat, 'ios') || str_contains($plat, 'آبل')
            || str_contains($plat, 'أيفون') || str_contains($plat, 'iphone') || str_contains($plat, 'موحّد')
            || str_contains($plat, 'flutter') || str_contains($plat, 'react');
        $needShots = $apple ? self::SHOTS_APPLE : self::SHOTS_PLAY;

        $items = [
            [
                'key' => 'icon', 'label' => 'أيقونة التطبيق', 'req' => true,
                'ok' => (bool) $app->logo_id,
                'why' => 'الأيقونةُ أولُ ما يُرى في المتجر وفي شاشة الجهاز — وبدونها لا تُقبل الحزمة أصلاً.',
                'fix' => 'ارفعها من حقل «الأيقونة / الشعار» في تعديل التطبيق.',
            ],
            [
                'key' => 'shots', 'label' => 'لقطات المتجر (' . $n . '/' . $needShots . ')', 'req' => true,
                'ok' => $n >= $needShots,
                'why' => 'المتاجرُ تشترط ' . $needShots . ' لقطاتٍ على الأقل، وهي ما يقرّر عليه المستخدمُ التحميل قبل قراءة سطر.',
                'fix' => 'ارفعها دفعةً واحدة من معرض اللقطات أدناه.',
            ],
            [
                'key' => 'desc', 'label' => 'وصف المتجر', 'req' => true,
                'ok' => $desc['len'] >= self::DESC_MIN && $desc['len'] <= self::DESC_MAX,
                'why' => $desc['hint'],
                'fix' => 'حرّره من «وصف التطبيق» في تعديل التطبيق.',
            ],
            [
                'key' => 'privacy', 'label' => 'سياسة الخصوصية', 'req' => true,
                'ok' => (bool) $app->privacy,
                'why' => 'رابطُ سياسةٍ منشور شرطٌ صريحٌ في المتجرين — وغيابُه رفضٌ مباشرٌ لا نقاش فيه.',
                'fix' => 'ضع رابطها في حقل «سياسة الخصوصية».',
            ],
            [
                'key' => 'support', 'label' => 'بريد الدعم', 'req' => true,
                'ok' => (bool) $app->sup_email,
                'why' => 'المتجرُ يطلب قناةَ دعمٍ للمستخدم — وبريدٌ لا يُقرأ أسوأُ من لا شيء.',
                'fix' => 'ضعه في حقل «بريد الدعم».',
            ],
            [
                'key' => 'del_acc', 'label' => 'رابط حذف الحساب', 'req' => false,
                'ok' => (bool) $app->del_acc,
                'why' => 'إن كان في التطبيق تسجيلُ حساب، فآبل وجوجل تشترطان مساراً لحذفه — ورفضُها عليه شائع.',
                'fix' => 'ضع الرابط في حقل «حذف الحساب».',
            ],
            [
                'key' => 'store', 'label' => 'رابط المتجر', 'req' => false,
                'ok' => (bool) ($app->play || $app->appstore || $app->huawei),
                'why' => 'رابطُ الصفحة المنشورة يربط سجلَّك بالواقع — ومنه تُقرأ الأرقام وتُشارَك الصفحة.',
                'fix' => 'ضعه في «Google Play» أو «App Store» أو «AppGallery».',
            ],
        ];

        $req = array_values(array_filter($items, fn ($i) => $i['req']));
        $done = count(array_filter($req, fn ($i) => $i['ok']));
        $pct = $req ? (int) round($done / count($req) * 100) : 100;

        return [
            'items' => $items,
            'pct'   => $pct,
            'need'  => count($req),
            'done'  => $done,
            'missing' => array_values(array_filter($items, fn ($i) => ! $i['ok'] && $i['req'])),
            'tone'  => $pct === 100 ? 'ok' : ($pct >= 60 ? 'wn' : 'bad'),
            'shotsNeeded' => $needShots,
        ];
    }
}
