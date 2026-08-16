<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بوابة الملفات المخزَّنة في حقول الوحدات (file/img).
 *
 * كانت تخدم **أي ملفٍ تحت `hub/`** لأي مستخدمٍ مصادَق: لا وحدة ولا نطاق ولا
 * صلاحية — فسريّة عقد الموظف أو صورة هويته كانت في **عشوائية اسم الملف وحدها**،
 * ومن يعرف المسار (من نسخةٍ احتياطية، أو سجلٍّ رآه يوماً، أو تسريب) يقرؤه أبداً.
 *
 * الآن: يُبحث عن السجل المُشير إلى الملف في وحدات السجل، ويُخدَم الملف فقط إن
 * كان ذلك السجل مما **يراه** هذا المستخدم (صلاحية الوحدة + نطاقه + عزل شركاته).
 */
class FileController extends Controller
{
    public function show(\Illuminate\Http\Request $r, string $path)
    {
        // داخل hub/ فقط وبلا صعود مسارات
        abort_unless(str_starts_with($path, 'hub/') && ! str_contains($path, '..'), 404);

        abort_unless($this->mayRead($path), 403, 'هذا الملف يخصّ سجلاً خارج صلاحيتك');

        // حاجز الإصابة يسري على خدمة الملفات بالمسار كما على التنزيل والمعاينة:
        // مرفقٌ وُسم مصاباً لا يُبَثّ من هنا (هذا المسار يخدم مسارات المرفقات أيضاً).
        abort_if(\App\Models\Attachment::where('path', $path)->where('av_status', 'infected')->exists(),
            423, 'حُجب هذا الملف — وُسم مصاباً بفحص الفيروسات');

        foreach ([storage_path('app/' . $path), storage_path('app/public/' . $path)] as $abs) {
            if (is_file($abs)) {
                /*
                 * **لا تنفيذَ لمرفوع.** كانت البوابة تبثّ بترويسة noindex وحدها
                 * والنوعُ يُخمَّن من الملف — فـSVG/HTML مرفوعٌ في حقل وحدةٍ يعمل
                 * سكربتُه بأصل التطبيق لدى كل من يفتح السجل (XSS مخزَّن).
                 * سياسة المرفقات نفسها: الصور النقطية وPDF تُعاين حيّاً،
                 * وكل ما سواها تنزيلٌ قسري — وnosniff دائماً فلا يجتهد المتصفح.
                 */
                $mime = (string) (mime_content_type($abs) ?: 'application/octet-stream');
                $inline = in_array($mime, AttachmentController::INLINE_MIMES, true) && ! $r->boolean('dl');

                /*
                 * **`?dl=1` تنزيلٌ باسمٍ يُعرَف.** كان الملفُ المرفوع في حقل وحدة
                 * (شعارُ المشروع، الهويةُ البصرية، نسخةُ العقد) يُفتح معاينةً في
                 * تبويب ولا زرَّ لتنزيله؛ ومن حفظه بزرّ المتصفح حصل على
                 * `hub/9f3c…e1.png` — اسمُ التخزين العشوائيّ لا اسمُ الملف. فصار
                 * له بابٌ صريح، والاسمُ يُشتقّ من مصدره: الاسمُ الأصليّ إن حُفظ،
                 * وإلا من **السجل وحقله** («مشروع أطلس — شعار المشروع.png»).
                 */
                if (! $inline) {
                    return response()->download($abs, $this->downloadName($path), [
                        'X-Robots-Tag' => 'noindex',
                        'X-Content-Type-Options' => 'nosniff',
                    ]);
                }

                return response()->file($abs, [
                    'X-Robots-Tag' => 'noindex',
                    'X-Content-Type-Options' => 'nosniff',
                    'Content-Disposition' => 'inline; filename="' . rawurlencode(basename($path)) . '"',
                ]);
            }
        }

        abort(404);
    }

