<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نسخ احتياطي كامل على السيرفر — JSON بنفس صيغة hub:import
 * (الاستعادة: php artisan hub:import storage/app/backups/<الملف>)
 * يُدوّر النسخ تلقائياً: يبقي آخر ١٤.
 */
class HubBackup extends Command
{
    protected $signature = 'hub:backup {--keep=14 : عدد النسخ المحفوظة}';
    protected $description = 'تصدير القاعدة كاملة إلى storage/app/backups مع تدوير النسخ';

    /**
     * جداول تشغيلية خارج سجل الوحدات — كانت النسخة «الكاملة» تُسقطها كلياً:
     * قيود اليومية المزدوجة، بنود الرواتب، بيانات المرفقات، سلسلة أدلة التواقيع
     * (الموقّعون والأحداث والمراحل)، النسخ التاريخية، التدقيق، الرسائل المباشرة،
     * روابط غرفة البيانات، اتصالات أودو — بياناتٌ لا تُعاد بنقر المستخدمين.
     * تُصدَّر خاماً (أعمدتها كما هي) فتحفظ تواريخها ومحتواها حرفياً.
     */
    protected const RAW_TABLES = [
        'journal_lines', 'payroll_lines', 'attachments',
        'contract_signers', 'contract_events', 'contract_approval_steps',
        'sign_template_versions', 'record_versions', 'record_acks',
        'comments', 'audits', 'dm_messages', 'share_links', 'odoo_connections',
    ];

    public function handle(): int
    {
        $out = ['_meta' => ['app' => (string) setting('app.name', config('app.name')),
                            'version' => config('hub.version'), 'at' => now()->toIso8601String()]];

        // الأدوار — بصيغة الاستيراد: all/scope/m + الأعلام في المستوى الأعلى
        $out['roles'] = DB::table('roles')->get()->map(function ($r) {
            $flags = json_decode($r->flags ?? '[]', true) ?: [];
            return array_merge([
                'id' => $r->id, 'name' => $r->name, 'all' => (bool) $r->is_owner,
                'scope' => $r->scope ?? 'proj', 'm' => json_decode($r->matrix ?? '[]', true) ?: [],
                // **قيود مستوى الحقل تُنسَخ**: كانت تسقط من النسخة، فالاستعادة
                // تُعيد النظام **بلا قيدٍ واحد** — وهي أخطر من غياب النسخة
                // أصلاً لأنها تُظنّ استعادةً كاملة فلا يراجعها أحد.
                'fieldRules' => json_decode($r->field_rules ?? '[]', true) ?: [],
            ], $flags);
        })->all();

        // المستخدمون — بلا كلمات مرور (كما يتوقع الاستيراد)
        $out['users'] = DB::table('users')->whereNull('deleted_at')->get()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'title' => $u->job_title, 'roleId' => $u->role_id, 'status' => $u->status,
            'nprefs' => json_decode($u->notify_prefs ?? '[]', true) ?: [],
            // عزل الشركات وحارسا الحساب — كلها تسقط عند الاستعادة إن لم تُنسخ،
            // فيعود الحساب المعزول يرى المنشأة كلها والمنتهي يعمل بلا انتهاء
            'companies'  => json_decode($u->companies ?? '[]', true) ?: [],
            'allowedIps' => $u->allowed_ips,
            'expiresAt'  => $u->expires_at,
        ])->all();

        // كل وحدات السجل — الأعمدة تعود مفاتيحَ (عكس خريطة الاستيراد)
        $total = 0;
        foreach (hub_modules() as $key => $def) {
            if ($key === 'users') continue;
            $map = collect($def['fields'])->pluck('key', 'col')->all();   // col → key

            $rows = [];
            // chunkById لا chunk على created_at: ترقيم OFFSET فوق ترتيبٍ تتساوى
            // قيمُه يُسقط صفوفاً أو يكررها عند حدود الصفحات — قرعةُ المحرّكين نفسها
            DB::table($def['table'])->whereNull('deleted_at')
                ->chunkById(500, function ($part) use (&$rows, $map) {
                    foreach ($part as $row) {
                        $rec = ['id' => $row->id, '_v' => $row->version ?? 1];
                        if (! empty($row->archived)) $rec['_arch'] = true;
                        // التواريخ والنسبة تُنسَخ: كانت الاستعادة تدوس تاريخ إنشاء
                        // كل سجل بـnow() وتفقد منشئه — فينكسر كل فرزٍ زمني بعدها
                        if (! empty($row->created_at)) $rec['_ca'] = (string) $row->created_at;
                        if (! empty($row->updated_at)) $rec['_ua'] = (string) $row->updated_at;
                        if (! empty($row->created_by)) $rec['_cb'] = $row->created_by;
                        foreach ($map as $col => $fk) {
                            $v = $row->{$col} ?? null;
                            if ($v === null || $v === '') continue;
                            // المصفوفات المخزنة JSON تعود مصفوفات (الاستيراد يعيد ترميزها)
                            if (is_string($v) && strlen($v) > 1 && ($v[0] === '[' || $v[0] === '{')) {
                                $d = json_decode($v, true);
                                if (is_array($d)) $v = $d;
                            }
                            $rec[$fk] = $v;
                        }
                        if (! empty($row->custom)) {
                            foreach ((array) (json_decode($row->custom, true) ?: []) as $ck => $cv) $rec[$ck] = $cv;
                        }
                        $rows[] = $rec;
                    }
                });

            if ($rows) { $out[$key] = $rows; $total += count($rows); }
        }

        // الجداول التشغيلية الخام — أعمدتها كما هي، بمحذوفاتها الناعمة (أمانة النسخة)
        foreach (self::RAW_TABLES as $t) {
            if (! Schema::hasTable($t)) continue;
            $rows = [];
            DB::table($t)->chunkById(500, function ($part) use (&$rows) {
                foreach ($part as $row) $rows[] = (array) $row;
            });
            if ($rows) { $out['_tables'][$t] = $rows; $total += count($rows); }
        }

        // الإعدادات
        $out['settings'] = DB::table('settings')->pluck('value', 'key')
            ->map(fn ($v) => json_decode($v, true) ?? $v)->all();

        // الكتابة + التدوير — بصلاحياتٍ خاصة: الملف قاعدةُ الأعمال كلها
        // (هواتف، قيود IP، شفرات الخزنة) فلا يقرؤه حسابٌ محليّ آخر على الخادم
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) mkdir($dir, 0700, true);
        $file = $dir . '/hub-' . now()->format('Y-m-d-Hi') . '.json';
        file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE));
        @chmod($file, 0600);
        @chmod($dir, 0700);

        $keep = max(1, (int) $this->option('keep'));
        $old = glob($dir . '/hub-*.json');
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - $keep)) as $f) @unlink($f);

        $this->info('✓ ' . basename($file) . ' — ' . number_format($total) . ' سجل، ' .
                    number_format(filesize($file) / 1024, 1) . ' KB (محفوظ آخر ' . $keep . ' نسخة)');

        \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.backup'], ['value' => now()->toIso8601String()]);
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        return self::SUCCESS;
    }
}
