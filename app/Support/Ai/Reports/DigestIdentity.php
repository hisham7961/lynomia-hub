<?php

namespace App\Support\Ai\Reports;

use App\Models\Role;
use App\Models\User;

/**
 * **هويّةُ مُلخِّص التقارير — حسابُ خدمةٍ في الذاكرة لا في القاعدة** (نمطُ `AuditorIdentity`).
 *
 * الجولةُ المجدولةُ بلا مستخدمٍ جالس، فكلُّ قراءةٍ تمرّ بـ`hub_scope`/`hub_field_mode` بهذه الهويّة
 * كما يمرّ بها أيُّ إنسان — لا طريقَ جانبيّاً. عرضٌ فقط على ما يقرؤه (التقاريرُ ومشاريعُها ومهامُّها)،
 * بلا أيِّ راية (ولا `fieldsec`)، ولا تُحفَظ صفّاً في `users` فلا تدخل عدَّ المستخدمين ولا يُدخَل بها.
 *
 * **واتّساعُ قراءتها لا يصير اتّساعَ رؤية:** الملخّصُ يُعرَض لمن يرى المشروعَ ويملك `updates:v`
 * (`DigestAccess`) — أي لمن كان سيقرأ التقاريرَ نفسَها في صفحة المشروع.
 */
final class DigestIdentity
{
    public const MODULES = ['updates', 'projects', 'tasks'];

    public static function user(): User
    {
        $matrix = [];
        foreach (self::MODULES as $m) {
            $matrix[$m] = ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0];
        }

        $role = new Role(['name' => 'مُلخِّص التقارير (خدمة)', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $role->is_owner = false;

        $u = new User(['name' => 'مُلخِّص التقارير', 'email' => 'report-digest@service.invalid']);
        $u->account_type = 'internal';
        $u->companies = [];
        $u->clients = [];
        $u->setRelation('role', $role);

        return $u;
    }
}
