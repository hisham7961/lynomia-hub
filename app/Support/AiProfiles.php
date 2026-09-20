<?php

namespace App\Support;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProfileModel;

/**
 * **ملفّاتُ السياسة وسلاسلُها** (المرحلة ٢ · W7 · §٩).
 *
 * ── **لماذا طبقةٌ بين الميزةِ والنموذج؟ ليست أناقةً بل عزلَ التغيير** ──
 *
 * ميزةٌ في Hub تطلب `general`، لا اسمَ نموذجٍ بعينِه. فتبديلُ النموذجِ يصير
 * **قرارَ مديرٍ بنقرة** لا دفعةَ شيفرةٍ تمرّ بمراجعةٍ ونشرٍ واختبار. وبلا هذه
 * الطبقةِ يُدفَن اسمُ نموذجٍ في عشرينَ موضعاً، فيموتُ النموذجُ عند المزوّدِ
 * فتموتُ عشرون ميزةً معاً.
 *
 * ── **الثابتُ الذي تحرسه هذه الطبقة** (§٩ · §K) ──
 *
 * > **ملفٌّ يشترط قدرةً لا يقبل نموذجاً قدرتُه `false` ولا `unknown`.**
 *
 * وهذا تطبيقُ قاعدةِ `Tri` ① حرفاً: **عند التنفيذِ المجهولُ لا يمرّ**. فملفُّ
 * `vision` لا يقبل نموذجاً لم تُثبِت البوّابةُ رؤيتَه — لا لأنّنا نظنّه عاجزاً،
 * بل لأنّ ترقيةَ «لا نعرف» إلى «مدعوم» بالصمتِ تُنتج ميزةً تفشل عند أوّلِ
 * مستخدم. **والبابُ مفتوحٌ صراحةً لا صمتاً**: يُثبِت المديرُ القدرةَ بفاحصِ
 * المستوى E (يكتبها `verified`) أو يتجاوزها يدويّاً (`hub_override`) — قرارٌ
 * مرئيٌّ موسومٌ بصاحبِه، لا افتراضٌ في الشيفرة.
 *
 * ── **والمصطلحُ واحد** ──
 *
 * الملفُّ **هو** مجموعةُ النماذج. ولا `model_groups` منفصلةٌ — اسمان لشيءٍ
 * واحدٍ يفترقان بعد شهر.
 *
 * ── **ولا اسمَ نموذجٍ ولا مزوّدٍ في هذا الملفّ** ──
 *
 * البذورُ السبعُ **أغراضٌ** لا نماذج. والسلسلةُ يملؤها المديرُ ممّا اسْتُورد.
 */
final class AiProfiles
{
    /** شكلُ الجوابِ الموحَّد */
    public const SHAPE = ['ok', 'profile', 'error'];

    /**
     * **الأغراضُ السبعةُ** (§٩) — ومعها القدرةُ التي يشترطها كلُّ غرض.
     *
     * والاشتراطُ **صدقٌ لا تشدُّد**: نشرٌ وضعُه `embedding` لا يجيب محادثةً،
     * فوضعُه في سلسلةِ `general` يُنتج فشلاً عند التنفيذِ لا احتياطاً ناجحاً.
     * وأربعةُ ملفّاتٍ تشترط `chat` لأنّها تفترق في الكلفةِ والسرعةِ لا في
     * القدرة — والفرقُ بينها ترتيبُ السلسلةِ الذي يضعه المدير.
     */
    public const SEEDS = [
        ['key' => 'general',   'label' => 'عامّ',        'cap' => 'chat',
         'desc' => 'الغرضُ الافتراضيُّ لأيِّ ميزةٍ لم تطلب غرضاً بعينِه.'],
        ['key' => 'fast',      'label' => 'سريع',        'cap' => 'chat',
         'desc' => 'زمنُ الجوابِ أهمُّ من عمقِه — للاقتراحاتِ الفوريّةِ داخلَ الشاشات.'],
        ['key' => 'reasoning', 'label' => 'استدلال',     'cap' => 'reasoning',
         'desc' => 'تحليلٌ متعدّدُ الخطوات. **يشترط قدرةً مُثبَتة** فلا يقبل مجهولَها.'],
        ['key' => 'coding',    'label' => 'برمجة',       'cap' => 'chat',
         'desc' => 'توليدُ شيفرةٍ وشرحُها — ويُرتَّب بنماذجَ أثبتت جودتَها فيها.'],
        ['key' => 'vision',    'label' => 'رؤية',        'cap' => 'vision',
         'desc' => 'قراءةُ صورةٍ أو وثيقةٍ مصوّرة. **يشترط قدرةً مُثبَتة.**'],
        ['key' => 'cheap',     'label' => 'اقتصاديّ',    'cap' => 'chat',
         'desc' => 'أقلُّ كلفةٍ ممكنة — للأعمالِ الكثيرةِ الرخيصةِ كالتصنيفِ والوسم.'],
        ['key' => 'embedding', 'label' => 'تضمين',       'cap' => 'embeddings',
         'desc' => 'تحويلُ نصٍّ إلى متّجهٍ للبحثِ الدلاليّ. **غرضٌ منفصلٌ تماماً.**'],
    ];

