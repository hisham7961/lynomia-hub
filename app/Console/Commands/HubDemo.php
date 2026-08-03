<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * الوضع التجريبي (Sandbox): بيانات وهمية واقعية للتدريب وتجربة الاستيراد
 * والمسارات والـ API — كل صف يُوسم meta:{"demo":1} فيُمسح بدقة عند التصفير.
 */
class HubDemo extends Command
{
    protected $signature = 'hub:demo {--purge : مسح بيانات التجربة فقط دون إعادة توليد}
                                     {--full : توليد شامل — كل وحدة من السجل تنال صفوفاً تجريبية، والإعدادات الفارغة تُملأ}';
    protected $description = 'توليد بيانات تجريبية موسومة أو مسحها (--purge) — و--full يعمّها على كل الوحدات والإعدادات';

    /** الجداول التي يوسم فيها وضع التجربة (النواة اليدوية — --full يضيف بقية السجل) */
    protected const TABLES = ['companies', 'clients', 'projects', 'tasks', 'tickets',
        'fin_documents', 'domains', 'employees', 'quotes', 'suppliers'];

    /** الإعدادات التي يملؤها --full **إن كانت فارغة فقط** — لا يُكتب فوق قيمة قائمة.
     *  عمداً ليست هنا: maintenance.on (يقفل النظام)، approval.rules (يُقيّد التحرير)،
     *  والأسرار (توكن تلجرام، مفتاح أودو) — لا تُخترع أسرارٌ وهمية. */
    protected const SETTINGS = [
        'app.name'         => 'Lynomia Hub',
        'app.company'      => '🎭 شركة الأفق التجريبية',
        'app.currency'     => 'د.ك',
        'auth.pw_min'      => '8',
        'auth.max_fail'    => '5',
        'auth.lock_min'    => '15',
        'auth.session_min' => '480',
        'files.max_kb'     => '512000',
        'notify.tg_chat'   => '@demo_channel',
        'maintenance.msg'  => 'النظام تحت صيانة مجدولة — نعود خلال دقائق.',
        'sla.rules'        => 'عاجلة:1:8 عالية:4:24 افتراضي:8:72',
        'odoo.url'         => 'https://demo-company.odoo.com',
        'odoo.db'          => 'demo',
        'odoo.user'        => 'demo@example.com',
    ];

    /** مجمّع المعرفات المدرجة: table => [ids] — منه تُحلّ المراجع بين الوحدات */
    protected array $pool = [];

    /** حارس الدور: وحدات قيد التوليد الآن — يقطع أي حلقة مراجع متبادلة */
    protected array $seeding = [];

    public function handle(): int
    {
        $purged = $this->purge();
        if ($this->option('purge')) {
            \App\Models\Setting::where('key', 'demo.on')->delete();
            \Illuminate\Support\Facades\Cache::forget('settings:all');
            $this->info("مُسح الوضع التجريبي: {$purged} صف");
            return self::SUCCESS;
        }

        $n = $this->seed();
        if ($this->option('full')) {
            $n += $this->seedRegistry();
            $this->seedSettings();
            // عدّة الانطلاق: مسارات عمل حقيقية دائمة (بالاسم فلا تتكرر، ولا يمسحها التصفير)
            $this->call('hub:flows-starter');
            $this->call('hub:alerts-starter');
        }
        \App\Models\Setting::updateOrCreate(['key' => 'demo.on'], ['value' => '1']);
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        $this->info("وضع تجريبي جاهز: {$n} سجل وهمي" . ($purged ? " (بعد مسح {$purged} قديم)" : '') . ' — صفّره متى شئت بزر التصفير أو hub:demo --purge');

        return self::SUCCESS;
    }

    /**
     * المسح بمسار JSON لا بـ LIKE على النص: عمود meta من نوع json، وMySQL يعيد
     * صياغته عند التخزين («{"demo": 1}» بمسافة) فلا يطابق نمطاً نصياً كُتب بلا مسافة —
     * فكان المسح يحذف صفراً على MySQL وتتضاعف البيانات التجريبية مع كل تصفير.
     * يمسح كل جداول السجل (لا النواة فقط) — فتصفير ما ولّده --full مضمون.
     */
    protected function purge(): int
    {
        $tables = array_unique(array_merge(self::TABLES,
            array_filter(array_column(config('hub.modules', []), 'table'))));

        $n = 0;
        foreach ($tables as $t) {
            if ($t === 'users' || ! \Illuminate\Support\Facades\Schema::hasTable($t)) continue;
            if (! \Illuminate\Support\Facades\Schema::hasColumn($t, 'meta')) continue;
            $n += DB::table($t)->where('meta->demo', 1)->delete();
        }

        // الإعدادات التي ملأها --full (المسجّلة بالاسم) تُحذف هي وحدها — لا تخمين
        if ($keys = json_decode((string) \App\Models\Setting::where('key', 'demo.settings')->value('value'), true)) {
            \App\Models\Setting::whereIn('key', $keys)->delete();
        }
        \App\Models\Setting::where('key', 'demo.settings')->delete();

        return $n;
    }

