<?php

namespace App\Support;

use App\Models\Task;
use Illuminate\Support\Str;

/**
 * أفعالُ المعالجة (WP-8.5 · spec §6.13 · §31).
 *
 * «نتيجةٌ» بلا فعلٍ يُغلقها ليست إدارةً — هي لوحةُ شكوى. والفعلُ **لا يُخترع
 * له نظام**: المهامُّ نظامٌ قائمٌ بمسؤولٍ وموعدٍ وأولويةٍ وسجلِّ إغلاق، فزرُّ
 * «أنشئ مهمّةَ معالجة» يكتب مهمّةً كما يفعل `ErrorCenterController::toTask`
 * حرفياً — لا جدولَ «إجراءاتٍ تصحيحية» ثانياً بجانبه.
 *
 * ثلاثةُ التزاماتٍ تجعله فعلاً لا زرّاً:
 *  • **ذاكرةٌ تمنع التكرار** — النقرةُ العاشرة تفتح المهمّةَ الأولى لا العاشرة.
 *  • **رابطٌ عكسيّ بنمط البيت** — عمودُ `task_id` على المصدر حيث يوجد، وإلا
 *    `tasks.meta.origin = {kind, module, key}` (مفتاحُ `(module, record_id)`
 *    متعدّدُ الأشكال نفسُه الذي يربط التعليقاتِ والمرفقاتِ والقياسات).
 *  • **أثرٌ** — من فتح، ولأيّ نتيجة، ومتى (§31).
 */
class Remediation
{
    /** أصنافُ النتائج التي تُعالَج — وكلُّ صنفٍ يقول وحدتَه ونصَّ مهمّته */
    public const KINDS = [
        'quality' => 'نتيجة جودة بيانات',
        'kpi'     => 'مؤشّر خارج الهدف',
        'okr'     => 'هدف متعثّر',
        'sla'     => 'خرق SLA',
    ];

    /** الأولويةُ الافتراضية لكل صنف — تُعلَن ولا تُخترع عند كل نداء */
    public const PRIORITY = [
        'quality' => 'متوسطة', 'kpi' => 'عالية', 'okr' => 'عالية', 'sla' => 'عاجلة',
    ];

    /** عرضُ عمود `tasks.title` — القصُّ بـ`mb_substr` عند الكاتب لا عند المحرّك */
    public const TITLE_MAX = 300;

    /**
     * مهمّةُ المعالجة القائمة لهذه النتيجة إن وُجدت — **الذاكرة**.
     * المفتاحُ نصٌّ واحدٌ (`kind:...`) يُستعلَم بمسار JSON محايدٍ للمحرّكين،
     * على نمط `meta->fingerprint` القائم في `helpers.php`.
     */
    public static function existing(string $key): ?Task
    {
        return Task::where('meta->origin->key', $key)->orderBy('id')->first();
    }

    /**
     * افتح مهمّةَ معالجةٍ لنتيجة — أو أعِد القائمةَ إن سبق فتحُها.
     *
     * @param  array{kind:string,module:?string,record:?string,key:string,title:string,
     *               body:string,company_id:?string,project_id:?string,name:?string}  $origin
     * @return array{task: Task, created: bool}
     */
    public static function open(array $origin, array $input = []): array
    {
        $kind = (string) $origin['kind'];
        abort_unless(isset(self::KINDS[$kind]), 422, 'صنفُ معالجةٍ غير معروف');
        abort_unless(hub_can(auth()->user(), 'tasks', 'a'), 403, 'إنشاء المهام يتطلب صلاحيتها');

        $key = (string) $origin['key'];
        if ($old = self::existing($key)) {
            return ['task' => $old, 'created' => false];
        }

        $task = Task::create([
            // القصُّ عند الكاتب بعرض العمود الصريح — لا اقتطاعٌ صامتٌ من MySQL
            'title' => mb_substr('🛠 معالجة: ' . $origin['title'], 0, self::TITLE_MAX),
            'status' => 'جديدة',
            'priority' => $input['priority'] ?? (self::PRIORITY[$kind] ?? 'متوسطة'),
            'assignee_id' => $input['assignee_id'] ?? null,
            'due' => $input['due'] ?? null,
            // شركةُ المصدر تُورَّث: مهمّةٌ بلا شركةٍ تخرج من تقارير شركتها
            // ومن رؤية كلِّ معزولٍ عليها — فتُفتح ولا يراها من يعنيه أمرُها
            'company_id' => $origin['company_id'] ?? null,
            'project_id' => $origin['project_id'] ?? null,
            // الوصفُ يمرّ بالمُطهِّر الواحد: المهمّةَ يقرؤها من لا يملك فتحَ
            // المركز الذي وُلدت فيه — فلا تسريبَ عبرها
            'description' => Redactor::text($origin['body']),
            'meta' => ['origin' => [
                'kind' => $kind,
                'module' => $origin['module'] ?? null,
                'record' => $origin['record'] ?? null,
                'key' => $key,
                'at' => now()->toDateTimeString(),
            ]],
        ]);

        // الرابطُ العكسيّ على المصدر حيث يوجد عمودُه (بحارس العمود قبل الترحيل)
        $module = (string) ($origin['module'] ?? '');
        $record = (string) ($origin['record'] ?? '');
        $def = $module ? hub_mod($module) : null;
        if ($def && $record !== '' && hub_has_col((string) $def['table'], 'task_id')) {
            \Illuminate\Support\Facades\DB::table($def['table'])
                ->where('id', $record)->whereNull('task_id')->update(['task_id' => $task->id]);
        }

        // الأثر (§31): فتحُ معالجةٍ فعلُ مستوى تحكّمٍ — من، ولأيّ نتيجة، ومتى.
        // و`audits.record_id` من نوع uuid فلا يسعه مفتاحُ نتيجةِ جودة — يمرّ
        // المفتاحُ في الاسم وتبقى الخانةُ فارغةً بدل أن تُحشى بما ليس معرّفاً.
        hub_audit('إنشاء مهمّة معالجة', $module ?: null,
            Str::isUuid($record) ? $record : null,
            mb_substr((string) ($origin['name'] ?? $origin['title']), 0, 300),
            ['after' => ['kind' => self::KINDS[$kind], 'key' => $key, 'task_id' => $task->id]]);

        return ['task' => $task, 'created' => true];
    }
}