    /** **ثلاثةُ نماذجَ لا أكثر** — الحارسُ ① مطبَّقاً على طولِ السلسلة */
    public const MAX_LINKS = AiRouting::MAX_DEPTH + 1;

    // ── البذر ──────────────────────────────────────────────────────────

    /**
     * **يزرع الأغراضَ السبعةَ إن غابت** — بلا نموذجٍ واحدٍ في سلسلةٍ منها.
     *
     * وتكرارُ النداءِ لا يُنتج شيئاً: الغرضُ الموجودُ لا يُلمَس، فلا تُدهَس
     * سلسلةٌ رتّبها المديرُ ولا وصفٌ حرّره.
     */
    public static function seed(): int
    {
        $existing = AiProfile::query()->pluck('key')->flip();
        $born     = 0;

        foreach (self::SEEDS as $s) {
            if ($existing->has($s['key'])) continue;

            AiProfile::create([
                'key'                 => $s['key'],
                'label'               => $s['label'],
                'description'         => $s['desc'],
                'required_capability' => $s['cap'],
                'enabled'             => true,
            ]);
            $born++;
        }

        return $born;
    }

    /** الأغراضُ بترتيبِ البذرِ لا بترتيبِ الإدراج — فالترتيبُ غيرُ المطلوبِ قرعة */
    public static function all()
    {
        $order = array_column(self::SEEDS, 'key');

        return AiProfile::query()->orderBy('key')->get()
            ->sortBy(static fn ($p) => array_search((string) $p->key, $order, true) === false
                ? PHP_INT_MAX : array_search((string) $p->key, $order, true))
            ->values();
    }

    // ── الأهليّة — البوّابةُ التي يحرسها §٩ ─────────────────────────────

    /**
     * **أَيصلح هذا النموذجُ لهذا الغرض؟** — وجوابٌ يقول **لماذا لا**.
     *
     * والرفضُ يُفصِّل: «قدرةٌ غيرُ مثبتة» ليست «قدرةٌ منفيّة»، والفرقُ هو ما
     * يُرشد المديرَ إلى الفاحصِ E بدل أن يظنّ النموذجَ عاجزاً.
     *
     * @return array{ok: bool, why: ?string}
     */
    public static function eligibility(AiProfile $profile, AiModel $model): array
    {
        $cap = trim((string) $profile->required_capability);
        if ($cap === '') return ['ok' => true, 'why' => null];

        $caps = (array) $model->capabilities;
        $fact = $caps[$cap] ?? null;
        $v    = Tri::of(is_array($fact) ? ($fact['v'] ?? null) : $fact);

        if (Tri::allowsExecution($v)) return ['ok' => true, 'why' => null];

        return ['ok' => false, 'why' => $v === false
            ? 'النموذجُ لا يدعم «' . $cap . '» — والبوّابةُ تقول ذلك صراحةً'
            : 'قدرةُ «' . $cap . '» **غيرُ مثبتةٍ** لهذا النموذج — أثبِتها بفاحصِ المستوى E أو تجاوزها يدويّاً، ولا تُرقَّى بالصمت'];
    }

    // ── بناءُ السلسلة ──────────────────────────────────────────────────

