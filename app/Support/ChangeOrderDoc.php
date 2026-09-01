<?php

namespace App\Support;

use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Project;

/**
 * مستندُ أمر التغيير الاحترافيّ — **وثيقةٌ للعميل**: المشروعُ الأصل، التغييرُ
 * المطلوب، أثرُ القيمة والجدول، والقبول. **لا تكلفةَ داخلية** تُقرأ هنا أصلاً
 * (`cost_delta` محجوبٌ بنيوياً كما هامشُ العرض).
 */
class ChangeOrderDoc
{
    public static function html(ChangeOrder $co): string
    {
        $co2 = self::brand();
        $client = $co->client_id ? Client::find($co->client_id) : null;
        $project = $co->project_id ? Project::find($co->project_id) : null;
        $cur = e((string) $co->currency);
        $e = fn ($v) => e((string) $v);
        $money = fn ($v) => number_format((float) $v, 3) . ' ' . $cur;

        $h = '<div style="font-family:sans-serif;color:#1a1a1a;line-height:1.7">';

        // الغلاف
        $h .= '<div style="text-align:center;padding:36px 20px;border-bottom:3px solid ' . $e($co2['color']) . '">';
        if ($co2['logo']) $h .= '<img src="' . $e($co2['logo']) . '" style="max-height:64px;margin-bottom:12px"><br>';
        $h .= '<div style="font-size:20px;font-weight:bold;color:' . $e($co2['color']) . '">' . $e($co2['company']) . '</div>';
        $h .= '<div style="font-size:26px;font-weight:bold;margin:20px 0 6px">أمرُ تغيير / Change Order</div>';
        $h .= '<div style="font-size:16px;color:#444">' . $e($co->title) . '</div>';
        $h .= '<table style="margin:20px auto;font-size:13px;color:#333"><tr>'
            . '<td style="padding:4px 14px">رقم الأمر: <b>' . $e($co->doc_no) . '</b></td>'
            . '<td style="padding:4px 14px">التاريخ: <b>' . $e(optional($co->created_at)->format('Y-m-d') ?: now()->format('Y-m-d')) . '</b></td></tr></table>';
        if ($client) $h .= '<div style="font-size:14px">العميل: <b>' . $e($client->name) . '</b></div>';
        if ($project) $h .= '<div style="font-size:14px;color:#555">المشروع: ' . $e($project->name) . '</div>';
        $h .= '</div>';

        $h .= self::section('سبب التغيير', $co->reason, $co2['color']);
        $h .= self::section('النطاق المُضاف', $co->description, $co2['color']);

        // أثرُ القيمة والجدول (بلا تكلفةٍ داخلية)
        $h .= '<h2 style="color:' . $e($co2['color']) . ';border-bottom:1px solid #ddd;padding-bottom:6px;margin-top:26px">الأثر التجاريّ</h2>';
        $h .= '<table style="width:60%;font-size:14px">'
            . '<tr><td style="padding:6px">تغيّر القيمة التعاقدية</td><td style="padding:6px;text-align:left"><b>' . $money($co->value_delta) . '</b></td></tr>'
            . '<tr><td style="padding:6px">أثر الجدول الزمنيّ</td><td style="padding:6px;text-align:left">' . ((int) $co->timeline_days !== 0 ? (int) $co->timeline_days . ' يوماً' : 'لا تغيير') . '</td></tr>'
            . '</table>';

        // القبول
        $h .= '<div style="margin-top:32px;padding:16px;border:2px dashed ' . $e($co2['color']) . ';border-radius:8px">';
        $h .= '<div style="font-weight:bold;color:' . $e($co2['color']) . ';margin-bottom:8px">القبول والاعتماد</div>';
        if (in_array($co->status, ['معتمد', 'مطبَّق'], true)) {
            $h .= '<div style="color:#0E7C66;font-weight:bold">✓ اعتُمد أمرُ التغيير'
                . ($co->approved_by ? ' — ' . $e($co->approved_by) : '')
                . ($co->approved_at ? ' في ' . $e($co->approved_at->format('Y-m-d H:i')) : '') . '</div>';
        } else {
            $h .= '<table style="width:100%;margin-top:8px;font-size:13px"><tr>'
                . '<td style="padding:18px 8px 4px">الاسم: ____________________</td>'
                . '<td style="padding:18px 8px 4px">التوقيع: ____________________</td>'
                . '<td style="padding:18px 8px 4px">التاريخ: ____________________</td></tr></table>';
        }
        $h .= '</div>';

        $h .= '<div style="margin-top:26px;padding-top:10px;border-top:1px solid #ddd;font-size:11px;color:#888;text-align:center">'
            . $e($co2['company']) . '</div>';
        $h .= '</div>';

        return $h;
    }

    protected static function section(string $title, $body, string $color): string
    {
        $body = trim((string) $body);
        if ($body === '') return '';

        return '<h2 style="color:' . e($color) . ';border-bottom:1px solid #ddd;padding-bottom:6px;margin-top:26px">' . e($title) . '</h2>'
            . '<div style="font-size:14px;color:#333;white-space:pre-line">' . e($body) . '</div>';
    }

    protected static function brand(): array
    {
        $logo = setting('app.logo');

        return [
            'company' => (string) setting('app.company', setting('app.name', 'Lynomia')),
            'color' => (string) setting('app.color', '#0E7C66'),
            'logo' => $logo ? public_path('storage/' . $logo) : null,
        ];
    }
}
