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
    public function show(string $path)
    {
        // داخل hub/ فقط وبلا صعود مسارات
        abort_unless(str_starts_with($path, 'hub/') && ! str_contains($path, '..'), 404);

        abort_unless($this->mayRead($path), 403, 'هذا الملف يخصّ سجلاً خارج صلاحيتك');

        foreach ([storage_path('app/' . $path), storage_path('app/public/' . $path)] as $abs) {
            if (is_file($abs)) {
                return response()->file($abs, ['X-Robots-Tag' => 'noindex']);
            }
        }

        abort(404);
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

        return (bool) Cache::remember(
            'fileacc:' . ($u->role_id ?? '0') . ':' . $u->id . ':' . sha1($path), 120,
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

                // المرفقات العامة وصندوق الوارد — لهما بوابتاهما المحروستان
                foreach ([['attachments', 'path'], ['inbox_documents', 'path']] as [$t, $c]) {
                    if (Schema::hasTable($t) && Schema::hasColumn($t, $c)
                        && DB::table($t)->where($c, $path)->exists()) {
                        return true;
                    }
                }

                return false;
            }
        );
    }
}
