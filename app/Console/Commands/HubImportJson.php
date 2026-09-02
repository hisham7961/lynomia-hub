<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * استيراد النسخة الاحتياطية (JSON) — عكس hub:backup تماماً.
 * php artisan hub:import storage/app/backups/hub-....json
 */
class HubImportJson extends Command
{
    protected $signature = 'hub:import {file} {--truncate}';
    protected $description = 'استيراد بيانات Lynomia Hub من ملف JSON إلى قاعدة البيانات';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! file_exists($path)) { $this->error('الملف غير موجود'); return 1; }

        // **ملفٌ تالف لا يمرّ «بنجاح: 0 سجل»**: نسخةٌ مبتورة النقل كانت تعطي
        // null فتنزلق الحلقات على الفراغ ويُبلَّغ المشغّل أن الاستعادة تمّت —
        // ويكتشف الكارثة بعد فوات إمكانية الحصول على نسخة سليمة.
        // نسخةٌ مشفَّرة (.json.enc) تُفكّ بمفتاح التطبيق (v2.399) — مفتاحٌ مختلف = رسالةٌ لا «0 سجل»
        $db = \App\Console\Commands\HubBackup::readFile($path);
        if (! is_array($db)) {
            $this->error(str_ends_with($path, '.enc')
                ? 'تعذّر فكُّ تشفير النسخة أو قراءتها — APP_KEY مختلفٌ عن الذي أُخذت به، أو الملف معطوب'
                : 'ملف النسخة غير صالح (JSON معطوب)');
            return 1;
        }

        $modules = config('hub.modules');
        $total = 0;

        // **‎--truncate كان معلَناً ولا يُقرأ**: من يستعيد نسخةً ظنّاً أنها
        // تستبدل القاعدة كان يحصل على دمجٍ صامت — سجلاتٌ قديمة تبقى مختلطةً
        // بالمستعادة، فتتضاعف الفواتير ويعود عميلٌ حُذف عمداً.
        $truncate = (bool) $this->option('truncate');

        DB::transaction(function () use ($db, $modules, &$total, $truncate) {
            if ($truncate) {
                foreach ($modules as $key => $def) {
                    if ($key === 'users') continue;
                    $t = (string) ($def['table'] ?? '');
                    // تُفرَّغ الجداول التي يحملها الملف وحدها — لا يُمسّ ما لا يُستعاد
                    if ($t !== '' && Schema::hasTable($t) && array_key_exists($key, (array) $db)) {
                        DB::table($t)->delete();
                    }
                }
                $this->warn('‎--truncate: أُفرغت جداول الوحدات الموجودة في الملف قبل الاستعادة');
            }

            // الأدوار والمستخدمون أولاً (مراجع) — تاريخ الإنشاء لا يُداس عند التحديث
            foreach ((array) ($db['roles'] ?? []) as $r) {
                $this->upsertRow('roles', (string) $r['id'], [
                    'name' => $r['name'], 'is_owner' => (bool) ($r['all'] ?? false),
                    'scope' => $r['scope'] ?? 'proj',
                    'flags' => json_encode(collect($r)->only(['secrets','approve','users','audit','exp','monitor','copySec'])),
                    'matrix' => json_encode($r['m'] ?? []),
                    'field_rules' => json_encode($r['fieldRules'] ?? []),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach ((array) ($db['users'] ?? []) as $u) {
                $this->upsertRow('users', (string) $u['id'], [
                    'name' => $u['name'], 'email' => $u['email'] ?? Str::slug($u['name']) . '@example.com',
                    'phone' => $u['phone'] ?? null, 'job_title' => $u['title'] ?? null,
                    'role_id' => $u['roleId'] ?? null, 'status' => $u['status'] ?? 'نشط',
                    'notify_prefs' => json_encode($u['nprefs'] ?? []),
                    'companies' => json_encode($u['companies'] ?? []),
                    'allowed_ips' => $u['allowedIps'] ?? null,
                    'expires_at' => $u['expiresAt'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach ($modules as $key => $def) {
                if ($key === 'users') continue;
                $rows = (array) ($db[$key] ?? []);
                if (! $rows) continue;

                $map = collect($def['fields'])->pluck('col', 'key')->all();
                $hasDel = Schema::hasColumn($def['table'], 'deleted_at');
                $chunk = [];
                foreach ($rows as $row) {
                    $rec = ['id' => $row['id'] ?? (string) Str::uuid(), 'version' => $row['_v'] ?? 1,
                            // التواريخ الحقيقية من النسخة — now() كانت تدوس تاريخ
                            // إنشاء كل سجل (حتى الموجود سلفاً) فتنكسر الفروز الزمنية
                            'created_at' => $row['_ca'] ?? now(), 'updated_at' => $row['_ua'] ?? now(),
                            'archived' => (bool) ($row['_arch'] ?? false)];
                    if (! empty($row['_cb'])) $rec['created_by'] = $row['_cb'];
                    // كل صفٍّ في النسخة كان حياً وقتها — الاستعادة تُحييه:
                    // «حذفٌ خاطئ ثم استرجاع نسخة الأمس» هو أشيع دوافع الاستعادة
                    if ($hasDel) $rec['deleted_at'] = null;
                    $custom = [];
                    foreach ($row as $k => $v) {
                        if (in_array($k, ['id', '_v', '_up', '_by', '_arch', '_ca', '_ua', '_cb'], true)) continue;
                        if (isset($map[$k])) {
                            $rec[$map[$k]] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
                        } elseif (str_starts_with($k, 'cf_')) {
                            $custom[$k] = $v;
                        }
                    }
                    if ($custom) $rec['custom'] = json_encode($custom, JSON_UNESCAPED_UNICODE);
                    $chunk[] = $rec;
                }

                $total += $this->upsertUniform($def['table'], $chunk);
                $this->info("✓ {$def['label']}: " . count($chunk));
            }

            // الجداول التشغيلية الخام (قيود اليومية، المرفقات، سلسلة التواقيع...)
            foreach ((array) ($db['_tables'] ?? []) as $t => $rows) {
                if (! Schema::hasTable($t) || ! $rows) continue;
                $cols = array_flip(Schema::getColumnListing($t));
                $clean = array_map(fn ($r) => array_intersect_key((array) $r, $cols), (array) $rows);
                $total += $this->upsertUniform($t, $clean);
                $this->info("✓ {$t}: " . count($clean));
            }

            // الإعدادات
            foreach ((array) ($db['settings'] ?? []) as $k => $v) {
                DB::table('settings')->updateOrInsert(['key' => $k],
                    ['value' => json_encode($v, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        // الإعدادات المستعادة تسري فوراً — لا تعمل المنشأة بإعدادات ما قبل
        // الاستعادة من الخبيئة حتى تنتهي صلاحيتها (النسخ نفسه يفعلها، والاستيراد كان ينساها)
        \Illuminate\Support\Facades\Cache::forget('settings:all');

        $this->info("تم الاستيراد: $total سجل.");

        // **سلسلةُ التدقيق بعد الاستعادة**: سجلاتُ `audits` تُستعاد ببصماتها القديمة
        // (`hash`/`prev_hash` من القاعدة المصدر) بينما رأسُ السلسلة (`audit_chain`)
        // لا يُستعاد — فتلتقي لينتان لا تتّصلان، ويُبلّغ مركزُ الأمان «انقطاعاً» بلا
        // عبثٍ وقع. المخرجُ الصريح: إعادةُ الوصل في سلسلةٍ واحدة.
        if (! empty($db['_tables']['audits'])) {
            $this->warn('استُعيدت سجلاتُ التدقيق ببصماتها القديمة ورأسُ السلسلة لم يُستعَد — '
                . 'شغّل `php artisan hub:audit-verify --rebuild` لإعادة وصلها في سلسلةٍ واحدة.');
        }

        return 0;
    }

    /**
     * upsert على صفوفٍ **موحَّدة الأعمدة**: النسخة تُسقط الحقول الفارغة من كل
     * صفٍّ على حدة، وLaravel يبني قائمة أعمدة INSERT من أول صفٍّ وحده — فدفعةٌ
     * غير متجانسة كانت تنفجر «Column count doesn't match» أو تُنزلق قيماً بين
     * الأعمدة بصمت. التطبيع (تعبئة الغائب بـnull) يجعل كل دفعةٍ متجانسة،
     * وقائمة التحديث الصريحة تستثني created_at/created_by فلا يُداسان.
     */
    protected function upsertUniform(string $table, array $rows): int
    {
        if (! $rows) return 0;
        $keys = [];
        foreach ($rows as $r) $keys += array_flip(array_keys($r));
        $template = array_fill_keys(array_keys($keys), null);

        $update = array_values(array_diff(array_keys($keys), ['id', 'created_at', 'created_by']));
        foreach (array_chunk($rows, 500) as $part) {
            $part = array_map(fn ($r) => array_replace($template, $r), $part);
            DB::table($table)->upsert($part, ['id'], $update ?: null);
        }

        return count($rows);
    }

    /** إدراجٌ أو تحديث لصفٍّ واحد — التحديث لا يمسّ created_at القائم */
    protected function upsertRow(string $table, string $id, array $vals): void
    {
        if (DB::table($table)->where('id', $id)->exists()) {
            unset($vals['created_at']);
            DB::table($table)->where('id', $id)->update($vals);
        } else {
            DB::table($table)->insert(['id' => $id] + $vals);
        }
    }
}
