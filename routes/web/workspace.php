<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — مبدّلُ الشركة ومساحةُ العميل وإدارةُ عضويّته
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\ClientPortalController;
use App\Http\Controllers\Web\ClientMemberController;
use Illuminate\Support\Facades\Route;

    // ── محوّل الشركة النشطة (تصفية القوائم) ──
    Route::post('company-switch', function (\Illuminate\Http\Request $r) {
        $cid = (string) $r->input('company', '');
        if ($cid !== '') {
            abort_unless(hub_can(auth()->user(), 'companies', 'v'), 403);
            $allowed = hub_company_ids();
            abort_if($allowed !== null && ! in_array($cid, $allowed, true), 403, 'هذه الشركة خارج نطاقك');
            abort_unless(\App\Models\Company::whereNull('deleted_at')->whereKey($cid)->exists(), 404);
        }
        session(['hub.company' => $cid]);

        return back()->with('ok', $cid === '' ? 'عدت لعرض كل الشركات' : 'تُصفّى القوائم الآن على الشركة المختارة');
    })->name('company.switch');

    // ── مبدّل مساحة عمل العميل: الوحداتُ نفسُها تعمل داخلياً أو لعميلٍ محدد ──
    // نظيرُ مبدّل الشركة حرفياً: بوابةُ صلاحية، فعزلٌ صارم، فوجودٌ فعلي.
    Route::post('client-switch', function (\Illuminate\Http\Request $r) {
        $kid = (string) $r->input('client', '');
        if ($kid !== '') {
            abort_unless(hub_can(auth()->user(), 'clients', 'v'), 403);
            $allowed = hub_client_ids();
            abort_if($allowed !== null && ! in_array($kid, $allowed, true), 403, 'هذا العميل خارج نطاقك');
            abort_unless(\App\Models\Client::whereNull('deleted_at')->whereKey($kid)->exists(), 404);
        }
        session(['hub.client' => $kid]);

        return back()->with('ok', $kid === '' ? 'عدت للمساحة الداخلية — كل السجلات' : 'تعمل الآن في مساحة العميل المختار');
    })->name('client.switch');

    // ── مساحةُ العميل (Work OS · الطور B · WP-B.2) — شلٌّ منفصلٌ أبسطُ عمداً ──
    // خلف PortalGuard (العميلُ فقط؛ الأسماءُ `portal.*` مُدرَجةٌ سلفاً في NAME_ALLOW)،
    // والداخليُّ يُحوَّل للوحة داخل المتحكّم لا ٥٠٠. كلُّ قراءةٍ معزولةٌ بعملاءِ القارئ
    // (`hub_client_ids`) + جمهورِ الوثيقة/المحادثة — لا رقمَ داخليٍّ ولا سجلَّ عميلٍ آخر.
    Route::prefix('portal')->name('portal.')->group(function () {
        Route::get('/', [ClientPortalController::class, 'home'])->name('home');
        Route::get('engagements', [ClientPortalController::class, 'engagements'])->name('engagements');
        Route::get('projects', [ClientPortalController::class, 'projects'])->name('projects');
        Route::get('projects/{id}', [ClientPortalController::class, 'project'])->name('project');
        Route::get('documents', [ClientPortalController::class, 'documents'])->name('documents');
        Route::get('documents/{id}', [ClientPortalController::class, 'document'])->name('document');
        Route::get('invoices', [ClientPortalController::class, 'invoices'])->name('invoices');
        Route::get('invoices/{id}', [ClientPortalController::class, 'invoice'])->name('invoice');
        Route::get('conversations', [ClientPortalController::class, 'conversations'])->name('conversations');
        Route::get('conversations/{id}', [ClientPortalController::class, 'conversation'])->name('conversation');
        // (الجولة 1 · F24) كتابةُ عضوِ الغرفةِ العميلِ في **غرفته** حصراً — على محرّك
        // الرسائل القائم (comments/conversation_id)؛ الحرسُ في المتحكّم والمحرّك.
        Route::post('conversations/{id}/messages', [ClientPortalController::class, 'conversationSend'])
            ->middleware('throttle:30,1')->name('conversation.send');
        // (الجولة 1 · F25) تنزيلُ ملفِّ الوثيقةِ المشارَكة — مسارٌ مصادَقٌ محكومٌ
        // بعضويّة العميل وجمهورِ الوثيقة وسياستِها؛ لا روابطَ عامّةً ولا توقيعَ URL.
        Route::get('documents/{id}/download', [ClientPortalController::class, 'documentDownload'])
            ->middleware('throttle:60,1')->name('document.download');
        // (الجولة 2 · G7/G5) «تذاكري» — قناةُ بلاغِ العميل ومتابعتُها: كانت البوّابةُ
        // ستَّ وجهاتٍ بلا بابِ دعمٍ واحد، فالبلاغُ يجري هاتفيّاً خارج النظام. العزلُ
        // كلُّه في المتحكّم/القارئ (عملاؤه + مشاريعه)، والكتابةُ مقنَّنةُ المعدّل.
        // `tickets/new` قبل `tickets/{id}` — وإلا ابتلعها معرّفُ السجلّ.
        Route::get('tickets', [ClientPortalController::class, 'tickets'])->name('tickets');
        Route::get('tickets/new', [ClientPortalController::class, 'ticketCreate'])->name('ticket.create');
        Route::post('tickets', [ClientPortalController::class, 'ticketStore'])
            ->middleware('throttle:20,1')->name('ticket.store');
        Route::get('tickets/{id}', [ClientPortalController::class, 'ticket'])->name('ticket');
        // (الجولة 3 · V3) **ردُّ العميلِ على تذكرته** — كانت القناةُ باتّجاهٍ واحد منذ
        // v2.497.0: يبلّغ ولا يُردّ عليه، فإذا طال الصمتُ أعاد البلاغَ نفسَه. الردُّ
        // على محرّكِ التعليقات القائم، والعزلُ بفاحصِ الشاشةِ نفسِه (٤٠٤ لغيره)،
        // و`internal` مختومٌ خادميّاً. مقنَّنُ المعدّل كبقيّة كتابات البوّابة.
        Route::post('tickets/{id}/replies', [ClientPortalController::class, 'ticketReply'])
            ->middleware('throttle:20,1')->name('ticket.reply');
        // (الجولة 3 · V4) جلساتُ صاحبِ الحساب — على سكّةِ `Sessions` الواحدة وعلى
        // صفوفه هو حصراً. شاشةُ حسابه صارت بقشرةِ بوّابته، فأفعالُها بادئتُها كذلك
        // (مسارات `my/security` داخليّةٌ خارجَ قائمةِ PortalGuard البيضاء).
        Route::middleware('throttle:20,1')->group(function () {
            Route::post('account/sessions/others', [ClientPortalController::class, 'sessionsRevokeOthers'])
                ->name('sessions.others');
            Route::post('account/sessions/{id}/revoke', [ClientPortalController::class, 'sessionRevoke'])
                ->name('session.revoke');
        });
    });

    // ── إدارةُ عضويّة العميل (Work OS · الطور B · WP-B.3) — داخليّةٌ فقط (مديرُ الحساب) ──
    // لوحةٌ على «عميل ٣٦٠» تدعو زميلاً وتمنح دوراً وتسحب وصولاً. الحرسُ في المتحكّم
    // (`hub_can('clients','e')` + `hub_scope`)، ومنحُ Owner/سحبُ الوصول = تصعيدُ هوية.
    // حسابُ العميل نفسُه يُردّ هنا بـPortalGuard (٤٠٤) فوق مصفوفة الأدوار. تُقنَّن الكتابةُ.
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('clients/{client}/members', [ClientMemberController::class, 'invite'])->name('clients.members.invite');
        Route::post('clients/{client}/members/{membership}/role', [ClientMemberController::class, 'setRole'])->name('clients.members.role');
        Route::post('clients/{client}/members/{membership}/revoke', [ClientMemberController::class, 'revoke'])->name('clients.members.revoke');
    });

