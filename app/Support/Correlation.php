<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * (WP-1.4) **الترابط** — كلُّ ما كتبه طلبٌ واحد عبر الطبقات، بمعرّفه الواحد.
 *
 * `X-Request-Id` يُصدَر مع كل ردّ ويُحفظ في التدقيق والأخطاء والصادر والويبهوك
 * والإشعارات والمنع والحوادث — وكانت كلُّ طبقةٍ تُقرأ وحدَها. هنا قارئٌ واحد
 * يجمعها صفوفاً موحَّدة `['at','kind','severity','title','why','url','meta']`،
 * **كلُّ مصدرٍ خلف حارسِه ونطاقِه**: قيودُ التدقيق للمالك أو حامل علم `audit`
 * (منطَّقةً بالشركة والوحدات المرئية لغير المالك — نمطُ WidgetRegistry)، وبقيةُ
 * المصادر (أخطاء/صادر/ويبهوك/إشعارات/منع/حوادث) للمالك وحده لأن شاشاتِها
 * الأصلية للمالك وحده. لا معرّفَ ثانياً: `Api::requestId()` يبقى المصدرَ الوحيد.
 */
final class Correlation
{
    /** شكلُ المعرّف المقبول — نفسُ قاعدة وسيط Observability (مع سماحِ الأقصر المولَّد قديماً) */
    public const RID_RE = '/^[A-Za-z0-9][A-Za-z0-9._:-]{3,63}$/';

    /** سقفُ الصفوف لكل مصدر — صفحةُ تشخيصٍ لا أرشيف */
    public const LIMIT = 100;

    /** تسمياتُ المصادر للعرض */
    public const KINDS = [
        'audit'    => 'تدقيق',
        'error'    => 'خطأ',
        'outbox'   => 'صادر',
        'webhook'  => 'ويبهوك',
        'notify'   => 'إشعار',
        'denial'   => 'منع',
        'incident' => 'حادثة',
    ];

