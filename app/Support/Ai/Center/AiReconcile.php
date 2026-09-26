<?php

namespace App\Support\Ai\Center;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\Ai\Gateway\LiteLlmAdmin;
use App\Support\Platform\Redactor;

/**
 * **تصالحُ الحالةِ بين Hub والبوّابة** (المرحلة ٢ · W9 · §١٧ · R3).
 *
 * ── **الخطرُ الذي يُغلقه** ──
 *
 * قاعدتان مستقلّتان تفترقان. والاستعادةُ الجزئيّةُ أوضحُ أبوابِ الافتراق:
 * قاعدةُ Hub من الأمسِ وقاعدةُ البوّابةِ من اليوم ⇒ **نماذجُ في سلسلةِ توجيهٍ
 * لا وجودَ لها عند البوّابة**. فيُوجَّه طلبُ مستخدمٍ إلى اسمٍ لا يعرفه أحد،
 * ويعود ٤٠٤ يُقرَأ «عطلُ شبكة».
 *
 * **والوسمُ يُستخرَج من واقعِ البوّابةِ لا من ظنّ**: ما لا تُعلنه البوّابةُ
 * يُوسَم `ORPHANED` **ويُستبعَد من التوجيهِ تلقائيّاً** (`AiProfiles::UNROUTABLE`).
 *
 * ── **والتعافي آليٌّ كالوسمِ سواءً بسواء** ──
 *
 * نموذجٌ عاد يظهر عند البوّابةِ **يُرفَع عنه الوسمُ في التصالحِ التالي**. ولو
 * كان الوسمُ طريقاً واحداً لكانت استعادةٌ واحدةٌ تقتل الكتالوجَ إلى الأبد،
 * فيُصلَح بيدٍ صفّاً صفّاً — وما يُصلَح باليدِ يُنسى.
 *
 * ── **ولا يستورد شيئاً ولا يحذف شيئاً** ──
 *
 * نموذجٌ عند البوّابةِ باعتمادِنا ولا صفَّ له في Hub **يُبلَّغ ولا يُستورَد**:
 * الاستيرادُ قرارُ مديرٍ صريحٌ منذ W5، **والاكتشافُ لا يُفعِّل**. وصفُّ Hub لا
 * يُحذَف بحال — الوسمُ عكوسٌ والحذفُ ليس كذلك.
 *
 * ── **ولا كلفةَ ولا توليد** ──
 *
 * قراءتان مجّانيّتان: `/model/info` و`/credentials`. وهما المستويان A/C من
 * §١٢ — كلفتُهما صفرٌ بنصِّ الخطّة.
 */
final class AiReconcile
{
    /** وسمُ ما لا تعرفه البوّابة */
    public const ORPHANED = 'ORPHANED';

    /** الحالةُ التي يعود إليها نموذجٌ رُفع عنه الوسم */
    public const RESTORED = 'UNKNOWN';

