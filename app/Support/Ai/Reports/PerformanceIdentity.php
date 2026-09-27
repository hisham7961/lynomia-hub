<?php

namespace App\Support\Ai\Reports;

use App\Models\Role;
use App\Models\User;

/**
 * **هويّةُ كاتب تقارير الأداء — حسابُ خدمةٍ في الذاكرة لا في القاعدة** (نمطُ `DigestIdentity`).
 *
 * الجولةُ المجدولةُ بلا مستخدمٍ جالس، فكلُّ قراءةٍ للحقائق تمرّ بـ`hub_scope` بهذه الهويّة كما يمرّ بها
 * أيُّ إنسان. عرضٌ فقط على ما تُبنى منه الحقائق، بلا أيِّ راية — **ولا `fieldsec`**: فالراتبُ والهويّةُ
 * والحسابُ البنكيّ (`hub_field_sec`) محجوبةٌ عنها بالبناء، ولا يُنتقى منها عمودٌ أصلاً.
 *
 * **واتّساعُ قراءتها لا يصير اتّساعَ رؤية:** التقريرُ يُعرَض بقاعدة `PerformanceAccess` وحدَها.
 */
final class PerformanceIdentity
{
    public const MODULES = ['hr', 'updates', 'projects', 'tasks', 'attend', 'leaves', 'assets', 'tickets', 'issues'];

    public static function user(): User
    {
        $matrix = [];
        foreach (self::MODULES as $m) {
            $matrix[$m] = ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0];
        }

        $role = new Role(['name' => 'كاتبُ تقارير الأداء (خدمة)', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $role->is_owner = false;

        $u = new User(['name' => 'كاتبُ تقارير الأداء', 'email' => 'employee-performance@service.invalid']);
        $u->account_type = 'internal';
        $u->companies = [];
        $u->clients = [];
        $u->setRelation('role', $role);

        return $u;
    }
}
