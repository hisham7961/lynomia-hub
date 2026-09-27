<?php

/*
|---------------------------------------------------------------------------
| سطحُ الجوال — التعاونُ والعميلُ وسيرُ العمل (خطّة التطبيق · المرحلة ٤)
|---------------------------------------------------------------------------
| يُضمَّن **في موضعه نفسِه** داخل مجموعة `api/mobile/v1` المُصادَقة في routes/api.php
| (الوسائطُ موروثة: throttle:api · mobile.session · mobile.portal · mobile.context) —
| **قبل** `sync/{module}` والـcatch-all `{module}` و`{module}/{id}/actions` (Critic F9):
| كلُّ مسارٍ هنا حرفيُّ المقطعِ الأوّل فلا يبتلعه الـcatch-all، وأسماؤه خارج
| `mobile.resource.*` فلا يلتبس بمسارات الوحدات العامّة. القواعدُ كلُّها في الخدمات
| المشتركة مع الويب (لا حارسَ ثانٍ): ClientTickets · ChannelService · GroupService ·
| DmService · MessageSearch · CommentActions · ReportReview · CalendarFeed ·
| FinPayment · QuoteActions/QuoteAcceptance · PurchaseFlow · AskPipeline.
*/

use App\Http\Controllers\Api\MobileAskController;
use App\Http\Controllers\Api\MobileCalendarController;
use App\Http\Controllers\Api\MobileChannelsController;
use App\Http\Controllers\Api\MobileCommentActionsController;
use App\Http\Controllers\Api\MobileFinanceActionsController;
use App\Http\Controllers\Api\MobilePortalTicketsController;
use App\Http\Controllers\Api\MobileTeamReportsController;
use Illuminate\Support\Facades\Route;

    // ── 4.1 «تذاكري» — بوّابةُ العميل (`mobile.portal.*` في قائمة MobilePortalGuard البيضاء؛
    //    الداخليُّ يُردّ بعقد البوّابة القائم). الكتابةُ مقنَّنةٌ كنظيرِها الويبيّ (20/دقيقة).
    Route::get('portal/tickets', [MobilePortalTicketsController::class, 'tickets'])->name('mobile.portal.tickets.index');
    Route::post('portal/tickets', [MobilePortalTicketsController::class, 'ticketStore'])
        ->middleware('throttle:20,1')->name('mobile.portal.tickets.store');
    Route::get('portal/tickets/{id}', [MobilePortalTicketsController::class, 'ticket'])->name('mobile.portal.tickets.show');
    Route::post('portal/tickets/{id}/reply', [MobilePortalTicketsController::class, 'ticketReply'])
        ->middleware('throttle:20,1')->name('mobile.portal.tickets.reply');

    // ── 4.2 إدارةُ القنوات — `conversations/directory` قبل أيّ `conversations/{id}` (F9)،
    //    والخنقُ نظيرُ الويب (إنشاءٌ/انضمامٌ 30، الأعضاءُ والتفضيلات 60 في الدقيقة).
    Route::get('conversations/directory', [MobileChannelsController::class, 'directory'])->name('mobile.conversations.directory');
    Route::post('conversations', [MobileChannelsController::class, 'channelStore'])
        ->middleware('throttle:30,1')->name('mobile.conversations.store');
    Route::post('conversations/{id}/join', [MobileChannelsController::class, 'join'])
        ->middleware('throttle:30,1')->name('mobile.conversations.join');
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('conversations/{id}/members', [MobileChannelsController::class, 'members'])->name('mobile.conversations.members.index');
        Route::post('conversations/{id}/members', [MobileChannelsController::class, 'addMember'])->name('mobile.conversations.members.add');
        Route::put('conversations/{id}/members/{user}', [MobileChannelsController::class, 'setRole'])->name('mobile.conversations.members.role');
        Route::delete('conversations/{id}/members/{user}', [MobileChannelsController::class, 'removeMember'])->name('mobile.conversations.members.remove');
        Route::post('conversations/{id}/favorite', [MobileChannelsController::class, 'favorite'])->name('mobile.conversations.favorite');
        Route::post('conversations/{id}/archive', [MobileChannelsController::class, 'archive'])->name('mobile.conversations.archive');
        Route::put('conversations/{id}/notify', [MobileChannelsController::class, 'notifyPref'])->name('mobile.conversations.notify');
    });

    // مجموعاتُ الرسائل (§35) — إضافةُ مشاركٍ = مجموعةٌ جديدة (أمنُ الجمهور التاريخيّ)
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('groups', [MobileChannelsController::class, 'groupStore'])->name('mobile.groups.store');
        Route::post('groups/{id}/participants', [MobileChannelsController::class, 'groupFork'])->name('mobile.groups.fork');
        Route::post('groups/{id}/leave', [MobileChannelsController::class, 'groupLeave'])->name('mobile.groups.leave');
    });

    // تحريرُ/سحبُ رسالةٍ مباشرة — لصاحبها وحده (`mobile.dm.*` خارجَ قائمة العميل)
    Route::patch('dm/messages/{id}', [MobileChannelsController::class, 'dmEdit'])->name('mobile.dm.edit');
    Route::delete('dm/messages/{id}', [MobileChannelsController::class, 'dmDestroy'])->name('mobile.dm.destroy');

    // بحثُ الرسائل عبر السطوح (§25) — ما يراه القارئ وحدَه
    Route::get('search/messages', [MobileChannelsController::class, 'searchMessages'])->name('mobile.search.messages');

    // ── 4.3 أفعالُ التعليق — `mobile.comment_actions.*` (لا `mobile.comments.*` المفتوحةَ للعميل)
    Route::patch('comments/{id}', [MobileCommentActionsController::class, 'commentEdit'])->name('mobile.comment_actions.edit');
    Route::delete('comments/{id}', [MobileCommentActionsController::class, 'commentDestroy'])->name('mobile.comment_actions.destroy');
    Route::post('comments/{id}/pin', [MobileCommentActionsController::class, 'commentPin'])->name('mobile.comment_actions.pin');
    Route::post('comments/{id}/resolve', [MobileCommentActionsController::class, 'commentResolve'])->name('mobile.comment_actions.resolve');
    Route::post('comments/{id}/to-task', [MobileCommentActionsController::class, 'commentToTask'])->name('mobile.comment_actions.to_task');

    // ── 4.4 مراجعةُ التقارير اليوميّة للفريق (المدير/HR) — نظيرُ reports/review الويبيّ
    Route::get('reports/daily', [MobileTeamReportsController::class, 'daily'])->name('mobile.reports.daily');
    Route::post('reports/daily/{id}/review', [MobileTeamReportsController::class, 'review'])
        ->middleware('throttle:60,1')->name('mobile.reports.review');

    // ── 4.5 التقويمُ والتنبيهات (صفحتا calendar/alerts الويبيّتان)
    Route::get('calendar', [MobileCalendarController::class, 'calendar'])->name('mobile.calendar');
    Route::get('alerts', [MobileCalendarController::class, 'alerts'])->name('mobile.alerts');

    // ── 4.6 أفعالٌ ماليّةٌ عبر المحرّكات الموحّدة — **قبل** `{module}/{id}/actions` والـcatch-all
    // خياراتُ نموذج الدفعة (قراءةٌ بلا أثرٍ ولا تصعيد) — ما يعرضه نموذجُ الويب لمن يملك الفعل
    Route::get('fin/{id}/pay-options', [MobileFinanceActionsController::class, 'payOptions'])->name('mobile.fin.pay_options');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('fin/{id}/pay', [MobileFinanceActionsController::class, 'pay'])->name('mobile.fin.pay');
        Route::post('quotes/{id}/send', [MobileFinanceActionsController::class, 'quoteSend'])->name('mobile.quotes.send');
        Route::post('quotes/{id}/accept', [MobileFinanceActionsController::class, 'quoteAccept'])->name('mobile.quotes.accept');
        Route::post('purchases/{id}/receive', [MobileFinanceActionsController::class, 'purchaseReceive'])->name('mobile.purchases.receive');
    });

    // ── 4.7 «اسأل Hub» بالبثّ (SSE) — الحرّاسُ والخنقُ نفسُهما؛ `POST ask` باقٍ كما هو
    Route::post('ask/stream', [MobileAskController::class, 'stream'])
        ->middleware('throttle:' . \App\Support\Ai\Ask\AskPolicy::THROTTLE)->name('mobile.ask.stream');
