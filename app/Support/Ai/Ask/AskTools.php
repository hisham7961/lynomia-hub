<?php

namespace App\Support\Ai\Ask;

use Illuminate\Support\Str;
use App\Support\Redactor;

/**
 * **طبقةُ أدواتِ القراءةِ المحكومة — قلبُ المرحلة ٣** (P3-W3).
 *
 * ── **القاعدةُ التي يقوم عليها الملفُّ كلُّه** ──
 *
 * > **لا اتصالَ حرٍّ بالقاعدةِ ولا SQL عامّ.** كلُّ قراءةٍ تمرّ بأداةٍ مُعرَّفةٍ
 * > بوسائطَ مُحقَّقةٍ من **سجلِّ الوحدات**، وتُنفَّذ عبر `hub_can` + `hub_scope`
 * > + `hub_visible_fields` — **الآليّةُ نفسُها التي تحرس الشاشات، لا نسخةٌ منها**.
 *
 * ── **ولمَ خمسُ أدواتٍ عامّةٍ لا أداةٌ لكلِّ وحدة؟** ──
 *
 * سجلُّ الوحداتِ الخمسُ والثمانون **بشكلٍ واحد** (`key · table · model ·
 * display · columns · fields · search`). فخمسُ أدواتٍ تكفيها جميعاً.
 *
 * **والأهمُّ ليس الاختصار:** أداةٌ لكلِّ وحدةٍ تعني ٨٥ سطحَ هجومٍ يُراجَع كلٌّ
 * منها وحدَه، **ووحدةً جديدةً تُنسى فيها الحراسةُ فتُسرّب**. أمّا هنا فوحدةٌ
 * تُضاف غداً تعمل بلا سطرٍ يُكتَب، **وتُحرَس بالحارسِ نفسِه بالضرورة**.
 *
 * ── **والحارسُ الأوّلُ: النموذجُ لا يعرف ما لا يملكه صاحبُ الجلسة** ──
 *
 * `catalog()` تُبنى **لهذا المستخدمِ بعينِه**: وحدةٌ لا يملك `v` عليها **لا
 * تظهر في وصفِ الأدواتِ المُسلَّمِ للنموذجِ أصلاً**. فلا يُمنَع منها — **لا
 * يعرف أنّها موجودة**. وهذا يُبطل صنفَ «حقنِ الأداة» عند منبعِه.
 *
 * **والحارسُ الثاني عند التنفيذ** يبقى قائماً حزاماً ثانياً: نداءٌ بوحدةٍ خارجَ
 * القائمةِ يُرَدّ — **ولا يُصحَّح ولا يُخمَّن**.
 */
final class AskTools
{
    /** الأدواتُ الخمسُ — **قراءةٌ محضةٌ كلُّها، ولا سادسةَ تكتب** */
    public const TOOLS = ['hub_modules', 'hub_search', 'hub_list', 'hub_record', 'hub_count'];

    /**
     * **الأدواتُ الكاتبة: فارغةٌ بقرارِ مالكٍ محسوم.**
     *
     * والثابتُ كان مكتوباً في خمسِ وثائقَ وغيرَ موجودٍ في الشيفرةِ سطراً —
     * **وعدٌ بلا حارس**. فمن يقرأ الوثيقةَ يطمئنّ، ومن يُضيف أداةً كاتبةً غداً
     * لا يصطدم بشيء. وكتابتُه هنا تجعله **قابلاً للفحصِ آليّاً**: حارسٌ يقرأ
     * هذا الثابتَ ويقرأ ما خرج إلى البوّابةِ فعلاً، فلا يُوسَّع صمتاً.
     *
     * وتوسيعُه **قرارُ مالكٍ لا تفصيلُ تنفيذ**.
     */
    public const WRITE_TOOLS = [];

    /** مفرداتُ الترشيحِ المغلقة — **لا عاملَ خارجَها يُقبَل** */
    public const OPS = ['eq', 'ne', 'contains', 'gt', 'lt'];

