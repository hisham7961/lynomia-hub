<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Attendance;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Engagement;
use App\Models\FinDocument;
use App\Models\Issue;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Station;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkUpdate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * **بيئةُ شركةٍ تجريبيّةٌ واقعيّة** — لتجربةِ المنتجِ كما يعيشه البشر (dogfooding):
 * ثلاثُ شركات، ثلاثون موظّفاً بأدوارٍ متباينة، عملاءُ ومشاريعُ بمراحلَ مختلفة،
 * مئةُ مهمّة، أسابيعُ حضورٍ وتقاريرَ بحالاتِها الطبيعيّة (تأخّر/نسيان/إجازة)،
 * أصولٌ ومحطّاتٌ ووثائقُ وقنواتُ تواصلٍ ورسائل. **بياناتٌ وهميّةٌ بالكامل** —
 * لا تُبذَر في الإنتاج (يفترض CoreSeeder قد سبقها على قاعدةٍ معزولة).
 *
 * كلمةُ مرورِ جميعِ حساباتِ العرض: Demo!2026x
 */
class DemoCompanySeeder extends Seeder
{
    private array $co = [];      // شركات
    private array $u = [];       // مستخدمون بالمفتاح القصير
    private array $emp = [];     // موظّفون (صفوف hr)
    private array $cl = [];      // عملاء
    private array $pr = [];      // مشاريع
    private array $st = [];      // محطّات

    public function run(): void
    {
        mt_srand(20260913);
        $this->companies();
        $this->rolesAndUsers();
        $this->stationsAndAssets();
        $this->clientsAndEngagements();
        $this->projects();
        $this->tasks();
        $this->attendanceAndReports();
        $this->finance();
        $this->serviceDesk();
        $this->documents();
        $this->collaboration();
        $this->notifications();
        $this->command?->info('✔ بيئةُ الشركةِ التجريبيّة جاهزة — الدخول بأيِّ بريدٍ أدناه وكلمة Demo!2026x');
    }

    /* ───────────────────────── الشركات ───────────────────────── */

    private function companies(): void
    {
        foreach ([
            'kw' => 'لينوميا الكويت',
            'ae' => 'لينوميا الإمارات',
            'sa' => 'لينوميا السعودية',
        ] as $k => $name) {
            $this->co[$k] = Company::create(['name_ar' => $name, 'status' => 'نشطة']);
        }
    }

    /* ───────────────────────── الأدوار والمستخدمون ───────────────────────── */

    private function role(string $name, array $mods, array $flags = [], string $scope = 'all', ?array $companies = null, array $fieldRules = []): Role
    {
        $matrix = [];
        foreach ($mods as $m => $ops) $matrix[$m] = $ops;

        // ملاحظة: قيدُ الشركات يعيش على users.companies لا على الدور
        return Role::create(['name' => $name, 'scope' => $scope, 'flags' => $flags,
            'matrix' => $matrix, 'field_rules' => $fieldRules ?: null]);
    }

    private function user(string $key, string $name, string $email, Role $role, ?array $companies = null, string $type = 'internal'): User
    {
        return $this->u[$key] = User::create([
            'name' => $name, 'email' => $email, 'password' => 'Demo!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => $type,
            'password_changed_at' => now()->subDays(40), 'companies' => $companies,
        ]);
    }

    private function employee(string $key, string $co, string $dept, string $title, ?string $mgrKey = null, array $extra = []): Employee
    {
        $u = $this->u[$key];

        $emp = $this->emp[$key] = Employee::create(array_merge([
            'name' => $u->name, 'user_id' => $u->id, 'email' => $u->email,
            'company_id' => $this->co[$co]->id, 'dept' => $dept, 'title' => $title,
            // المدير يُخزَّن بمعرِّف **المستخدم** (Employee::manager → belongsTo User)
            // — تخزينُ معرِّف الموظّف أظهر UUID خاماً عند HR (وكيلا المحاكاة 5 و10)
            'manager_id' => $mgrKey ? ($this->u[$mgrKey]->id ?? null) : null,
            'hired' => now()->subMonths(mt_rand(4, 30))->format('Y-m-d'),
            'contract' => 'دوام كامل', 'status' => 'نشط', 'leave_bal' => 21,
            'phone' => '+965 5' . mt_rand(100, 999) . ' ' . mt_rand(1000, 9999),
        ], $extra));

        /*
         * **عمرُ الحسابِ يوافق عمرَ التعيين** (الجولة 3): كان `User::create` يختم
         * `created_at` بلحظةِ البذر، فيصير **كلُّ** حسابٍ في البيئة عمرُه ساعات
         * بينما صاحبُه معيَّنٌ منذ سنتين. وبطاقةُ «أوّل أسبوع» تظهر — بحقٍّ —
         * لمن حسابُه جديدٌ ولو قدُم عهدُه بالمنشأة (قاعدةٌ مقصودةٌ موثّقةٌ في
         * `Staff::firstWeek`)، فكانت تظهر **للجميع**. وقد بلّغ عنها أربعةُ وكلاءَ
         * بوصفها عطلاً في المنتج، وهي **أثرُ بذرةٍ**: المنطقُ سليمٌ والبياناتُ كاذبة.
         * فيُختم عمرُ الحسابِ من تاريخِ التعيين — ويبقى «أوّلُ يومٍ» أوّلَ يومٍ
         * حقّاً لمن بُذر كذلك عمداً (الموظّفةُ الجديدة).
         */
        if (! empty($emp->hired)) {
            $u->forceFill(['created_at' => \Illuminate\Support\Carbon::parse($emp->hired)
                ->setTime(8, 0)])->saveQuietly();
        }

        return $emp;
    }

