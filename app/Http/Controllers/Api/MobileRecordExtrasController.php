<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileEndpoint;
use App\Models\Comment;
use App\Models\DmMessage;
use App\Models\RecordVersion;
use App\Support\Collaboration\CommentService;
use App\Support\Mobile\MessageAttachment;
use App\Support\Platform\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * **ملحقاتُ السجلّ والرسالة على الجوال** (خطّةُ التطبيق · المرحلة ٣ · 3.5/3.6).
 *
 *  • `GET {module}/{id}/versions` — قائمةُ نسخِ السجلّ (رقم/متى/من) لتفعيل إجراء
 *    `restore-version` القائم. الحارسُ حارسُ عرضِ السجلّ نفسُه (`resolveApi(v)` + `hub_scope`)،
 *    ولا تُعاد لقطةٌ ولا قيمة — وأسماءُ الحقولِ المتغيّرة تُرشَّح بـ`hub_field_mode` (المخفيُّ عن
 *    الدور لا يُذكَر اسمُه). `restorable` مرآةُ شرطِ زرّ الويب (تعديلٌ + ليست الحاليّة + ليس في
 *    السلّة + بلا طابور موافقة). حسابُ العميل لا يبلغها (الويبُ يُخفي الإصدارات عنه).
 *  • `GET comments/{id}/attachment` · `GET dm/messages/{id}/attachment` — تنزيلُ مرفقِ
 *    تعليقٍ/رسالةٍ بمقبضِ صاحبها (طلب الجوال #2): الرسالةُ لطرفَيها وحدَهما (غيرُهما ٤٠٤)،
 *    والتعليقُ لمن يرى خيطَه (`CommentService::guardTarget` — حارسُ قائمة التعليقات نفسُه).
 */
class MobileRecordExtrasController extends V1Controller
{
    use MobileEndpoint;

    /** `GET {module}/{id}/versions` — `?limit=` (افتراضاً ٢٠، سقفاً ١٠٠)، الأحدثُ أوّلاً */
    public function versions(Request $r, string $module, string $id): Response
    {
        $this->tagMobile($r);
        [$def, $class] = $this->resolveApi($module, 'v');
        $row = $this->findScoped($class, $module, $id, 'with');   // الشاملُ للسلّة كعرضِ الإجراءات
        $u = $r->user();

        $limit = max(1, min(100, (int) $r->query('limit', '20')));
        // صفٌّ زائدٌ لحساب «ما تغيّر» في أقدمِ نسخةٍ معروضة
        $rows = RecordVersion::where('module', $module)->where('record_id', $row->id)
            ->orderByDesc('version')->orderByDesc('id')->limit($limit + 1)->get();
        $page = $rows->take($limit)->values();
        $names = DB::table('users')->whereIn('id', $page->pluck('changed_by')->filter()->unique()->values())
            ->pluck('name', 'id');

        $current = (int) ($row->version ?? 0);
        $trashed = method_exists($row, 'trashed') && $row->trashed();
        $canRestore = hub_can($u, $module, 'e') && ! $trashed && ! hub_needs_approval($u, $module, 'e');

        // عمودٌ ⇒ مفتاحُ حقلٍ مرئيٍّ للدور (المخفيُّ لا يُذكَر اسمُه)
        $visible = [];
        foreach ($def['fields'] ?? [] as $f) {
            if (empty($f['col']) || empty($f['key'])) continue;
            if (hub_field_mode($u, $module, (string) $f['key']) === 'hide') continue;
            $visible[(string) $f['col']] = (string) $f['key'];
        }

        $out = [];
        foreach ($page as $i => $v) {
            $prev = $rows->get($i + 1);
            $out[] = [
                'version' => (int) $v->version,
                'at' => optional($v->created_at)->toIso8601String(),
                'by' => $v->changed_by ? ['id' => (string) $v->changed_by, 'name' => (string) ($names[$v->changed_by] ?? '')] : null,
                'current' => (int) $v->version === $current,
                'restorable' => $canRestore && (int) $v->version !== $current,
                'changed' => $prev ? self::changedKeys((array) $prev->snapshot, (array) $v->snapshot, $visible) : null,
            ];
        }

        return $this->okData([
            'module' => $module,
            'id' => (string) $row->id,
            'current_version' => $current ?: null,
            'trashed' => $trashed,
            'versions' => $out,
            'restore' => ['action' => 'restore-version', 'path' => '/' . \App\Support\Mobile\MobileOpenApi::PREFIX
                . '/' . $module . '/' . $row->id . '/actions/restore-version', 'needs' => ['version']],
        ]);
    }

    /** مفاتيحُ الحقول المرئيّة التي اختلفت قيمتُها بين لقطتين (أسماءٌ لا قيم) */
    private static function changedKeys(array $before, array $after, array $visible): array
    {
        $out = [];
        foreach ($visible as $col => $key) {
            $a = $before[$col] ?? null;
            $b = $after[$col] ?? null;
            if (json_encode($a) !== json_encode($b)) $out[] = $key;
        }

        return $out;
    }

    /** `GET comments/{id}/attachment` — مرفقُ تعليقٍ أرى خيطَه */
    public function commentAttachment(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $u = $r->user();
        // مرفقاتُ التعليقات الداخليّة لا تبلغ حسابَ العميل (لا بطاقةَ مرفقٍ له أصلاً)
        if (hub_is_client($u)) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود');

        /** @var Comment $c */
        $c = Comment::findOrFail($id);
        if ((string) $c->user_id !== (string) $u->id) {
            CommentService::guardTarget($u, (string) $c->module, $c->record_id !== null ? (string) $c->record_id : null);
            if ((string) $c->module === 'feed') CommentService::guardFeedComment($u, $c);
        }

        return MessageAttachment::download($c->att);
    }

    /** `GET dm/messages/{id}/attachment` — مرفقُ رسالةٍ أنا طرفٌ فيها (غيرُ الطرف ٤٠٤) */
    public function dmAttachment(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $me = (string) $r->user()->id;

        $m = DmMessage::whereKey($id)->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('from_id', $me)->orWhere('to_id', $me))->first();
        if (! $m) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود');

        return MessageAttachment::download($m->att);
    }
}
