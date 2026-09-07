<?php

namespace App\Support;

use App\Models\IpRule;
use App\Models\VaultSecret;
use Illuminate\Support\Facades\Http;

/**
 * **محوّلُ حافّة الشبكة الصادق** (Work OS · الطور I · WP-I.3 · §39–42 · عائلة C15).
 *
 * حظرُ **التطبيق** (وسيطُ `IpDefense`) هو السلطةُ القاطعة ويعمل بلا أي تبعية.
 * حظرُ **حافّة الشبكة** (Cloudflare/Nginx) يتطلب اعتماداً حقيقياً خارج النظام —
 * وهذا الصنفُ هو الترجمةُ الصادقة لذلك:
 *
 *  • الاعتمادُ **مرجعُ VaultSecret** (`security.edge_cloudflare_secret_ref` يحمل
 *    معرّفَ سجلٍّ في الخزنة) — لا توكن خامّ في الإعدادات ولا في التدقيق أبداً؛
 *    القيمةُ تُجلب من الخزنة لحظةَ النداء ولا تُمرَّر ولا تُسجَّل.
 *  • **التدهورُ الصادق**: محوّلٌ غيرُ معلَن، أو مرجعٌ فارغ/مكسور/بلا سرٍّ، أو أيُّ
 *    عطلِ نداء ⇒ `configured() === false` وبطاقةُ الحالة تقول
 *    «حظرُ حافّة الشبكة: غير مُهيّأ» — **لا حظرَ حافّةٍ زائفاً أبداً** (C15).
 *  • **best-effort**: حين يكتمل الاعتمادُ فعلاً يحاول `push` دفعَ قاعدةِ الحظر
 *    إلى قواعد وصول Cloudflare على مستوى المستخدم، وأيُّ إخفاقٍ يعود false
 *    بصمتٍ — الحظرُ التطبيقيّ لا يتأثر، ولا يُدّعى نجاحٌ لم يُثبَت.
 *
 * > مؤجَّلٌ صراحةً (خطة الطور I): إدارةُ دورةِ حياة قواعد الحافّة كاملةً (إزالةٌ
 * > عند الإلغاء، مزامنةٌ، Nginx) — تهبط حين تتوفر اعتماداتُ تشغيلٍ حقيقية.
 */
class EdgeDefense
{
    /** المحوّلات المعروفة — قائمةُ سماحٍ في التطبيق (غيرُ المعروف = غير مُهيّأ) */
    public const ADAPTERS = ['cloudflare'];

    /** نقطةُ قواعد الوصول على مستوى المستخدم — لا تحتاج معرّفَ منطقةٍ إضافياً */
    protected const CF_ACCESS_RULES = 'https://api.cloudflare.com/client/v4/user/firewall/access_rules/rules';

    /** اسمُ المحوّل المعلَن — فارغٌ افتراضاً = لا حافّةَ إطلاقاً */
    public static function adapter(): string
    {
        return trim((string) setting('security.edge_adapter', ''));
    }

    /**
     * هل الحافّةُ مُهيّأةٌ فعلاً؟ — محوّلٌ معروف + مرجعُ VaultSecret يقابل سجلَّ
     * خزنةٍ حيّاً بسرٍّ غيرِ فارغ. أيُّ نقصٍ أو عطلٍ = false (تدهورٌ صادق).
     * لا يُعاد السرُّ ولا يُلمَس هنا إلا وجوداً — القيمةُ تُجلب في `push` وحدَه.
     */
    public static function configured(): bool
    {
        try {
            if (self::adapter() !== 'cloudflare') return false;

            $ref = trim((string) setting('security.edge_cloudflare_secret_ref', ''));
            if ($ref === '') return false;

            $secret = VaultSecret::query()->find($ref);   // SoftDeletes: المحذوفُ لا يُعاد

            return $secret !== null && trim((string) $secret->secret_cipher) !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * بطاقةُ الحالة الصادقة (تستهلكها شاشةُ security.blocks):
     * التطبيقُ **فعّالٌ دائماً** — هو السلطةُ ويعمل بلا تبعية؛ والحافّةُ
     * «غير مُهيّأ» ما لم يكتمل الاعتمادُ الحقيقيّ. لا قيمةَ سرٍّ في الناتج.
     */
    public static function status(): array
    {
        $configured = self::configured();

        return [
            'app' => [
                'state' => 'active', 'label' => 'فعّال',
                'why'   => 'السلطةُ القاطعة — وسيطُ IpDefense على كل طلبِ ويب وAPI، بلا أي تبعيةٍ خارجية',
            ],
            'edge' => [
                'state'   => $configured ? 'ready' : 'not_configured',
                'label'   => $configured ? 'مُهيّأ (أفضل جهد — Cloudflare)' : 'غير مُهيّأ',
                'adapter' => self::adapter(),
                'why'     => $configured
                    ? 'اعتمادُ Cloudflare مرجعُ خزنةٍ حيّ — تُدفَع قواعدُ الحظر للحافّة بأفضل جهدٍ، والتطبيقُ يبقى السلطة'
                    : 'يتطلب اعتمادَ Cloudflare/Nginx حقيقياً (security.edge_adapter + مرجع VaultSecret) — لا يُدّعى حظرُ حافّةٍ لم يقع',
            ],
        ];
    }

    /**
     * دفعُ قاعدةِ حظرٍ إلى الحافّة — **أفضلُ جهدٍ لا وعدٌ**: غيرُ المُهيّأ يعود
     * false فوراً بلا نداء؛ والنداءُ الفعليّ أيُّ إخفاقٍ فيه (شبكةً أو ردّاً)
     * يعود false بصمت. لا يُسجَّل التوكن ولا يظهر في أي أثرٍ أو استثناء.
     */
    public static function push(IpRule $rule): bool
    {
        try {
            if ($rule->mode !== 'block' || ! self::configured()) return false;

            $ref = trim((string) setting('security.edge_cloudflare_secret_ref', ''));
            $token = (string) VaultSecret::query()->find($ref)?->secret_cipher;
            if (trim($token) === '') return false;

            $resp = Http::withToken($token)->timeout(5)->post(self::CF_ACCESS_RULES, [
                'mode'          => 'block',
                'configuration' => [
                    'target' => $rule->is_cidr ? 'ip_range' : 'ip',
                    'value'  => (string) $rule->ip,
                ],
                'notes' => 'Lynomia adaptive defense — rule ' . $rule->id,
            ]);

            return $resp->successful() && (bool) data_get($resp->json(), 'success');
        } catch (\Throwable $e) {
            return false;   // إخفاقُ الحافّة لا يمسّ الحظرَ التطبيقيّ ولا يُدّعى عكسُه
        }
    }
}