    private function rolesAndUsers(): void
    {
        $v = ['v' => 1]; $ve = ['v' => 1, 'e' => 1]; $vae = ['v' => 1, 'a' => 1, 'e' => 1];
        $daily = ['tasks' => $vae, 'updates' => $vae, 'projects' => $v, 'files' => $v, 'leaves' => $vae];

        // ملاحظة مصحَّحة (اكتشفها وكيلُ المحاكاة 4): عاملُ `+` يُبقي مفاتيحَ الطرفِ
        // الأيسر، فكان `$daily + ['projects' => …]` يمحو صلاحياتِ الدورِ المقصودة
        // لصالح `projects => v` — array_merge تُغلِّب الطرفَ الأيمن (المقصود).
        $rOps = $this->role('مدير العمليات', array_merge($daily, [
            'projects' => $vae + ['projTeam' => 1, 'projTech' => 1], 'clients' => $v, 'hr' => $v,
            'assets' => $vae + ['custodyAssign' => 1, 'assetStatus' => 1], 'stations' => $vae,
            'attend' => $v, 'tickets' => $vae, 'issues' => $vae, 'engagements' => $v,
        ]), ['opsAnalytics' => 1, 'approve' => 1]);

        $rPm = $this->role('مدير مشاريع', array_merge($daily, [
            'projects' => $vae + ['projTeam' => 1, 'projTech' => 1, 'assetAssign' => 1],
            'issues' => $vae, 'tickets' => $vae, 'clients' => $v, 'engagements' => $v, 'quotes' => $v,
        ]), []);

        // الموارد البشرية: مهمّتُها المعلنة «تعيينٌ وتهيئة» لا حفظُ ملفّات (الجولة 2 · G15).
        // كانت بلا مفتاحِ فتحِ الحسابات فتنتظر المالكَ ليفتح لكلِّ موظّفٍ حسابَه، وبلا
        // رؤيةِ أصولٍ فلا تسلّم عهدةً في أوّل يوم، وبلا مسارِ توظيفٍ فلا تحوّل مرشّحاً
        // إلى موظّف — ثلاثةُ أبوابٍ يحتاجها التعيينُ الواحد كانت بيد ثلاثة أشخاص.
        $rHr = $this->role('موظّفة موارد بشريّة', array_merge($daily, [
            'hr' => $vae + ['fieldsec' => 1, 'docsec' => 1, 'export' => 1, 'staffAccounts' => 1],
            // docsec على وحدة الوثائق نفسِها: «سري» يُقرأ من hub_scope('files') بها (F21ب)
            'attend' => $ve, 'leaves' => $vae, 'files' => $vae + ['docsec' => 1], 'updates' => $ve,
            'recruit' => $vae,
            // رؤيةُ الأصولِ وإسنادُ العهدةِ وحدَه — بلا تعديلِ مواصفاتِ الأصل (custodyAssign)
            'assets' => $v + ['custodyAssign' => 1],
        ]), []);

        $rAcc = $this->role('محاسب', [
            // bankPost: قبضٌ/صرفٌ يحرّك رصيدَ حسابٍ بنكيّ دون banks:e (F16)
            // exportNight: إقفالُ الشهر ليلاً — استثناءُ التصدير خارج الدوام (F17)
            'fin' => $vae + ['export' => 1, 'fieldsec' => 1, 'exportNight' => 1],
            'banks' => $v + ['fieldsec' => 1, 'bankPost' => 1],
            'purchases' => $vae, 'quotes' => $v + ['fieldsec' => 1],
            // مسيّراتُ الرواتب مهمّةٌ محاسبيّةٌ معلنة — كان يرى الحضورَ ولا يسيّر عليه راتباً
            'payroll' => $vae + ['export' => 1],
            'attend' => $v + ['export' => 1, 'exportNight' => 1],
            'clients' => $v, 'projects' => $v, 'files' => $v, 'updates' => $vae, 'tasks' => $vae, 'leaves' => $vae,
        ], ['finAnalytics' => 1]);

        // مسؤول تقنية المعلومات: كان يملك الأجهزةَ والأصولَ ولا يملك **الحادثة** —
        // لا تذكرةً ولا مشكلةً ولا سيرفراً ولا وحدةَ إدارةِ الحوادث التقنية. فمن
        // يرصد عطلاً يفتح تذكرةً لا يراها من يصلحها (رصده وكيلُ رحلةِ الحادثة).
        $rIt = $this->role('مسؤول تقنية المعلومات', array_merge($daily, [
            'assets' => $vae + ['custodyAssign' => 1, 'assetStatus' => 1, 'assetStation' => 1, 'assetInventory' => 1],
            'stations' => $vae, 'endpoints' => $v + ['command' => 1], 'apps' => $vae, 'dbs' => $vae, 'apis' => $vae,
            'phones' => $v, 'products' => $v,
            'tickets' => $vae, 'issues' => $vae, 'servers' => $vae, 'incidents' => $vae,
        ]), ['secOps' => 1]);

        $rSales = $this->role('موظّف مبيعات', array_merge($daily, [
            'clients' => $vae, 'quotes' => $vae, 'engagements' => $vae, 'social' => $v,
        ]), []);

        $rMkt = $this->role('موظّفة تسويق', array_merge($daily, [
            'clients' => $v, 'social' => $vae, 'media' => $vae, 'designs' => $v,
        ]), []);

        // دورُ فريقٍ خاصٌّ بالعرض بصلاحيّات العمل اليوميّ كاملةً — إعادةُ استخدام
        // «عضو فريق» من CoreSeeder (v فقط) جعلت الموظفَ يرى زرّ «＋ بند عمل» ثم 403
        // (رصدها وكيلا المحاكاة 3 و10).
        $rEmp = $this->role('عضو فريق تشغيلي', $daily, [], 'proj');

        $rRestricted = $this->role('موظّف مقيّد (تقارير فقط)', [
            'updates' => $vae, 'tasks' => $v, 'leaves' => $vae,
        ]);

        $rMonitor = $this->role('مراقب أداء', ['updates' => $v, 'projects' => $v], ['opsAnalytics' => 1]);

        $rClient = $this->role('حساب عميل — بوّابة', [
            'engagements' => $v, 'projects' => $v, 'fin' => $v, 'files' => $v, 'tickets' => $vae,
        ]);

        $mgr = Role::where('name', 'مدير')->first();

        // ── القيادة والإدارة (المالكُ غيث بذرَه CoreSeeder) ──
        $this->user('ops', 'سالم المطيري', 'salem@lynomia-demo.test', $rOps);
        $this->user('pm', 'نورة الهاجري', 'noura@lynomia-demo.test', $rPm);
        $this->user('pm2', 'خالد العتيبي', 'khaled@lynomia-demo.test', $rPm);
        $this->user('hr', 'مريم الكندري', 'maryam@lynomia-demo.test', $rHr);
        $this->user('acc', 'يوسف الحربي', 'yousef@lynomia-demo.test', $rAcc);
        $this->user('it', 'عبدالله الشمري', 'abdullah@lynomia-demo.test', $rIt);
        $this->user('mgr-ae', 'راشد بن حمد', 'rashed@lynomia-demo.test', $mgr, [$this->co['ae']->id]);
        $this->user('sales', 'فهد الدوسري', 'fahad@lynomia-demo.test', $rSales);
        $this->user('mkt', 'دانة العنزي', 'dana@lynomia-demo.test', $rMkt);
        $this->user('monitor', 'منصور الرشيد', 'mansour@lynomia-demo.test', $rMonitor);
        $this->user('restricted', 'بدر المهنّا', 'bader@lynomia-demo.test', $rRestricted);
        $this->user('newbie', 'لطيفة السالم', 'latifa@lynomia-demo.test', $rEmp);

        // ── موظّفو الفرق (١٨ عضوَ فريق عبر الشركات) ──
        $team = [
            ['dev1', 'أحمد قاسم', 'kw', 'تطوير', 'مطوّر واجهات'],
            ['dev2', 'حسين علي', 'kw', 'تطوير', 'مطوّر خلفيّة'],
            ['dev3', 'عمر فاروق', 'kw', 'تطوير', 'مطوّر تطبيقات'],
            ['dev4', 'زينب محمود', 'kw', 'تطوير', 'مطوّرة'],
            ['des1', 'ريم الفضلي', 'kw', 'تصميم', 'مصمّمة UI/UX'],
            ['des2', 'طلال ناصر', 'kw', 'تصميم', 'مصمّم جرافيك'],
            ['qa1', 'هدى سلطان', 'kw', 'تطوير', 'مختبِرة جودة'],
            ['sup1', 'جاسم البلوشي', 'kw', 'دعم', 'دعم فنّي'],
            ['sup2', 'شيخة العجمي', 'kw', 'دعم', 'دعم فنّي'],
            ['ops1', 'ماجد الصانع', 'kw', 'عمليات', 'منسّق عمليات'],
            ['dev5', 'كريم مصطفى', 'ae', 'تطوير', 'مطوّر'],
            ['dev6', 'ليلى حسن', 'ae', 'تطوير', 'مطوّرة'],
            ['des3', 'سارة يوسف', 'ae', 'تصميم', 'مصمّمة'],
            ['sal2', 'محمد العلي', 'ae', 'مبيعات', 'تنفيذي مبيعات'],
            ['dev7', 'تركي السبيعي', 'sa', 'تطوير', 'مطوّر'],
            ['dev8', 'غادة القحطاني', 'sa', 'تطوير', 'مطوّرة'],
            ['mkt2', 'نايف الزهراني', 'sa', 'تسويق', 'أخصائي تسويق'],
            ['ops2', 'وليد باقر', 'sa', 'عمليات', 'منسّق'],
        ];
        foreach ($team as [$k, $name, $co, $dept, $title]) {
            $email = $k . '@lynomia-demo.test';
            $this->user($k, $name, $email, $rEmp);
        }

        // ── حسابا عميلَين خارجيَّين ──
        $this->user('cli1', 'عبير الرومي — الخليج للتأمين', 'abeer@gulf-insure.test', $rClient, null, 'client');
        $this->user('cli2', 'سامي النجّار — النخيل العقارية', 'sami@nakheel-re.test', $rClient, null, 'client');

        // ── ملفّاتُ الموظّفين (hr) — القيادة أوّلاً ثم الفرق ──
        $owner = User::where('email', config('hub.bootstrap.owner_email', 'owner@lynomia.com'))->first();
        $this->u['owner'] = $owner;
        $this->emp['owner'] = Employee::create(['name' => $owner->name, 'user_id' => $owner->id,
            'company_id' => $this->co['kw']->id, 'dept' => 'إدارة', 'title' => 'المالك التنفيذي',
            'hired' => '2023-01-15', 'contract' => 'دوام كامل', 'status' => 'نشط']);

        $this->employee('ops', 'kw', 'عمليات', 'مدير العمليات', 'owner', ['salary' => 2400, 'civil_id' => '287051234567', 'iban' => 'KW81CBKU0000000000001234560101']);
        $this->employee('pm', 'kw', 'عمليات', 'مديرة مشاريع', 'ops', ['salary' => 1900]);
        $this->employee('pm2', 'ae', 'عمليات', 'مدير مشاريع', 'ops', ['salary' => 1850]);
        $this->employee('hr', 'kw', 'إدارة', 'مسؤولة الموارد البشريّة', 'ops', ['salary' => 1600]);
        $this->employee('acc', 'kw', 'محاسبة', 'محاسب أوّل', 'ops', ['salary' => 1700, 'iban' => 'KW45NBOK0000000000009876540101']);
        $this->employee('it', 'kw', 'دعم', 'مسؤول تقنية المعلومات', 'ops', ['salary' => 1750]);
        $this->employee('mgr-ae', 'ae', 'إدارة', 'مدير فرع الإمارات', 'owner');
        $this->employee('sales', 'kw', 'مبيعات', 'مسؤول مبيعات', 'ops');
        $this->employee('mkt', 'kw', 'تسويق', 'مسؤولة تسويق', 'ops');
        $this->employee('monitor', 'kw', 'إدارة', 'مراقب أداء', 'owner');
        $this->employee('restricted', 'kw', 'عمليات', 'موظّف ميدانيّ', 'ops');
        $this->employee('newbie', 'kw', 'تطوير', 'مطوّرة مبتدئة (أوّل يوم)', 'pm', ['hired' => now()->format('Y-m-d')]);
        foreach ($team as [$k, $name, $co, $dept, $title]) {
            $mgrKey = $co === 'kw' ? 'ops' : ($co === 'ae' ? 'mgr-ae' : 'ops');
            $this->employee($k, $co, $dept, $title, $mgrKey, ['salary' => mt_rand(900, 1500)]);
        }
    }