    /** إدراج مباشر بوسم demo — بلا تدقيق ولا webhooks فلا يلوث السجلات الحقيقية */
    protected function row(string $table, array $data): string
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert($data + [
            'id' => $id, 'meta' => '{"demo":1}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->pool[$table][] = $id;

        return $id;
    }

    protected function seed(): int
    {
        // ثلاث شركات لا واحدة — فيُختبر محوّل الشركات والعزل والتقارير المجمّعة فعلاً
        $cos = [];
        foreach ([['🎭 شركة الأفق التجريبية', 'Demo Horizon', 'تقنية'],
                  ['🎭 النخيل للتجارة العامة', 'Demo Palms Trading', 'تجارة'],
                  ['🎭 البشائر للخدمات', 'Demo Bashaer Services', 'خدمات']] as [$na, $ne, $ty]) {
            $cos[] = $this->row('companies', ['name_ar' => $na, 'name_en' => $ne,
                'country' => 'الكويت', 'city' => 'مدينة الكويت', 'type' => $ty, 'status' => 'نشطة']);
        }
        $co = $cos[0];

        $clients = [];
        foreach ([['🎭 مطاعم الذواقة', 'info@demo-taste.example', 'عميل'],
                  ['🎭 عيادات الشفاء', 'care@demo-clinic.example', 'عميل'],
                  ['🎭 مكتبة المعرفة', 'hi@demo-books.example', 'مهتم'],
                  ['🎭 نادي اللياقة', 'fit@demo-gym.example', 'تفاوض']] as $i => [$n2, $e, $st]) {
            $clients[] = $this->row('clients', ['name' => $n2, 'email' => $e, 'stage' => $st,
                'company_id' => $cos[$i % 3], 'country' => 'الكويت', 'phone' => '+965 5' . random_int(1000000, 9999999)]);
        }

        $projects = [];
        foreach ([['🎭 تطبيق توصيل الطلبات', 'قيد التنفيذ', 'عالية'],
                  ['🎭 موقع حجوزات العيادة', 'قيد التنفيذ', 'متوسطة'],
                  ['🎭 متجر الكتب الإلكتروني', 'تخطيط', 'منخفضة']] as $i => [$n2, $st, $pr]) {
            $projects[] = $this->row('projects', ['name' => $n2, 'company_id' => $cos[$i % 3],
                'status' => $st, 'priority' => $pr, 'type' => 'تطبيق',
                'start_date' => now()->subDays(random_int(20, 90))->toDateString()]);
        }

        $n = 10;   // ما أُدرج أعلاه
        foreach (['تصميم الواجهات', 'ربط الدفع الإلكتروني', 'شاشة تتبع الطلب', 'اختبار الإصدار الأول',
                  'رفع المتجر لآبل', 'صفحة الحجوزات', 'تقارير الإدارة', 'إصلاح ملاحظات العميل'] as $i => $t) {
            $this->row('tasks', ['title' => '🎭 ' . $t, 'project_id' => $projects[$i % 3],
                'status' => ['جديدة', 'قيد التنفيذ', 'قيد التنفيذ', 'منجزة'][$i % 4],
                'priority' => ['عادية', 'عالية'][$i % 2],
                'due' => now()->addDays(random_int(-5, 20))->toDateString()]);
            $n++;
        }

        foreach ([['التطبيق يعلق عند الدفع', 'عاجلة'], ['طلب تعديل شعار', 'عادية'],
                  ['العميل لا يستطيع الدخول', 'عالية'], ['استفسار عن فاتورة', 'عادية']] as $i => [$s, $p]) {
            $this->row('tickets', ['subject' => '🎭 ' . $s, 'customer' => 'عميل تجريبي ' . ($i + 1),
                'priority' => $p, 'status' => ['جديدة', 'قيد المعالجة'][$i % 2],
                'channel' => 'بريد', 'project_id' => $projects[$i % 3]]);
            $n++;
        }

        foreach ([['فاتورة', 'مطاعم الذواقة', 1200, 'مدفوعة'], ['فاتورة', 'عيادات الشفاء', 850, 'مرسلة'],
                  ['مصروف', 'استضافة السيرفرات', 95, 'مدفوعة'], ['مصروف', 'اشتراك أدوات التصميم', 45, 'مدفوعة'],
                  ['فاتورة', 'نادي اللياقة', 2400, 'مسودة']] as $i => [$k, $p, $amt, $st]) {
            $this->row('fin_documents', ['doc_no' => '🎭DEMO-' . (100 + $i), 'kind' => $k, 'partner' => $p,
                'date' => now()->subDays($i * 9)->toDateString(), 'amount' => $amt, 'tax' => 0,
                'total' => $amt, 'currency' => 'د.ك', 'state' => $st, 'company_id' => $cos[$i % 3],
                'project_id' => $projects[$i % 3]]);
            $n++;
        }

        foreach ([['demo-taste.example', 25], ['demo-clinic.example', 190], ['demo-books.example', 400]] as $i => [$d, $days]) {
            $this->row('domains', ['name' => $d, 'company_id' => $cos[$i % 3], 'registrar' => 'Namecheap',
                'expiry' => now()->addDays($days)->toDateString(), 'currency' => 'د.ك', 'cost' => 4]);
            $n++;
        }

        foreach ([['🎭 نورة المطيري', 'تصميم'], ['🎭 يوسف العنزي', 'تطوير'], ['🎭 دلال الصباح', 'دعم']] as $i => [$e, $d]) {
            $this->row('employees', ['name' => $e, 'dept' => $d, 'company_id' => $cos[$i % 3],
                'hired' => now()->subMonths(random_int(3, 30))->toDateString(), 'status' => 'على رأس العمل']);
            $n++;
        }

        $this->row('quotes', ['doc_no' => '🎭Q-DEMO-1', 'client_id' => $clients[3],
            'date' => now()->toDateString(), 'amount' => 3500, 'tax' => 0, 'total' => 3500,
            'currency' => 'د.ك', 'status' => 'مرسل']);

        return $n + 1;
    }

    /* ─────────────────────────── التوليد الشامل (--full) ─────────────────────────── */

    /**
     * يمرّ على سجل الوحدات كله: كل وحدة لم تنلها النواة اليدوية تنال صفّين تجريبيين،
     * حقولُهما تُملأ من تعريف السجل نفسه (النوع والخيارات والمراجع) — فما يظهر في
     * النموذج للمستخدم هو ما يُملأ هنا، والمراجع تُحلّ لسجلات تجريبية حقيقية
     * (وتُولَّد الوحدة المُشار إليها أولاً عند اللزوم) فتعمل صفحات ٣٦٠ والخط الزمني.
     */
    protected function seedRegistry(): int
    {
        $n = 0;
        foreach (array_keys(config('hub.modules', [])) as $mk) {
            $n += $this->ensureSeeded($mk);
        }

        return $n;
    }

    /** يضمن أن للوحدة صفوفاً تجريبية — يُولّد مرجعياتها أولاً، بحارسٍ يقطع الدور */
    protected function ensureSeeded(string $module): int
    {
        $def = config("hub.modules.{$module}");
        $table = $def['table'] ?? null;
        if (! $table || $table === 'users' || isset($this->seeding[$module])) return 0;
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) return 0;
        if (! empty($this->pool[$table])) return 0;      // نالتها النواة اليدوية أو دورة سابقة

        $this->seeding[$module] = true;
        $n = 0;
        try {
            $cols = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing($table));
            for ($i = 0; $i < 2; $i++) {
                $data = [];
                foreach ($def['fields'] ?? [] as $f) {
                    $col = $f['col'] ?? null;
                    if (! $col || ! isset($cols[$col]) || array_key_exists($col, $data)) continue;
                    $v = $this->fake($f, $def, $i);
                    if ($v !== null) $data[$col] = $v;
                }
                if ($data) { $this->row($table, $data); $n++; }
            }
        } finally {
            unset($this->seeding[$module]);
        }

