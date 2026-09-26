<?php

namespace App\Support\Documents\Esign;

use App\Models\HubNotification;
use App\Models\SignRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * **إتمامُ التوقيع وتسليمُه** — طرائقُ نُقلت من `EsignController` بلا تغيير (docs/REORG_PLAN.md §R6):
 * تطبيقُ التوقيع والإتمام، وإكمالُ السجلّ المرتبط (موافقة · إقرار · عرض)، وأرشفةُ النسخة الموقّعة،
 * والتسليمُ للموقّع التالي وإشعاراتُه. والمتحكّمُ يفوّض إليها بالتوقيعِ والظهورِ نفسَيهما.
 */
final class EsignFinalizer
{
    /**
     * تطبيق التوقيع واكتمال الطلب ثم آثاره — بذرّيّة الاكتمال.
     * applySignature تكتب صف الموقّع وتقلب الطلب «وُقّع» تحت قفلٍ صفّيّ، فتُعيد
     * ما إذا اكتمل الطلب الآن (بيدنا). الآثار الخارجية (إسراء العقد، إكمال الجهة
     * المربوطة، أرشفة النسخة وبريدها) تُنفَّذ بعد المعاملة على من أغلق الطلب وحده،
     * فلا إقرارَ سياسةٍ مكرّر ولا مرفقَ نسخةٍ مزدوج مهما تزامنت الإرساليات.
     *
     * @return array{0:int,1:bool} [عدد المعلّقين، هل أُغلق الطلب بهذه الإرسالية]
     */
    public static function finalize(SignRequest $req, ?\App\Models\ContractSigner $viaSigner, array $d, Request $r): array
    {
        [$pending, $completed] = \App\Support\Documents\Esign\EsignFinalizer::applySignature($req, $viaSigner, $d, $r);

        if ($completed) {
            // سير العملية يكتمل: العقد المربوط «ساري» — بحفظ Eloquent (v2.117) فيُدقَّق
            // ويُصدَر ويُطلق contract.signed فعلاً (كان التحديث المباشر يخرسه)
            if ($req->contract_id) {
                \App\Support\Documents\Esign\EsignFinalizer::flipContract($req->contract_id, ['قيد التوقيع', 'مسودة'], 'ساري');
                \App\Support\Documents\Esign\EsignFinalizer::notifyOwners('📑 العقد المرتبط بـ«' . $req->title . '» صار «ساري» بعد توقيعه');
            }
            \App\Support\Documents\Esign\EsignFinalizer::completeLinked($req, $d['signer_name'], $r);
            // v2.122: حزمة الأدلة — تجميد رأس السلسلة + أرشفة نسخة PDF + بريد النسخ
            \App\Support\Documents\Esign\EsignFinalizer::archiveSignedCopy($req);
        } else {
            // متسلسل: بريد الدور التالي يخرج تلقائياً لحظة اكتمال من قبله
            if ($req->mode === 'متسلسل') \App\Support\Documents\Esign\EsignFinalizer::deliverNext($req);
            \App\Support\Documents\Esign\EsignFinalizer::notifyOwners('✍️ وقّع ' . $d['signer_name'] . ' على «' . $req->title
                . '» — بقي ' . $pending . ' من الموقّعين');
        }

        return [$pending, $completed];
    }