    /** سقفُ الصفوفِ لكلِّ نداء — يُقصّ ولا يُرفَض */
    public const MAX_ROWS = AskPolicy::MAX_ROWS_PER_TOOL;

    /** أطولُ قيمةِ حقلٍ تُسلَّم — نصٌّ طويلٌ يُغرِق السياقَ ويُنفق */
    public const MAX_VALUE_CHARS = 300;

    /** **الحدُّ الأدنى للبحثِ** — حرفٌ واحدٌ يُعيد الجدولَ كلَّه فعليّاً */
    public const MIN_SEARCH_CHARS = 2;

    // ── ① الكتالوجُ — يُبنى لهذا المستخدمِ وحدَه ────────────────────────

    /**
     * **الوحداتُ التي يملك هذا المستخدمُ عرضَها** — ولا شيءَ سواها.
     *
     * @return array<string, array{label: string, fields: list<string>, searchable: bool}>
     */
    public static function catalog(mixed $user = null): array
    {
        $u   = $user ?? auth()->user();
        $out = [];

        foreach (hub_modules() as $key => $def) {
            if (! is_array($def) || ! hub_can($u, $key, 'v')) continue;
            if (! self::modelOf($key)) continue;

            $fields = array_values(array_unique(array_map(
                static fn ($f) => (string) ($f['key'] ?? ''),
                hub_visible_fields($u, $key, $def)
            )));
            $fields = array_values(array_filter($fields, static fn ($f) => $f !== ''));

            $out[$key] = [
                'label'      => (string) ($def['label'] ?? $key),
                'fields'     => $fields,
                'searchable' => ! empty($def['search']),
            ];
        }

        ksort($out);   // **ترتيبٌ حتميّ** — فوصفُ الأدواتِ لا يتبدّل بين نداءين

        return $out;
    }

