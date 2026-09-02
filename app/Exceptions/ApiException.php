<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * استثناءُ API بكودٍ آليّ ثابت.
 *
 * الرسالةُ العربية للإنسان، و`code` للعميل الآليّ (`VALIDATION_FAILED`،
 * `INSUFFICIENT_SCOPE`…) كي لا يُضطرّ التكاملُ إلى تحليل نصٍّ عربيّ ليعرف
 * ماذا وقع. يرث `HttpException` فيسري عليه كلُّ ما يسري على `abort()`:
 * رادارُ الكشف يلتقط ٤٠٣، ومركزُ الأخطاء يتجاهل ما دون ٥٠٠، وردُّ الويب لا يتغيّر.
 */
class ApiException extends HttpException
{
    public function __construct(
        public readonly string $errorCode,
        int $status,
        string $message,
        public readonly array $details = [],
        array $headers = [],
    ) {
        parent::__construct($status, $message, null, $headers + ['X-Error-Code' => $errorCode]);
    }
}