    /**
     * هل يبدو المعرّفُ **خارجيّاً** (أرسله عميل)؟ المولَّدُ داخلياً UUID دائماً؛
     * ما خالف شكلَه أرسله العميلُ حتماً — وعكسُه غيرُ مضمون، فالوسمُ للعرض لا للتخويل.
     */
    public static function looksExternal(string $rid): bool
    {
        return ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($rid));
    }

    /**
     * أثرُ معرّفٍ واحد عبر المصادر السبعة — صفوفٌ موحَّدة مرتّبةٌ زمنياً.
     *
     * @param \App\Models\User|null $viewer القارئ — يقرّر أيُّ المصادر تُفتح وبأيّ نطاق
     * @return array<int, array{at:string,kind:string,severity:string,title:string,why:?string,url:?string,meta:array}>
     */
    public static function forRequestId(string $rid, $viewer): array
    {
        $rid = trim($rid);
        if ($rid === '' || ! preg_match(self::RID_RE, $rid)) return [];

        $owner = hub_is_owner($viewer);
        if (! $owner && ! hub_flag($viewer, 'audit')) return [];
        $rows = [];

        // ── التدقيق — للمالك كاملاً، ولحامل العلم منطَّقاً (نمط WidgetRegistry) ──
        if (Schema::hasTable('audits') && hub_has_col('audits', 'request_id')) {
            $q = DB::table('audits')
                ->leftJoin('users', 'users.id', '=', 'audits.user_id')
                ->where('audits.request_id', $rid)
                ->orderBy('audits.created_at')->orderBy('audits.id')->limit(self::LIMIT)
                ->select('audits.action', 'audits.module', 'audits.record_id', 'audits.name',
                         'audits.ip', 'audits.created_at', 'users.name as user_name');
            if (! $owner) {
                // وحدةٌ لا يراها القارئ لا يظهر نشاطُها: اسمُ السجل وحده تسريب
                $visible = array_values(array_filter(array_keys(hub_modules()),
                    fn ($m) => hub_can($viewer, $m, 'v')));
                $q->where(fn ($w) => $w->whereNull('audits.module')->orWhereIn('audits.module', $visible));
                if (($cids = hub_company_ids($viewer)) !== null) {
                    if (Schema::hasColumn('audits', 'company_id')) $q->whereIn('audits.company_id', $cids);
                    else $q->where('audits.user_id', $viewer->id);   // درعُ ما قبل الترحيل — تحفّظٌ لا انهيار
                }
            }
            foreach ($q->get() as $a) {
                $mod = $a->module ? (hub_mod($a->module)['label'] ?? $a->module) : null;
                $rows[] = ['at' => (string) $a->created_at, 'kind' => 'audit', 'severity' => 'info',
                    'title' => trim($a->action . ($a->name !== null && $a->name !== '' ? ' — ' . $a->name : '')),
                    'why' => trim(($a->user_name ?: 'النظام') . ($mod ? ' · ' . $mod : '')),
                    'url' => route('audit.index'), 'meta' => ['ip' => $a->ip]];
            }
        }

        if (! $owner) {
            // بقيةُ المصادر شاشاتُها للمالك وحده — تُخفى لا تُنطَّق
            usort($rows, fn ($x, $y) => strcmp($x['at'], $y['at']));

            return $rows;
        }

        // ── مركزُ الأخطاء ──
        if (Schema::hasTable('error_events')) {
            $q = DB::table('error_events')->where('request_id', $rid)
                ->orderBy('last_seen')->orderBy('id')->limit(self::LIMIT)->get();
            foreach ($q as $e) {
                $rows[] = ['at' => (string) $e->last_seen, 'kind' => 'error',
                    'severity' => (string) ($e->severity ?? 'ERROR'),
                    'title' => (string) $e->message,
                    'why' => trim(($e->kind ?? '') . ($e->file ? ' · ' . $e->file . ($e->line ? ':' . $e->line : '') : '')),
                    'url' => route('errors.show', $e->id), 'meta' => ['count' => (int) ($e->count ?? 1)]];
            }
        }

        // ── الصندوق الصادر — النصُّ عبر المُطهِّر الواحد قبل العرض ──
        if (Schema::hasTable('outbox') && hub_has_col('outbox', 'request_id')) {
            $q = DB::table('outbox')->where('request_id', $rid)
                ->orderBy('created_at')->orderBy('id')->limit(self::LIMIT)->get();
            foreach ($q as $o) {
                $rows[] = ['at' => (string) $o->created_at, 'kind' => 'outbox',
                    'severity' => ($o->state ?? '') === 'failed' ? 'high' : 'info',
                    'title' => 'رسالة صادرة (' . $o->channel . ') — ' . $o->state,
                    'why' => mb_substr(Redactor::text((string) $o->text), 0, 160),
                    'url' => route('integrations.messaging'), 'meta' => []];
            }
        }

        // ── تسليماتُ الويبهوك ──
        if (Schema::hasTable('webhook_deliveries') && hub_has_col('webhook_deliveries', 'request_id')) {
            $q = DB::table('webhook_deliveries')->where('request_id', $rid)
                ->orderBy('created_at')->orderBy('id')->limit(self::LIMIT)->get();
            foreach ($q as $w) {
                $rows[] = ['at' => (string) $w->created_at, 'kind' => 'webhook',
                    'severity' => ($w->state ?? '') === 'failed' ? 'high' : 'info',
                    'title' => 'ويبهوك ' . $w->event . ' — ' . $w->state . ($w->code ? ' (' . $w->code . ')' : ''),
                    'why' => $w->error ? mb_substr(Redactor::text((string) $w->error), 0, 160) : null,
                    'url' => route('webhooks.log', $w->webhook_id), 'meta' => []];
            }
        }

        // ── الإشعارات ──
        if (Schema::hasTable('notifications_hub') && hub_has_col('notifications_hub', 'request_id')) {
            $q = DB::table('notifications_hub')
                ->leftJoin('users', 'users.id', '=', 'notifications_hub.user_id')
                ->where('notifications_hub.request_id', $rid)
                ->orderBy('notifications_hub.created_at')->orderBy('notifications_hub.id')->limit(self::LIMIT)
                ->get(['notifications_hub.text', 'notifications_hub.kind', 'notifications_hub.created_at', 'users.name as user_name']);
            foreach ($q as $n) {
                $rows[] = ['at' => (string) $n->created_at, 'kind' => 'notify', 'severity' => 'info',
                    'title' => (string) $n->text,
                    'why' => trim('إلى ' . ($n->user_name ?: '؟') . ' · ' . $n->kind),
                    'url' => null, 'meta' => []];
            }
        }

        // ── محاولاتُ الوصول المرفوضة ──
        if (Schema::hasTable('access_denials') && hub_has_col('access_denials', 'request_id')) {
            $q = DB::table('access_denials')->where('request_id', $rid)
                ->orderBy('created_at')->orderBy('id')->limit(self::LIMIT)->get();
            foreach ($q as $d) {
                $rows[] = ['at' => (string) $d->created_at, 'kind' => 'denial', 'severity' => 'medium',
                    'title' => 'محاولة مرفوضة: ' . $d->kind,
                    'why' => trim(($d->method ?? '') . ' ' . ($d->path ?? '') . ($d->detail ? ' — ' . $d->detail : '')),
                    'url' => route('security.index'), 'meta' => ['ip' => $d->ip]];
            }
        }

        // ── الحوادث ──
        if (Schema::hasTable('incidents') && hub_has_col('incidents', 'request_id')) {
            $q = DB::table('incidents')->where('request_id', $rid)->whereNull('deleted_at')
                ->orderBy('created_at')->orderBy('id')->limit(self::LIMIT)->get();
            foreach ($q as $i) {
                $rows[] = ['at' => (string) $i->created_at, 'kind' => 'incident',
                    'severity' => (string) ($i->severity ?? 'عالي'),
                    'title' => (string) $i->title, 'why' => $i->status,
                    'url' => route('m.show', ['incidents', $i->id]), 'meta' => []];
            }
        }

        usort($rows, fn ($x, $y) => strcmp($x['at'], $y['at']));

        return $rows;
    }
}