        return $n;
    }

    /** قيمة تجريبية لحقلٍ حسب نوعه في السجل — العرض يبدأ بـ🎭 ليُعرف بعينه */
    protected function fake(array $f, array $def, int $i): mixed
    {
        $key = strtolower($f['col'] ?? $f['key'] ?? '');
        $label = $f['label'] ?? 'حقل';

        return match ($f['type'] ?? 'text') {
            'sel'  => ($o = array_values($f['options'] ?? [])) ? $o[$i % count($o)] : null,
            'num'  => match (true) {
                str_contains($key, 'progress') || str_contains($key, 'percent') => random_int(10, 90),
                (bool) preg_match('/amount|total|price|cost|salary|budget|value/', $key) => random_int(100, 3000),
                (bool) preg_match('/qty|count|stock|hours|days/', $key) => random_int(1, 30),
                // التقييمُ سُلَّمُ خمسٍ وعمودُه decimal(3,2): «١٠» تجاوزُ مدىً
                // يمرّ على SQLite ويرمي على MySQL — فتسقط بذرةُ العرض كلُّها
                (bool) preg_match('/rating|score|stars/', $key) => random_int(3, 5),
                default => random_int(1, 10),
            },
            'date' => now()->addDays(random_int(-30, 45))->toDateString(),
            'dt'   => now()->subHours(random_int(1, 72))->toDateTimeString(),
            'url'  => 'https://demo.example/' . ($def['key'] ?? 'x') . '/' . ($i + 1),
            'bool' => $i % 2,
            'tags' => json_encode(['تجريبي', 'demo'], JSON_UNESCAPED_UNICODE),
            'ref'  => $this->fakeRef($f, $i),
            'ta'   => 'بياناتٌ تجريبية للاختبار — ' . $label . '. عدّلها بحرية من شاشة التحرير.',
            'file', 'img', 'sec', 'pass', 'big' => null,   // لا ملفات وهمية ولا أسرار مخترعة
            default => $this->fakeText($f, $def, $i, $key, $label),
        };
    }

    /** نصٌّ حسب دلالة العمود: بريد صالح، هاتف كويتي، رقم مستند، وإلا اسمٌ معنون */
    protected function fakeText(array $f, array $def, int $i, string $key, string $label): string
    {
        if (str_contains($key, 'email')) return 'demo' . random_int(100, 999) . '@example.com';
        if (str_contains($key, 'phone') || str_contains($key, 'mobile')) return '+965 5' . random_int(1000000, 9999999);
        if (preg_match('/doc_no|_no$|^no$|code|sku|serial/', $key)) return '🎭D-' . strtoupper(substr((string) Str::uuid(), 0, 6));
        if (str_contains($key, 'currency')) return 'د.ك';
        if (str_contains($key, 'color')) return '#2F6F5E';

        // عمود العرض يُعنون باسم الوحدة فتُقرأ القوائم بلا لبس
        $display = $def['display'] ?? 'name';
        if (($f['col'] ?? '') === $display) {
            return '🎭 ' . ($def['label'] ?? 'سجل') . ' تجريبي ' . ($i + 1);
        }

        return '🎭 ' . $label . ' ' . ($i + 1);
    }

    /** مرجع محلول: مستخدمٌ حقيقي للمسؤولين، وسجلٌ تجريبي (يُولَّد عند اللزوم) لغيره */
    protected function fakeRef(array $f, int $i): mixed
    {
        $target = $f['ref'] ?? null;
        if (! $target) return null;

        if ($target === 'users') {
            $id = DB::table('users')->whereNull('deleted_at')->orderBy('created_at')->value('id');
            if (! $id) return null;
            return empty($f['multi']) ? $id : json_encode([$id]);
        }

        $table = config("hub.modules.{$target}.table");
        if (! $table) return null;
        if (empty($this->pool[$table])) $this->ensureSeeded($target);
        $ids = $this->pool[$table] ?? [];
        if (! $ids) return null;

        $id = $ids[$i % count($ids)];
        return empty($f['multi']) ? $id : json_encode([$id]);
    }

    /** يملأ الإعدادات **الفارغة فقط** ويسجّل أسماءها في demo.settings — فالتصفير يحذفها هي وحدها */
    protected function seedSettings(): void
    {
        $filled = [];
        foreach (self::SETTINGS as $key => $value) {
            if ((string) setting($key, '') !== '') continue;    // قيمة قائمة لا تُمسّ
            \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            $filled[] = $key;
        }
        if ($filled) {
            \App\Models\Setting::updateOrCreate(['key' => 'demo.settings'],
                ['value' => json_encode($filled)]);
        }
        \Illuminate\Support\Facades\Cache::forget('settings:all');
    }
}
