<?php

namespace App\Support\Workforce;

use App\Support\Platform\SchemaCache;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * **المهمّةُ الخاصّة تُرى لأهلها وحدَهم** (طلبُ المالك: «لمّا نرسل مهمّة لموظّف تظهر له هو لحاله»).
 *
 * أهلُها: المُسنَد إليه، والمشاركون، ومُنشئها، ومديرُ مشروعها — والمالكُ فوق الجميع.
 * يُستدعى من `hub_scope` فيسري الحصرُ على كلِّ بابِ قراءة (القائمة والسجلّ والبحث والتصدير
 * والـAPI ومزامنة الجوال واسأل Hub والعقل الثاني) لا على شاشةٍ بعينها. والمهمّةُ العامّة
 * (العلَمُ مطفأ — افتراضُ كلِّ ما سبق هذه النسخة) لا يمسّها شيء.
 */
final class PrivateTasks
{
    public static function scope($q, mixed $user): void
    {
        if (hub_is_owner($user) || ! SchemaCache::hasColumn('tasks', 'private')) return;

        $uid = (string) $user->id;
        $t = $q instanceof EloquentBuilder ? $q->getModel()->getTable() : 'tasks';

        $q->where(function ($w) use ($uid, $t) {
            $w->where("$t.private", false)->orWhereNull("$t.private")
                ->orWhere("$t.assignee_id", $uid)
                ->orWhere("$t.created_by", $uid)
                // المشاركون مصفوفةُ UUID في JSON — والـUUID فريدٌ فلا يطابق جزءاً من غيره
                ->orWhere("$t.parts", 'like', '%' . $uid . '%')
                ->orWhereIn("$t.project_id", fn ($sub) => $sub->select('id')->from('projects')->where('manager_id', $uid));
        });
    }
}
