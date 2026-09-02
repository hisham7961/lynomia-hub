<?php

namespace App\Support;

/** ملفُ PDF بصيغةٍ لا يقرؤها محلّلُ FPDI المجانيّ (xref streams / object streams — PDF 1.5+) */
class UnsupportedPdfException extends \RuntimeException
{
}