    /* ───────────────────────── المحطّات والأصول ───────────────────────── */

    private function stationsAndAssets(): void
    {
        $stations = [
            ['ST-KW-01', 'المقرّ الرئيسي — برج التحرير', '12', 'مكتب', 'تطوير', 'kw'],
            ['ST-KW-02', 'المقرّ الرئيسي — برج التحرير', '12', 'مكتب', 'تصميم', 'kw'],
            ['ST-KW-03', 'المقرّ الرئيسي — برج التحرير', '11', 'قاعة اجتماعات', 'إدارة', 'kw'],
            ['ST-KW-04', 'المقرّ الرئيسي — برج التحرير', 'B1', 'مستودع', 'دعم', 'kw'],
            ['ST-AE-01', 'مكتب دبي — الخليج التجاري', '7', 'مكتب', 'تطوير', 'ae'],
            ['ST-SA-01', 'مكتب الرياض — العليّا', '3', 'مكتب', 'تطوير', 'sa'],
        ];
        foreach ($stations as [$code, $facility, $floor, $type, $dept, $co]) {
            $this->st[$code] = Station::create(['code' => $code, 'facility' => $facility,
                'floor' => $floor, 'type' => $type, 'dept' => $dept,
                'company_id' => $this->co[$co]->id, 'status' => 'مشغولة']);
        }

        $holders = ['ops', 'pm', 'pm2', 'hr', 'acc', 'it', 'sales', 'mkt', 'dev1', 'dev2', 'dev3', 'dev4',
            'des1', 'des2', 'qa1', 'sup1', 'sup2', 'ops1', 'dev5', 'dev6', 'des3', 'sal2', 'dev7', 'dev8'];
        $i = 0;
        foreach ($holders as $k) {
            $i++;
            $co = $this->emp[$k]->company_id;
            $stCode = str_starts_with((string) Company::find($co)?->name_ar, 'لينوميا الإمارات') ? 'ST-AE-01'
                : (str_contains((string) Company::find($co)?->name_ar, 'السعودية') ? 'ST-SA-01' : 'ST-KW-0' . (($i % 2) + 1));
            Asset::create([
                'code' => sprintf('LYN-LT-%03d', $i), 'name' => 'لابتوب ' . $this->u[$k]->name,
                'type' => 'لابتوب', 'serial' => 'SN-DL-' . mt_rand(100000, 999999),
                'company_id' => $co, 'holder_id' => $this->u[$k]->id,
                'station_id' => $this->st[$stCode]->id, 'status' => 'قيد الاستخدام',
                'vendor' => 'شركة الحاسبات المتحدة', 'buy_date' => now()->subMonths(mt_rand(3, 20))->format('Y-m-d'),
                'price' => mt_rand(280, 520),
            ]);
        }
        foreach (['ops', 'pm', 'sales', 'it', 'mgr-ae', 'sal2'] as $j => $k) {
            Asset::create(['code' => sprintf('LYN-PH-%03d', $j + 1), 'name' => 'هاتف عمل ' . $this->u[$k]->name,
                'type' => 'هاتف', 'company_id' => $this->emp[$k]->company_id,
                'holder_id' => $this->u[$k]->id, 'status' => 'قيد الاستخدام',
                'buy_date' => now()->subMonths(mt_rand(2, 14))->format('Y-m-d'), 'price' => mt_rand(120, 350)]);
        }
        foreach ([['LYN-SRV-001', 'خادم التطبيقات الرئيسي', 'kw'], ['LYN-SRV-002', 'خادم النسخ الاحتياطي', 'kw'],
                  ['LYN-SRV-003', 'خادم بيئة الاختبار', 'ae']] as [$code, $name, $co]) {
            Asset::create(['code' => $code, 'name' => $name, 'type' => 'سيرفر',
                'company_id' => $this->co[$co]->id, 'station_id' => $this->st[$co === 'ae' ? 'ST-AE-01' : 'ST-KW-04']->id,
                'status' => 'قيد الاستخدام', 'price' => mt_rand(900, 2400)]);
        }
        for ($j = 1; $j <= 12; $j++) {
            Asset::create(['code' => sprintf('LYN-SC-%03d', $j), 'name' => 'شاشة عرض ٢٧ بوصة',
                'type' => 'شاشة', 'company_id' => $this->co['kw']->id,
                'station_id' => $this->st['ST-KW-0' . (($j % 2) + 1)]->id,
                'status' => $j <= 9 ? 'قيد الاستخدام' : 'متاح', 'price' => 85]);
        }
        // أصولٌ متاحةٌ للتسليم + حالاتٌ واقعيّة
        for ($j = 1; $j <= 6; $j++) {
            Asset::create(['code' => sprintf('LYN-LT-9%02d', $j), 'name' => 'لابتوب احتياطي ' . $j,
                'type' => 'لابتوب', 'company_id' => $this->co['kw']->id,
                'station_id' => $this->st['ST-KW-04']->id, 'status' => 'متاح', 'price' => 300]);
        }
        Asset::create(['code' => 'LYN-LT-950', 'name' => 'لابتوب قيد الصيانة', 'type' => 'لابتوب',
            'company_id' => $this->co['kw']->id, 'status' => 'صيانة', 'notes' => 'مشكلةُ شاشةٍ — لدى المورّد منذ الأسبوع الماضي']);
        Asset::create(['code' => 'LYN-PH-990', 'name' => 'هاتف مفقود — بلاغ', 'type' => 'هاتف',
            'company_id' => $this->co['kw']->id, 'status' => 'مفقود', 'notes' => 'أُبلغ عن فقده في مهمّة ميدانيّة ٢٠٢٦-٠٩-٠٢']);
        Asset::create(['code' => 'LYN-PRN-001', 'name' => 'طابعة الإدارة', 'type' => 'طابعة',
            'company_id' => $this->co['kw']->id, 'station_id' => $this->st['ST-KW-03']->id, 'status' => 'قيد الاستخدام']);

        // أجهزةٌ طرفيّةٌ مسجّلة (وكيل MDM)
        foreach ([['dev1', 'LT-AHMED-01'], ['dev2', 'LT-HUSSAIN-01'], ['it', 'LT-ADMIN-01'], ['dev5', 'LT-KARIM-AE']] as [$k, $host]) {
            $asset = Asset::where('holder_id', $this->u[$k]->id)->where('type', 'لابتوب')->first();
            DB::table('endpoint_devices')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'hostname' => $host,
                'device_uuid' => (string) \Illuminate\Support\Str::uuid(), 'os' => 'Windows 11 Pro',
                'company_id' => $this->emp[$k]->company_id, 'employee_id' => $this->u[$k]->id,
                'asset_id' => $asset?->id, 'station_id' => $asset?->station_id, 'status' => 'active',
                'last_heartbeat_at' => now()->subMinutes(mt_rand(5, 240)),
                'created_at' => now()->subDays(30), 'updated_at' => now(),
            ]);
        }
    }

    /* ───────────────────────── العملاء والارتباطات ───────────────────────── */

    private function clientsAndEngagements(): void
    {
        $clients = [
            ['gulf', 'شركة الخليج للتأمين', 'عبير الرومي', 'الكويت', 'عميل حالي', 45000, 1],
            ['nakheel', 'مجموعة النخيل العقارية', 'سامي النجّار', 'الكويت', 'عميل حالي', 60000, 1],
            ['salam', 'مستشفى السلام الدولي', 'د. هيا المضف', 'الكويت', 'عميل حالي', 28000, 0],
            ['ofoq', 'شركة أفق للاتصالات', 'ناصر العوضي', 'الإمارات', 'تفاوض', 35000, 0],
            ['madina', 'بنك المدينة', 'لمى الشايع', 'السعودية', 'عرض سعر', 90000, 1],
            ['zahra', 'مطاعم الزهرة', 'أبو محمد', 'الكويت', 'عميل محتمل', 6000, 0],
        ];
        foreach ($clients as [$k, $name, $contact, $country, $stage, $value, $vip]) {
            $this->cl[$k] = Client::create(['name' => $name, 'contact' => $contact,
                'country' => $country, 'stage' => $stage, 'value' => $value, 'vip' => $vip,
                'email' => 'info@' . $k . '.test', 'phone' => '+965 2' . mt_rand(200, 299) . ' ' . mt_rand(1000, 9999),
                'owner_id' => $this->u['sales']->id, 'source' => 'إحالة']);
        }

        ClientMembership::create(['client_id' => $this->cl['gulf']->id, 'user_id' => $this->u['cli1']->id,
            'role' => 'lead', 'status' => 'active', 'activated_at' => now()->subMonths(3)]);
        ClientMembership::create(['client_id' => $this->cl['nakheel']->id, 'user_id' => $this->u['cli2']->id,
            'role' => 'lead', 'status' => 'active', 'activated_at' => now()->subMonths(2)]);

        foreach ([
            ['بوّابة الخليج للتأمين — تطوير وتشغيل', 'gulf', 'تنفيذ مشروع', 'نشط', 45000],
            ['عقد صيانة النخيل الشهري', 'nakheel', 'عقد شهري (Retainer)', 'نشط', 2500],
            ['نظام مواعيد مستشفى السلام', 'salam', 'تنفيذ مشروع', 'نشط', 28000],
            ['استشارة تحوّل رقمي — أفق', 'ofoq', 'استشارة', 'محتمل', 12000],
            ['تطبيق بنك المدينة', 'madina', 'تنفيذ مشروع', 'محتمل', 90000],
        ] as [$name, $ck, $type, $status, $rev]) {
            Engagement::create(['name' => $name, 'client_id' => $this->cl[$ck]->id, 'type' => $type,
                'status' => $status, 'revenue' => $rev, 'currency' => 'د.ك', 'billing' => 'دفعات مراحل',
                'date_start' => now()->subMonths(mt_rand(1, 6))->format('Y-m-d'),
                'am_id' => $this->u['sales']->id, 'pm_id' => $this->u['pm']->id]);
        }
    }

    /* ───────────────────────── المشاريع ───────────────────────── */

    private function projects(): void
    {
        $mk = function (string $k, array $a): Project {
            $p = Project::create($a);

            return $this->pr[$k] = $p;
        };

        $members = fn (array $keys) => array_map(fn ($k) => (string) $this->u[$k]->id, $keys);

        $mk('gulf', ['name' => 'بوّابة الخليج للتأمين', 'type' => 'نظام داخلي', 'status' => 'قيد التنفيذ',
            'priority' => 'عالية', 'progress' => 65, 'company_id' => $this->co['kw']->id,
            'client_id' => $this->cl['gulf']->id, 'manager_id' => $this->u['pm']->id,
            'members' => $members(['dev1', 'dev2', 'des1', 'qa1']),
            'start_date' => now()->subMonths(3)->format('Y-m-d'), 'launch_exp' => now()->addMonth()->format('Y-m-d'),
            'budget' => 45000, 'cost' => 21000, 'rev_exp' => 45000, 'currency' => 'د.ك',
            'description' => 'بوّابة إلكترونيّة لعملاء التأمين: إصدار الوثائق، المطالبات، والدفع الإلكتروني.']);
        Project::whereKey($this->pr['gulf']->id)->update(['audience' => 'client']);

        $mk('salam', ['name' => 'نظام مواعيد مستشفى السلام', 'type' => 'تطبيق', 'status' => 'قيد التنفيذ',
            'priority' => 'عاجلة', 'progress' => 38, 'company_id' => $this->co['kw']->id,
            'client_id' => $this->cl['salam']->id, 'manager_id' => $this->u['pm']->id,
            'members' => $members(['dev3', 'dev4', 'des2']),
            'start_date' => now()->subMonths(2)->format('Y-m-d'),
            'launch_exp' => now()->subDays(5)->format('Y-m-d'),   // متأخّر عن موعده
            'budget' => 28000, 'cost' => 16500, 'currency' => 'د.ك',
            'description' => 'حجز مواعيد العيادات وتذكيرات المرضى وتكامل مع نظام المستشفى.']);

        $mk('nakheel', ['name' => 'صيانة موقع النخيل العقارية', 'type' => 'موقع', 'status' => 'نشط',
            'priority' => 'متوسطة', 'progress' => 80, 'company_id' => $this->co['kw']->id,
            'client_id' => $this->cl['nakheel']->id, 'manager_id' => $this->u['pm2']->id,
            'members' => $members(['sup1', 'sup2']),
            'start_date' => now()->subMonths(6)->format('Y-m-d'), 'budget' => 7500, 'cost' => 3200, 'currency' => 'د.ك',
            'description' => 'عقد صيانة شهريّ: تحديثات المحتوى، النسخ الاحتياطي، ومراقبة الأداء.']);
        Project::whereKey($this->pr['nakheel']->id)->update(['audience' => 'client']);

        $mk('erp', ['name' => 'نظام لينوميا الداخلي — تحسينات', 'type' => 'نظام داخلي', 'status' => 'نشط',
            'priority' => 'متوسطة', 'progress' => 55, 'company_id' => $this->co['kw']->id,
            'manager_id' => $this->u['ops']->id, 'members' => $members(['dev1', 'dev2', 'qa1', 'it']),
            'start_date' => now()->subMonths(4)->format('Y-m-d'), 'budget' => 9000, 'cost' => 4100, 'currency' => 'د.ك',
            'description' => 'تحسيناتُ النظام الداخلي: تقارير، صلاحيّات، وأتمتة العمليّات.']);

        $mk('madina', ['name' => 'تطبيق بنك المدينة — دراسة وتصميم', 'type' => 'تطبيق', 'status' => 'تخطيط',
            'priority' => 'عالية', 'progress' => 10, 'company_id' => $this->co['sa']->id,
            'client_id' => $this->cl['madina']->id, 'manager_id' => $this->u['pm2']->id,
            'members' => $members(['dev7', 'dev8', 'des3']),
            'start_date' => now()->addWeek()->format('Y-m-d'), 'budget' => 90000, 'currency' => 'ر.س',
            'description' => 'مرحلة الدراسة والتصميم الأوّلي لتطبيق الخدمات المصرفيّة.']);

        $mk('ofoq', ['name' => 'تحوّل أفق الرقمي — المرحلة الأولى', 'type' => 'خدمة', 'status' => 'مراجعة',
            'priority' => 'متوسطة', 'progress' => 92, 'company_id' => $this->co['ae']->id,
            'client_id' => $this->cl['ofoq']->id, 'manager_id' => $this->u['pm2']->id,
            'members' => $members(['dev5', 'dev6']),
            'start_date' => now()->subMonths(3)->format('Y-m-d'), 'launch_exp' => now()->addDays(10)->format('Y-m-d'),
            'budget' => 12000, 'cost' => 9800, 'currency' => 'د.إ',
            'description' => 'تقييم الأنظمة الحاليّة وخارطة طريق التحوّل — بانتظار المراجعة النهائيّة.']);

        $mk('website', ['name' => 'موقع لينوميا الجديد', 'type' => 'موقع', 'status' => 'مكتمل',
            'priority' => 'منخفضة', 'progress' => 100, 'company_id' => $this->co['kw']->id,
            'manager_id' => $this->u['mkt']->id, 'members' => $members(['des1', 'des2', 'dev3']),
            'start_date' => now()->subMonths(8)->format('Y-m-d'), 'launch_act' => now()->subMonths(2)->format('Y-m-d'),
            'budget' => 3000, 'cost' => 2750, 'currency' => 'د.ك',
            'description' => 'إعادة تصميم الموقع التعريفي وإطلاقه — اكتمل وأُطلق.']);

        $mk('paused', ['name' => 'متجر الزهرة الإلكتروني', 'type' => 'متجر إلكتروني', 'status' => 'متوقف',
            'priority' => 'منخفضة', 'progress' => 25, 'company_id' => $this->co['kw']->id,
            'client_id' => $this->cl['zahra']->id, 'manager_id' => $this->u['pm']->id,
            'members' => $members(['dev4']), 'start_date' => now()->subMonths(2)->format('Y-m-d'),
            'budget' => 6000, 'cost' => 1400, 'currency' => 'د.ك',
            'description' => 'متوقّف بانتظار اعتماد العميل للميزانيّة المعدّلة.']);

        $mk('dubai', ['name' => 'تشغيل مكتب دبي', 'type' => 'أخرى', 'status' => 'نشط',
            'priority' => 'عالية', 'progress' => 45, 'company_id' => $this->co['ae']->id,
            'manager_id' => $this->u['mgr-ae']->id, 'members' => $members(['dev5', 'dev6', 'des3', 'sal2']),
            'start_date' => now()->subMonth()->format('Y-m-d'), 'budget' => 15000, 'currency' => 'د.إ',
            'description' => 'تأسيس فريق دبي وتجهيز المكتب وبدء العمليّات.']);

        // أصولٌ مخصَّصةٌ لمشاريع (علاقة تشغيليّة)
        $svc = new \App\Support\Assets\AssetProjectService();
        $owner = $this->u['owner'];
        foreach ([['LYN-SRV-001', 'gulf', 'خادم بيئة العميل'], ['LYN-SRV-003', 'ofoq', 'بيئة اختبار المرحلة الأولى'],
                  ['LYN-LT-901', 'salam', 'جهاز اختبار ميدانيّ في المستشفى']] as [$code, $pk, $purpose]) {
            $asset = Asset::where('code', $code)->first();
            if ($asset) $svc->assign($asset, $this->pr[$pk], $owner, $purpose);
        }
    }

    /* ───────────────────────── المهامّ ───────────────────────── */

    private function tasks(): void
    {
        $catalog = [
            'gulf' => [
                ['تصميم شاشة إصدار الوثيقة', 'des1', 'منجزة', -20, 'عالية'],
                ['تطوير واجهة المطالبات', 'dev1', 'قيد التنفيذ', 3, 'عالية'],
                ['ربط بوّابة الدفع K-Net', 'dev2', 'قيد التنفيذ', -2, 'عاجلة'],
                ['اختبار رحلة إصدار الوثيقة', 'qa1', 'جديدة', 6, 'متوسطة'],
                ['صفحة الأسئلة الشائعة', 'des1', 'جديدة', 9, 'منخفضة'],
                ['معالجة ملاحظات العميل على النماذج', 'dev1', 'قيد التنفيذ', 1, 'عالية'],
                ['توثيق واجهات API للعميل', 'dev2', 'جديدة', 12, 'متوسطة'],
                ['تحسين سرعة صفحة الوثائق', 'dev1', 'متوقفة', 4, 'متوسطة'],
            ],
            'salam' => [
                ['شاشة حجز الموعد للمريض', 'dev3', 'قيد التنفيذ', -1, 'عاجلة'],
                ['تكامل رسائل التذكير SMS', 'dev4', 'قيد التنفيذ', 2, 'عالية'],
                ['تصميم لوحة الاستقبال', 'des2', 'منجزة', -8, 'عالية'],
                ['ربط جدول أطبّاء العيادات', 'dev3', 'جديدة', 5, 'عالية'],
                ['اختبار تعارض المواعيد', 'dev4', 'جديدة', 7, 'متوسطة'],
                ['مراجعة أمان بيانات المرضى', 'dev3', 'جديدة', 4, 'عاجلة'],
            ],
            'nakheel' => [
                ['تحديث صور المشاريع الجديدة', 'sup1', 'منجزة', -4, 'متوسطة'],
                ['نسخة احتياطيّة شهريّة وتقرير', 'sup2', 'منجزة', -2, 'متوسطة'],
                ['إصلاح نموذج التواصل', 'sup1', 'قيد التنفيذ', 1, 'عالية'],
                ['تقرير أداء الموقع لشهر ٨', 'sup2', 'منجزة', -10, 'منخفضة'],
            ],
            'erp' => [
                ['تقرير الحضور الشهري للمحاسبة', 'dev1', 'منجزة', -15, 'عالية'],
                ['أتمتة تذكير التقارير اليوميّة', 'dev2', 'قيد التنفيذ', 4, 'متوسطة'],
                ['صفحة جرد الأصول السنويّ', 'it', 'جديدة', 14, 'متوسطة'],
                ['تحسين شاشة الصلاحيّات', 'dev2', 'قيد التنفيذ', 6, 'متوسطة'],
                ['ترحيل أرشيف ٢٠٢٤', 'dev1', 'متوقفة', 20, 'منخفضة'],
            ],
            'madina' => [
                ['جمع متطلّبات الخدمات المصرفيّة', 'pm2', 'قيد التنفيذ', 5, 'عالية'],
                ['دراسة تكامل أنظمة البنك', 'dev7', 'جديدة', 10, 'عالية'],
                ['مسوّدة الهويّة البصريّة للتطبيق', 'des3', 'جديدة', 8, 'متوسطة'],
            ],
            'ofoq' => [
                ['تقرير التقييم النهائي', 'dev5', 'قيد التنفيذ', 2, 'عاجلة'],
                ['عرض خارطة الطريق للإدارة', 'pm2', 'جديدة', 9, 'عالية'],
                ['تسليم كلمات المرور والأصول', 'dev6', 'جديدة', 10, 'متوسطة'],
            ],
            'dubai' => [
                ['تجهيز شبكة المكتب', 'dev5', 'منجزة', -6, 'عالية'],
                ['توظيف مصمّم إضافي', 'mgr-ae', 'قيد التنفيذ', 15, 'متوسطة'],
                ['فتح حساب بنكي تشغيلي', 'mgr-ae', 'قيد التنفيذ', 3, 'عالية'],
                ['خطّة مبيعات الربع الرابع', 'sal2', 'جديدة', 12, 'عالية'],
            ],
            'paused' => [
                ['بانتظار اعتماد الميزانيّة المعدّلة', 'pm', 'متوقفة', 30, 'منخفضة'],
            ],
        ];

        $descs = ['حسب المواصفات المتّفق عليها مع العميل.', 'يُنسَّق مع فريق التصميم قبل البدء.',
            'راجع محضر الاجتماع الأخير للتفاصيل.', 'التسليم يشمل التوثيق والاختبار.', ''];

        foreach ($catalog as $pk => $rows) {
            foreach ($rows as $i => [$title, $assignee, $status, $dueOffset, $priority]) {
                Task::create(['title' => $title, 'project_id' => $this->pr[$pk]->id,
                    'assignee_id' => $this->u[$assignee]->id, 'status' => $status, 'priority' => $priority,
                    'due' => now()->addDays($dueOffset)->format('Y-m-d'),
                    'est_h' => mt_rand(4, 24), 'progress' => $status === 'منجزة' ? 100 : ($status === 'قيد التنفيذ' ? mt_rand(20, 80) : 0),
                    // ختمُ الإنجاز مصدرُ صدقِ «أُنجز» في لوحات الأداء — بذرُ مهمّةٍ
                    // منجزةٍ بلا ختمٍ جعل اللوحةَ تعدّ «0» (وكيل المحاكاة 2)
                    'completed_at' => $status === 'منجزة'
                        ? now()->addDays(min($dueOffset, 0))->subHours(mt_rand(1, 20)) : null,
                    'description' => $descs[$i % count($descs)]]);
            }
        }

        // حشوٌ واقعيّ إضافيّ حتى نتجاوز ١٠٠ مهمّة (أعمالٌ صغيرةٌ متكرّرة)
        $small = ['مراجعة كود', 'اجتماع متابعة أسبوعي', 'تحديث توثيق', 'إصلاح ملاحظة عميل',
            'تحسين أداء استعلام', 'مراجعة تصميم', 'اختبار إصدار', 'تجهيز عرض تقديمي'];
        $pool = ['dev1', 'dev2', 'dev3', 'dev4', 'des1', 'des2', 'qa1', 'sup1', 'dev5', 'dev6', 'dev7', 'dev8'];
        $pks = array_keys($this->pr);
        for ($i = 0; $i < 70; $i++) {
            $pk = $pks[$i % count($pks)];
            $st = ['منجزة', 'منجزة', 'قيد التنفيذ', 'جديدة'][$i % 4];
            Task::create(['title' => $small[$i % count($small)] . ' — ' . $this->pr[$pk]->name,
                'project_id' => $this->pr[$pk]->id, 'assignee_id' => $this->u[$pool[$i % count($pool)]]->id,
                'status' => $st, 'priority' => ['متوسطة', 'منخفضة', 'عالية'][$i % 3],
                'due' => now()->addDays(($i % 21) - 7)->format('Y-m-d'),
                'progress' => $st === 'منجزة' ? 100 : ($st === 'قيد التنفيذ' ? 50 : 0),
                'completed_at' => $st === 'منجزة' ? now()->subDays(($i % 14) + 1)->subHours($i % 9) : null]);
        }
    }

    /* ───────────────────────── الحضور والتقارير (٣ أسابيع) ───────────────────────── */

    private function attendanceAndReports(): void
    {
        $staff = ['dev1' => 'gulf', 'dev2' => 'gulf', 'dev3' => 'salam', 'dev4' => 'salam',
            'des1' => 'gulf', 'qa1' => 'erp', 'sup1' => 'nakheel', 'dev5' => 'ofoq',
            'ops1' => 'erp', 'restricted' => null];

        $days = [];
        $d = Carbon::parse('2026-08-24');
        while (count($days) < 15) {                        // ١٥ يومَ عملٍ (أحد–خميس)
            if (! in_array($d->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY])) $days[] = $d->copy();
            $d->addDay();
        }

        foreach ($staff as $k => $pk) {
            $emp = $this->emp[$k];
            foreach ($days as $di => $day) {
                $seed = crc32($k . $di);
                // إجازةٌ معتمدةٌ يومَ ٧ لريم، وغيابٌ بلا تسجيلٍ يومَ ١١ لبدر
                if ($k === 'des1' && $di === 7) continue;
                if ($k === 'restricted' && $di === 11) continue;

                $late = $seed % 9 === 0;                                    // تأخّرٌ أحياناً
                $noOut = $seed % 11 === 0;                                  // نسيانُ الانصراف
                $in = sprintf('%02d:%02d:%02d', $late ? 9 : 8, ($seed % 50) + ($late ? 5 : 0), $seed % 60);
                $out = sprintf('%02d:%02d:%02d', 16 + ($seed % 2), ($seed % 55), ($seed * 7) % 60);
                $hours = $noOut ? null : round((strtotime($out) - strtotime($in)) / 3600, 2);

                Attendance::create(['company_id' => $emp->company_id, 'emp_id' => $emp->id,
                    'mode' => $k === 'restricted' ? 'عمل ميداني' : ($seed % 7 === 0 ? 'عن بعد' : 'مكتب'),
                    'project_id' => $pk ? $this->pr[$pk]->id : null,
                    'date' => $day->format('Y-m-d'), 'time_in' => $in,
                    'time_out' => $noOut ? null : $out, 'hours' => $hours,
                    'status' => $late ? 'متأخر' : 'حاضر',
                    'created_at' => $day->copy()->setTimeFromTimeString($in),
                    'updated_at' => $day->copy()->setTimeFromTimeString($noOut ? $in : $out)]);

                // التقريرُ اليوميّ — يغيب أحياناً (لكشفِ رقابةِ الامتثال)
                if ($seed % 8 === 0 || $k === 'restricted' && $di % 3 === 2) continue;
                $done = [
                    'أنجزتُ العمل على ' . ($pk ? $this->pr[$pk]->name : 'المهامّ الميدانيّة') . ' وفق الخطّة.',
                    'متابعةُ الملاحظات وإغلاقُ بندَين من قائمة المراجعة.',
                    'اجتماعُ تنسيقٍ مع الفريق ثم تنفيذُ المتّفق عليه.',
                    'تطويرُ الشاشة المطلوبة واختبارُها مبدئيّاً.',
                ][$seed % 4];
                $w = WorkUpdate::create(['work_date' => $day->format('Y-m-d'),
                    'project_id' => $pk ? $this->pr[$pk]->id : null,
                    'done' => $done, 'hours' => $hours ? min($hours, 8) : 7,
                    'problems' => $seed % 6 === 0 ? 'تأخّرُ ردِّ العميل على الاستفسار الفنّي.' : null,
                    'next' => 'استكمالُ المتبقّي غداً.']);
                $w->forceFill(['created_by' => $this->u[$k]->id,
                    'created_at' => $day->copy()->setTime(17, mt_rand(0, 45)),
                    'review_status' => $di < 10 ? 'accepted' : 'pending_review',
                    'reviewed_by' => $di < 10 ? $this->u['ops']->id : null,
                    'reviewed_at' => $di < 10 ? $day->copy()->setTime(18, 30) : null])->save();
            }
        }

        // إجازاتٌ بحالاتٍ مختلفة
        LeaveRequest::create(['company_id' => $this->co['kw']->id, 'emp_id' => $this->emp['des1']->id,
            'type' => 'إجازة سنوية', 'date_from' => $days[7]->format('Y-m-d'), 'date_to' => $days[7]->format('Y-m-d'),
            'days' => 1, 'reason' => 'ظرف عائلي', 'status' => 'معتمد', 'mgr_id' => $this->u['ops']->id]);
        LeaveRequest::create(['company_id' => $this->co['kw']->id, 'emp_id' => $this->emp['dev2']->id,
            'type' => 'إجازة مرضية', 'date_from' => now()->addDays(1)->format('Y-m-d'),
            'date_to' => now()->addDays(2)->format('Y-m-d'), 'days' => 2,
            'reason' => 'موعد طبّي وتقرير مرفق لاحقاً', 'status' => 'مقدّم']);
        LeaveRequest::create(['company_id' => $this->co['kw']->id, 'emp_id' => $this->emp['qa1']->id,
            'type' => 'إذن خروج', 'date_from' => now()->format('Y-m-d'), 'date_to' => now()->format('Y-m-d'),
            'days' => 0, 'reason' => 'مراجعة جهة حكوميّة ساعتين', 'status' => 'موافقة المدير', 'mgr_id' => $this->u['ops']->id]);
        LeaveRequest::create(['company_id' => $this->co['ae']->id, 'emp_id' => $this->emp['dev5']->id,
            'type' => 'عمل عن بعد', 'date_from' => now()->addDays(3)->format('Y-m-d'),
            'date_to' => now()->addDays(3)->format('Y-m-d'), 'days' => 1, 'reason' => 'صيانة منزليّة', 'status' => 'مقدّم']);
    }

    /* ───────────────────────── الماليّة ───────────────────────── */

    private function finance(): void
    {
        foreach ([
            ['حساب التشغيل الرئيسي', 'بنك الكويت الوطني', 'KW81NBOK0000000000001234567890', 'kw', 42500],
            ['حساب الرواتب', 'بيت التمويل الكويتي', 'KW54KFHO0000000000009876543210', 'kw', 18200],
            ['حساب دبي التشغيلي', 'بنك الإمارات دبي الوطني', 'AE070331234567890123456', 'ae', 9400],
        ] as [$name, $bank, $iban, $co, $bal]) {
            BankAccount::create(['name' => $name, 'bank' => $bank, 'iban' => $iban, 'kind' => 'جاري',
                'currency' => $co === 'ae' ? 'د.إ' : 'د.ك', 'company_id' => $this->co[$co]->id,
                'balance' => $bal, 'status' => 'نشط']);
        }

        $n = 0;
        $mkFin = function (string $kind, ?string $ck, ?string $pk, float $total, float $paid, string $state, int $daysAgo, ?string $desc = null) use (&$n) {
            $n++;
            FinDocument::create(['doc_no' => sprintf('%s-2026-%03d', $kind === 'فاتورة مبيعات' ? 'INV' : ($kind === 'دفعة واردة' ? 'RCV' : 'EXP'), $n),
                'kind' => $kind, 'client_id' => $ck ? $this->cl[$ck]->id : null,
                'project_id' => $pk ? $this->pr[$pk]->id : null,
                'company_id' => $this->co['kw']->id,
                'date' => now()->subDays($daysAgo)->format('Y-m-d'),
                'due' => now()->subDays($daysAgo - 30)->format('Y-m-d'),
                'amount' => $total, 'total' => $total, 'paid' => $paid, 'currency' => 'د.ك',
                'state' => $state, 'description' => $desc,
                'created_at' => now()->subDays($daysAgo)]);
        };

        $mkFin('فاتورة مبيعات', 'gulf', 'gulf', 15000, 15000, 'مدفوعة', 75, 'الدفعة الأولى — بوّابة التأمين');
        $mkFin('فاتورة مبيعات', 'gulf', 'gulf', 15000, 7500, 'مدفوعة جزئياً', 40, 'الدفعة الثانية — منتصف المشروع');
        $mkFin('فاتورة مبيعات', 'salam', 'salam', 9500, 0, 'متأخرة', 50, 'دفعة التصميم والتحليل');
        $mkFin('فاتورة مبيعات', 'salam', 'salam', 9000, 0, 'مرسلة', 12, 'دفعة التطوير الأولى');
        $mkFin('فاتورة مبيعات', 'nakheel', 'nakheel', 2500, 2500, 'مدفوعة', 35, 'صيانة أغسطس');
        $mkFin('فاتورة مبيعات', 'nakheel', 'nakheel', 2500, 0, 'مرسلة', 5, 'صيانة سبتمبر');
        $mkFin('فاتورة مبيعات', 'ofoq', 'ofoq', 6000, 6000, 'مدفوعة', 60, 'دفعة الاستشارة الأولى');
        $mkFin('دفعة واردة', 'gulf', null, 7500, 7500, 'مدفوعة', 38, 'تحويل بنكي — الخليج للتأمين');
        $mkFin('دفعة واردة', 'nakheel', null, 2500, 2500, 'مدفوعة', 33, 'شيك — النخيل');
        for ($i = 1; $i <= 8; $i++) {
            /*
             * **المدفوعُ يُشتقّ من الإجماليّ لا يُقرَع بجانبه** (الجولة 3): كان
             * السطرُ يستدعي `mt_rand` مرّتين مستقلّتين — واحدةً للإجماليّ وأخرى
             * للمدفوع — فيخرج بالصدفة مصروفٌ مدفوعُه أكبرُ من إجماليّه
             * (EXP-2026-013: إجماليّ 319 ومدفوع 594). وقد بلّغ عنه وكيلُ المالية
             * بوصفه عيباً في المنتج، وهو **عيبُ بذرتي أنا**: حارسُ المنتج سليمٌ
             * وقد أثبت الوكيلُ نفسُه أنّه يقصّ دفعةً زائدة. وبياناتٌ فاسدةٌ في
             * بيئة التجريب أسوأُ من قلّةِ بيانات: تُنفق وقتَ من يُطاردها عيباً.
             */
            $expTotal = mt_rand(80, 900);
            $expPaid  = $i % 2 === 0 ? $expTotal : 0;      // مدفوعةٌ بالكامل أو غيرُ مدفوعة
            $mkFin('مصروف', null, null, $expTotal, $expPaid,
                ['مدفوعة', 'معتمدة'][$i % 2], $i * 9,
                ['اشتراك استضافة الخوادم', 'رسوم تراخيص برمجيّة', 'قرطاسيّة ومستلزمات مكتب', 'ضيافة اجتماع عميل',
                 'اشتراك إنترنت المكتب', 'وقود مهمّات ميدانيّة', 'صيانة تكييف', 'إعلانات ممولة'][$i - 1]);
        }

        foreach ([
            ['Q-2026-014', 'عرض سعر تطبيق بنك المدينة', 'madina', 'madina', 90000, 'مُرسل'],
            ['Q-2026-015', 'تحديث بوّابة الخليج — مرحلة ثانية', 'gulf', 'gulf', 22000, 'مسودة'],
            ['Q-2026-016', 'متجر الزهرة — ميزانيّة معدّلة', 'zahra', 'paused', 5200, 'قيد التفاوض'],
            ['Q-2026-017', 'دعم سنوي — مستشفى السلام', 'salam', null, 4800, 'معتمد'],
        ] as [$no, $title, $ck, $pk, $total, $status]) {
            Quote::create(['doc_no' => $no, 'title' => $title, 'client_id' => $this->cl[$ck]->id,
                'project_id' => $pk ? $this->pr[$pk]->id : null, 'company_id' => $this->co['kw']->id,
                'date' => now()->subDays(mt_rand(3, 30))->format('Y-m-d'), 'total' => $total, 'amount' => $total,
                'currency' => 'د.ك', 'status' => $status, 'owner_id' => $this->u['sales']->id]);
        }
    }

    /* ───────────────────────── التذاكر والمخاطر ───────────────────────── */

    private function serviceDesk(): void
    {
        $tickets = [
            ['gulf', 'خطأ في احتساب قسط وثيقة المركبات', 'العميل يذكر أنّ القسط الظاهر يزيد ديناراً عن المتّفق.', 'عالية', 'قيد المعالجة', 'dev1'],
            ['gulf', 'طلب تقرير شهري بعدد الوثائق المصدرة', 'تريد الإدارة تقريراً شهريّاً تلقائيّاً.', 'متوسطة', 'جديدة', null],
            ['nakheel', 'صور مشروع «الواحة» لا تظهر', 'بعد التحديث الأخير اختفت صور أحد المشاريع.', 'عالية', 'تم الحل', 'sup1'],
            ['nakheel', 'تعديل رقم التواصل في التذييل', 'تغيير الرقم إلى الرقم الموحّد الجديد.', 'منخفضة', 'مغلقة', 'sup2'],
            ['salam', 'استفسار عن موعد تسليم شاشة الحجز', 'إدارة المستشفى تسأل عن الموعد المتوقّع.', 'متوسطة', 'بانتظار العميل', 'pm'],
            ['ofoq', 'طلب نسخة من تقرير التقييم الأوّلي', 'لعرضه على مجلس الإدارة.', 'متوسطة', 'جديدة', 'pm2'],
            ['gulf', 'بطء صفحة المطالبات وقت الذروة', 'شكوى من بطء التحميل بين ١٠ و١٢ صباحاً.', 'عاجلة', 'قيد المعالجة', 'dev2'],
            ['salam', 'إضافة عيادة الأسنان الجديدة', 'افتُتحت عيادة جديدة وتحتاج إدراجاً في النظام.', 'متوسطة', 'جديدة', null],
        ];
        foreach ($tickets as [$ck, $subject, $body, $priority, $status, $assignee]) {
            Ticket::create(['client_id' => $this->cl[$ck]->id, 'subject' => $subject, 'body' => $body,
                'priority' => $priority, 'status' => $status, 'channel' => 'بريد إلكتروني', 'cat' => 'عطل تقني',
                'assignee_id' => $assignee ? $this->u[$assignee]->id : null,
                'created_at' => now()->subDays(mt_rand(1, 20))]);
        }

        $issues = [
            ['تأخّر اعتماد تصاميم مستشفى السلام أسبوعين', 'salam', 'عالية', 'مفتوحة', 'خطر'],
            ['اعتماديّة مزوّد SMS الحالي ضعيفة', 'salam', 'متوسطة', 'قيد المعالجة', 'خطر'],
            ['فجوة اختبار في رحلة الدفع', 'gulf', 'حرجة', 'قيد المعالجة', 'مشكلة'],
            ['نقص مطوّر خلفيّة في فريق دبي', 'dubai', 'عالية', 'مفتوحة', 'خطر'],
            ['انتهاء شهادة SSL لموقع النخيل خلال ٣ أسابيع', 'nakheel', 'متوسطة', 'مفتوحة', 'مشكلة'],
            ['غموض نطاق المرحلة الثانية لأفق', 'ofoq', 'منخفضة', 'محلولة', 'خطر'],
        ];
        foreach ($issues as [$title, $pk, $severity, $status, $kind]) {
            Issue::create(['title' => $title, 'project_id' => $this->pr[$pk]->id, 'severity' => $severity,
                'status' => $status, 'kind' => $kind, 'assignee_id' => $this->u['pm']->id,
                'created_at' => now()->subDays(mt_rand(2, 25))]);
        }
    }

    /* ───────────────────────── الوثائق ───────────────────────── */

    private function documents(): void
    {
        $mk = fn (array $a) => Document::create(array_merge([
            'issue_date' => now()->subDays(mt_rand(10, 200))->format('Y-m-d'),
            'company_id' => $this->co['kw']->id,
        ], $a));

        // سياساتُ الشركة (عامّة داخليّاً)
        foreach ([['سياسة الحضور والانصراف', 'POL-001'], ['سياسة العمل عن بُعد', 'POL-002'],
                  ['دليل الموظّف الجديد', 'POL-003'], ['سياسة أمن المعلومات', 'POL-004'],
                  ['سياسة العُهد والأصول', 'POL-005']] as [$name, $no]) {
            $mk(['name' => $name, 'cat' => 'ملفات الشركة', 'secrecy' => 'داخلي', 'doc_no' => $no,
                 'description' => 'نسخة معتمدة من الإدارة — تسري على جميع الموظّفين.']);
        }
        // ملفّات موارد بشريّة سريّة
        foreach ([['عقد عمل — سالم المطيري', 'HR-CT-011'], ['تقييم أداء سنوي — أحمد قاسم', 'HR-PA-024'],
                  ['مسير رواتب أغسطس ٢٠٢٦', 'HR-PR-208']] as [$name, $no]) {
            $mk(['name' => $name, 'cat' => 'قانوني', 'secrecy' => 'سري', 'doc_no' => $no,
                 'description' => 'وثيقة سريّة — الاطّلاع لقسم الموارد البشريّة فقط.']);
        }
        // عقودُ عملاء
        foreach ([['عقد بوّابة الخليج للتأمين', 'gulf', 'CT-2026-07'], ['عقد صيانة النخيل', 'nakheel', 'CT-2026-03'],
                  ['عقد نظام مواعيد السلام', 'salam', 'CT-2026-09']] as [$name, $pk, $no]) {
            $mk(['name' => $name, 'cat' => 'عقود', 'secrecy' => 'داخلي', 'doc_no' => $no,
                 'project_id' => $this->pr[$pk]->id, 'description' => 'العقد الموقّع بين الطرفين مع الملاحق.']);
        }
        // وثائقُ مشاريع فنّيّة
        foreach ([['مواصفات واجهات بوّابة الخليج', 'gulf'], ['مخطّط قاعدة بيانات نظام المواعيد', 'salam'],
                  ['خارطة طريق تحوّل أفق', 'ofoq'], ['دليل تشغيل خادم النسخ الاحتياطي', 'erp'],
                  ['هوية تطبيق بنك المدينة — مسوّدة', 'madina']] as [$name, $pk]) {
            $mk(['name' => $name, 'cat' => 'تقني', 'secrecy' => 'داخلي', 'project_id' => $this->pr[$pk]->id,
                 'description' => 'وثيقة فنّيّة حيّة — تُحدَّث مع تقدّم العمل.']);
        }
        // وثائقُ مرئيّةٌ للعميل عبر البوّابة
        foreach ([['تقرير تقدّم أغسطس — بوّابة الخليج', 'gulf', 'gulf'],
                  ['محضر اجتماع الإطلاق التجريبي', 'gulf', 'gulf'],
                  ['تقرير صيانة أغسطس — النخيل', 'nakheel', 'nakheel']] as [$name, $pk, $ck]) {
            $d = $mk(['name' => $name, 'cat' => 'تقارير', 'secrecy' => 'عام', 'project_id' => $this->pr[$pk]->id,
                 'description' => 'نسخة مشتركة مع العميل عبر البوّابة.']);
            Document::whereKey($d->id)->update(['audience' => 'client', 'client_id' => $this->cl[$ck]->id]);
        }
        // فواتيرُ وماليّة (أرشيف)
        for ($i = 1; $i <= 6; $i++) {
            $mk(['name' => 'أرشيف فاتورة INV-2026-0' . $i . ' (PDF)', 'cat' => 'فواتير', 'secrecy' => 'داخلي',
                 'description' => 'نسخة ممسوحة من الفاتورة الموقّعة.']);
        }
        // تصميمٌ وهويّة
        foreach (['الشعار الرسمي — ملفّات المصدر', 'ألوان الهويّة ودليل الاستخدام', 'قوالب العروض التقديميّة'] as $name) {
            $mk(['name' => $name, 'cat' => 'تصميم وهوية', 'secrecy' => 'داخلي']);
        }
    }

    /* ───────────────────────── التواصل ───────────────────────── */

    private function collaboration(): void
    {
        $mkConv = function (array $a, array $memberKeys) {
            $c = Conversation::create($a);
            foreach ($memberKeys as $k) {
                ConversationMember::create(['conversation_id' => $c->id, 'user_id' => $this->u[$k]->id]);
            }

            return $c;
        };
        $say = function ($conv, string $k, string $body, int $daysAgo, int $h = 10) {
            Comment::create(['module' => 'channel', 'record_id' => (string) $conv->id,
                'conversation_id' => $conv->id, 'body' => $body, 'user_id' => $this->u[$k]->id,
                'created_at' => now()->subDays($daysAgo)->setTime($h, mt_rand(0, 59))]);
        };

        $all = ['owner', 'ops', 'pm', 'pm2', 'hr', 'acc', 'it', 'sales', 'mkt', 'dev1', 'dev2', 'dev3', 'dev4',
            'des1', 'des2', 'qa1', 'sup1', 'sup2', 'ops1', 'newbie'];

        // القناةُ العامّة
        $general = $mkConv(['kind' => 'channel', 'title' => 'عام — لينوميا', 'audience' => 'internal'], $all);
        $say($general, 'ops', 'صباح الخير جميعاً — تذكير: اجتماع المتابعة الأسبوعي غداً الساعة ١٠.', 6, 8);
        $say($general, 'hr', 'الرجاء ممّن لم يحدّث بياناته في ملفّ الموظّف إكمالُها قبل نهاية الأسبوع.', 5, 11);
        $say($general, 'it', 'صيانة مجدولة لخادم الاختبار الخميس ٦–٧ مساءً — لا تأثير على الإنتاج.', 4, 14);
        $say($general, 'mkt', 'أُطلقت حملة سبتمبر — شاركونا أيّ ملاحظات من العملاء 🙌', 2, 9);

        // إعلاناتُ الإدارة (قناة شركة الكويت)
        $ann = $mkConv(['kind' => 'channel', 'title' => 'إعلانات الإدارة', 'audience' => 'internal',
            'company_id' => $this->co['kw']->id], ['owner', 'ops', 'pm', 'hr', 'acc', 'it', 'sales', 'mkt']);
        $say($ann, 'owner', 'اعتمدنا خطّة الربع الرابع — التركيز على تسليم السلام وبوّابة الخليج.', 7, 9);
        $say($ann, 'ops', 'من الأسبوع القادم: مراجعة التقارير اليوميّة قبل الساعة ٦ مساءً.', 3, 16);

        // قناةُ القيادة الخاصّة
        $lead = $mkConv(['kind' => 'channel', 'title' => 'القيادة — خاص', 'audience' => 'internal',
            'visibility' => 'private'], ['owner', 'ops', 'mgr-ae']);
        $say($lead, 'owner', 'ما رأيكم في عرض بنك المدينة؟ الميزانيّة كبيرة لكن الفريق مشغول.', 5, 20);
        $say($lead, 'ops', 'أقترح قبوله بشرط تأجيل البدء لبداية أكتوبر وتوظيف مطوّر إضافيّ.', 5, 21);

        // غرفةُ مشروعِ السلام الداخليّة
        $salamRoom = $mkConv(['kind' => 'channel', 'title' => 'مشروع مستشفى السلام', 'audience' => 'internal',
            'project_id' => $this->pr['salam']->id], ['pm', 'dev3', 'dev4', 'des2', 'ops']);
        $say($salamRoom, 'pm', 'العميل استعجل شاشة الحجز — نحتاج نسخة تجريبيّة نهاية الأسبوع.', 3, 10);
        $say($salamRoom, 'dev3', 'أنجزت ٧٠٪ منها. المتبقّي ربط جدول الأطبّاء — أحتاج بيانات العيادات.', 3, 11);
        $say($salamRoom, 'dev4', 'مزوّد SMS يرفض الرسائل التجريبيّة — أتواصل مع دعمهم.', 2, 12);
        $say($salamRoom, 'pm', 'تمام. سأبلغ العميل بموعد الخميس وأطلب بيانات العيادات اليوم.', 2, 13);

        // غرفةُ عميلِ الخليج (يشارك فيها العميل)
        $gulfRoom = $mkConv(['kind' => 'channel', 'title' => 'غرفة الخليج للتأمين', 'audience' => 'client',
            'project_id' => $this->pr['gulf']->id, 'client_id' => $this->cl['gulf']->id],
            ['pm', 'dev1', 'sales', 'cli1']);
        $say($gulfRoom, 'pm', 'أهلاً أستاذة عبير — رفعنا تقرير تقدّم أغسطس في ملفّات المشروع.', 6, 12);
        $say($gulfRoom, 'cli1', 'شكراً لكم. متى نبدأ اختبار بوّابة الدفع؟ الإدارة تسأل.', 5, 13);
        $say($gulfRoom, 'pm', 'الأسبوع القادم بإذن الله — سنرسل رابط بيئة الاختبار وبيانات الدخول.', 5, 14);

        // منشوراتُ الخلاصة (feed)
        $feed = [
            ['ops', 'أهلاً بلطيفة السالم — انضمّت اليوم لفريق التطوير 👋', 0],
            ['mkt', 'موقعنا الجديد تجاوز ١٠ آلاف زيارة هذا الشهر 🎉', 4],
            ['pm2', 'اكتملت مرحلة التقييم في مشروع أفق — أحسنتم يا فريق دبي!', 8],
            ['hr', 'تذكير: آخر موعد لطلبات إجازة العيد نهاية هذا الأسبوع.', 1],
            ['it', 'تحديث أمنيّ إلزاميّ للابتوبات سيصلكم إشعاره — الرجاء عدم التأجيل.', 3],
        ];
        foreach ($feed as [$k, $body, $daysAgo]) {
            Comment::create(['module' => 'feed', 'body' => $body, 'user_id' => $this->u[$k]->id,
                'company_id' => $this->co['kw']->id,
                'created_at' => now()->subDays($daysAgo)->setTime(mt_rand(8, 15), mt_rand(0, 59))]);
        }

        // رسائلُ مباشرة
        $dm = function (string $a, string $b, array $msgs) {
            $key = DmMessage::threadKey((string) $this->u[$a]->id, (string) $this->u[$b]->id);
            foreach ($msgs as $i => [$from, $body, $daysAgo, $h]) {
                DmMessage::create(['thread_key' => $key, 'from_id' => $this->u[$from]->id,
                    'to_id' => $this->u[$from === $a ? $b : $a]->id, 'body' => $body,
                    'company_id' => $this->co['kw']->id,
                    'read_at' => $daysAgo > 0 ? now()->subDays($daysAgo)->setTime($h + 1, 0) : null,
                    'created_at' => now()->subDays($daysAgo)->setTime($h, mt_rand(0, 59))]);
            }
        };
        $dm('pm', 'dev1', [
            ['pm', 'أحمد، وين وصلنا في ربط K-Net؟ العميل يسأل.', 1, 9],
            ['dev1', 'واجهت مشكلة في بيئة الاختبار حقّتهم — فتحت تذكرة معهم أمس.', 1, 10],
            ['pm', 'طيّب وثّقها في المشروع كعائق حتى تكون ظاهرة في التقرير.', 1, 10],
            ['dev1', 'تم ✅', 0, 9],
        ]);
        $dm('hr', 'newbie', [
            ['hr', 'أهلاً لطيفة! أرسلت لك دليل الموظّف الجديد في الملفّات — أيّ سؤال أنا موجودة.', 0, 8],
            ['newbie', 'شكراً أستاذة مريم 🌷 أوّل شي أسوّيه اليوم؟', 0, 8],
            ['hr', 'سجّلي حضورك من الشاشة الرئيسيّة، ثم راجعي مهامك مع نورة.', 0, 9],
        ]);
        $dm('ops', 'acc', [
            ['ops', 'يوسف، فاتورة السلام المتأخّرة صار لها ٥٠ يوم — نحتاج متابعة.', 2, 11],
            ['acc', 'راسلتهم الأسبوع الماضي. سأتّصل بالمحاسبة عندهم اليوم وأحدّثك.', 2, 12],
        ]);
    }

    /* ───────────────────────── إشعارات ───────────────────────── */

    private function notifications(): void
    {
        hub_notify($this->u['pm']->id, 'due', '⏰ مهمّة «ربط بوّابة الدفع K-Net» تجاوزت موعدها', 'tasks', null);
        hub_notify($this->u['ops']->id, 'report', '📋 ٤ تقارير يوميّة بانتظار مراجعتك', 'updates', null);
        hub_notify($this->u['acc']->id, 'fin', '💰 فاتورة INV-2026-003 متأخّرة ٥٠ يوماً — مستشفى السلام', 'fin', null);
        hub_notify($this->u['it']->id, 'asset', '🔎 أصل مفقود بحاجة لمتابعة: LYN-PH-990', 'assets', null);
        hub_notify($this->u['newbie']->id, 'welcome', '👋 أهلاً بك في لينوميا — ابدئي بتسجيل الحضور ومراجعة مهامك', null, null);
    }
}
