<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Quote;

/**
 * بنّاءُ عرضِ المشروع الاحترافيّ (PDF) — **عرضٌ تجاريّ لا فاتورة**.
 *
 * يبني HTML مكتفياً بذاته (أنماطٌ سطرية، بلا متغيّرات CSS ولا ملفاتٍ خارجية —
 * صديقُ mPDF) بغلافٍ وملخّصٍ تنفيذيّ ونطاقٍ ومراحل وتسعيرٍ وجدولِ مدفوعاتٍ
 * وشروطٍ وقبول. **لا يظهر فيه شيءٌ داخليّ**: التكلفةُ والهامشُ محجوبان بنيوياً
 * (لا يُقرآن هنا أصلاً). ولقطةٌ مجمّدة: يُبنى من العرض كما هو لحظةَ التوليد.
 */
class Proposal
{
    public static function html(Quote $q): string
    {
        $co = self::brand($q);
        $client = $q->client_id ? Client::find($q->client_id) : null;
        $cur = e((string) $q->currency);
        $lines = $q->lines()->get();
        $milestones = $q->milestones()->get();

        $e = fn ($v) => e((string) $v);
        $money = fn ($v) => number_format((float) $v, 3) . ' ' . $cur;

        $h = '<div style="font-family:sans-serif;color:#1a1a1a;line-height:1.7">';

        // ── الغلاف ──
        $h .= '<div style="text-align:center;padding:40px 20px;border-bottom:3px solid ' . $e($co['color']) . '">';
        if ($co['logo']) $h .= '<img src="' . $e($co['logo']) . '" style="max-height:70px;margin-bottom:16px"><br>';
        $h .= '<div style="font-size:22px;font-weight:bold;color:' . $e($co['color']) . '">' . $e($co['company']) . '</div>';
        $h .= '<div style="font-size:28px;font-weight:bold;margin:24px 0 8px">عرضُ مشروعٍ / Project Proposal</div>';
        $h .= '<div style="font-size:18px;color:#444">' . $e($q->title ?: $q->doc_no) . '</div>';
        $h .= '<table style="margin:24px auto;font-size:13px;color:#333"><tr>'
            . '<td style="padding:4px 14px">رقم العرض: <b>' . $e($q->doc_no) . '</b></td>'
            . '<td style="padding:4px 14px">التاريخ: <b>' . $e(optional($q->date)->format('Y-m-d') ?: now()->format('Y-m-d')) . '</b></td>'
            . '<td style="padding:4px 14px">صالح حتى: <b>' . $e(optional($q->valid)->format('Y-m-d') ?: '—') . '</b></td></tr></table>';
        if ($client) $h .= '<div style="font-size:15px">مُعدٌّ لـ: <b>' . $e($client->name) . '</b></div>';
        $h .= '</div>';

        // ── الملخّص التنفيذي والهدف والنطاق ──
        $h .= self::section('الملخّص التنفيذي', $q->exec_summary, $co['color']);
        $h .= self::section('هدف المشروع', $q->objective, $co['color']);
        $h .= self::section('نطاق العمل', $q->scope, $co['color']);

        // ── المراحل والتسليمات (البنود مجمَّعةً بالمرحلة) ──
        if ($lines->isNotEmpty()) {
            $byPhase = $lines->groupBy(fn ($l) => $l->phase ?: '—');
            $h .= '<h2 style="color:' . $e($co['color']) . ';border-bottom:1px solid #ddd;padding-bottom:6px;margin-top:28px">المراحل والتسليمات</h2>';
            foreach ($byPhase as $phase => $group) {
                if ($phase !== '—') $h .= '<div style="font-weight:bold;font-size:15px;margin:14px 0 6px;color:#333">▸ ' . $e($phase) . '</div>';
                $h .= '<ul style="margin:0 0 8px;padding-inline-start:20px">';
                foreach ($group as $l) {
                    $h .= '<li style="margin-bottom:4px"><b>' . $e($l->title) . '</b>'
                        . ($l->kind ? ' <span style="color:#888;font-size:12px">(' . $e($l->kind) . ')</span>' : '')
                        . ($l->description ? '<br><span style="color:#555;font-size:13px">' . $e($l->description) . '</span>' : '')
                        . '</li>';
                }
                $h .= '</ul>';
            }
        }

        // ── العرض التجاريّ (جدول التسعير) — بلا تكلفةٍ ولا هامش ──
        if ($lines->isNotEmpty()) {
            $h .= '<h2 style="color:' . $e($co['color']) . ';border-bottom:1px solid #ddd;padding-bottom:6px;margin-top:28px">العرض التجاريّ</h2>';
            $h .= '<table style="width:100%;border-collapse:collapse;font-size:13px">';
            $h .= '<tr style="background:' . $e($co['color']) . ';color:#fff">'
                . '<th style="padding:8px;text-align:right">البند</th><th style="padding:8px">الكمية</th>'
                . '<th style="padding:8px">سعر الوحدة</th><th style="padding:8px">الإجمالي</th></tr>';
            foreach ($lines as $i => $l) {
                $bg = $i % 2 ? '#f7f7f7' : '#fff';
                $h .= '<tr style="background:' . $bg . '">'
                    . '<td style="padding:8px;border-bottom:1px solid #eee">' . $e($l->title) . '</td>'
                    . '<td style="padding:8px;text-align:center;border-bottom:1px solid #eee">' . rtrim(rtrim(number_format((float) $l->qty, 3), '0'), '.') . '</td>'
                    . '<td style="padding:8px;text-align:center;border-bottom:1px solid #eee">' . number_format((float) $l->unit_price, 3) . '</td>'
                    . '<td style="padding:8px;text-align:center;border-bottom:1px solid #eee"><b>' . number_format((float) $l->line_total, 3) . '</b></td></tr>';
            }
            $h .= '</table>';
            $h .= '<table style="width:40%;margin-top:10px;margin-inline-start:auto;font-size:14px">'
                . '<tr><td style="padding:4px">الصافي قبل الضريبة</td><td style="padding:4px;text-align:left">' . $money($q->amount) . '</td></tr>'
                . ((float) $q->discount > 0 ? '<tr><td style="padding:4px">الخصم</td><td style="padding:4px;text-align:left">' . $money($q->discount) . '</td></tr>' : '')
                . '<tr><td style="padding:4px">الضريبة</td><td style="padding:4px;text-align:left">' . $money($q->tax) . '</td></tr>'
                . '<tr style="font-weight:bold;font-size:16px;border-top:2px solid ' . $e($co['color']) . '"><td style="padding:6px 4px">الإجمالي</td><td style="padding:6px 4px;text-align:left">' . $money($q->total) . '</td></tr>'
                . '</table>';
        }

        // ── جدول المدفوعات ──
        if ($milestones->isNotEmpty()) {
            $h .= '<h2 style="color:' . $e($co['color']) . ';border-bottom:1px solid #ddd;padding-bottom:6px;margin-top:28px">جدول المدفوعات</h2>';
            $h .= '<table style="width:100%;border-collapse:collapse;font-size:13px">';
            $h .= '<tr style="background:#f0f0f0"><th style="padding:8px;text-align:right">الدفعة</th><th style="padding:8px">النسبة</th><th style="padding:8px">المبلغ</th><th style="padding:8px;text-align:right">المحفّز</th></tr>';
            foreach ($milestones as $m) {
                $amt = (float) $m->amount ?: ((float) $m->pct ? (float) $q->total * (float) $m->pct / 100 : 0);
                $h .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">' . $e($m->title) . '</td>'
                    . '<td style="padding:8px;text-align:center;border-bottom:1px solid #eee">' . ((float) $m->pct ? (float) $m->pct . '%' : '—') . '</td>'
                    . '<td style="padding:8px;text-align:center;border-bottom:1px solid #eee">' . ($amt ? number_format($amt, 3) : '—') . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #eee">' . $e($m->trigger ?: '—') . '</td></tr>';
            }
            $h .= '</table>';
        }

        // ── الافتراضات وخارج النطاق والشروط ──
        $h .= self::section('الافتراضات', $q->assumptions, $co['color']);
        $h .= self::section('خارج النطاق', $q->exclusions, $co['color']);
        $h .= self::section('الشروط والأحكام', $q->terms, $co['color']);

        // ── القبول ──
        $h .= '<div style="margin-top:36px;padding:18px;border:2px dashed ' . $e($co['color']) . ';border-radius:8px">';
        $h .= '<div style="font-weight:bold;color:' . $e($co['color']) . ';margin-bottom:10px">القبول والاعتماد</div>';
        if ($q->status === 'مقبول' || $q->status === 'محوّل') {
            $h .= '<div style="color:#0E7C66;font-weight:bold">✓ قُبل هذا العرض'
                . ($q->accepted_by ? ' — ' . $e($q->accepted_by) : '')
                . ($q->accepted_at ? ' في ' . $e($q->accepted_at->format('Y-m-d H:i')) : '') . '</div>';
        } else {
            $h .= '<table style="width:100%;margin-top:10px;font-size:13px"><tr>'
                . '<td style="padding:20px 8px 4px">الاسم: ____________________</td>'
                . '<td style="padding:20px 8px 4px">التوقيع: ____________________</td>'
                . '<td style="padding:20px 8px 4px">التاريخ: ____________________</td></tr></table>';
        }
        $h .= '</div>';

        // ── تذييل الشركة ──
        $h .= '<div style="margin-top:30px;padding-top:12px;border-top:1px solid #ddd;font-size:11px;color:#888;text-align:center">'
            . $e($co['company']) . ' — ' . $e($co['name']) . '</div>';

        $h .= '</div>';

        return $h;
    }

    protected static function section(string $title, $body, string $color): string
    {
        $body = trim((string) $body);
        if ($body === '') return '';

        return '<h2 style="color:' . e($color) . ';border-bottom:1px solid #ddd;padding-bottom:6px;margin-top:28px">' . e($title) . '</h2>'
            . '<div style="font-size:14px;color:#333;white-space:pre-line">' . e($body) . '</div>';
    }

    /** الهويّةُ البصرية: من الشركة المُصدِرة إن وُجدت، وإلا الإعدادات العامة */
    protected static function brand(Quote $q): array
    {
        $logo = setting('app.logo');

        return [
            'company' => (string) setting('app.company', setting('app.name', 'Lynomia')),
            'name' => (string) setting('app.name', 'Lynomia'),
            'color' => (string) setting('app.color', '#0E7C66'),
            'logo' => $logo ? public_path('storage/' . $logo) : null,
        ];
    }
}
