<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

/**
 * بوابة الملفات المصادَق عليها: مرفقات السجلات لا تُخدم إلا لمستخدم داخل —
 * كانت على القرص العام (أي حامل رابط يقرؤها بلا دخول). يخدم الجديد من القرص
 * الخاص والقديم من العام ريثما يُرحَّل بأمر hub:privatize-files.
 */
class FileController extends Controller
{
    public function show(string $path)
    {
        // داخل hub/ فقط وبلا صعود مسارات
        abort_unless(str_starts_with($path, 'hub/') && ! str_contains($path, '..'), 404);

        foreach ([storage_path('app/' . $path), storage_path('app/public/' . $path)] as $abs) {
            if (is_file($abs)) {
                return response()->file($abs, ['X-Robots-Tag' => 'noindex']);
            }
        }

        abort(404);
    }
}