    /**
     * **اسمُ الملف عند التنزيل** — لا اسمَ تخزينه العشوائيّ.
     *
     * الملفُّ يُخزَّن باسمٍ مولَّد (`hub/9f3c…e1.png`) حمايةً من التخمين، فمن
     * نزّله وجد في «التنزيلات» ملفاً لا يدلّ على شيء — وعشرةُ ملفاتٍ كذلك تصير
     * كومةً لا تُميَّز. الاسمُ هنا يُستردّ من مصدره بالترتيب:
     *
     *   ١. **المرفق العام**: اسمه الأصليّ محفوظٌ في `attachments.original_name`.
     *   ٢. **وثيقة الوارد**: `inbox_documents.orig`.
     *   ٣. **حقلُ ملفٍ في وحدة**: الاسمُ الأصليّ إن خُتم في `meta.files.{عمود}`
     *      (يُختم منذ هذه النسخة)، وإلا يُبنى وصفاً من **السجل وحقله** —
     *      وهو للملفات القديمة أدلُّ من اسمٍ عشوائيّ لا يقول شيئاً.
     *
     * والامتدادُ يُفرض من الملف المخزَّن دائماً: اسمٌ يعد بامتدادٍ غير محتواه
     * يُربك من فتحه، وسجلٌّ اسمُه «تقرير.php» لا يُنزَّل بامتدادٍ تنفيذيّ.
     */
    protected function downloadName(string $path): string
    {
        $base = basename($path);
        $ext = (string) pathinfo($base, PATHINFO_EXTENSION);

        if (Schema::hasTable('attachments')
            && ($n = DB::table('attachments')->where('path', $path)->value('original_name'))) {
            return $this->safeName((string) $n, $ext);
        }

        if (Schema::hasTable('inbox_documents')
            && ($n = DB::table('inbox_documents')->where('path', $path)->value('orig'))) {
            return $this->safeName((string) $n, $ext);
        }

        foreach (hub_modules() as $mk => $def) {
            $table = (string) ($def['table'] ?? '');
            if ($table === '' || ! Schema::hasTable($table)) continue;

            $cols = collect($def['fields'] ?? [])
                ->filter(fn ($f) => in_array($f['type'] ?? '', ['file', 'img'], true))
                ->mapWithKeys(fn ($f) => [(string) $f['col'] => (string) $f['label']])->all();
            if (! $cols) continue;

            $row = DB::table($table)->where(function ($w) use ($cols, $path) {
                foreach (array_keys($cols) as $c) $w->orWhere($c, $path);
            })->first();
            if (! $row) continue;

            // أيُّ عمودٍ منها يحمل هذا الملف؟ (السجل قد يحمل ملفين)
            $col = collect(array_keys($cols))->first(fn ($c) => ($row->{$c} ?? null) === $path);
            if (! $col) continue;

            $meta = json_decode((string) ($row->meta ?? ''), true) ?: [];
            if ($orig = ($meta['files'][$col]['name'] ?? null)) {
                return $this->safeName((string) $orig, $ext);
            }

            $disp = (string) ($row->{hub_display_col($mk)} ?? '');
            $label = $cols[$col];

            return $this->safeName(trim($disp !== '' ? "{$disp} — {$label}" : $label), $ext);
        }

        return $base;
    }