    /**
     * **يضمّ نموذجاً إلى سلسلةِ غرض** — والمرتبةُ صريحةٌ أو تاليةُ الأخيرة.
     *
     * `rank = 0` أساسيّ · ١ فما فوقُ احتياطٌ بالترتيب.
     *
     * @return array{ok: bool, profile: ?AiProfile, error: ?string}
     */
    public static function attach(AiProfile $profile, AiModel $model, ?int $rank = null): array
    {
        $fit = self::eligibility($profile, $model);
        if (! $fit['ok']) return self::fail((string) $fit['why']);

        if (AiProfileModel::query()->where('profile_id', $profile->id)
            ->where('model_id', $model->id)->exists()) {
            return self::fail('النموذجُ في السلسلةِ أصلاً — ولا يُكرَّر فيها');
        }

        $links = self::links($profile);
        if ($links->count() >= self::MAX_LINKS) {
            return self::fail('السلسلةُ بلغت ' . self::MAX_LINKS
                . ' نماذجَ — وهو **الحارسُ ①**: عمقُ الاحتياطِ أقصاه ' . AiRouting::MAX_DEPTH);
        }

        $rank = $rank ?? (int) (($links->max('rank') ?? -1) + 1);
        if ($rank < 0 || $rank >= self::MAX_LINKS) {
            return self::fail('المرتبةُ خارجَ المدى ٠–' . (self::MAX_LINKS - 1));
        }

        // مرتبةٌ مشغولةٌ ⇒ يُزاح من بعدَها — والقيدُ الفريدُ لا يقبل تساوياً
        if ($links->firstWhere('rank', $rank) !== null) {
            foreach ($links->sortByDesc('rank') as $l) {
                if ((int) $l->rank >= $rank) $l->forceFill(['rank' => (int) $l->rank + 1])->save();
            }
        }

        AiProfileModel::create([
            'profile_id' => $profile->id,
            'model_id'   => $model->id,
            'rank'       => $rank,
            'enabled'    => true,
        ]);

        self::trace('ضمُّ نموذجٍ إلى سلسلةِ غرض', $profile,
            ['model' => (string) $model->litellm_model_name, 'rank' => $rank]);

        return self::ok($profile);
    }

    /** يُخرِج حلقةً ثمّ **يرصّ المراتبَ** — فلا فجوةَ تُربك قراءةَ السلسلة */
    public static function detach(AiProfileModel $link): array
    {
        $profile = $link->profile;
        if ($profile === null) return self::fail('حلقةٌ بلا غرض');

        $name = (string) ($link->model?->litellm_model_name ?? '—');
        $link->delete();
        self::compact($profile);

        self::trace('إخراجُ نموذجٍ من سلسلةِ غرض', $profile, ['model' => $name]);

        return self::ok($profile);
    }

    /**
     * **إعادةُ الترتيبِ بقائمةِ معرّفاتٍ كاملة** — ومن ليس فيها لا يُمَسّ ترتيبُه.
     *
     * والكتابةُ على مرحلتين (إزاحةٌ بعيدةٌ ثمّ استقرار) لأنّ `(profile_id, rank)`
     * فريدٌ: كتابةٌ مباشرةٌ تصطدم بمرتبةٍ لم تُفرَّغ بعد.
     *
     * @param  list<string>  $linkIds  بالترتيبِ المطلوب
     */
    public static function reorder(AiProfile $profile, array $linkIds): array
    {
        $links = self::links($profile)->keyBy('id');
        $ids   = array_values(array_filter($linkIds, static fn ($i) => $links->has($i)));

        if (count($ids) !== $links->count()) {
            return self::fail('الترتيبُ لا يشمل كلَّ حلقاتِ السلسلة — وترتيبٌ ناقصٌ يترك مرتبةً مبهمة');
        }

        $far = 1000;
        foreach ($ids as $id) $links[$id]->forceFill(['rank' => $far++])->save();
        foreach ($ids as $i => $id) $links[$id]->forceFill(['rank' => $i])->save();

        self::trace('إعادةُ ترتيبِ سلسلةِ غرض', $profile, ['order' => $ids]);

        return self::ok($profile);
    }

    public static function setLinkEnabled(AiProfileModel $link, bool $on): array
    {
        $link->forceFill(['enabled' => $on])->save();
        $profile = $link->profile;

        if ($profile !== null) {
            self::trace($on ? 'تفعيلُ حلقةٍ في سلسلة' : 'تعطيلُ حلقةٍ في سلسلة', $profile,
                ['model' => (string) ($link->model?->litellm_model_name ?? '—')]);
        }

        return $profile === null ? self::fail('حلقةٌ بلا غرض') : self::ok($profile);
    }

    public static function setEnabled(AiProfile $profile, bool $on): array
    {
        $profile->forceFill(['enabled' => $on])->save();
        self::trace($on ? 'تفعيلُ غرض' : 'تعطيلُ غرض', $profile, []);

        return self::ok($profile->fresh());
    }

    // ── قراءةُ السلسلة ─────────────────────────────────────────────────

