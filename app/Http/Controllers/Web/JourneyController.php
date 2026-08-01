<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * رحلة العميل: كل ما جرى معه في خيط زمني واحد — عروض، عقود، اجتماعات،
 * قرارات، فواتير (بمطابقة اسم الطرف)، وتعليقات فريقك عليه.
 */
class JourneyController extends Controller
{
    public function show(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'clients', 'v'), 403);
        $client = hub_scope(Client::query(), 'clients')->findOrFail($id);

        $events = [];
        $add = function ($at, string $ico, string $label, string $title, ?string $url, ?string $status = null) use (&$events) {
            if (! $at) return;
            $events[] = ['at' => (string) $at, 'ico' => $ico, 'label' => $label,
                         'title' => $title, 'url' => $url, 'status' => $status];
        };

        $add($client->created_at, '🌱', 'البداية', 'سُجّل العميل في النظام', null);

        // الوحدات التي تشير للعميل بحقل مرجعي صريح — التذاكر كانت الحلقة المفقودة
        // الوحيدة في الرؤية الشاملة (معلّقة على نص حر) حتى v2.144
        foreach ([['quotes', '🧾', 'عرض سعر'], ['contracts', '📜', 'عقد'],
                  ['meetings', '🤝', 'اجتماع'], ['decisions', '⚖️', 'قرار'],
                  ['tickets', '🎫', 'تذكرة']] as [$mk, $ico, $lbl]) {
            $md = hub_mod($mk);
            if (! $md || ! hub_can(auth()->user(), $mk, 'v')) continue;
            $disp = hub_display_col($mk);
            $sc = hub_status_col($mk);
            // النطاق يُفرض على الابن نفسه: رؤية العميل لا تمنح رؤية عروضه وعقوده
            $rows = hub_scope(DB::table($md['table'])->whereNull('deleted_at'), $mk)
                ->where('client_id', $client->id)->limit(100)
                ->get(array_values(array_unique(array_filter(['id', $disp, $sc, 'created_at']))));
            foreach ($rows as $r) {
                $add($r->created_at, $ico, $lbl, (string) ($r->{$disp} ?? $r->id),
                    route('m.show', [$mk, $r->id]), $sc ? $r->{$sc} : null);
            }
        }

        // المستندات المالية: المرجع الصريح أولاً، ثم مطابقة الاسم لما لم يُرحَّل بعد
        // (الأسماء المكرّرة لا تُرحَّل عمداً). الصفحة تقول أيّهما جاء من أين.
        $fin = ['count' => 0, 'total' => 0.0, 'paid' => 0.0, 'linked' => 0, 'byName' => 0];
        if (hub_can(auth()->user(), 'fin', 'v')) {
            $name = trim((string) $client->name);
            $docs = hub_scope(DB::table('fin_documents')->whereNull('deleted_at'), 'fin')
                ->where(fn ($w) => $w
                    ->where('client_id', $client->id)
                    ->when($name !== '', fn ($q) => $q->orWhere(fn ($x) => $x
                        ->whereNull('client_id')->where('partner', $name))))
                ->limit(100)->get();
            foreach ($docs as $d) {
                $fin['count']++;
                $d->client_id ? $fin['linked']++ : $fin['byName']++;
                $fin['total'] += (float) ($d->total ?? 0);
                $fin['paid'] += (float) ($d->paid ?? 0);
                $add($d->date ?: $d->created_at, '💵', $d->kind ?: 'مستند مالي',
                    trim(($d->doc_no ? $d->doc_no . ' — ' : '') . number_format((float) ($d->total ?? 0), 2)),
                    route('m.show', ['fin', $d->id]), $d->state);
            }
        }

        // تعليقات الفريق على سجل العميل
        $comments = DB::table('comments')->whereNull('deleted_at')
            ->where('module', 'clients')->where('record_id', $client->id)->limit(100)
            ->get(['id', 'body', 'user_id', 'created_at']);
        $users = DB::table('users')->whereIn('id', $comments->pluck('user_id'))->pluck('name', 'id');
        foreach ($comments as $c) {
            $add($c->created_at, '💬', 'تعليق — ' . ($users[$c->user_id] ?? '؟'),
                \Illuminate\Support\Str::limit((string) $c->body, 90),
                route('m.show', ['clients', $client->id]) . '#c-' . $c->id);
        }

        // الأقدم أولاً — رحلة تُقرأ من بدايتها
        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return view('journey', ['client' => $client, 'events' => $events, 'fin' => $fin]);
    }
}