    /**
     * **يقرأ الواقعَ ويقارنه بما عندنا.**
     *
     * @param  bool  $apply  `false` معاينةٌ لا تكتب · `true` تكتب الوسمَ وترفعه
     * @return array{ok: bool, error: ?string, checked_at: string,
     *               orphaned: list<string>, restored: list<string>,
     *               unregistered: list<string>, credential_gone: list<string>,
     *               applied: bool, counts: array<string,int>}
     */
    public static function run(bool $apply = false): array
    {
        $info = LiteLlmAdmin::modelInfo();
        if (! $info['ok']) return self::fail((string) $info['error']);

        // **أسماءُ ما تُعلنه البوّابةُ الآن** — وهي المرجعُ لا ظنُّنا
        $live = [];
        foreach ((array) (($info['data']['data'] ?? $info['data']) ?: []) as $entry) {
            if (! is_array($entry)) continue;
            $name = trim((string) ($entry['model_name'] ?? ''));
            if ($name !== '') $live[$name] = (string) (($entry['litellm_params'] ?? [])['litellm_credential_name'] ?? '');
        }

        /*
         * **حارسُ الردِّ الفارغ** — أخطرُ حالةٍ في هذا الصنف.
         *
         * بوّابةٌ تردّ ٢٠٠ بقائمةٍ فارغةٍ (لحظةَ إعادةِ تشغيلٍ، أو إعدادٌ لم
         * يُحمَّل بعد) كانت ستُوسِم **الكتالوجَ كلَّه** يتيماً فيُطفأ التوجيهُ
         * كلُّه دفعةً واحدة. والفرقُ بين «لا نماذجَ عندها» و«لم تُجِب بعد» لا
         * يُقرَأ من الرمز. فالمعاينةُ تُحسَب دائماً، **والكتابةُ تُمنَع** ما
         * دام عندنا نماذجُ وعندها لا شيء.
         */
        $weHave  = AiModel::query()->count();
        $blocked = $apply && $live === [] && $weHave > 0;

        $orphaned = [];
        $restored = [];

        foreach (AiModel::query()->orderBy('litellm_model_name')->orderBy('id')->get() as $m) {
            $name  = (string) $m->litellm_model_name;
            $known = array_key_exists($name, $live);
            $mark  = mb_strtoupper((string) $m->health) === self::ORPHANED;

            if (! $known && ! $mark) {
                $orphaned[] = $name;
                if ($apply && ! $blocked) {
                    $m->forceFill(['health' => self::ORPHANED])->save();
                    self::trace('وسمُ نموذجٍ يتيم', $name, 'لا نظيرَ له عند البوّابة');
                }
            } elseif ($known && $mark) {
                $restored[] = $name;
                if ($apply && ! $blocked) {
                    $m->forceFill(['health' => self::RESTORED])->save();
                    self::trace('رفعُ وسمِ اليُتم', $name, 'عاد يظهر عند البوّابة');
                }
            }
        }

        // ما عند البوّابةِ باعتمادِنا ولا صفَّ له عندنا — **يُبلَّغ ولا يُستورَد**
        $ours  = AiProvider::query()->pluck('credential_name')->flip();
        $mine  = AiModel::query()->pluck('litellm_model_name')->flip();
        $unreg = [];
        foreach ($live as $name => $cred) {
            if ($cred !== '' && $ours->has($cred) && ! $mine->has($name)) $unreg[] = $name;
        }
        sort($unreg);

        // واعتمادٌ عندنا مرجعُه ولا أثرَ له في خزنةِ البوّابة
        $gone = self::credentialsGone();

        return [
            'ok'              => true,
            'error'           => $blocked
                ? 'البوّابةُ لم تُعلن نموذجاً واحداً وعندنا ' . $weHave
                    . ' — **لم يُكتَب وسمٌ**: ردٌّ فارغٌ قد يكون إعادةَ تشغيلٍ لا كتالوجاً فارغاً'
                : null,
            'checked_at'      => now()->toDateTimeString(),
            'orphaned'        => $orphaned,
            'restored'        => $restored,
            'unregistered'    => $unreg,
            'credential_gone' => $gone,
            'applied'         => $apply && ! $blocked,
            'counts'          => [
                'hub_models'  => $weHave,
                'live_models' => count($live),
                'orphaned'    => count($orphaned),
                'restored'    => count($restored),
                'unregistered' => count($unreg),
                'credential_gone' => count($gone),
            ],
        ];
    }

    /**
     * **اعتمادٌ في Hub بلا أثرٍ في خزنةِ البوّابة.**
     *
     * ولا يُوسَم شيءٌ ولا يُغيَّر: قد تكون البوّابةُ لا تُجيب، أو نسخةٌ
     * استُعيدت جزئيّاً. **فالإبلاغُ هنا خبرٌ للمدير لا فعلٌ آليّ** — وإبطالُ
     * اعتمادٍ قائمٍ بناءً على ردٍّ عابرٍ خسارةٌ لا تُسترَدّ.
     *
     * @return list<string>
     */
    private static function credentialsGone(): array
    {
        $res = LiteLlmAdmin::credentials();
        if (! $res['ok']) return [];

        $live = [];
        $rows = (array) (($res['data']['credentials'] ?? $res['data']) ?: []);
        foreach ($rows as $row) {
            if (! is_array($row)) continue;
            $n = trim((string) ($row['credential_name'] ?? ''));
            if ($n !== '') $live[$n] = true;
        }

        if ($live === []) return [];   // ردٌّ فارغٌ لا يُقرَأ نفياً

        $gone = [];
        foreach (AiProvider::query()->where('credential_state', '!=', 'missing')
                     ->orderBy('label')->orderBy('id')->get() as $p) {
            if (! isset($live[(string) $p->credential_name])) $gone[] = (string) $p->label;
        }

        return $gone;
    }

    private static function trace(string $action, string $model, string $why): void
    {
        hub_audit($action, AiModel::MODULE, null, $model,
            ['after' => Redactor::arr(['model' => $model, 'why' => $why])]);
    }

    private static function fail(string $why): array
    {
        return [
            'ok' => false, 'error' => $why, 'checked_at' => now()->toDateTimeString(),
            'orphaned' => [], 'restored' => [], 'unregistered' => [], 'credential_gone' => [],
            'applied' => false,
            'counts' => ['hub_models' => 0, 'live_models' => 0, 'orphaned' => 0,
                         'restored' => 0, 'unregistered' => 0, 'credential_gone' => 0],
        ];
    }
}