    /**
     * **السلسلةُ الصالحةُ الآن** — مرتّبةً: الأساسيُّ أوّلاً ثمّ الاحتياطُ.
     *
     * ويُستبعَد من السلسلةِ ما لا يصحّ إرسالُ طلبٍ إليه أصلاً: حلقةٌ مُعطَّلةٌ،
     * أو نموذجٌ مُعطَّلٌ، أو مزوّدٌ مُعطَّلٌ أو لم يستقرَّ اعتمادُه، أو نموذجٌ
     * وُسم `UNAVAILABLE` لأنّه أُزيل من المنبع. **والقدرةُ المشترطةُ تُفحَص
     * هنا أيضاً** لا عند الضمِّ وحدَه: تجاوزٌ يدويٌّ قد يُلغى، وتحديثٌ من
     * البوّابةِ قد ينفي قدرةً كانت مُثبَتة — فالسلسلةُ تُصفّى عند كلِّ قراءة.
     *
     * @return \Illuminate\Support\Collection<int, AiModel>
     */
    public static function chain(AiProfile $profile)
    {
        if (! $profile->enabled) return collect();

        return self::links($profile)
            ->filter(static fn (AiProfileModel $l) => (bool) $l->enabled)
            ->map(static fn (AiProfileModel $l) => $l->model)
            ->filter(static function (?AiModel $m) use ($profile) {
                if ($m === null || ! $m->enabled) return false;
                if (mb_strtoupper((string) $m->health) === 'UNAVAILABLE') return false;

                $p = $m->provider;
                if ($p === null || ! $p->enabled) return false;
                if ((string) $p->credential_state === 'missing') return false;

                return self::eligibility($profile, $m)['ok'];
            })
            ->values();
    }

    /** ما أُخرج من السلسلةِ ولماذا — فالشاشةُ تقول السببَ بدل أن تُخفيَ الحلقة */
    public static function excluded(AiProfile $profile): array
    {
        $out = [];

        foreach (self::links($profile) as $l) {
            $m = $l->model;
            if ($m === null) { $out[] = ['name' => '—', 'why' => 'نموذجٌ محذوف']; continue; }

            $why = match (true) {
                ! $profile->enabled                                       => 'الغرضُ نفسُه مُعطَّل',
                ! $l->enabled                                             => 'الحلقةُ مُعطَّلةٌ في هذه السلسلة',
                ! $m->enabled                                             => 'النموذجُ مُعطَّل',
                mb_strtoupper((string) $m->health) === 'UNAVAILABLE'      => 'النموذجُ أُزيل من المنبع ووُسم متعطّلاً',
                $m->provider === null || ! $m->provider->enabled          => 'مزوّدُ النموذجِ مُعطَّل',
                (string) ($m->provider->credential_state ?? '') === 'missing' => 'اعتمادُ المزوّدِ لم يستقرَّ بعد',
                ! self::eligibility($profile, $m)['ok']                   => (string) self::eligibility($profile, $m)['why'],
                default                                                   => null,
            };

            if ($why !== null) $out[] = ['name' => (string) $m->litellm_model_name, 'why' => $why];
        }

        return $out;
    }

    // ── الداخل ─────────────────────────────────────────────────────────

    /** **الترتيبُ مطلوبٌ صراحةً دائماً** — `ORDER BY rank` لا قرعةَ إدراج */
    private static function links(AiProfile $profile)
    {
        return AiProfileModel::query()->with(['model.provider'])
            ->where('profile_id', $profile->id)->orderBy('rank')->get();
    }

    /** يرصّ المراتبَ ٠،١،٢ بعد حذف — على مرحلتين لأجلِ القيدِ الفريد */
    private static function compact(AiProfile $profile): void
    {
        $links = self::links($profile);
        $far   = 1000;
        foreach ($links as $l) $l->forceFill(['rank' => $far++])->save();
        foreach ($links->values() as $i => $l) $l->forceFill(['rank' => $i])->save();
    }

    private static function trace(string $action, AiProfile $profile, array $extra): void
    {
        hub_audit($action, AiProfile::MODULE, (string) $profile->id, (string) $profile->label,
            ['after' => Redactor::arr($extra + ['profile' => (string) $profile->key])]);
    }

    /** @return array{ok: true, profile: AiProfile, error: null} */
    private static function ok(AiProfile $profile): array
    {
        return ['ok' => true, 'profile' => $profile, 'error' => null];
    }

    /** @return array{ok: false, profile: null, error: string} */
    private static function fail(string $why): array
    {
        return ['ok' => false, 'profile' => null, 'error' => $why];
    }
}
