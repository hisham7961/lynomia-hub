<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\InformationArchitecture;
use Illuminate\Http\Request;

/**
 * خريطةُ النظام (IA · الطور 6 · «خريطة النظام») — الشجرةُ الكاملةُ المُنطَّقةُ من
 * `InformationArchitecture::systemMap`: سطوحٌ (الرئيسية/مهامّي) ثمّ مجالاتٌ بأقسامها
 * ووجهاتها المرئيّة — كلٌّ مُرشَّحٌ بصلاحية المستخدم (لا يظهر ما لا يبلغه، فلا تسريبَ
 * لاسمِ وحدةٍ/مركزٍ محجوب). للمالك: لوحةُ تشخيصٍ حيّةٌ (نظيرُ 06-final-audit): كلُّ
 * الوحدات ببيتها/حالتها، الأيتام (يجب 0)، والبيوتُ المكرّرة (يجب 0).
 *
 * الحرسُ: `auth` فقط (سطحُ الرئيسية — لكلِّ مستخدمٍ داخليّ)؛ **الرؤيةُ لا الوصولُ**
 * تُنطَّق داخل الخدمة، والوجهاتُ تبقى محروسةً بمتحكّماتها (الرابطُ لا يمنح دخولاً).
 */
class SystemMapController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $ia = InformationArchitecture::make();
        $map = $ia->systemMap($u);

        // إلحاقُ رابطٍ آمنٍ بكلِّ وجهة: الوجهاتُ السياقيّةُ (تحتاج سجلاً/معاملاً) لا
        // تُولَّد رابطاً عامّاً فتظهر تسميةً فقط — لا رميَ ولا رابطٌ مكسور.
        $annotate = function (array &$node): void {
            foreach ($node['sections'] ?? [] as &$s) {
                foreach ($s['destinations'] ?? [] as &$d) {
                    $d['url'] = null;
                    $rn = $d['route'] ?? null;
                    if ($rn) {
                        try {
                            $d['url'] = route($rn, $d['args'] ?? []);
                        } catch (\Throwable $e) {
                            $d['url'] = null;   // مسارٌ يحتاج معاملاً غيرَ متاح — تسميةٌ فقط
                        }
                    }
                }
                unset($d);
            }
            unset($s);
        };
        foreach ($map['surfaces'] as &$sf) {
            $annotate($sf);
        }
        unset($sf);
        foreach ($map['domains'] as &$dm) {
            $annotate($dm);
        }
        unset($dm);

        return view('system-map', ['map' => $map]);
    }
}
