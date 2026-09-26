<?php

namespace App\Support\Insights;

/**
 * محرّكاتٌ نُقلت من `helpers.php` بلا تغيير (docs/REORG_PLAN.md §R5) — والدوالُّ العامّةُ بأسمائها
 * باقيةٌ هناك أغلفةً من سطرٍ واحد، فلا Blade ولا متحكّمَ يتغيّر.
 */
final class CompanyHealth
{
    /**
     * تقرير صحة الشركة: نتيجة 0-100 لكل قسم بمعادلة شفافة تُذكر في note.
     * مخبأ 30 دقيقة — كل قسم محصّن بـ try/catch حتى لا يُسقط قسمٌ التقريرَ كله.
     */
    public static function compute(bool $fresh = false): array
    {
        /*
         * **والختمُ يسبق المهلة** (مجلس الخبراء). كان المفتاحُ `hub:health` **خاماً
         * بلا ختمِ بيانات** — بخلافِ `hub_expiry` و`hub_screen` وكلِّ شاشةٍ محسوبةٍ
         * في المنتج — فالأبعادُ مجمّدةٌ نصفَ ساعةٍ مهما تغيّرت البيانات: يُسجَّل عقدٌ
         * فيبقى التقريرُ يقول «لا سجلّاتٍ بعد».
         *
         * والختمُ قراءةُ مخبأٍ لا استعلامَ قاعدة، فلا كلفةَ تُذكر.
         */
        // `metric_points` مُدرَجٌ لأنّ بُعدَ «الأمن» يقرأ لقطتَه اليوميّةَ منه —
        // كان خارجَ الختمِ فتبقى الدرجةُ قديمةً بعد لقطةٍ جديدة (مجلس الخبراء).
        $key = 'hub:health' . hub_data_stamp([
            'contracts', 'domains', 'employees', 'fin_documents', 'incidents',
            'issues', 'projects', 'servers', 'users', 'vault_secrets', 'metric_points',
        ]);
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, 1800, function () {
            $db = \Illuminate\Support\Facades\DB::getFacadeRoot();
            $today = now()->toDateString();
            $soon  = now()->addDays(30)->toDateString();
            $out = [];
            $clamp = fn ($v) => (int) round(min(100, max(0, $v)));

            // المالية: خصم بنسبة المستحقات المتأخرة، وخصم إن كان صافي الشهر سالباً
            try {
                $open = $db->table('fin_documents')->whereNull('deleted_at')
                    ->whereIn('state', ['مرسلة', 'مدفوعة جزئياً', 'متأخرة']);
                $openN = (clone $open)->count();
                $late  = $openN ? (clone $open)->whereNotNull('due')->where('due', '<', $today)->count() : 0;
                $m0  = now()->startOfMonth()->toDateString();
                $inc = hub_fin_sum(config('hub.fin.income'), $m0);
                $exp = hub_fin_sum(config('hub.fin.expense'), $m0);
                $score = 100 - ($openN ? ($late / $openN) * 60 : 0) - ($inc - $exp < 0 ? 20 : 0);
                $out['المالية'] = ['score' => $clamp($score), 'measured' => $openN > 0,
                    'note' => "{$late}/{$openN} مستحق متأخر · صافي الشهر " . ($inc - $exp >= 0 ? 'موجب' : 'سالب')];
            } catch (\Throwable $e) {}

            // المشاريع: متوسط نسبة الإنجاز للمشاريع غير المغلقة
            try {
                $projects = $db->table('projects')->whereNull('deleted_at')
                    ->where(fn ($w) => $w->whereNull('status')->orWhere(fn ($x) => $x->where('status', 'NOT LIKE', '%مكتمل%')->where('status', 'NOT LIKE', '%ملغ%')))
                    ->limit(30)->pluck('id');
                $ps = collect($projects)->map(fn ($id) => hub_progress($id)['pct'])->filter(fn ($p) => $p !== null);
                if ($ps->count()) $out['المشاريع'] = ['score' => $clamp($ps->avg()), 'measured' => true,
                    'note' => 'متوسط إنجاز ' . $ps->count() . ' مشروع جارٍ'];
            } catch (\Throwable $e) {}

            // الأمن: خصم للمستخدمين الخاملين >60 يوماً وللأسرار التي لم تُحدَّث >180 يوماً
            try {
                $users = $db->table('users')->whereNull('deleted_at')->where('status', '!=', 'موقوف');
                $un = (clone $users)->count();
                $idle = $un ? (clone $users)->where(fn ($w) => $w->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(60)))->count() : 0;
                $sec = $db->table('vault_secrets')->whereNull('deleted_at');
                $sn = (clone $sec)->count();
                $stale = $sn ? (clone $sec)->where('updated_at', '<', now()->subDays(180))->count() : 0;
                // (الطور ٤ · §2.2 توحيدُ الدرجة) المصدرُ الواحد هو لقطةُ الوضعية اليومية
                // (SecurityPosture::summary عبر hub:security-snapshot)؛ والمعادلةُ المحلية
                // القديمة تبقى احتياطاً صادقاً قبل أول لقطة — لا درجتين متضاربتين بعدها.
                $snap = hub_metric_latest('security', 'org', 'score');
                $score = $snap !== null ? $snap : (100 - ($un ? ($idle / $un) * 35 : 0) - ($sn ? ($stale / $sn) * 45 : 0));
                $out['الأمن'] = ['score' => $clamp($score), 'measured' => ($un + $sn) > 0,
                    'note' => "{$idle}/{$un} مستخدم خامل · {$stale}/{$sn} سر لم يُغيَّر منذ ٦ أشهر"];
            } catch (\Throwable $e) {}

            // الموارد البشرية: خصم لوثائق الموظفين المنتهية والقريبة من الانتهاء
            try {
                $emp = $db->table('employees')->whereNull('deleted_at')->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'NOT LIKE', '%منتهي%'));
                $en = (clone $emp)->count();
                $expired = $en ? (clone $emp)->where(fn ($w) => $w->where('iqama_exp', '<', $today)->orWhere('pass_exp', '<', $today))->count() : 0;
                $soonN = $en ? (clone $emp)->where(fn ($w) => $w->whereBetween('iqama_exp', [$today, $soon])->orWhereBetween('pass_exp', [$today, $soon]))->count() : 0;
                $score = 100 - ($en ? ($expired / $en) * 55 + ($soonN / $en) * 20 : 0);
                $out['الموارد البشرية'] = ['score' => $clamp($score), 'measured' => $en > 0,
                    'note' => "{$expired} وثيقة منتهية · {$soonN} تنتهي خلال شهر (من {$en} موظف)"];
            } catch (\Throwable $e) {}

            // الامتثال: العقود والدومينات المنتهية أو القريبة
            try {
                $c = $db->table('contracts')->whereNull('deleted_at'); $cn = (clone $c)->count();
                // العمود date_end لا end — الاسم الخاطئ كان يرمي «unknown column»
                // فيبتلعه catch وتختفي بطاقة الامتثال صامتةً من صحة الشركة
                $cLate = (clone $c)->whereNotNull('date_end')->where('date_end', '<', $today)->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'NOT LIKE', '%منته%'))->count();
                $d = $db->table('domains')->whereNull('deleted_at'); $dn = (clone $d)->count();
                $dLate = (clone $d)->whereNotNull('expiry')->where('expiry', '<', $today)->count();
                $tot = $cn + $dn;
                $score = 100 - ($tot ? (($cLate + $dLate) / $tot) * 70 : 0);
                $out['الامتثال'] = ['score' => $clamp($score), 'measured' => $tot > 0,
                    'note' => "{$cLate} عقد و{$dLate} دومين متجاوز للنهاية (من {$tot})"];
            } catch (\Throwable $e) {}

            // البنية التحتية: سيرفرات/شهادات SSL منتهية + أعطال حرجة مفتوحة
            try {
                $s = $db->table('servers')->whereNull('deleted_at'); $sn2 = (clone $s)->count();
                $sLate = (clone $s)->whereNotNull('expiry')->where('expiry', '<', $today)->count();
                $ssl = $db->table('domains')->whereNull('deleted_at')->whereNotNull('ssl_exp')->where('ssl_exp', '<', $today)->count();
                $crit = $db->table('issues')->whereNull('deleted_at')->where('severity', 'LIKE', '%حرج%')
                    ->where(fn ($w) => $w->whereNull('status')->orWhere(fn ($x) => $x->where('status', 'NOT LIKE', '%مغلق%')->where('status', 'NOT LIKE', '%محلول%')))->count();
                // الحوادث المفتوحة تدخل الدرجة أخيراً — كانت وحدة إدارة الحوادث
                // كاملةً خارج تقييم البنية التحتية، والدرجة تعدّ issues بدلاً منها
                $inc = \Illuminate\Support\Facades\Schema::hasTable('incidents')
                    ? $db->table('incidents')->whereNull('deleted_at')
                        ->whereNotIn('status', ['مغلق بتقرير', 'مُستعاد'])->count() : 0;
                $score = 100 - ($sn2 ? ($sLate / $sn2) * 30 : 0) - min(30, $ssl * 10)
                       - min(40, $crit * 10) - min(30, $inc * 12);
                $out['البنية التحتية'] = ['score' => $clamp($score),
                    // مقيسٌ إن وُجد ما يُقاس: سيرفراتٌ أو شهاداتٌ أو أعطالٌ أو حوادث
                    'measured' => ($sn2 + $ssl + $crit + $inc) > 0,
                    'note' => "{$sLate} سيرفر منتهٍ · {$ssl} شهادة SSL منتهية · {$crit} عطل حرج مفتوح · {$inc} حادثة مفتوحة"];
            } catch (\Throwable $e) {}

            /*
             * **الدرجةُ تُمحى حيث لا قياس** (مجلس الخبراء · PROD-10).
             *
             * لا يكفي وسمُ `measured=false` وتركُ الدرجةِ مئةً: كلُّ قارئٍ لا
             * يفحص الوسمَ — و`/api/v1/health` يمرّرها كما هي — سيقرأ **مئةً
             * كاذبة**. فالقيمةُ تُصبح `null` صراحةً، والقارئُ الذي يجمع أو يقارن
             * يحصل على فراغٍ لا على «ممتاز». **والبُعدُ يبقى في التقرير** باسمِه
             * وسببِه، وتعود درجتُه فورَ وجودِ أوّلِ سجلّ.
             */
            foreach ($out as $k => $d) {
                if (is_array($d) && ! ($d['measured'] ?? true)) $out[$k]['score'] = null;
            }

            return $out;
        });
    }
}
