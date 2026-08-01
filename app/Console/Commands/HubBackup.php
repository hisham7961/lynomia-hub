<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * نسخ احتياطي كامل على السيرفر — JSON بنفس صيغة hub:import
 * (الاستعادة: php artisan hub:import storage/app/backups/<الملف>)
 * يُدوّر النسخ تلقائياً: يبقي آخر ١٤.
 */
class HubBackup extends Command
{
    protected $signature = 'hub:backup {--keep=14 : عدد النسخ المحفوظة}';
    protected $description = 'تصدير القاعدة كاملة إلى storage/app/backups مع تدوير النسخ';

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
            DB::table($def['table'])->whereNull('deleted_at')->orderBy('created_at')
                ->chunk(500, function ($part) use (&$rows, $map) {
                    foreach ($part as $row) {
                        $rec = ['id' => $row->id, '_v' => $row->version ?? 1];
                        if (! empty($row->archived)) $rec['_arch'] = true;
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

        // الإعدادات
        $out['settings'] = DB::table('settings')->pluck('value', 'key')
            ->map(fn ($v) => json_decode($v, true) ?? $v)->all();

        // الكتابة + التدوير
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) mkdir($dir, 0755, true);
        $file = $dir . '/hub-' . now()->format('Y-m-d-Hi') . '.json';
        file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE));

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