    /**
     * **وصفُ الأدواتِ بشكلِ البوّابة** — من الكتالوجِ نفسِه لا من قائمةٍ ثانية.
     *
     * الشكلُ شكلُ «وصفِ أداةٍ» في الواجهةِ القياسيّة كما قُرئ من عقدِ الإصدارِ
     * المثبَّت (المرجعُ بالملفِّ والسطرِ في `docs/ai-hub/24-litellm-chat-contract.md`
     * §٢)، **وهو عقدُ البوّابةِ لا عقدُ مزوّدٍ بعينِه**: البوّابةُ تترجمه إلى
     * شكلِ كلِّ مزوّدٍ عندها، فنحن نكتبه مرّةً واحدة.
     *
     * **والمرجعُ في الوثيقةِ لا هنا عمداً**: حارسُ «لا طبقةَ نقلٍ لمزوّدٍ داخل
     * Hub» يمسح `app/` على أسماءِ المزوّدين، **ويصدق في منعِه**. فاسمُ نوعٍ
     * أو مسارُ ملفٍّ يحمل اسمَ مزوّدٍ يبقى في الوثيقةِ حيث يفيد، ولا يدخل
     * الشيفرةَ حيث يُضعِف حارساً قائماً.
     *
     * ── **ولمَ تُبنى القائمةُ من الكتالوجِ في كلِّ نداء؟** ──
     *
     * لأنّ `enum` الوحداتِ هنا **هو الحارسُ الأوّلُ في شكلٍ يفهمه النموذج**:
     * وحدةٌ لا يملكها صاحبُ الجلسةِ **لا تظهر في المفرداتِ المتاحةِ للاختيار**.
     * فلا يُمنَع منها — لا يعرف أنّها موجودة. وقائمةٌ ثابتةٌ مكتوبةٌ في مكانٍ
     * آخرَ كانت ستنحرف عن الكتالوجِ بعد أوّلِ وحدةٍ تُضاف، **وتُفشي أسماءَ ما
     * لا يملكه السائل**.
     *
     * **ولا وسيطَ يُعلَن ولا يُنفَّذ.** لا `limit` ولا `page` هنا لأنّ
     * `hub_list` لا يقرؤهما — سقفُ الصفوفِ ثابتٌ في الخادم. وإعلانُ وسيطٍ
     * مُتجاهَلٍ يجعل النموذجَ يظنّ أنّه ضيّق نتيجةً وهو لم يضيّق شيئاً.
     *
     * @param  array<string, array{label:string, fields:list<string>, searchable:bool}>  $catalog
     * @return list<array{type:string, function:array}>
     */
    public static function schema(array $catalog): array
    {
        $modules = array_keys($catalog);

        $moduleArg = ['type' => 'string', 'description' => 'مفتاحُ الوحدة'];
        // **مصفوفةٌ فارغةٌ ليست `enum` صالحاً** — ومن لا وحدةَ له لا يُرسَل له قيدٌ فاسد
        if ($modules !== []) $moduleArg['enum'] = array_values($modules);

        $filters = [
            'type'        => 'array',
            'description' => 'ترشيحٌ بحقلٍ مرئيٍّ وعاملٍ من المفرداتِ المغلقة',
            'items'       => [
                'type'       => 'object',
                'properties' => [
                    'field' => ['type' => 'string', 'description' => 'حقلٌ من `fields` المُعلَنةِ للوحدة'],
                    'op'    => ['type' => 'string', 'enum' => self::OPS],
                    'value' => ['type' => 'string'],
                ],
                'required'   => ['field', 'op', 'value'],
            ],
        ];

        $fn = static function (string $name, string $desc, array $props, array $required = []): array {
            $params = [
                'type'       => 'object',
                // **كائنٌ فارغٌ لا مصفوفةٌ فارغة**: `[]` يُسلسَل `[]` في JSON،
                // و`properties` يجب أن يكون كائناً — ومزوّدٌ صارمٌ يردّه ٤٠٠
                'properties' => $props === [] ? new \stdClass() : $props,
            ];

            // **ولا `required` فارغةٌ تُرسَل**: مسموحةٌ في المسوّداتِ المتأخّرةِ
            // ومرفوضةٌ في المبكّرة، وإرسالُها بلا حاجةٍ يشتري خطرَ رفضٍ بلا مقابل
            if ($required !== []) $params['required'] = array_values($required);

            return ['type' => 'function',
                    'function' => ['name' => $name, 'description' => $desc, 'parameters' => $params]];
        };

        return [
            $fn('hub_modules', 'فهرسُ الوحداتِ المتاحةِ لصاحبِ الجلسة (مفتاحٌ وعنوان). '
                . 'ومع `module` يعيد حقولَ تلك الوحدةِ وحدَها. '
                . 'ولعدِّ صفوفِ وحدةٍ لا تحتج إليه: نادِ `hub_count` بمفتاحِ الوحدةِ مباشرةً.',
                ['module' => ['type' => 'string', 'description' => 'مفتاحُ وحدةٍ لطلبِ حقولِها وحدَها']]),
            $fn('hub_search', 'بحثٌ نصّيٌّ عبر الوحداتِ المتاحة. حرفان على الأقلّ.',
                ['q' => ['type' => 'string', 'minLength' => self::MIN_SEARCH_CHARS]], ['q']),
            $fn('hub_list', 'يعيد صفوفَ وحدةٍ داخلَ نطاقِ صاحبِ الجلسة. السقفُ '
                . self::MAX_ROWS . ' صفّاً ويُعلَن القصُّ في النتيجة.',
                ['module' => $moduleArg, 'filters' => $filters], ['module']),
            $fn('hub_record', 'يعيد سجلّاً واحداً بمعرّفِه — إن كان داخلَ نطاقِ صاحبِ الجلسة.',
                ['module' => $moduleArg, 'id' => ['type' => 'string']], ['module', 'id']),
            $fn('hub_count', 'يعيد عدَّ الصفوفِ داخلَ النطاقِ بلا تسليمِ صفٍّ واحد. '
                . '**استعملها لكلِّ سؤالِ «كم»** — ولا تجلب صفوفاً لتعدَّها بنفسِك.',
                ['module' => $moduleArg, 'filters' => $filters], ['module']),
        ];
    }

