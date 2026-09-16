<?php

namespace App\Console\Commands;

use App\Models\Role;
use Database\Seeders\CoreSeeder;
use Illuminate\Console\Command;

/**
 * **فتحُ وحداتِ العملِ الجوهريّةِ لدورٍ قائم** — مسارُ التنصيباتِ التي سبقت التصحيح.
 *
 * صُحّح دورُ «عضو فريق» **المُسلَّمُ مع النظام** فصار يكتب عملَ صاحبِه (`CoreSeeder`)،
 * لكنّ التصحيحَ يحمي التنصيباتِ الجديدةَ وحدَها: القائمةُ فيها أدوارٌ مبنيّةٌ بيدِ
 * مديرها، وتوسيعُها **بهجرةٍ صامتة** يُلغي قراراً اتّخذه إنسانٌ عمداً — فدورُ
 * «مراقب أداء» بلا كتابةٍ **ليس عيباً يُصلَح**، هو الغرضُ من الدور.
 *
 * فالتوسيعُ هنا **يُطلَب باسمِ الدورِ صراحةً**، ولا يُشتقّ ولا يُعمَّم:
 *   · لا يُمَسّ دورُ مالك (يتجاوز أصلاً)؛
 *   · لا يُمنَح حذفٌ بحال، ولا رايةُ سلطة؛
 *   · ما كان ممنوحاً يبقى كما هو — الإضافةُ لا الكسر؛
 *   · `--dry` يعرض الفرقَ ولا يكتب حرفاً.
 */
class HubRolesWidenCoreWork extends Command
{
    protected $signature = 'hub:roles-widen-core-work {role* : اسمُ الدورِ (أو أكثر) كما يظهر في محرّر الأدوار}'
        . ' {--dry : اعرض ما سيُمنَح ولا تكتب}';
    protected $description = 'منحُ كتابةِ وحداتِ العملِ الجوهريّةِ لدورٍ قائمٍ يُسمّى صراحةً (لا حذفَ ولا رايات)';

    public function handle(): int
    {
        $touched = 0;

        foreach ((array) $this->argument('role') as $name) {
            $role = Role::where('name', $name)->first();
            if (! $role) { $this->error("لا دورَ باسم «{$name}»"); continue; }
            if ($role->is_owner) { $this->warn("«{$name}» دورُ مالكٍ — يتجاوز أصلاً، لا شيءَ يُمنَح"); continue; }

            $mx = (array) $role->matrix;
            $granted = [];

            foreach ([[CoreSeeder::MEMBER_WRITE, ['v', 'a', 'e']], [CoreSeeder::MEMBER_CREATE, ['v', 'a']]] as [$mods, $ops]) {
                foreach ($mods as $m) {
                    if (! hub_mod($m)) continue;                       // وحدةٌ غيرُ مسجَّلةٍ في هذه النسخة
                    foreach ($ops as $op) {
                        if (! empty($mx[$m][$op])) continue;           // ممنوحٌ أصلاً — لا يُمَسّ
                        $mx[$m][$op] = 1;
                        $granted[] = "{$m}:{$op}";
                    }
                }
            }

            if (! $granted) { $this->line("· «{$name}» يملكها كلَّها — لا جديد"); continue; }

            $this->line(($this->option('dry') ? '· ' : '✔ ') . "«{$name}» ⟵ " . implode('، ', $granted));
            if ($this->option('dry')) { $touched++; continue; }

            $role->update(['matrix' => $mx]);
            hub_audit('توسيع كتابة وحدات العمل لدور', null, (string) $role->id, $role->name,
                ['after' => ['granted' => $granted]]);
            $touched++;
        }

        $this->info(($this->option('dry') ? 'سيُعدَّل ' : 'عُدِّل ') . $touched . ' دوراً');
        $this->line('ولا يُمنَح حذفٌ ولا رايةُ سلطةٍ من هنا — تُضبط من محرّر الأدوار بقرارٍ معلَن.');

        return self::SUCCESS;
    }
}
