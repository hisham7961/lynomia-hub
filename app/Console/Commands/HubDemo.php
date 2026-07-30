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
    protected $signature = 'hub:demo {--purge : مسح بيانات التجربة فقط دون إعادة توليد}';
    protected $description = 'توليد بيانات تجريبية موسومة أو مسحها (--purge)';

    /** الجداول التي يوسم فيها وضع التجربة */
    protected const TABLES = ['companies', 'clients', 'projects', 'tasks', 'tickets',
        'fin_documents', 'domains', 'employees', 'quotes', 'suppliers'];

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
        \App\Models\Setting::updateOrCreate(['key' => 'demo.on'], ['value' => '1']);
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        $this->info("وضع تجريبي جاهز: {$n} سجل وهمي" . ($purged ? " (بعد مسح {$purged} قديم)" : '') . ' — صفّره متى شئت بزر التصفير أو hub:demo --purge');

        return self::SUCCESS;
    }

    protected function purge(): int
    {
        $n = 0;
        foreach (self::TABLES as $t) {
            $n += DB::table($t)->where('meta', 'LIKE', '%"demo":1%')->delete();
        }

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

        return $id;
    }

    protected function seed(): int
    {
        $co = $this->row('companies', ['name_ar' => '🎭 شركة الأفق التجريبية', 'name_en' => 'Demo Horizon',
            'country' => 'الكويت', 'city' => 'مدينة الكويت', 'type' => 'تقنية', 'status' => 'نشطة']);

        $clients = [];
        foreach ([['🎭 مطاعم الذواقة', 'info@demo-taste.example', 'عميل'],
                  ['🎭 عيادات الشفاء', 'care@demo-clinic.example', 'عميل'],
                  ['🎭 مكتبة المعرفة', 'hi@demo-books.example', 'مهتم'],
                  ['🎭 نادي اللياقة', 'fit@demo-gym.example', 'تفاوض']] as [$n2, $e, $st]) {
            $clients[] = $this->row('clients', ['name' => $n2, 'email' => $e, 'stage' => $st,
                'company_id' => $co, 'country' => 'الكويت', 'phone' => '+965 5' . random_int(1000000, 9999999)]);
        }

        $projects = [];
        foreach ([['🎭 تطبيق توصيل الطلبات', 'قيد التنفيذ', 'عالية'],
                  ['🎭 موقع حجوزات العيادة', 'قيد التنفيذ', 'متوسطة'],
                  ['🎭 متجر الكتب الإلكتروني', 'تخطيط', 'منخفضة']] as [$n2, $st, $pr]) {
            $projects[] = $this->row('projects', ['name' => $n2, 'company_id' => $co,
                'status' => $st, 'priority' => $pr, 'type' => 'تطبيق',
                'start_date' => now()->subDays(random_int(20, 90))->toDateString()]);
        }

        $n = 8;   // ما أُدرج أعلاه
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
                'total' => $amt, 'currency' => 'د.ك', 'state' => $st, 'company_id' => $co,
                'project_id' => $projects[$i % 3]]);
            $n++;
        }

        foreach ([['demo-taste.example', 25], ['demo-clinic.example', 190], ['demo-books.example', 400]] as [$d, $days]) {
            $this->row('domains', ['name' => $d, 'company_id' => $co, 'registrar' => 'Namecheap',
                'expiry' => now()->addDays($days)->toDateString(), 'currency' => 'د.ك', 'cost' => 4]);
            $n++;
        }

        foreach ([['🎭 نورة المطيري', 'تصميم'], ['🎭 يوسف العنزي', 'تطوير'], ['🎭 دلال الصباح', 'دعم']] as [$e, $d]) {
            $this->row('employees', ['name' => $e, 'dept' => $d, 'company_id' => $co,
                'hired' => now()->subMonths(random_int(3, 30))->toDateString(), 'status' => 'على رأس العمل']);
            $n++;
        }

        $this->row('quotes', ['doc_no' => '🎭Q-DEMO-1', 'client_id' => $clients[3],
            'date' => now()->toDateString(), 'amount' => 3500, 'tax' => 0, 'total' => 3500,
            'currency' => 'د.ك', 'status' => 'مرسل']);

        return $n + 1;
    }
}
