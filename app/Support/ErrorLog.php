<?php

namespace App\Support;

use App\Models\ErrorEvent;

/** مسجل الأخطاء المجمّع — لا يرمي أبداً (فشل التسجيل لا يفاقم الخطأ الأصلي) */
class ErrorLog
{
    public static function capture(string $kind, string $message, ?string $file = null, ?int $line = null,
                                   ?string $trace = null): void
    {
        try {
            $message = mb_substr($message, 0, 490);
            $hash = hash('sha256', $kind . '|' . $message . '|' . $file . '|' . $line);

            $req = app()->runningInConsole() ? null : request();

            // زيادة ذرّية أولاً: فحص-ثم-إدراج كان يسابق القيد الفريد على hash
            // فيضيع عدّ التكرارات المتزامنة — التحديث المشروط لا يسابق أحداً
            if (self::bump($hash, $req)) return;

            try {
                ErrorEvent::create([
                    'hash' => $hash, 'kind' => $kind, 'message' => $message,
                    'file' => $file ? mb_substr($file, 0, 290) : null, 'line' => $line,
                    'url' => $req ? mb_substr($req->fullUrl(), 0, 390) : null,
                    'method' => $req?->method(),
                    'user_id' => auth()->id(),
                    'request_id' => $req?->attributes->get('request_id'),
                    'trace' => $trace ? mb_substr($trace, 0, 12000) : null,
                    'first_seen' => now(), 'last_seen' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                self::bump($hash, $req);    // خسرنا سباق الإدراج — الصف موجود الآن فزده
            }
        } catch (\Throwable $e) {
            // صمت تام — التسجيل لا يكسر شيئاً
        }
    }

    /** زيادة العدّاد ذرّياً إن وُجد الصف — يعيد false إن لم يوجد */
    protected static function bump(string $hash, $req): bool
    {
        $hit = ErrorEvent::where('hash', $hash)->update([
            'count' => \Illuminate\Support\Facades\DB::raw('count + 1'),
            'last_seen' => now(),
            'url' => $req ? mb_substr($req->fullUrl(), 0, 390) : \Illuminate\Support\Facades\DB::raw('url'),
            'user_id' => auth()->id() ?? \Illuminate\Support\Facades\DB::raw('user_id'),
        ]);
        if ($hit) {
            // خطأ محلول عاد للظهور → يعود «جديد» ليلفت النظر
            ErrorEvent::where('hash', $hash)->where('status', 'محلول')->update(['status' => 'جديد']);
        }

        return (bool) $hit;
    }

    public static function exception(\Throwable $e): void
    {
        // ما لا يستحق التجميع: أخطاء تحقق ومصادقة وصفحات مفقودة
        foreach ([\Illuminate\Validation\ValidationException::class,
                  \Illuminate\Auth\AuthenticationException::class,
                  \Symfony\Component\HttpKernel\Exception\HttpException::class,
                  \Illuminate\Database\Eloquent\ModelNotFoundException::class,
                  \Illuminate\Session\TokenMismatchException::class] as $skip) {
            if ($e instanceof $skip) return;
        }

        self::capture(
            app()->runningInConsole() ? 'php' : (request()->is('api/*') ? 'api' : 'php'),
            get_class($e) . ': ' . $e->getMessage(),
            $e->getFile(), $e->getLine(),
            $e->getTraceAsString()
        );
    }
}
