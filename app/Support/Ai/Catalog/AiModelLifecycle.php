<?php

namespace App\Support\Ai\Catalog;

use App\Models\AiModel;
use App\Models\AiProfileModel;
use App\Models\AiProvider;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Gateway\LiteLlmAdmin;
use App\Support\Ai\Routing\AiProfiles;

/**
 * **دورةُ حياةِ النموذج — وبابُ الخروجِ منها.**
 *
 * ── **العيبُ الذي وُلد منه هذا الصنف** ──
 *
 * بُنيت الدورةُ إضافةً وتبنّياً وتهيئةً وتفعيلاً وفحصاً — **بلا إزالة**.
 * `LiteLlmAdmin::deleteModel()` مكتوبةٌ منذ W3 و**لا مُستدعيَ لها**: لا مسارَ
 * ولا مُتحكِّمَ ولا زرّ. فمن تبنّى نموذجاً لا يناسبه وجد نفسَه محبوساً معه.
 *
 * ── **وثلاثةُ ثوابتَ تحكم الإزالة** ──
 *
 *  ① **لا حذفَ أعمى.** النموذجُ قد يكون حلقةً في سلسلةِ توجيه، أو الطريقَ
 *     الوحيدَ لغرضِ «اسأل Hub»، أو مُفعَّلاً يخدم طلباتٍ الآن. فتُعرَض الموانعُ
 *     **بأسمائِها** ويُعطى المديرُ مساراً لفكِّها — لا رسالةَ «تعذّر».
 *
 *  ② **الطرفانِ أو لا شيء.** النشرُ عند البوّابةِ يُلغى **أوّلاً**، ثمّ يسقط
 *     الصفُّ محليّاً. فلو فشل الأوّلُ بقي الثاني — **ويتيمٌ في Hub خيرٌ من
 *     يتيمٍ عند البوّابة**: الأوّلُ نراه ونُصالحه، والثاني يستهلك تهيئةً لا
 *     يعرف أحدٌ من أين جاءت.
 *
 *  ③ **الإعادةُ لا تُنتج أثراً ثانياً.** طلبٌ مكرَّرٌ، أو نموذجٌ زال من
 *     البوّابةِ وبقي عندنا، أو انقطاعٌ في المنتصف — كلُّها تنتهي إلى الحالِ
 *     نفسِه ولا تُسقِط العمليّة.
 */
final class AiModelLifecycle
{
    /** شكلُ الجوابِ الموحَّد */
    public const SHAPE = ['ok', 'error'];

    /** أصنافُ الموانع — تُعرَض للمدير ولا تُخترَع في كلِّ موضع */
    public const BLOCK_PROFILE = 'profile';

    public const BLOCK_ASK = 'ask_default';

    public const BLOCK_ENABLED = 'enabled';

    // ═══ ① ما الذي يتعلّق بهذا النموذج؟ ═══

    /**
     * **جردُ التبعيّاتِ قبل أيِّ إزالة.**
     *
     * والتمييزُ بين **مانعٍ** و**أثرٍ** مقصود: المانعُ شيءٌ يكسره الحذفُ الآن،
     * والأثرُ سِجلٌّ يبقى شاهداً ولا يمنع شيئاً. وخلطُهما يُنتج إمّا حذفاً
     * يكسر الإنتاج، وإمّا منعاً أبديّاً لأنّ للنموذجِ سجلَّ فحصٍ قديم.
     *
     * @return array{
     *     removable: bool,
     *     blocking: list<array{kind: string, label: string, hint: string}>,
     *     notes: list<string>
     * }
     */
    public static function dependencies(AiModel $model): array
    {
        $blocking = [];
        $notes    = [];

        // ── ① المُفعَّلُ يخدم طلباتٍ الآن ──
        if ((bool) $model->enabled) {
            $blocking[] = [
                'kind'  => self::BLOCK_ENABLED,
                'label' => 'النموذجُ مُفعَّل',
                'hint'  => 'أطفئه أوّلاً — فلا يختفي نموذجٌ من تحتِ طلبٍ جارٍ',
            ];
        }

        // ── ② حلقاتُ سلاسلِ التوجيه ──
        $links = AiProfileModel::query()->with('profile')
            ->where('model_id', $model->id)->orderBy('id')->get();

        foreach ($links as $link) {
            $profile = $link->profile;
            $blocking[] = [
                'kind'  => self::BLOCK_PROFILE,
                'label' => 'مربوطٌ بغرضِ «' . (string) ($profile?->label ?? $profile?->key ?? '—') . '»'
                           . ((int) $link->rank === 0 ? ' — وهو **الأساسيّ** فيه' : ' — احتياطاً'),
                'hint'  => 'فُكَّ الارتباطَ من «التوجيهُ والأغراض» أو اختر بديلاً',
            ];

            // ── ③ وغرضُ «اسأل Hub» حالةٌ خاصّة: كسرُه يُطفئ الميزةَ كلَّها ──
            if ($profile !== null && (string) $profile->key === AskPolicy::profileKey()
                && self::isOnlyLinkOf($profile->id, $link->id)) {
                $blocking[] = [
                    'kind'  => self::BLOCK_ASK,
                    'label' => 'وهو **الطريقُ الوحيد** لغرضِ «اسأل Hub» الحاليّ',
                    'hint'  => 'اربط بديلاً بالغرضِ أوّلاً، أو غيّر غرضَ «اسأل Hub»',
                ];
            }
        }

        // ── ④ آثارٌ تُذكَر ولا تمنع ──
        if ($model->last_probe_at !== null) {
            $notes[] = 'له سجلُّ فحصٍ سابق — يبقى في الأثرِ بعد الإزالة';
        }
        if ((string) $model->health === 'FAILED') {
            $notes[] = 'آخرُ فحصٍ له فشل — وهذا لا يمنع إزالتَه';
        }

        return ['removable' => $blocking === [], 'blocking' => $blocking, 'notes' => $notes];
    }