    // ── ② التنفيذُ — بابٌ واحدٌ لكلِّ الأدوات ──────────────────────────

    /**
     * **ينفّذ أداةً بوسائطَ غيرِ موثوقة** — والجوابُ موحَّدُ الشكل.
     *
     * والوسائطُ **تأتي من النموذجِ فهي مدخلُ مهاجم**: تُحقَّق كما يُحقَّق طلبُ
     * HTTP، **لا كما تُقرَأ قيمةٌ داخليّة**.
     *
     * @return array{ok: bool, tool: string, module: ?string, rows: list<array<string,mixed>>,
     *               count: ?int, error: ?string, truncated: bool}
     */
    public static function run(string $tool, array $args, mixed $user = null): array
    {
        $u = $user ?? auth()->user();

        if (! in_array($tool, self::TOOLS, true)) {
            return self::fail($tool, 'أداةٌ غيرُ معروفة');
        }
        if ($u === null || ! AskPolicy::canAsk($u)) {
            return self::fail($tool, 'لا صلاحيّةَ لاستعمالِ المساعد');
        }

        return match ($tool) {
            'hub_modules' => self::toolModules($u, $args),
            'hub_search'  => self::toolSearch($u, $args),
            'hub_list'    => self::toolList($u, $args),
            'hub_record'  => self::toolRecord($u, $args),
            'hub_count'   => self::toolCount($u, $args),
        };
    }

    // ── الأدوات ────────────────────────────────────────────────────────

    /** **ما الذي يمكن سؤالي عنه؟** — أسماءٌ ووسومٌ، **ولا صفَّ بيانات** */
    /**
     * **فهرسُ الوحدات — كاملاً ومُقتضَباً معاً** (إصلاحُ قبولِ الإنتاج `71b0059e`).
     *
     * ── **العطبُ الذي كان** ──
     *
     * كان الفهرسُ يحمل **أسماءَ حقولِ كلِّ وحدة**: ألفٌ وأربعُمئةٍ وسبعةٌ
     * وأربعون اسمَ حقلٍ عبر خمسٍ وثمانين وحدة — **تسعةَ عشرَ ألفَ حرفٍ** من
     * أربعةٍ وعشرين ألفاً هي ميزانيّةُ السياقِ كلُّها. ثمّ يقصّه وعاءُ السياقِ
     * إلى **خمسةٍ وعشرين صفّاً** بحدِّ صفوفِ **البيانات**، فيصل النموذجَ
     * خمسٌ وعشرون وحدةً أبجديّاً — و`projects` التاسعةُ والخمسون.
     *
     * **فالسائلُ يسأل عن المشاريعِ ودليلُه لا يذكرها.**
     *
     * ── **والعلاجُ بنيويٌّ لا سقفٌ أكبر** ──
     *
     *  · الفهرسُ **مفتاحٌ وعنوانٌ فقط** — فيسع الخمسَ والثمانين في أقلَّ من
     *    ربعِ الميزانيّة، وهو نفسُه ما تُعلنه مفرداتُ `enum` فلا يكشف جديداً.
     *  · **والحقولُ تُطلَب لوحدةٍ بعينِها** — من يريد أن يُرشِّح يسأل عن حقولِ
     *    وحدتِه وحدَها، ولا يُحمَّل الجميعُ ثمنَ حاجةِ واحد.
     *  · وهو **فهرسٌ لا بيانات**، فلا يُقصّ بحدِّ صفوفِ البيانات.
     */
    private static function toolModules(mixed $u, array $args = []): array
    {
        $catalog = self::catalog($u);
        $one     = trim((string) ($args['module'] ?? ''));

        // **حقولُ وحدةٍ بعينِها** — والوحدةُ تُتحقَّق من الكتالوجِ لا من قولِ النموذج
        if ($one !== '') {
            if (! isset($catalog[$one])) {
                return self::fail('hub_modules', 'وحدةٌ غيرُ متاحةٍ لصاحبِ الجلسة');
            }

            return self::ok('hub_modules', $one, [[
                'module' => $one,
                'label'  => $catalog[$one]['label'],
                'fields' => implode(',', $catalog[$one]['fields']),
            ]], false, true);
        }

        $rows = [];
        foreach ($catalog as $key => $meta) {
            $rows[] = ['module' => $key, 'label' => $meta['label']];
        }

        return self::ok('hub_modules', null, $rows, false, true);
    }