    /**
     * كتابة أثر التوقيع على صف الموقّع وقلبُ الطلب «وُقّع» إن اكتمل — ذرّيّاً.
     * الكتلة كلها تُقرأ-ثم-تُفعل: الحالة، عدد المعلّقين، ثم الإغلاق. بلا تسلسل
     * تمرّ إرساليتان متزامنتان معاً فيكتمل الطلب مرّتين. القفل الصفّيّ وإعادة قراءة
     * الحالة داخل المعاملة يسلسلانها: من يمسك القفل أولاً يُغلق، والثاني يرى «وُقّع» فيُرفض.
     *
     * @return array{0:int,1:bool} [عدد المعلّقين بعد هذا التوقيع، هل أُغلق الطلب الآن]
     */
    public static function applySignature(SignRequest $req, ?\App\Models\ContractSigner $viaSigner, array $d, Request $r): array
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($req, $viaSigner, $d, $r) {
            // إعادة قراءة حالة الطلب من صفٍّ مقفول: إرساليةٌ متزامنة أغلقته بيننا
            // وقراءتنا الأولى (في sign) تُكشف الآن فتُرفض بدل أن تُكرّر الاكتمال.
            $locked = SignRequest::whereKey($req->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status !== 'بانتظار التوقيع', 410,
                'هذه الوثيقة أُغلقت — وُقّعت أو رُفضت مسبقاً');

            // صف الموقّع يُقرأ ويُقفل أيضاً: نفس الموقّع لا يوقّع مرتين متزامنتين
            $signer = $viaSigner
                ? \App\Models\ContractSigner::whereKey($viaSigner->id)->lockForUpdate()->first()
                : \App\Models\ContractSigner::where('request_id', $locked->id)
                    ->orderBy('order')->lockForUpdate()->first();
            abort_if($signer && $signer->status === 'وُقّع', 410, 'وقّعت هذه الوثيقة مسبقاً');

            $signer?->forceFill([
                'status' => 'وُقّع', 'name' => $d['signer_name'], 'signature' => $d['signature'],
                'id_no' => $d['signer_id_no'] ?? null, 'selfie' => $d['selfie'] ?? null,
                'signed_at' => now(), 'ip' => $r->ip(),
                'agent' => substr((string) $r->userAgent(), 0, 250),
                'locale' => substr((string) $r->header('Accept-Language'), 0, 60),
            ])->save();
            \App\Models\ContractEvent::log('signed', $req, ['signer_id' => $signer?->id,
                'meta' => json_encode(['name' => $d['signer_name']], JSON_UNESCAPED_UNICODE)]);

            // اكتمال الطلب: كل من دوره «موقّع» وقّع — طلب الموقّع الواحد يكتمل فوراً كما كان
            $pending = \App\Models\ContractSigner::where('request_id', $locked->id)
                ->where('role', 'موقّع')->where('status', '!=', 'وُقّع')->count();

            $completed = $pending === 0;
            if ($completed) {
                // القلب يقع تحت القفل: من يصل صفر المعلّقين أولاً يُغلق، وأي متزامنٍ
                // بعده يرى «وُقّع» عند إعادة القراءة فيُرفض — فالاكتمال مرّةٌ واحدة.
                $req->forceFill([
                    'status' => 'وُقّع', 'signer_name' => $d['signer_name'], 'signature' => $d['signature'],
                    'signer_id_no' => $d['signer_id_no'] ?? null, 'selfie' => $d['selfie'] ?? null,
                    'signed_at' => now(), 'signed_ip' => $r->ip(),
                    'signed_agent' => substr((string) $r->userAgent(), 0, 250),
                    'signed_locale' => substr((string) $r->header('Accept-Language'), 0, 60),
                ])->save();
            }

            return [$pending, $completed];
        });
    }

    /**
     * التوقيع يُكمل سير الجهة المربوطة تلقائياً:
     *  - موافقة موجهة لشخص → «معتمد» بتوقيعه (إلا الموافقات المُلزِمة ذات العملية
     *    المؤجلة: تلك تُنفَّذ حصراً من شاشة الموافقات كي لا تُعتمد دون تنفيذ حمولتها).
     *  - سياسة → يتولد سجل إقرارٍ موثّق (النسخة، الوقت، IP، الجهاز، رمز التحقق).
     *  - سجل إقرار سياسة معلّق → يصير «مُقرّة» ببيانات التوقيع نفسها.
     */
    public static function completeLinked(SignRequest $req, string $signer, Request $r): void
    {
        if (! $req->link_module || ! $req->link_id) return;

        try {
            if ($req->link_module === 'approvals') {
                $ap = \App\Models\Approval::find($req->link_id);
                if ($ap && ! $ap->mod && in_array($ap->status, [null, '', 'معلّق'], true)) {
                    $ap->forceFill(['status' => 'معتمد', 'decided_at' => now()])->save();
                    \App\Support\Documents\Esign\EsignFinalizer::notifyOwners('✅ الموافقة «' . $ap->title . '» اعتُمدت بتوقيع ' . $signer
                        . ' [' . $req->verify_code . ']');
                    hub_audit('اعتماد موافقة بالتوقيع الإلكتروني', 'approvals', $ap->id, $ap->title);
                }
            } elseif ($req->link_module === 'policies') {
                $pol = \App\Models\Policy::find($req->link_id);
                if ($pol) {
                    // الموظف المُقِر يُحل من بريد الموقّع — سجل امتثال بلا صاحب لا يُدقَّق
                    $signerEmail = \App\Models\ContractSigner::where('request_id', $req->id)
                        ->where('role', 'موقّع')->whereNotNull('email')->value('email');
                    \App\Models\PolicyAck::create([
                        'title' => $pol->title . ' — ' . $signer,
                        'policy_id' => $pol->id, 'ver' => $pol->ver,
                        'user_id' => $signerEmail
                            ? \App\Models\User::whereNull('deleted_at')->where('email', $signerEmail)->value('id')
                            : null,
                        'ack_at' => now(), 'ip' => $r->ip(),
                        'device' => substr((string) $r->userAgent(), 0, 190),
                        'status' => 'مُقرّة',
                        'notes' => 'إقرار موقّع إلكترونياً — رمز التحقق ' . $req->verify_code,
                    ]);
                    \App\Support\Documents\Esign\EsignFinalizer::notifyOwners('📜 وُثّق إقرار «' . $pol->title . '» بتوقيع ' . $signer);
                    hub_audit('إقرار سياسة بالتوقيع الإلكتروني', 'policies', $pol->id, $pol->title . ' — ' . $signer);
                }
            } elseif ($req->link_module === 'policyacks') {
                $ack = \App\Models\PolicyAck::find($req->link_id);
                if ($ack && $ack->status !== 'مُقرّة') {
                    $ack->forceFill(['status' => 'مُقرّة', 'ack_at' => now(), 'ip' => $r->ip(),
                        'device' => substr((string) $r->userAgent(), 0, 190),
                        'notes' => trim(($ack->notes ? $ack->notes . "\n" : '')
                            . 'وُقّع إلكترونياً بواسطة ' . $signer . ' — رمز التحقق ' . $req->verify_code),
                    ])->save();
                    hub_audit('إتمام إقرار سياسة بالتوقيع الإلكتروني', 'policyacks', $ack->id, (string) $ack->title);
                }
            } elseif ($req->link_module === 'decisions') {
                hub_audit('توثيق قرار بتوقيع إلكتروني', 'decisions', $req->link_id, $req->title . ' — ' . $signer);
            } elseif ($req->link_module === 'quotes') {
                // **قبولُ العميل للعرض بتوقيعٍ إلكترونيّ**: يقلب العرضَ «مقبول»
                // (فيُطلق quote.accepted) بأدلّةٍ كاملة — لا محرك قبولٍ ثانٍ.
                $q = \App\Models\Quote::find($req->link_id);
                if ($q && ! in_array($q->status, ['مقبول', 'محوّل'], true)) {
                    $q->forceFill([
                        'status' => 'مقبول', 'accepted_at' => now(), 'accepted_by' => $signer,
                        'meta' => array_merge((array) $q->meta, ['accept_sign' => $req->verify_code]),
                    ])->save();
                    \App\Support\Platform\FlowRunner::fire('status', 'quotes', $q, 'مقبول');
                    \App\Support\Documents\Esign\EsignFinalizer::notifyOwners('🎉 قَبِل العميلُ العرضَ «' . ($q->title ?: $q->doc_no) . '» بتوقيعٍ إلكترونيّ [' . $req->verify_code . ']');
                    hub_audit('قبول عرض بتوقيع إلكتروني', 'quotes', $q->id, $q->doc_no . ' — ' . $signer);
                }
            }
        } catch (\Throwable $e) {
            report($e);   // إكمال السير إضافة — فشله لا يُفشل التوقيع نفسه المحفوظ فعلاً
        }
    }

    /**
     * لحظة الاكتمال (v2.122): تجميد رأس سلسلة الأدلة في evidence_hash، أرشفة
     * نسخة PDF موقعة كمرفق على العقد المربوط، وبريد النسخة النهائية والشهادة
     * لكل موقّعٍ بريدي ومستلمي النسخ. كله إثراء — فشله لا يمس التوقيع المحفوظ.
     */
    public static function archiveSignedCopy(SignRequest $req): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasColumn('sign_requests', 'evidence_hash')) {
                [, $head] = \App\Support\Documents\Evidence::chain($req);
                $req->forceFill(['evidence_hash' => $head])->saveQuietly();
            }

            if ($req->contract_id
                && ($pdf = \App\Support\Documents\DocRenderer::pdf(\App\Support\Documents\DocRenderer::docHtml($req), $req->title))) {
                $path = 'hub/att/signed-' . $req->verify_code . '.pdf';
                \Illuminate\Support\Facades\Storage::disk('local')->put($path, $pdf);
                \App\Models\Attachment::create([
                    'module' => 'contracts', 'record_id' => $req->contract_id,
                    'field' => 'نسخة موقعة — ' . $req->verify_code,
                    'disk' => 'local', 'path' => $path,
                    'original_name' => 'signed-' . $req->verify_code . '.pdf',
                    'mime' => 'application/pdf', 'size' => strlen($pdf),
                    'checksum' => hash('sha256', $pdf),
                    'uploaded_by' => $req->created_by,
                ]);
            }

            foreach (\App\Models\ContractSigner::where('request_id', $req->id)
                         ->whereNotNull('email')->orderBy('order')->get() as $s) {
                \App\Models\OutboxMessage::create([
                    'kind' => 'sign_copy', 'channel' => 'mail', 'target' => $s->email,
                    'text' => 'اكتمل توقيع «' . Str::limit($req->title, 60) . '» — نسختك النهائية: '
                        . route('sign.doc', $s->token) . ' وشهادة الإتمام: ' . route('sign.cert', $s->token),
                    'state' => 'queued', 'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** بريد الموقّع التالي في المتسلسل — أول موقّعٍ معلق دوره بريديٌّ ولم يُرسَل له بعد */
    public static function deliverNext(SignRequest $req): void
    {
        $next = \App\Models\ContractSigner::where('request_id', $req->id)
            ->where('role', 'موقّع')->where('status', 'بانتظار التوقيع')
            ->whereNotNull('email')->orderBy('order')->first();
        if (! $next || \App\Support\Documents\Esign\EsignFinalizer::waitingFor($req, $next)) return;
        if (\App\Models\ContractEvent::where('request_id', $req->id)
                ->where('signer_id', $next->id)->where('event', 'sent')->exists()) return;
        \App\Support\Documents\Esign\EsignFinalizer::mailSigner($req, $next);
    }

    /** رسالة رابط التوقيع لموقّع — نصية موجزة عبر صندوق الصادر (يسلّمها hub:outbox) */
    public static function mailSigner(SignRequest $req, \App\Models\ContractSigner $signer, string $kind = 'sign_link'): void
    {
        \App\Models\OutboxMessage::create([
            'kind' => $kind, 'channel' => 'mail', 'target' => $signer->email,
            'text' => ($kind === 'sign_reminder' ? 'تذكير: ' : '')
                . 'وثيقة «' . Str::limit($req->title, 60) . '» بانتظار توقيعك: '
                . route('sign.show', $signer->token)
                . ' — افتح الرابط واطلب رمز التحقق البريدي ثم وقّع.',
            'state' => 'queued', 'created_at' => now(),
        ]);
        \App\Models\ContractEvent::log($kind === 'sign_reminder' ? 'reminded' : 'sent', $req, ['signer_id' => $signer->id]);
    }

    /**
     * انقلاب حالة العقد المربوط بحفظ Eloquent (v2.117): كان saveQuietly/التحديث المباشر
     * يتجاوز Auditable وHasVersions وFlowRunner — فلا يُطلق contract.signed أبداً في
     * مسار التوقيع الفعلي. الآن يمر بكل البوابات ويُطلق الحدث الدلالي.
     */
    public static function flipContract(?string $contractId, array $from, string $to): void
    {
        if (! $contractId) return;
        $c = \App\Models\Contract::whereKey($contractId)->whereIn('status', $from)->first();
        if (! $c || (string) $c->status === $to) return;
        $c->status = $to;
        $c->save();
        \App\Support\Platform\FlowRunner::fire('status', 'contracts', $c, $to);
    }

    public static function notifyOwners(string $text): void
    {
        foreach (array_unique(hub_approvers()) as $oid) {
            if (! $oid) continue;
            HubNotification::create(['user_id' => $oid, 'kind' => 'sign',
                'text' => $text, 'read' => false, 'created_at' => now()]);
        }
    }

    /** تسليم طلبٍ اكتملت موافقاته: يتحرر ويُراسَل موقّعوه وينقلب عقده «قيد التوقيع» */
    public static function deliver(SignRequest $req): void
    {
        $req->forceFill(['status' => 'بانتظار التوقيع', 'sent_at' => now()])->save();
        foreach (\App\Models\ContractSigner::where('request_id', $req->id)
                     ->where('role', 'موقّع')->where('status', 'بانتظار التوقيع')
                     ->whereNotNull('email')->orderBy('order')->get() as $s) {
            if ($req->mode === 'متسلسل' && \App\Support\Documents\Esign\EsignFinalizer::waitingFor($req, $s)) continue;
            if (\App\Models\ContractEvent::where('request_id', $req->id)
                    ->where('signer_id', $s->id)->where('event', 'sent')->exists()) continue;
            \App\Support\Documents\Esign\EsignFinalizer::mailSigner($req, $s);
        }
        \App\Support\Documents\Esign\EsignFinalizer::flipContract($req->contract_id, ['مسودة', 'قيد التوقيع', ''], 'قيد التوقيع');
    }

    /** إخطار صاحب قرار المرحلة — الموافِق المسمى أو المالكين عند غيابه */
    public static function notifyStage(\App\Models\ContractApprovalStep $step, SignRequest $req): void
    {
        $text = '🔏 طلب توقيع «' . Str::limit($req->title, 60) . '» بانتظار اعتمادك — مرحلة '
            . $step->stage . ': ' . ($step->label ?: $step->kind);
        foreach ($step->approver_id ? [$step->approver_id] : array_unique(hub_approvers()) as $uid) {
            if (! $uid) continue;
            HubNotification::create(['user_id' => $uid, 'kind' => 'sign',
                'text' => $text, 'read' => false, 'created_at' => now()]);
        }
    }

    /** حارس المتسلسل: الموقّع N لا يفتح قبل توقيع من قبله */
    public static function waitingFor(SignRequest $req, \App\Models\ContractSigner $signer): ?\App\Models\ContractSigner
    {
        if ($req->mode !== 'متسلسل') return null;

        return \App\Models\ContractSigner::where('request_id', $req->id)
            ->where('role', 'موقّع')->where('order', '<', $signer->order)
            ->where('status', '!=', 'وُقّع')->orderBy('order')->first();
    }

    /** الموقّعون المستقلون الذين وقّعوا — الموقّع المرحّل برمز الطلب يبقى على كتلته القديمة */
    public static function signedIndependents(SignRequest $req)
    {
        return \App\Models\ContractSigner::where('request_id', $req->id)
            ->where('status', 'وُقّع')->where('token', '!=', (string) $req->token)
            ->orderBy('order')->get();
    }
}
