<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\ChunkedUpload;
use Illuminate\Http\Request;

/**
 * بابُ القطع: يستقبل الملفَ الكبير قطعةً قطعة فيصل ما لا يمرّ في طلبٍ واحد.
 *
 * كلُّ طلبٍ هنا صغيرٌ عمداً (أصغرُ من سقف الخادم لطلبٍ واحد)، والتجميعُ على
 * القرص في مجلد صاحب الرفعة. لا شيءَ يُسجَّل في القاعدة قبل أن يكتمل الملف
 * ويمرّ بمسار الرفع المعتاد بقواعده — فرفعةٌ مهجورةٌ لا تترك سجلاً، والقطعُ
 * تُكنَس بعد ساعة.
 */
class UploadChunkController extends Controller
{
    public function chunk(Request $r)
    {
        $cap = hub_upload_cap();

        $d = $r->validate([
            'uid'   => 'required|string|max:64',
            // الفهرسُ صفريّ البداية: أقصاه العددُ ناقصَ واحد — «max:MAX_PARTS»
            // كانت تسمح بقطعةٍ زائدةٍ عن المقصود (٤٠٩٧ بدل ٤٠٩٦)
            'i'     => 'required|integer|min:0|max:' . (ChunkedUpload::MAX_PARTS - 1),
            'chunk' => 'required|file',
        ], [], ['uid' => 'رمز الرفعة', 'i' => 'رقم القطعة', 'chunk' => 'القطعة']);

        abort_unless(ChunkedUpload::validToken($d['uid']), 422, 'رمزُ رفعةٍ غير صالح');

        // الحدُّ على المجموع هو حدُّ **النظام** لا حدُّ الطلب الواحد: التقطيعُ
        // وُجد ليتجاوز الثاني، فلو حُوسب به لعاد المنعُ من حيث فُرّ منه.
        $res = ChunkedUpload::append($d['uid'], (int) $d['i'], $r->file('chunk'), (int) $cap['appKb']);

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    /** إنهاءُ الرفعة: يقول للصفحة أن الملف مكتملٌ وحجمَه — والرمزُ يُستهلك في النموذج */
    public function finish(Request $r)
    {
        $d = $r->validate([
            'uid'  => 'required|string|max:64',
            'n'    => 'required|integer|min:1|max:' . ChunkedUpload::MAX_PARTS,
        ], [], ['uid' => 'رمز الرفعة', 'n' => 'عدد القطع']);

        abort_unless(ChunkedUpload::validToken($d['uid']), 422, 'رمزُ رفعةٍ غير صالح');

        $seen = ChunkedUpload::seen($d['uid']);
        if ($seen !== (int) $d['n']) {
            return response()->json([
                'ok' => false,
                'msg' => 'وصل ' . $seen . ' من ' . (int) $d['n'] . ' قطعة — الرفعة ناقصة',
            ], 422);
        }

        // كنسُ المهجور عند كل إنهاء: أرخصُ من مهمّةٍ مجدولة، ولا يترك القرصَ ينمو
        ChunkedUpload::prune();

        return response()->json(['ok' => true, 'token' => $d['uid'], 'parts' => $seen]);
    }
}