    /**
     * **بحثٌ عبر الوحدات** — بمسارِ البحثِ المحكومِ القائم.
     *
     * ولا يُكتَب بحثٌ ثانٍ: `AskSearch` يلفّ منطقَ `SearchController` نفسَه،
     * **فبحثٌ ثانٍ ينحرف عن الأوّلِ بعد شهرٍ ويُسرّب حيث لا يُسرّب الأوّل**.
     */
    private static function toolSearch(mixed $u, array $args): array
    {
        $q = trim((string) ($args['q'] ?? ''));
        if (mb_strlen($q) < self::MIN_SEARCH_CHARS) {
            return self::fail('hub_search', 'نصُّ البحثِ أقصرُ من حرفين');
        }

        $allowed = self::catalog($u);
        $rows    = [];

        foreach (AskSearch::across($q, $u, self::MAX_ROWS) as $hit) {
            // **حزامٌ ثانٍ**: ما ليس في كتالوجِ هذا المستخدمِ لا يمرّ ولو أعاده البحث
            if (! isset($allowed[$hit['module']])) continue;

            $rows[] = [
                'module' => (string) $hit['module'],
                'label'  => (string) $hit['label'],
                'id'     => (string) $hit['id'],
                'name'   => self::clip((string) $hit['name']),
            ];
        }

        return self::ok('hub_search', null, $rows);
    }

    /** **صفوفُ وحدةٍ داخلَ نطاقِ المستخدم** — بترشيحٍ من مفرداتٍ مغلقة */
    private static function toolList(mixed $u, array $args): array
    {
        [$module, $def, $err] = self::resolveModule($u, $args);
        if ($err !== null) return self::fail('hub_list', $err);

        $q = self::scopedQuery($u, $module);
        if ($q === null) return self::fail('hub_list', 'تعذّر بناءُ استعلامٍ لهذه الوحدة');

        $fields = self::fieldMap($u, $module, $def);

        // ── الترشيح: حقلٌ من المرئيِّ وعاملٌ من المفردات، **وما عداهما يُرَدّ** ──
        foreach (self::filters($args) as $f) {
            $col = $fields[$f['field']] ?? null;
            if ($col === null) {
                return self::fail('hub_list', 'حقلٌ غيرُ متاحٍ للترشيح: ' . $f['field']);
            }
            self::applyFilter($q, $col, $f['op'], $f['value']);
        }

        // **ترتيبٌ حتميٌّ ينتهي بـ`id`** — وإلّا فأيُّ صفوفٍ تظهر قرعةٌ بين المحرّكَين
        $rows = $q->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::MAX_ROWS + 1)->get();

        $truncated = $rows->count() > self::MAX_ROWS;