    /** أهي الحلقةُ الوحيدةُ العاملةُ في هذا الغرض؟ */
    private static function isOnlyLinkOf(string $profileId, string $linkId): bool
    {
        return AiProfileModel::query()->where('profile_id', $profileId)
            ->where('id', '!=', $linkId)->count() === 0;
    }

    // ═══ ② فكُّ الارتباط ═══

    /**
     * **يُخرِج النموذجَ من كلِّ سلسلةٍ يقع فيها** — ولا يحذفه.
     *
     * وهو خطوةٌ مستقلّةٌ عمداً: فكُّ الارتباطِ قرارٌ قابلٌ للتراجع، والحذفُ
     * ليس كذلك. فلا يُدمَجان في زرٍّ واحد.
     *
     * @return array{ok: bool, detached: int, error: ?string}
     */
    public static function unlink(AiModel $model): array
    {
        $links = AiProfileModel::query()->where('model_id', $model->id)->orderBy('id')->get();

        $n = 0;
        foreach ($links as $link) {
            $res = AiProfiles::detach($link);
            if ($res['ok']) $n++;
        }

        if ($n > 0) {
            hub_audit('فكُّ ارتباطِ نموذجٍ من الأغراض', AiModel::MODULE, (string) $model->id,
                (string) $model->litellm_model_name, ['after' => ['detached' => $n]]);
        }

        return ['ok' => true, 'detached' => $n, 'error' => null];
    }

    // ═══ ③ الإزالة ═══

    /**
     * **إزالةُ نموذجٍ — الطرفانِ أو لا شيء.**
     *
     * الترتيبُ مقصود:
     *
     *  ① **الموانعُ تُقرأ أوّلاً** — فلا نداءَ شبكةٍ لنموذجٍ لن يُحذَف.
     *  ② **يُلتمَس معرّفُ النشرِ من سجلِّ البوّابة** — لأنّ مسارَ الحذفِ عندها
     *     يأخذ **معرّفَ النشرِ** لا اسمَه. وغيابُه يعني أنّه زال سلفاً.
     *  ③ **يُلغى النشر**، فإن فشل وُقف كلُّ شيء.
     *  ④ **ثمّ يسقط الصفُّ** حذفاً ناعماً — فالأثرُ والأرقامُ لا تُبتَر.
     *
     * @return array{ok: bool, gateway: string, error: ?string}
     */
    public static function remove(AiModel $model, bool $skipDependencyCheck = false): array
    {
        // **إعادةُ الطلبِ على صفٍّ زال ليست عطلاً** — النتيجةُ هي المطلوبة
        if ($model->id !== null && AiModel::query()->whereKey($model->id)->doesntExist()) {
            return ['ok' => true, 'gateway' => 'absent', 'error' => null];
        }

        if (! $skipDependencyCheck) {
            $deps = self::dependencies($model);
            if (! $deps['removable']) {
                return ['ok' => false, 'gateway' => 'untouched',
                        'error' => 'النموذجُ مستعمَلٌ — ' . self::firstLabel($deps['blocking'])];
            }
        }

        $name = (string) $model->litellm_model_name;

        // ② معرّفُ النشرِ عند البوّابة — وغيابُه ليس عطلاً
        $depId = self::deploymentIdOf($model->provider, $name);
        if ($depId === null) {
            $gateway = 'absent';
        } else {
            $res = LiteLlmAdmin::deleteModel($depId);
            if (! $res['ok']) {
                // **لا يسقط الصفُّ** — يتيمٌ عند البوّابةِ أسوأُ من يتيمٍ عندنا
                return ['ok' => false, 'gateway' => 'failed',
                        'error' => 'تعذّر إلغاءُ النشرِ عند البوّابة: ' . (string) $res['error']
                                   . ' — ولم يُمَسَّ سجلُّ Hub'];
            }
            $gateway = 'deleted';
        }

        hub_audit('إزالةُ نموذجِ ذكاء', AiModel::MODULE, (string) $model->id, $name,
            ['before' => ['upstream_model' => (string) $model->upstream_model,
                          'discovery_source' => (string) $model->discovery_source],
             'after'  => ['gateway' => $gateway]]);

        $model->delete();

        return ['ok' => true, 'gateway' => $gateway, 'error' => null];
    }