    /** اسمُ ملفٍ صالحٌ لكل نظام: بلا فواصل مسارٍ ولا محارف تحكّم، بامتداد المخزَّن */
    protected function safeName(string $name, string $ext): string
    {
        $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1F]#u', '-', trim($name)) ?: '';
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || $name === '.' || $name === '..') $name = 'ملف';
        $name = hub_fit($name, 120);

        if ($ext !== '' && ! str_ends_with(mb_strtolower($name), '.' . mb_strtolower($ext))) {
            $name .= '.' . $ext;
        }

        return $name;
    }

    /**
     * هل يملك المستخدم رؤية سجلٍ يشير إلى هذا الملف؟
     *
     * البحث على أعمدة الملفات في وحدات السجل وحدها (نحو ثلاثين وحدة)، ثم على
     * المرفقات العامة وصندوق الوارد — وهي تُخدم من مساراتها المحروسة أصلاً،
     * فوجودها هنا لا يفتح باباً جديداً بل يمنع كسر ما كان يعمل.
     */
    protected function mayRead(string $path): bool
    {
        $u = auth()->user();
        if (! $u) return false;
        if (hub_is_owner($u)) return true;

        // **الختم يسبق المهلة، والمفتاحُ يحمل نطاقَ القارئ لا دورَه وحده.**
        // كان يحمل الدورَ والمستخدمَ وختمَ الأدوار — فتبديلُ الشركة النشطة أو
        // عدسةِ المشروع **لا يُبطله**، ويُقدَّم ملفٌ من شركةٍ خرج منها القارئ
        // طَوالَ ١٢٠ث. `hub_scope_key` هي المعيارُ المرسَّخ لهذا بعينه.
        return (bool) Cache::remember(
            hub_scope_key('fileacc') . ':' . sha1($path) . ':' . hub_data_stamp(['roles']), 120,
            function () use ($u, $path) {
                foreach (hub_modules() as $mk => $def) {
                    $table = (string) ($def['table'] ?? '');
                    if ($table === '' || ! Schema::hasTable($table)) continue;

                    $cols = collect($def['fields'] ?? [])
                        ->filter(fn ($f) => in_array($f['type'] ?? '', ['file', 'img'], true))
                        ->pluck('col')->filter()->values()->all();
                    if (! $cols) continue;

                    // الوحدة غير مرئيةٍ له: لا حاجة لاستعلامٍ أصلاً
                    if (! hub_can($u, $mk, 'v')) continue;

                    $q = DB::table($table)
                        ->when(Schema::hasColumn($table, 'deleted_at'), fn ($x) => $x->whereNull('deleted_at'))
                        ->where(function ($w) use ($cols, $path) {
                            foreach ($cols as $c) $w->orWhere($c, $path);
                        });

                    if (hub_scope($q, $mk, $u)->exists()) return true;
                }

                /*
                 * **البابُ الخلفيّ**: كان هذا الجزء يعود `true` لأي مسارٍ موجودٍ
                 * في `attachments` أو `inbox_documents` **بلا صلاحيةٍ ولا نطاق**،
                 * بحجّة أن «لهما بوابتاهما المحروستان» — وهذه هي البوابة. فكلُّ
                 * الفحص أعلاه يُلتف عليه بمسارٍ واحدٍ من مرفقات سجلٍّ لا يملكه
                 * القارئ: عقدُ موظفٍ، أو صورةُ هويةٍ، أو كشفُ راتب.
                 *
                 * المرفقُ يُقاس بسجله: من يرى السجل يرى مرفقه، ولا أحد سواه.
                 */
                if (Schema::hasTable('attachments')) {
                    $rows = DB::table('attachments')->where('path', $path)
                        ->orWhere('thumb_path', $path)->get(['module', 'record_id']);
                    foreach ($rows as $a) {
                        $mk = (string) $a->module;
                        if (! hub_mod($mk) || ! hub_can($u, $mk, 'v')) continue;
                        if (! $a->record_id) continue;
                        if (hub_read($mk, $u)?->where('id', $a->record_id)->exists()) return true;
                    }
                }

                // صندوق الوارد: خلف صلاحية الوثائق نفسها التي تحرس شاشته
                if (Schema::hasTable('inbox_documents')
                    && (hub_can($u, 'inboxdocs', 'v') || hub_can($u, 'files', 'v'))
                    && DB::table('inbox_documents')->whereNull('deleted_at')
                        ->where('path', $path)->exists()) {
                    return true;
                }

                // مرفقات الرسائل المباشرة: لطرفَي المحادثة وحدهما. dm ليست وحدةً
                // في السجل فلم تكن البوابة تعرفها — وكل مرفقٍ يُرفض 403 حتى
                // لمرسِله ومستلمه أنفسهما، والرابط في الخيط ميت.
                if (Schema::hasTable('dm_messages')
                    && DB::table('dm_messages')->where('att', $path)
                        ->where(fn ($w) => $w->where('from_id', $u->id)->orWhere('to_id', $u->id))
                        ->exists()) {
                    return true;
                }

                /*
                 * **مرفقاتُ التعليقات**: `comments` ليست وحدةً في السجل فلم تكن
                 * البوابةُ تعرفها — فكلُّ مرفق تعليقٍ يُردّ 403 لغير رافعه، ورابطُه
                 * ظاهرٌ في الخيط يُغري بالضغط: ميزةٌ كاملةٌ ميتةٌ صامتة. الإذنُ
                 * يُشتقّ من **سجلِّ التعليق** بالحارس نفسِه الذي يحرس كتابته.
                 */
                if (Schema::hasTable('comments')) {
                    foreach (DB::table('comments')->whereNull('deleted_at')->where('att', $path)
                                ->get(['module', 'record_id', 'user_id']) as $c) {
                        if ((string) $c->user_id === (string) $u->id) return true;   // رافعُه يراه
                        $mk = (string) $c->module;
                        if ($mk === 'feed') return true;                             // قناةٌ عامّة لكل مصادَق
                        if (! hub_mod($mk) || ! hub_can($u, $mk, 'v')) continue;
                        if (! $c->record_id) continue;
                        if (hub_read($mk, $u)?->where('id', $c->record_id)->exists()) return true;
                    }
                }

                return false;
            }
        );
    }
}
