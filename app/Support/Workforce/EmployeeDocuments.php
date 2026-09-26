<?php

namespace App\Support\Workforce;

use App\Models\Attachment;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use App\Support\Documents\DocumentPolicy;

/**
 * **«وثائقي» — سلطةٌ واحدةٌ يقرؤها الويبُ والجوال** (مجلس الخبراء · N-5).
 *
 * رادارُ «ينتهي قريباً» يُنذر صاحبَ الشأنِ بوثيقتِه — إقامتِه وجوازِه وعقدِه —
 * ويسوقه إلى ملفِّه. وحتّى v2.525.0 لم تكن للوجهةِ صفحةٌ أصلاً؛ ثمّ بُنيت للويب
 * وحدَه، فبقي الجوالُ يُنذر بلا بابٍ يفتح — **نصفُ إغلاقٍ ينتج نصفَ عيب**.
 *
 * فالقاعدةُ هنا في موضعٍ واحد. وهذا ليس ترتيباً جماليّاً بل الدرسَ المتكرّرَ في
 * هذا السجلّ: **إصلاحٌ صحيحٌ يُطبَّق على قارئٍ واحدٍ يُنتج تعريفاً ثانياً** —
 * ولا اختبارَ يحمرّ حين يفترق سطحان، لأنّ كلَّ سطحٍ صادقٌ وحدَه.
 *
 * **والتفويضُ ارتباطُ الملفّ، لا صلاحيّةُ الوحدة** (كـ«عهدتي»): `hr:v` صلاحيّةٌ
 * لا يملكها الموظّفُ ولا ينبغي — ملفّاتُ زملائِه ليست له. وحدودُ الاستثناءِ
 * محفوظةٌ كما كُتبت في `DocumentPolicy::subjectMay`: يُستثنى من بوّابةِ
 * الحساسيّةِ وحدَها، **والمنعُ الصريحُ يعلو**.
 */
class EmployeeDocuments
{
    /** سقفٌ حارسُ ذاكرةٍ — لا يُتوقَّع لملفِّ موظّفٍ أن يتجاوزه */
    public const MAX = 60;

    /**
     * **سجلّاتُ الموظّفِ المرتبطةُ بحسابِه** — مصدرُ الصفةِ الوحيد.
     *
     * صفوفُه كلُّها لا أوّلُها (النموذجُ يُتيح ربطاً مزدوجاً)، مرتَّبةً بـ`id`
     * فلا يبقى للترتيبِ أثرٌ — نفسُ ما فعله `hub_expiry_self_scan` بعد أن كشفت
     * القرعةُ أنّها تُخفي أعجلَ إقامةٍ عن صاحبِها (F4).
     *
     * @return array<int,string>
     */
    public static function employeeIds(?User $user): array
    {
        if (! $user) return [];

        return Employee::where('user_id', $user->id)->whereNull('deleted_at')
            ->orderBy('id')->pluck('id')->map(fn ($x) => (string) $x)->all();
    }

    /**
     * **وثائقي** — ما على ملفّي، بنوعِها وتاريخِ انتهائها وحالتِها.
     *
     * صفوفٌ مجرَّدةٌ بلا `path` ولا `disk`: الوجهةُ تُبنى في السطحِ الذي يعرضها،
     * والقرصُ لا يُذكر في أيِّ عقد.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forUser(?User $user): array
    {
        $ids = self::employeeIds($user);
        if (! $ids || ! Schema::hasTable('attachments')) return [];

        $rows = Attachment::whereNull('deleted_at')
            ->where('module', 'hr')->whereIn('record_id', $ids)
            // المؤرَّخُ أوّلاً ثمّ الأحدثُ رفعاً — و`id` حاسمٌ أخيراً فلا قرعةَ بين المحرّكين
            ->orderByRaw('expires_at IS NULL, expires_at')
            ->orderByDesc('created_at')->orderBy('id')
            ->limit(self::MAX)->get();

        if ($rows->isEmpty()) return [];
        DocumentPolicy::primeMemo($rows->pluck('id'));

        $window = hub_radar_window();

        return $rows->filter(fn (Attachment $a) => DocumentPolicy::subjectMayAny($user, $a))
            ->map(function (Attachment $a) use ($window) {
                $days = $a->expires_at
                    ? (int) now()->startOfDay()->diffInDays($a->expires_at->copy()->startOfDay(), false)
                    : null;

                return [
                    'id' => (string) $a->id,
                    'kind' => (string) $a->kind,
                    'label' => hub_doc_label('hr', $a->kind) ?? 'وثيقة',
                    'name' => (string) $a->original_name,
                    'doc_no' => $a->doc_no ?: null,
                    'mime' => (string) $a->mime,
                    'size' => (int) $a->size,
                    'date' => $a->expires_at?->toDateString(),
                    'days' => $days,
                    'infected' => $a->av_status === 'infected',
                    // نافذةُ الرادارِ نفسُها تحكم النغمة — لا عتبةٌ ثالثةٌ في الواجهة (N-6)
                    'tone' => $days === null ? ''
                        : ($days < 0 ? 'bad' : ($days <= min(14, $window) ? 'wn' : '')),
                ];
            })->values()->all();
    }

    /**
     * **الوثيقةُ التي ثبت أنّها له** — أو `null`.
     *
     * المُنادي يردّ ٤٠٤ لا ٤٠٣: وثيقةُ زميلٍ لا يُثبَت وجودُها لمن لا تخصّه
     * (نظيرُ `portal.employee` مع حسابِ العميل). أمّا المنعُ الصريحُ على وثيقتِه
     * هو فيُردّ ٤٠٣ لأنّ وجودَها مُثبَتٌ له أصلاً.
     */
    public static function find(?User $user, string $id): ?Attachment
    {
        $a = Attachment::whereNull('deleted_at')->where('module', 'hr')->find($id);
        if (! $a) return null;

        return in_array((string) $a->record_id, self::employeeIds($user), true) ? $a : null;
    }
}