        return self::ok('hub_list', $module,
            self::project($rows->take(self::MAX_ROWS), $fields), $truncated);
    }

    /** **صفٌّ واحدٌ بمعرّفِه** — و٤٠٤ خارجَ النطاقِ كما في الشاشةِ سواءً بسواء */
    private static function toolRecord(mixed $u, array $args): array
    {
        [$module, $def, $err] = self::resolveModule($u, $args);
        if ($err !== null) return self::fail('hub_record', $err);

        $id = trim((string) ($args['id'] ?? ''));
        if ($id === '') return self::fail('hub_record', 'لا معرّفَ للسجلّ');

        $q = self::scopedQuery($u, $module);
        if ($q === null) return self::fail('hub_record', 'تعذّر بناءُ استعلامٍ لهذه الوحدة');

        $row = $q->whereKey($id)->first();
        if ($row === null) {
            // **ولا يُقال «ممنوع»**: التفريقُ بين «غيرُ موجود» و«ليس لك» يُفشي الوجود
            return self::fail('hub_record', 'لا سجلَّ بهذا المعرّفِ في نطاقِك');
        }

        return self::ok('hub_record', $module,
            self::project(collect([$row]), self::fieldMap($u, $module, $def)));
    }

    /** **عدٌّ داخلَ النطاق** — ولا صفَّ يُسلَّم، فالعددُ وحدَه جوابُ سؤالٍ كثير */
    private static function toolCount(mixed $u, array $args): array
    {
        [$module, $def, $err] = self::resolveModule($u, $args);
        if ($err !== null) return self::fail('hub_count', $err);

        $q = self::scopedQuery($u, $module);
        if ($q === null) return self::fail('hub_count', 'تعذّر بناءُ استعلامٍ لهذه الوحدة');

        $fields = self::fieldMap($u, $module, $def);
        foreach (self::filters($args) as $f) {
            $col = $fields[$f['field']] ?? null;
            if ($col === null) {
                return self::fail('hub_count', 'حقلٌ غيرُ متاحٍ للترشيح: ' . $f['field']);
            }
            self::applyFilter($q, $col, $f['op'], $f['value']);
        }

        $r = self::ok('hub_count', $module, []);
        $r['count'] = (int) $q->count();

        return $r;
    }

    // ── الداخل ─────────────────────────────────────────────────────────

    /**
     * **يفكّ اسمَ الوحدةِ ويتحقّق من حقِّ هذا المستخدمِ فيها.**
     *
     * @return array{0: string, 1: array, 2: ?string}
     */
    private static function resolveModule(mixed $u, array $args): array
    {
        $module = trim((string) ($args['module'] ?? ''));
        if ($module === '') return ['', [], 'لا وحدةَ مُحدَّدة'];

        $catalog = self::catalog($u);
        if (! isset($catalog[$module])) {
            // الرسالةُ واحدةٌ سواءٌ أكانت الوحدةُ غيرَ موجودةٍ أم غيرَ مسموحة —
            // **فالتفريقُ يرسم للمهاجمِ خريطةَ ما يملكه غيرُه**
            return ['', [], 'وحدةٌ غيرُ متاحةٍ لك'];
        }

        return [$module, (array) hub_mod($module), null];
    }

    /** استعلامٌ **مُنطَّقٌ بالكامل** — ولا استعلامَ يُبنى خارجَ هذا الموضع */
    private static function scopedQuery(mixed $u, string $module)
    {
        $class = self::modelOf($module);
        if ($class === null) return null;

        return hub_client_scope(hub_scope($class::query(), $module, $u), $module);
    }

    /** `field => column` للحقولِ **المرئيّةِ لهذا المستخدم** وحدَها */
    private static function fieldMap(mixed $u, string $module, array $def): array
    {
        $map = ['id' => 'id'];
        foreach (hub_visible_fields($u, $module, $def) as $f) {
            $k = (string) ($f['key'] ?? '');
            if ($k === '') continue;
            $map[$k] = (string) ($f['col'] ?? $k);
        }

        return $map;
    }

    /** الترشيحاتُ المُحقَّقةُ شكلاً — والمعنى يُحقَّق عند الاستعمال */
    private static function filters(array $args): array
    {
        $out = [];
        foreach ((array) ($args['filters'] ?? []) as $f) {
            if (! is_array($f)) continue;
            $field = trim((string) ($f['field'] ?? ''));
            $op    = (string) ($f['op'] ?? 'eq');
            if ($field === '' || ! in_array($op, self::OPS, true)) continue;

            $out[] = ['field' => $field, 'op' => $op,
                      'value' => mb_substr((string) ($f['value'] ?? ''), 0, 120)];
        }

        return array_slice($out, 0, 5);
    }

    /** **العاملُ يُترجَم بـ`match` لا بتركيبِ نصّ** — فلا سبيلَ لحقنِ SQL */
    private static function applyFilter($q, string $col, string $op, string $value): void
    {
        match ($op) {
            'eq'       => $q->where($col, '=', $value),
            'ne'       => $q->where($col, '!=', $value),
            'gt'       => $q->where($col, '>', $value),
            'lt'       => $q->where($col, '<', $value),
            'contains' => $q->where($col, 'like', '%' . self::escapeLike($value) . '%'),
        };
    }

    /**
     * **يُهرّب محارفَ النمطِ في `LIKE`** — `%` و`_` و`\`.
     *
     * وبدونه يصير `%` في مُدخلِ النموذجِ **بدلَ أيِّ شيء**: ترشيحٌ يُفترَض أنّه
     * يضيّق يصير يُوسّع. ولا يكسر العزلَ (النطاقُ فوقَه) لكنّه يُغرِق السياقَ
     * بصفوفٍ لا علاقةَ لها بالسؤال.
     */
    private static function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $v);
    }

    /** يُسقِط الصفوفَ على الحقولِ المرئيّةِ **ويطمس ويقصّ** */
    private static function project($rows, array $fields): array
    {
        $out = [];
        foreach ($rows as $row) {
            $r = [];
            foreach ($fields as $key => $col) {
                $v = $row->{$col} ?? null;
                if ($v === null || is_array($v) || is_object($v)) {
                    $v = $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);
                }
                if ($v === null) continue;
                $r[$key] = self::clip(Redactor::text((string) $v));
            }
            $out[] = $r;
        }

        return $out;
    }

    private static function clip(string $v): string
    {
        return Str::limit($v, self::MAX_VALUE_CHARS, '…');
    }

    /** صنفُ الموديلِ إن وُجد — ووحدةٌ بلا موديلٍ لا أداةَ لها */
    private static function modelOf(string $module): ?string
    {
        $name = (string) (hub_mod($module)['model'] ?? '');
        if ($name === '') return null;
        $class = 'App\\Models\\' . $name;

        return class_exists($class) ? $class : null;
    }

    /**
     * @param  bool  $directory  **أفهرسٌ هذا أم صفوفُ بيانات؟**
     *   الفهرسُ أسماءُ وحداتٍ وعناوينُها — **لا معرّفَ ولا قيمةَ سجلٍّ فيه**،
     *   وهو نفسُه ما تُعلنه مفرداتُ `enum` في وصفِ الأدوات. فقصُّه بحدِّ صفوفِ
     *   **البيانات** يُخفي عن النموذجِ وحداتٍ يملكها صاحبُ الجلسةِ فعلاً.
     */
    private static function ok(string $tool, ?string $module, array $rows,
                               bool $truncated = false, bool $directory = false): array
    {
        return ['ok' => true, 'tool' => $tool, 'module' => $module, 'rows' => $rows,
                'count' => null, 'error' => null, 'truncated' => $truncated,
                'directory' => $directory];
    }

    private static function fail(string $tool, string $why): array
    {
        return ['ok' => false, 'tool' => $tool, 'module' => null, 'rows' => [],
                'count' => null, 'error' => $why, 'truncated' => false,
                'directory' => false];
    }
}