    /** أوّلُ مانعٍ بلغةٍ مقروءة — فالرسالةُ تقول شيئاً لا «تعذّر» */
    private static function firstLabel(array $blocking): string
    {
        $first = $blocking[0] ?? null;

        return $first === null ? 'سببٌ غيرُ معروف'
            : strip_tags(str_replace('**', '', (string) $first['label'])) . '. ' . (string) $first['hint'];
    }

    // ═══ ④ المصالحةُ في الاتّجاهَين ═══

    /**
     * **أين يفترق Hub عن البوّابة؟** — كشفٌ محضٌ لا يكتب حرفاً.
     *
     * والاتّجاهانِ مختلفانِ في خطرِهما:
     *
     *  • **صفٌّ عندنا بلا نشرٍ عندها**: النموذجُ يبدو متاحاً في الشاشاتِ
     *    ويسقط عند أوّلِ طلبٍ حقيقيّ.
     *  • **نشرٌ عندها بلا صفٍّ عندنا**: تهيئةٌ حيّةٌ لا يحكمها Hub — تُنفق
     *    ولا تُعرَض ولا تُطفأ من مركزِ الذكاء.
     *
     * **ولا يُصلَح شيءٌ تلقائيّاً**: الإصلاحُ قرارُ إنسان، والكشفُ وظيفتُنا.
     *
     * @return array{
     *     ok: bool,
     *     hub_only: list<array<string,mixed>>,
     *     gateway_only: list<array<string,mixed>>,
     *     matched: int,
     *     error: ?string
     * }
     */
    public static function reconcile(AiProvider $provider): array
    {
        $res = LiteLlmAdmin::modelInfo();
        if (! $res['ok']) {
            return ['ok' => false, 'hub_only' => [], 'gateway_only' => [], 'matched' => 0,
                    'error' => (string) $res['error']];
        }

        $onGateway = [];
        foreach (self::entriesOf($res) as $entry) {
            $params = (array) ($entry['litellm_params'] ?? []);
            if ((string) ($params['litellm_credential_name'] ?? '') !== (string) $provider->credential_name) {
                continue;
            }
            $name = trim((string) ($entry['model_name'] ?? ''));
            if ($name === '') continue;

            $onGateway[$name] = [
                'litellm_model_name' => $name,
                'upstream_model'     => (string) ($params['model'] ?? ''),
                'deployment_id'      => (string) (((array) ($entry['model_info'] ?? []))['id'] ?? ''),
            ];
        }

        // **الترتيبُ يُطلَب صراحةً** — فلا يتبادل صفّان موضعَيهما بين قراءتَين
        $rows = AiModel::query()->where('provider_id', $provider->id)
            ->orderBy('litellm_model_name')->orderBy('id')->get();

        $hubOnly = [];
        $matched = 0;
        foreach ($rows as $m) {
            $name = (string) $m->litellm_model_name;
            if (isset($onGateway[$name])) { $matched++; unset($onGateway[$name]); continue; }

            $hubOnly[] = [
                'id'                 => (string) $m->id,
                'litellm_model_name' => $name,
                'upstream_model'     => (string) $m->upstream_model,
                'enabled'            => (bool) $m->enabled,
            ];
        }

        ksort($onGateway);

        return ['ok' => true, 'hub_only' => $hubOnly,
                'gateway_only' => array_values($onGateway), 'matched' => $matched, 'error' => null];
    }

    // ═══ أدواتٌ مشتركة ═══

    /** معرّفُ النشرِ عند البوّابةِ لاسمٍ عندنا — أو `null` إن لم يعد موجوداً */
    private static function deploymentIdOf(?AiProvider $provider, string $name): ?string
    {
        if ($provider === null || $name === '') return null;

        $res = LiteLlmAdmin::modelInfo();
        if (! $res['ok']) return null;

        foreach (self::entriesOf($res) as $entry) {
            if (trim((string) ($entry['model_name'] ?? '')) !== $name) continue;

            $id = trim((string) (((array) ($entry['model_info'] ?? []))['id'] ?? ''));

            return $id === '' ? null : $id;
        }

        return null;
    }

    /** مُدخَلاتُ سجلِّ البوّابةِ مهما لُفَّت — `data` أو الجذرُ نفسُه */
    private static function entriesOf(array $res): array
    {
        $out = [];
        foreach ((array) ((($res['data']['data'] ?? $res['data']) ?: [])) as $entry) {
            if (is_array($entry)) $out[] = $entry;
        }

        return $out;
    }
}
