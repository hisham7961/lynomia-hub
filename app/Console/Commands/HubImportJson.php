<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * استيراد النسخة الاحتياطية (JSON) من تطبيق الواجهة إلى PostgreSQL.
 * php artisan hub:import storage/app/hub-backup.json
 */
class HubImportJson extends Command
{
    protected $signature = 'hub:import {file} {--truncate}';
    protected $description = 'استيراد بيانات Lynomia Hub من ملف JSON إلى قاعدة البيانات';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! file_exists($path)) { $this->error('الملف غير موجود'); return 1; }

        $db = json_decode(file_get_contents($path), true);
        $modules = config('hub.modules');
        $total = 0;

        DB::transaction(function () use ($db, $modules, &$total) {
            // الأدوار والمستخدمون أولاً (مراجع)
            foreach ((array) ($db['roles'] ?? []) as $r) {
                DB::table('roles')->updateOrInsert(['id' => $r['id']], [
                    'name' => $r['name'], 'is_owner' => (bool) ($r['all'] ?? false),
                    'scope' => $r['scope'] ?? 'proj',
                    'flags' => json_encode(collect($r)->only(['secrets','approve','users','audit','exp','monitor','copySec'])),
                    'matrix' => json_encode($r['m'] ?? []),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach ((array) ($db['users'] ?? []) as $u) {
                DB::table('users')->updateOrInsert(['id' => $u['id']], [
                    'name' => $u['name'], 'email' => $u['email'] ?? Str::slug($u['name']) . '@example.com',
                    'phone' => $u['phone'] ?? null, 'job_title' => $u['title'] ?? null,
                    'role_id' => $u['roleId'] ?? null, 'status' => $u['status'] ?? 'نشط',
                    'notify_prefs' => json_encode($u['nprefs'] ?? []),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach ($modules as $key => $def) {
                if ($key === 'users') continue;
                $rows = (array) ($db[$key] ?? []);
                if (! $rows) continue;

                $map = collect($def['fields'])->pluck('col', 'key')->all();
                $chunk = [];
                foreach ($rows as $row) {
                    $rec = ['id' => $row['id'] ?? (string) Str::uuid(), 'version' => $row['_v'] ?? 1,
                            'created_at' => now(), 'updated_at' => now(),
                            'archived' => (bool) ($row['_arch'] ?? false)];
                    $custom = [];
                    foreach ($row as $k => $v) {
                        if (in_array($k, ['id', '_v', '_up', '_by', '_arch'], true)) continue;
                        if (isset($map[$k])) {
                            $rec[$map[$k]] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
                        } elseif (str_starts_with($k, 'cf_')) {
                            $custom[$k] = $v;
                        }
                    }
                    if ($custom) $rec['custom'] = json_encode($custom, JSON_UNESCAPED_UNICODE);
                    $chunk[] = $rec;
                }

                foreach (array_chunk($chunk, 500) as $part) {
                    DB::table($def['table'])->upsert($part, ['id']);
                    $total += count($part);
                }
                $this->info("✓ {$def['label']}: " . count($chunk));
            }

            // الإعدادات
            foreach ((array) ($db['settings'] ?? []) as $k => $v) {
                DB::table('settings')->updateOrInsert(['key' => $k],
                    ['value' => json_encode($v, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->info("تم الاستيراد: $total سجل.");
        return 0;
    }
}
