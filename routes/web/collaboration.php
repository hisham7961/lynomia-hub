<?php

/*
|---------------------------------------------------------------------------
| مساراتُ الويب — الوارد والموظّفون وغرفةُ البيانات والموافقاتُ والإقرارُ والتعليقاتُ والقنواتُ والمراسلة
|---------------------------------------------------------------------------
| جزءٌ من مجموعة `auth` في routes/web.php يُضمَّن **في موضعه نفسِه** (docs/REORG_PLAN.md §R3):
| الترتيبُ يحكم مطابقةَ المسارات ذات المتغيّرات، والوسائطُ موروثةٌ من المجموعة. تغييرُ
| ترتيبٍ أو اسمٍ أو وسيطٍ تُسقطه لقطةُ المسارات (tests/Fixtures/structure/routes.json).
*/

use App\Http\Controllers\Web\ApprovalDecisionController;
use App\Http\Controllers\Web\DmController;
use App\Http\Controllers\Web\PrefController;
use App\Http\Controllers\Web\CommentController;
use App\Http\Controllers\Web\SavedController;
use App\Http\Controllers\Web\ConversationController;
use App\Http\Controllers\Web\DataRoomController;
use App\Http\Controllers\Web\InboxDocController;
use App\Http\Controllers\Web\StaffController;
use Illuminate\Support\Facades\Route;

    // ── صندوق الوثائق الوارد ──
    Route::get('inboxdocs', [InboxDocController::class, 'index'])->name('inboxdocs.index');
    Route::post('inboxdocs', [InboxDocController::class, 'store'])->name('inboxdocs.store');
    Route::post('inboxdocs/{id}/classify', [InboxDocController::class, 'classify'])->name('inboxdocs.classify');
    Route::delete('inboxdocs/{id}', [InboxDocController::class, 'destroy'])->name('inboxdocs.destroy');

    // ── الموظفون وحساباتهم: طرفان لشخصٍ واحد ──
    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('staff/{id}/link', [StaffController::class, 'link'])->name('staff.link');
    Route::post('staff/{id}/unlink', [StaffController::class, 'unlink'])->name('staff.unlink');
    Route::post('staff/{id}/account', [StaffController::class, 'account'])->name('staff.account');
    Route::post('staff/{id}/align', [StaffController::class, 'align'])->name('staff.align');
    Route::post('staff/user/{id}/file', [StaffController::class, 'file'])->name('staff.file');

    // ── غرفة البيانات (الإدارة) ──
    Route::get('dataroom', [DataRoomController::class, 'index'])->name('dataroom.index');
    Route::post('dataroom', [DataRoomController::class, 'store'])->name('dataroom.store');
    Route::post('dataroom/{id}/revoke', [DataRoomController::class, 'revoke'])->name('dataroom.revoke');

    // ── حسم الموافقات المُلزِمة ──
    Route::post('approvals/{id}/approve', [ApprovalDecisionController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{id}/reject', [ApprovalDecisionController::class, 'reject'])->name('approvals.reject');

    // ── الإقرار الموثَّق على السجلات (محضر · عهدة · قرار) ──
    Route::post('acks/{module}/{id}', [\App\Http\Controllers\Web\AckController::class, 'store'])->name('acks.store');
    Route::post('acks/{module}/{id}/remind', [\App\Http\Controllers\Web\AckController::class, 'remind'])->name('acks.remind');

    // ── التعليقات وقناة الفريق ──
    Route::get('feed', [CommentController::class, 'feed'])->name('feed');
    Route::post('comments', [CommentController::class, 'store'])->name('comments.store');
    Route::post('comments/{id}/edit', [CommentController::class, 'edit'])->name('comments.edit');
    Route::post('comments/{id}/pin', [CommentController::class, 'pin'])->name('comments.pin');
    Route::post('comments/{id}/task', [CommentController::class, 'toTask'])->name('comments.task');
    Route::delete('comments/{id}', [CommentController::class, 'destroy'])->name('comments.destroy');
    Route::post('comments/{id}/react', [CommentController::class, 'react'])->name('comments.react');
    Route::post('comments/{id}/resolve', [CommentController::class, 'resolve'])->name('comments.resolve');
    // v2.123: سلسلة العقد (ملحق/تجديد) + مكتبة البنود
    Route::post('contract/{id}/amend', [\App\Http\Controllers\Web\ContractActionsController::class, 'amend'])->name('contract.amend');
    Route::post('contract/{id}/renew', [\App\Http\Controllers\Web\ContractActionsController::class, 'renew'])->name('contract.renew');
    Route::post('esign/clauses', [\App\Http\Controllers\Web\ContractActionsController::class, 'storeClause'])->name('esign.clause.store');
    Route::delete('esign/clauses', [\App\Http\Controllers\Web\ContractActionsController::class, 'destroyClause'])->name('esign.clause.destroy');

    // ── القنواتُ والفضاءات (Work OS · الطور C · WP-C.1) — رسائلُها تعليقاتٌ عبر
    //    conversation_id (لا محرّكَ ثانٍ). داخليّةٌ افتراضاً؛ العميلُ لا يبلغها
    //    (PortalGuard فوق الكل) — قناةُ جمهورِه تصله عبر portal.conversation.
    //    الحرسُ في المتحكّم: عضويّةٌ فعّالة + نطاقٌ + صلاحيةُ الوحدةِ الهدف. ──
    // مركزُ التواصلِ الموحّد — الألواحُ الثلاثة فوق المحرّكِ الواحد (المرحلة ٦ · §106).
    //    الحرسُ في المتحكّم: guardConversation للقناة و dmReachable للمحادثة عند الاختيار،
    //    والعميلُ لا يبلغه (PortalGuard + abort_if أدناه).
    Route::get('collab', [\App\Http\Controllers\Web\CollaborationController::class, 'center'])->name('collab.center');
    // §19 مركزُ الانتباه — الإشاراتُ والردودُ (وجهةٌ داخلَ التواصل، من محرّكِ الإشعاراتِ القائم)
    Route::get('collab/attention', [\App\Http\Controllers\Web\CollaborationController::class, 'attention'])->name('collab.attention');
    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('conversations/directory', [ConversationController::class, 'directory'])->name('conversations.directory');
    Route::post('conversations', [ConversationController::class, 'store'])
        ->middleware('throttle:30,1')->name('conversations.store');
    Route::post('conversations/{id}/join', [ConversationController::class, 'join'])
        ->middleware('throttle:30,1')->name('conversations.join');
    Route::get('conversations/{id}/since', [ConversationController::class, 'since'])
        ->middleware('throttle:120,1')->name('conversations.since');
    Route::post('conversations/{id}/typing', [ConversationController::class, 'typing'])
        ->middleware('throttle:60,1')->name('conversations.typing');
    Route::get('conversations/{id}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::post('conversations/{id}/notify', [ConversationController::class, 'setNotifyPref'])
        ->middleware('throttle:60,1')->name('conversations.notify');
    Route::post('conversations/{id}/favorite', [ConversationController::class, 'toggleFavorite'])
        ->middleware('throttle:60,1')->name('conversations.favorite');
    Route::post('conversations/{id}/archive', [ConversationController::class, 'toggleArchive'])
        ->middleware('throttle:60,1')->name('conversations.archive');
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('conversations/{id}/members', [ConversationController::class, 'addMember'])->name('conversations.member.add');
        Route::post('conversations/{id}/members/remove', [ConversationController::class, 'removeMember'])->name('conversations.member.remove');
        Route::post('conversations/{id}/members/role', [ConversationController::class, 'setRole'])->name('conversations.member.role');
    });

    // ── رقابةُ الاتصالات (Work OS · الطور C · WP-C.2 · §6) — بابٌ ظاهرٌ مُدقَّقٌ لا
    //    خفيّ: قارئٌ للقراءة فقط خلفَ دورِ الرقابة المُسنَد (collab.oversight_role،
    //    غيرُ المالك) + hub_require_stepup + سببٍ إلزاميّ؛ لا يمسّ read_at/read_by،
    //    وكلُّ قراءةٍ تكتب hub_audit. يغطّي محرّكَي الرسائل (Comment + DM عبر الحاوية).
    //    الحرسُ كلُّه في المتحكّم؛ والعميلُ لا يبلغها (PortalGuard فوقها → ٤٠٤). ──
    Route::get('oversight', [\App\Http\Controllers\Web\OversightController::class, 'index'])->name('oversight.index');
    Route::get('oversight/{id}', [\App\Http\Controllers\Web\OversightController::class, 'show'])->name('oversight.show');

    // ── مجموعاتُ الرسائل (§35) — محادثاتٌ جماعيّةٌ داخليّةٌ خاصّةٌ فوق حاويةِ المحادثة ──
    Route::get('groups', [\App\Http\Controllers\Web\GroupController::class, 'index'])->name('groups.index');
    Route::post('groups', [\App\Http\Controllers\Web\GroupController::class, 'store'])
        ->middleware('throttle:30,1')->name('groups.store');
    Route::post('groups/{id}/participants', [\App\Http\Controllers\Web\GroupController::class, 'fork'])
        ->middleware('throttle:30,1')->name('groups.fork');
    Route::post('groups/{id}/leave', [\App\Http\Controllers\Web\GroupController::class, 'leave'])
        ->middleware('throttle:30,1')->name('groups.leave');

    // ── المراسلة الداخلية المباشرة ──
    Route::get('dm', [DmController::class, 'inbox'])->name('dm.inbox');
    Route::post('dm', [DmController::class, 'start'])->name('dm.start');
    Route::post('dm/msg/{id}/edit', [DmController::class, 'edit'])->name('dm.edit');
    Route::post('dm/msg/{id}/react', [DmController::class, 'react'])->name('dm.react');
    Route::delete('dm/msg/{id}', [DmController::class, 'destroy'])->name('dm.destroy');
    Route::get('dm/{userId}/since', [DmController::class, 'since'])
        ->middleware('throttle:120,1')->name('dm.since');
    Route::post('dm/{userId}/typing', [DmController::class, 'typing'])
        ->middleware('throttle:60,1')->name('dm.typing');
    Route::get('dm/{userId}', [DmController::class, 'thread'])->name('dm.thread');
    Route::post('dm/{userId}', [DmController::class, 'send'])->name('dm.send');

    // ── بحثُ الرسائل عبر السطوح (§25) — في نصّ الخلاصة/القنوات/المحادثات، ما يراه القارئ ──
    Route::get('search/messages', [\App\Http\Controllers\Web\MessageSearchController::class, 'index'])->name('search.messages');

    // ── المحفوظاتُ الشخصيّة (§27) — «احفظ لاحقاً» لأيّ رسالة، مرجعٌ لا نسخُ محتوى ──
    Route::get('saved', [SavedController::class, 'index'])->name('saved.index');
    Route::post('saved', [SavedController::class, 'toggle'])->name('saved.toggle');
    Route::delete('saved/{id}', [SavedController::class, 'destroy'])->name('saved.destroy');

    // ── التخصيص الشخصي ──
    Route::get('personalize', [PrefController::class, 'edit'])->name('prefs.edit');
    Route::post('personalize', [PrefController::class, 'update'])->name('prefs.update');
    Route::post('personalize/reset', [PrefController::class, 'reset'])->name('prefs.reset');
    Route::post('personalize/pin', [PrefController::class, 'togglePin'])->name('prefs.pin');
    Route::post('personalize/cols', [PrefController::class, 'saveCols'])->name('prefs.cols');
    Route::post('views', [PrefController::class, 'storeView'])->name('views.store');
    Route::post('views/{id}/default', [PrefController::class, 'defaultView'])->name('views.default');
    Route::delete('views/{id}', [PrefController::class, 'destroyView'])->name('views.destroy');

