<?php

namespace App\Support;

/**
 * **حالاتُ نتيجةٍ/مشكلةٍ قيدَ المعالجة** — خمسُ حالاتٍ بمفاتيحَ إنجليزيةٍ تُخزَّن
 * وتسمياتٍ عربيةٍ تُعرَض. المفتاحُ هو المخزَّن (فرزٌ وتصفيةٌ محمولان على المحرّكين)،
 * والتسميةُ للعين فقط؛ المجهولُ يُعرَض كما هو ولا يُخفى.
 */
final class IssueState
{
    /** المفتاحُ المخزَّن ⇒ التسميةُ العربية */
    public const MAP = [
        'new' => 'جديد', 'investigating' => 'قيد التحقيق', 'in_progress' => 'قيد المعالجة',
        'resolved' => 'محلول', 'ignored' => 'متجاهَل',
    ];

    /** الحالاتُ المفتوحة (لم تُحسَم بعد) — للعدّ والتصفية */
    public const OPEN = ['new', 'investigating', 'in_progress'];

    /** التسميةُ العربية للحالة — المجهولُ يعود كما جاء */
    public static function label(?string $state): string
    {
        return self::MAP[(string) $state] ?? (string) $state;
    }
}
