<?php

namespace App\Support\Ai\Auditor;

use App\Models\Role;
use App\Models\User;

/**
 * **هويّةُ المدقّق — حسابُ خدمةٍ في الذاكرة لا في القاعدة** (§٣.٢).
 *
 * المدقّقُ يعمل مجدولاً بلا مستخدمٍ جالس، فيحتاج هويّةً تمرّ بها كلُّ قراءةٍ عبر
 * `hub_scope` و`hub_can` و`hub_field_mode` كما يمرّ بها أيُّ إنسان — **لا طريقَ
 * جانبيّاً**. ولماذا لا تُحفَظ صفّاً في `users`؟ لأنّ صفّاً هناك يدخل كلَّ ما يعدّ
 * المستخدمين: نداءَ الحضور (فيُرصَد «المدقّقُ» غائباً بلا تقرير)، ودليلَ الفريق،
 * ومنتقي الإسناد، وعدَّ المقاعد، وسجلَّ الدخول. وهويّةٌ لا تُحفَظ لا تُسرَق ولا
 * يُدخَل بها: لا كلمةَ مرور، ولا جلسة، ولا رمز.
 *
 * **ودورُها أضيقُ ما يكفي:**
 *  · عرضٌ فقط (`v`) على وحداتِ العمل التي يقرؤها — لا إضافةَ ولا تعديلَ ولا حذف.
 *  · نطاقُ `all` (يرى عملَ الفريقِ كلِّه) — **والعرضُ يُعاد تنطيقُه للمشاهد**، فاتّساعُ
 *    القراءةِ لا يصير اتّساعَ رؤية (`AuditorSignals::visibleTo`).
 *  · **بلا أيِّ راية** — ولا `fieldsec` خاصّةً: الرواتبُ والهويّاتُ والميزانيّاتُ
 *    محجوبةٌ عنه بـ`hub_visible_fields` نفسِها، فلا تبلغ كاشفاً ولا نموذجاً أصلاً.
 */
final class AuditorIdentity
{
    /** الوحداتُ التي يقرؤها المدقّق — عملُ الفريق لا غير */
    public const MODULES = ['updates', 'tasks', 'decisions', 'meetings', 'projects', 'okrs', 'krs'];

    public static function user(): User
    {
        $matrix = [];
        foreach (self::MODULES as $m) {
            $matrix[$m] = ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0];
        }

        $role = new Role(['name' => 'المدقّق (خدمة)', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $role->is_owner = false;

        $u = new User(['name' => 'المدقّق', 'email' => 'auditor@service.invalid']);
        $u->account_type = 'internal';
        $u->companies = [];
        $u->clients = [];
        $u->setRelation('role', $role);

        return $u;
    }
}
