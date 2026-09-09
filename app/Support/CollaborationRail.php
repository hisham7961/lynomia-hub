<?php

namespace App\Support;

use App\Http\Controllers\Web\ConversationController;
use App\Http\Controllers\Web\DmController;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;

/**
 * **سكّةُ مركزِ التواصلِ الموحّد** (المرحلة ٦ · §106) — تجمع القوائمَ اليسرى للمركز
 * ذي الألواح الثلاثة **فوق المحرّكِ الواحد** (`Conversation` + `comments` + `dm_messages`)
 * لا محرّكاً ثانياً: القنواتُ والغرفُ والمجموعاتُ والمحادثاتُ المباشرة، مع غيرِ المقروء
 * والمفضّلةِ والحضور.
 *
 * **العزلُ ليس هنا شرطاً جديداً بل إعادةُ استعمالِ الحرّاس:** العضويّةُ (`conversation_members`)
 * + دفاعُ نطاقِ الشركة/العميل فوقها (نظيرُ `ConversationController::index`) + سكّةُ
 * المحادثةِ (`DmService::threadRows` بنطاق الشركة). فلا تُدرَج في السكّة حاويةٌ لا يبلغها
 * صاحبُها أصلاً — القائمةُ مرآةُ ما يراه لا بابٌ يلتفّ على الحارس.
 */
class CollaborationRail
{
    /**
     * قوائمُ السكّةِ اليسرى لمستخدمٍ داخليّ (العميلُ لا يبلغ المركزَ — PortalGuard + الحارس).
     *
     * @return array{favorites: array, channels: array, rooms: array, groups: array, dms: array, unreadTotal: int}
     */
    public static function forUser(User $user): array
    {
        // ١) عضويّاتُه (أساسُ العزل) — المعرّف + الدور + نجمةُ المفضّلة
        $hasFav = hub_has_col('conversation_members', 'favorite_at');
        $memberships = ConversationMember::where('user_id', $user->getKey())
            ->get(['conversation_id', 'role', ...($hasFav ? ['favorite_at'] : [])]);
        $memberIds = $memberships->pluck('conversation_id');
        $favIds = $hasFav
            ? $memberships->filter(fn ($m) => $m->favorite_at)->pluck('conversation_id')->flip()
            : collect();

        // ٢) دفاعُ النطاق فوق العضويّة — كالحارس تماماً (لا صفّاً خارج نطاقه)
        $cids = hub_company_ids($user);
        $kids = hub_client_ids($user);
        $applyScope = function ($q) use ($cids, $kids) {
            return $q->when($cids !== null, fn ($x) => $x->where(
                    fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id')))
                ->when($kids !== null, fn ($x) => $x->where(
                    fn ($w) => $w->whereIn('client_id', $kids)->orWhereNull('client_id')));
        };

        $cols = ['id', 'kind', 'title', 'audience', 'visibility', 'project_id', 'client_id', 'module', 'record_id', 'updated_at'];

        // القنواتُ والغرفُ والمجموعاتُ النشطةُ التي هو عضوٌ فيها ضمن نطاقه
        $convs = $applyScope(
            Conversation::whereIn('kind', ['channel', 'group'])->active()
                ->whereNull('deleted_at')->whereIn('id', $memberIds)
        )->orderBy('title')->orderBy('id')->get($cols);

        $unread = ConversationController::unreadCounts($convs->pluck('id')->all(), (string) $user->getKey());

        $channels = [];
        $rooms = [];
        $groups = [];
        foreach ($convs as $c) {
            $u = $unread[$c->id] ?? 0;
            $fav = $favIds->has($c->id);
            $item = [
                'kind'     => $c->kind,
                'id'       => (string) $c->id,
                'title'    => (string) ($c->title ?: ''),
                'audience' => (string) $c->audience,
                'url'      => route('collab.center', ['c' => $c->id]),
                'unread'   => $u,
                'fav'      => $fav,
            ];
            if ($c->kind === 'group') {
                $item['title'] = $item['title'] ?: 'مجموعة';
                $groups[] = $item;
            } elseif ($c->project_id !== null) {
                // §9 غرفةُ مشروع — الجمهورُ يُميِّزها (داخليّةٌ لا تُخلَط بغرفةِ عميل).
                // `kind=room` تصنيفٌ عرضيّ فقط (الحاويةُ channel في المحرّك) — الشارةُ من `audience`.
                $item['kind'] = 'room';
                $item['title'] = $item['title'] ?: 'غرفة مشروع';
                $rooms[] = $item;
            } else {
                $item['title'] = $item['title'] ?: 'قناة';
                $channels[] = $item;
            }
        }

        // ٣) المحادثاتُ المباشرة — نفسُ سكّةِ الصندوق (بنطاق الشركة، دفاعاً في العمق)
        $threads = DmService::threadRows((string) $user->getKey());
        $otherIds = $threads->pluck('other')->all();
        $names = $otherIds
            ? User::whereIn('id', $otherIds)->pluck('name', 'id')
            : collect();
        $presence = DmController::presence($otherIds);
        $dms = [];
        foreach ($threads as $t) {
            $oid = (string) $t['other'];
            $dms[] = [
                'kind'     => 'dm',
                'other_id' => $oid,
                'title'    => (string) ($names[$t['other']] ?? 'مستخدم محذوف'),
                'url'      => route('collab.center', ['dm' => $oid]),
                'unread'   => (int) ($t['unread'] ?? 0),
                'presence' => $presence[$oid]['state'] ?? Presence::OFFLINE,
                'online'   => (bool) ($presence[$oid]['online'] ?? false),
            ];
        }

        // ٤) المفضّلة (قنواتٌ/غرفٌ/مجموعاتٌ منجَّمة) — عرضٌ أعلى، لا عزلٌ ثانٍ
        $favorites = array_values(array_filter(
            array_merge($channels, $rooms, $groups),
            fn ($i) => $i['fav']
        ));

        $unreadTotal = array_sum($unread) + array_sum(array_map(fn ($d) => $d['unread'], $dms));

        return compact('favorites', 'channels', 'rooms', 'groups', 'dms', 'unreadTotal');
    }
}
