<?php

namespace App\Http\Middleware;

use App\Support\ChunkedUpload;
use Closure;
use Illuminate\Http\Request;

/**
 * **الملفُّ المقطَّع يدخل من الباب نفسه.**
 *
 * بعد أن تصل قطعُ الملف الكبير ويُجمَّع على القرص، يبقى أن يراه المتحكّمُ
 * ملفاً مرفوعاً كأيّ ملف. والبديلُ — أن يقرأ كلُّ متحكّمٍ الرمزَ ويجمّع بنفسه —
 * يعني **مساراً ثانياً للرفع** بلا قواعد التحقق ولا منعِ الامتدادات التنفيذية
 * ولا البصمة: بابٌ خلفيّ يُنسى تحديثُه كلّما تغيّر البابُ الأمامي.
 *
 * فالحقنُ هنا، قبل المتحكّم: `_chunk_<الحقل>` يصير ملفاً في حقيبة الملفات
 * باسمه الأصليّ، و`_chunks[]` تصير `files[]`. وما بعد ذلك لا يعرف أحدٌ أن
 * الملفَّ جاء مقطَّعاً — التحققُ والتخزينُ والتدقيق كلُّها كما هي.
 *
 * والرمزُ يُطالَب به من **مجلد صاحبه** وحده (`auth()->id()`)، فلا يُستهلك رمزُ
 * غيره ولو خُمِّن. ويُوسَم الطلبُ كي يعرف `hub_upload_cap` أن سقفَ الطلب الواحد
 * لم يعد قيداً على هذا الملف.
 */
class ResolveChunkedUploads
{
    public const FLAG = 'hub.chunked';
    public const PREFIX = '_chunk_';

    /** أقصى ما يُطالَب به من رفعاتٍ مقطَّعة في طلبٍ واحد */
    public const MAX_CLAIMS = 50;

    public function handle(Request $request, Closure $next)
    {
        if (! auth()->id()) return $next($request);

        $claimed = false;

        /*
         * **من حقيبة الإدخال لا من `all()`.**
         *
         * `Request::all()` تستدعي `allFiles()` التي **تخبّئ** حقيبةَ الملفات
         * المحوَّلة في `convertedFiles`؛ فقراءةُ المفاتيح منها هنا تُثبّت
         * الخبيئةَ **فارغةً**، ثم لا يرى المتحكّمُ شيئاً ممّا نحقنه بعدها —
         * الحقنُ يقع فعلاً والقارئُ يقرأ نسخةً قديمة (وهو ما وقع أوّلَ مرة).
         * القراءةُ من حقيبة الإدخال تتجنّب ذلك، والخبيئةُ تُفرَّغ صراحةً بعد
         * الحقن على كل حال.
         */
        foreach ($request->request->all() as $key => $val) {
            if (! is_string($key) || ! str_starts_with($key, self::PREFIX) || ! is_array($val)) continue;

            $field = substr($key, strlen(self::PREFIX));
            if ($field === '') continue;

            $file = ChunkedUpload::claim(hub_str($val['token'] ?? ''), hub_str($val['name'] ?? 'ملف'));
            if (! $file) continue;

            $request->files->set($field, $file);
            $request->request->remove($key);
            $claimed = true;
        }

        // دفعةٌ من ملفات: `_chunks[]` ← `files[]` (بترتيب اختيارها كما أُرسلت)
        $batch = $request->request->all('_chunks');
        if ($batch) {
            $files = [];
            foreach (array_slice($batch, 0, self::MAX_CLAIMS) as $one) {
                if (! is_array($one)) continue;
                $file = ChunkedUpload::claim(hub_str($one['token'] ?? ''), hub_str($one['name'] ?? 'ملف'));
                if ($file) $files[] = $file;
            }
            if ($files) {
                // ما وصل مقطَّعاً يُضاف إلى ما وصل عادياً في الطلب نفسه — لا يدهسه
                $existing = $request->files->get('files');
                $existing = is_array($existing) ? $existing : ($existing ? [$existing] : []);
                $request->files->set('files', array_values(array_merge($existing, $files)));
                $claimed = true;
            }
            $request->request->remove('_chunks');
        }

        if ($claimed) {
            // إسقاطُ خبيئة الملفات المحوَّلة كي يرى المتحكّمُ ما حُقن للتوّ
            (function () { $this->convertedFiles = null; })->call($request);
            $request->attributes->set(self::FLAG, true);
        }

        return $next($request);
    }
}
