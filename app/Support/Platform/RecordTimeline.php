<?php

namespace App\Support\Platform;

/**
 * محرّكاتٌ نُقلت من `helpers.php` بلا تغيير (docs/REORG_PLAN.md §R5) — والدوالُّ العامّةُ بأسمائها
 * باقيةٌ هناك أغلفةً من سطرٍ واحد، فلا Blade ولا متحكّمَ يتغيّر.
 */
final class RecordTimeline
{
    /**
     * الخط الزمني الموحَّد لسجل — بلا جدول جديد.
     *
     * التاريخ كان موجوداً كاملاً موزّعاً على أربعة جداول تتشارك `(module, record_id)`
     * ولا أحد يدمجها، فكان على المستخدم أن يفتح أربع بطاقات ليعرف ما جرى. هذا قارئٌ
     * يوحّدها ويُطبّعها لشكل رحلة العميل — فتحصل الوحدات الـ٧١ على خط زمني دفعةً واحدة.
     *
     * لا يُخرج `before/after` من التدقيق: الخط الزمني يقول **من فعل ماذا ومتى**،
     * أما القيم القديمة فلها سجل الإصدارات بصلاحيته.
     */
    public static function build(string $module, string $recordId, int $limit = 60): array
    {
        $db = \Illuminate\Support\Facades\DB::class;
        $ev = [];
        $add = function ($at, string $ico, string $label, string $title,
                         ?string $url = null, ?string $who = null) use (&$ev) {
            if (! $at) return;
            $ev[] = ['at' => (string) $at, 'ico' => $ico, 'label' => $label,
                     'title' => $title, 'url' => $url, 'status' => null, 'who' => $who];
        };

        $users = [];
        $name  = function ($id) use (&$users) {
            if ($id === null) return null;
            if (! array_key_exists($id, $users)) {
                $users[$id] = \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', $id)->value('name');
            }

            return $users[$id];
        };

        $icons = ['إضافة' => '🌱', 'تعديل' => '✏️', 'حذف' => '🗑️', 'استعادة' => '♻️',
                  'تصدير' => '📤', 'استيراد' => '📥', 'عرض حساس' => '👁️'];

        // ١) التدقيق — من فعل ماذا
        foreach (\Illuminate\Support\Facades\DB::table('audits')
                    ->where('module', $module)->where('record_id', $recordId)
                    ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
                    ->get(['action', 'after', 'reason', 'user_id', 'created_at']) as $a) {
            // (WP-5.2) الاستعادةُ تُشتقّ من الفرق: restore() يكتب «تعديل»
            // بـdeleted_at:null ولا أحدَ يكتب فعل «استعادة» — كان الرمز ♻️
            // معرَّفاً لفعلٍ لا يقع، فيقرأ المستخدم «تعديل» عن سجلٍّ عاد من الحذف
            $act = (string) $a->action;
            if ($act === 'تعديل') {
                $af = json_decode((string) $a->after, true) ?: [];
                if (array_key_exists('deleted_at', $af) && $af['deleted_at'] === null) $act = 'استعادة';
            }
            $add($a->created_at, $icons[$act] ?? '📌', $act,
                (string) ($a->reason ?: ''), null, $name($a->user_id));
        }

        // ٢) التعليقات
        foreach (\Illuminate\Support\Facades\DB::table('comments')->whereNull('deleted_at')
                    ->where('module', $module)->where('record_id', $recordId)
                    ->orderByDesc('created_at')->limit($limit)
                    ->get(['id', 'body', 'user_id', 'created_at']) as $c) {
            $add($c->created_at, '💬', 'تعليق',
                \Illuminate\Support\Str::limit((string) $c->body, 90),
                '#c-' . $c->id, $name($c->user_id));
        }

        // ٣) المرفقات — والرؤيةُ تتبع قاعدةَ الوثيقة: مرفقٌ ممنوعٌ صريحاً عن القارئِ
        // لا يُذكَر اسمُه في خطِّه الزمنيّ (وجودٌ يُكشَف). تحميلٌ دفعيٌّ للقواعدِ مرّة.
        $tlAtts = \App\Models\Attachment::whereNull('deleted_at')
            ->where('module', $module)->where('record_id', $recordId)
            ->orderByDesc('created_at')->limit($limit)
            ->get(['id', 'original_name', 'uploaded_by', 'created_at']);
        \App\Support\Documents\DocumentPolicy::primeMemo($tlAtts->pluck('id'));
        $tlViewer = auth()->user();
        foreach ($tlAtts as $t) {
            if ($tlViewer && ! \App\Support\Documents\DocumentPolicy::listable($tlViewer, $t)) continue;
            $add($t->created_at, '📎', 'مرفق',
                \Illuminate\Support\Str::limit((string) $t->original_name, 60),
                null, $name($t->uploaded_by));
        }

        // ٤) الإصدارات المحفوظة
        foreach (\Illuminate\Support\Facades\DB::table('record_versions')
                    ->where('module', $module)->where('record_id', $recordId)
                    ->orderByDesc('created_at')->limit($limit)
                    ->get(['version', 'changed_by', 'created_at']) as $v) {
            $add($v->created_at, '🕐', 'نسخة', 'الإصدار ' . $v->version, null, $name($v->changed_by));
        }

        // ٥) سجل أدلة التوقيع (v2.118) — دورة التوقيع كاملة على صفحة العقد نفسها
        if ($module === 'contracts') {
            try {
                $signIcons = ['created' => '📨', 'sent' => '📤', 'opened' => '👀',
                    'otp_sent' => '🔑', 'otp_ok' => '🔓', 'signed' => '✍️', 'declined' => '🚫',
                    'voided' => '❌', 'reminded' => '⏰', 'downloaded' => '⬇️'];
                $signLabels = ['created' => 'أُنشئ طلب توقيع', 'sent' => 'أُرسل للتوقيع',
                    'opened' => 'فُتحت الوثيقة', 'otp_sent' => 'أُرسل رمز تحقق', 'otp_ok' => 'تحقق ناجح',
                    'signed' => 'وُقّعت الوثيقة', 'declined' => 'رُفض التوقيع', 'voided' => 'أُبطل الرابط',
                    'reminded' => 'تذكير بالتوقيع', 'downloaded' => 'نُزّلت الوثيقة'];
                foreach (\Illuminate\Support\Facades\DB::table('contract_events')
                            ->where('contract_id', $recordId)
                            ->orderByDesc('created_at')->limit($limit)
                            ->get(['event', 'ip', 'actor_id', 'meta', 'created_at']) as $s) {
                    $meta = json_decode((string) $s->meta, true) ?: [];
                    $add($s->created_at, $signIcons[$s->event] ?? '🖊️',
                        $signLabels[$s->event] ?? (string) $s->event,
                        trim(($meta['name'] ?? $meta['reason'] ?? '') . ($s->ip ? ' — ' . $s->ip : '')),
                        null, $name($s->actor_id));
                }
            } catch (\Throwable $e) {
                // كودٌ وصل قبل هجرته — الخط الزمني لا ينفجر
            }
        }

        // ── Control Plane: Phase 6 (WP-6.2) ──
        // **فرعُ الحوادث** (§8.2 · §8.4): غرفةُ القيادة تحتاج خطّاً واحداً لا خمسةَ
        // أماكن. المصادرُ الخمسة، كلٌّ محروسٌ بوجود جدوله ومحدودٌ بـ$limit:
        //   ١) `incident_links` — أدلّةٌ ربطها إنسانٌ من صفحات المصادر.
        //   ٢) `meta.events` — أدلّةٌ آليّة يكتبها `hub_open_incident`/`AlertEngine`
        //      منذ اليوم الأول **ولا يعرضها أحد**؛ فتُستخرج هنا لا في جدولٍ ثانٍ.
        //   ٣) `deployments.incident_id` — عمودُ مرجعٍ **قائم**، فلا صفَّ وصلٍ له
        //      (وحدةُ النشر مفتاحُها `deploys` وجدولُها `deployments`).
        //   ٤+٥) فرقا **الحالة والقائد** من التدقيق.
        // والتاريخُ يُطبَّع لصيغةٍ واحدة (`Y-m-d H:i:s`) قبل الإضافة: قيدُ meta
        // يُكتب ISO8601 و«T» تسبق الفراغَ في المقارنة النصّية فينهار فرزُ السطر
        // الأخير على المصادر كلِّها.
        // **الحجب**: اسمُ سجلٍّ من وحدةٍ لا يملك القارئُ عرضَها لا يظهر أبداً —
        // نفسُ فلتر `hub_related` (`hub_can($module,'v')`)، فالملخّصُ نصٌّ حرٌّ قد
        // يحمل اسمَ موظفٍ أو عميل.
        if ($module === 'incidents') {
            $at = fn ($t) => $t
                ? \Illuminate\Support\Carbon::parse($t)
                    ->setTimezone(config('app.timezone', 'Asia/Kuwait'))->format('Y-m-d H:i:s')
                : null;

            // ١) الأدلّةُ المرتبطة
            if (\Illuminate\Support\Facades\Schema::hasTable('incident_links')) {
                $kindLbl = ['error' => 'خطأ', 'audit' => 'قيد تدقيق', 'security' => 'حدث أمنيّ',
                            'request' => 'طلب', 'alert' => 'تنبيه', 'task' => 'مهمة',
                            'deploy' => 'نشر', 'note' => 'ملاحظة'];
                $seen = [];
                foreach (\Illuminate\Support\Facades\DB::table('incident_links')
                            ->where('incident_id', $recordId)
                            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
                            ->get(['kind', 'module', 'record_id', 'summary', 'by', 'created_at']) as $l) {
                    $lm = (string) ($l->module ?? '');
                    if ($lm !== '' && ! array_key_exists($lm, $seen)) {
                        $seen[$lm] = hub_can(auth()->user(), $lm, 'v');
                    }
                    $blind = $lm !== '' && ! $seen[$lm];
                    $add($at($l->created_at), '🔗', 'دليل: ' . ($kindLbl[$l->kind] ?? $l->kind),
                        $blind
                            ? 'سجلٌّ خارج صلاحيتك — أُخفي ملخّصُه'
                            : \Illuminate\Support\Str::limit(\App\Support\Platform\Redactor::text((string) $l->summary), 160),
                        (! $blind && $lm !== '' && $l->record_id) ? route('m.show', [$lm, $l->record_id]) : null,
                        $name($l->by));
                }
            }

            // ٢) الأدلّةُ الآليّة المخزّنة في meta.events
            $im = \Illuminate\Support\Facades\DB::table('incidents')->where('id', $recordId)->value('meta');
            $im = is_string($im) ? (json_decode($im, true) ?: []) : (array) $im;
            foreach (array_slice((array) ($im['events'] ?? []), -$limit) as $me) {
                if (! is_array($me)) continue;
                $txt = trim((string) ($me['note'] ?? ''));
                if ($txt === '' && ! empty($me['evidence']) && is_array($me['evidence'])) {
                    $txt = implode(' · ', array_map(
                        fn ($k, $v) => $k . ': ' . (is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)),
                        array_keys($me['evidence']), array_values($me['evidence'])));
                }
                $add($at($me['at'] ?? null), '🤖', 'قيد آليّ',
                    \Illuminate\Support\Str::limit(\App\Support\Platform\Redactor::text($txt), 160));
            }

            // ٣) النشرُ المرتبط بعمود المرجع القائم
            if (\Illuminate\Support\Facades\Schema::hasTable('deployments')) {
                $canDep = hub_can(auth()->user(), 'deploys', 'v');
                foreach (\Illuminate\Support\Facades\DB::table('deployments')->whereNull('deleted_at')
                            ->where('incident_id', $recordId)
                            ->orderByDesc('deployed_at')->orderByDesc('id')->limit($limit)
                            ->get(['id', 'ver', 'env', 'status', 'deployed_at', 'created_at', 'by_id']) as $d) {
                    $add($at($d->deployed_at ?: $d->created_at), '🚀', 'نشر مرتبط',
                        $canDep
                            ? trim((string) $d->ver . ($d->env ? ' — ' . $d->env : '') . ($d->status ? ' · ' . $d->status : ''))
                            : 'نشرٌ خارج صلاحيتك — أُخفيت تفاصيلُه',
                        $canDep ? route('m.show', ['deploys', $d->id]) : null, $name($d->by_id));
                }
            }

            // ٤+٥) فرقا الحالة والقائد — حقلان **بقائمةٍ بيضاء** لا فرقٌ عام: هما
            // ما تعرضه بطاقةُ الرأس أصلاً، فلا قيمةَ قديمة تتسرّب خارجَ ما يُرى
            // (قاعدةُ «لا before/after في الخط الزمني» تبقى قائمةً لكل ما عداهما)
            $dLbl = ['status' => ['تغيّر الحالة', '🔁'], 'lead_id' => ['تغيّر القائد', '👤']];
            foreach (\Illuminate\Support\Facades\DB::table('audits')
                        ->where('module', 'incidents')->where('record_id', $recordId)
                        ->where('action', 'تعديل')
                        ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
                        ->get(['before', 'after', 'user_id', 'created_at']) as $a) {
                $bf = json_decode((string) $a->before, true) ?: [];
                $af = json_decode((string) $a->after, true) ?: [];
                foreach ($dLbl as $col => [$lbl, $ico]) {
                    if (! array_key_exists($col, $af)) continue;
                    $from = $bf[$col] ?? null;
                    $to = $af[$col] ?? null;
                    if ($from === $to) continue;
                    $show = fn ($v) => $col === 'lead_id'
                        ? ($v ? ($name($v) ?: 'مستخدم محذوف') : 'بلا قائد')
                        : ($v !== null && $v !== '' ? (string) $v : '—');
                    $add($at($a->created_at), $ico, $lbl,
                        'من «' . $show($from) . '» إلى «' . $show($to) . '»', null, $name($a->user_id));
                }
            }
        }

        // الأحدث أولاً — تاريخُ سجلٍ يُقرأ من آخره، بخلاف رحلة العميل
        usort($ev, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($ev, 0, $limit);
    }
}
