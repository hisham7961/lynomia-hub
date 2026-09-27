<?php

/**
 * **سحرُ Laravel الذي لا يراه phpstan بلا larastan — يُستثنى بقياسٍ لا بعموم.**
 *
 * larastan غيرُ متاح (البند #36: الحزمةُ بلا مصدرٍ على Packagist وأرشيفاتُ
 * codeload `403`)، فـphpstan الخامُ لا يعرف ثلاثةَ أبوابٍ سحريّةٍ تمرّ بها
 * الشيفرةُ آلافَ المرّات:
 *
 *   ١. `Task::where(...)` — `Model::__callStatic` يُحيل إلى المُنشئ (Builder).
 *   ٢. `$task->title` — خاصّيّاتُ Eloquent أعمدةٌ في القاعدة لا في الصنف.
 *   ٣. `auth()->user()` — `AuthManager::__call` يُحيل إلى الحارس.
 *
 * **والاستثناءُ هنا محسوبٌ من الشيفرةِ نفسِها لا مكتوبٌ باليد:** أسماءُ الدوالّ
 * تُقرأ بالانعكاس من `Eloquent\Builder` و`Query\Builder` ومن نطاقاتِ
 * `scopeX` في `app/Models` — فـ`Task::whre()` (خطأٌ إملائيّ) **يبقى خطأً**،
 * و`Task::where()` يمرّ. وترقيةُ Laravel تُحدّث القائمةَ وحدَها.
 *
 * وما لا يُحسَب بدقّة (الخاصّيّاتُ: لا مخطّطَ يُقرأ هنا) يُستثنى بمعرِّفِه
 * ونمطِ صنفِه — ويُقال ذلك في `docs/ARCHITECTURE.md` حدّاً للأداة لا ميزة.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$q = static fn (array $names): string => implode('|', array_map(
    static fn ($n) => preg_quote($n, '#'), array_values(array_unique($names))));

$methodsOf = static function (string $class, bool $wantStatic = false): array {
    $out = [];
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->name[0] === '_' || $m->isStatic() !== $wantStatic) continue;
        $out[] = $m->name;
    }

    return $out;
};

// ماكروهاتُ SoftDeletes تُسجَّل على المُنشئ وقتَ التشغيل لا في الصنف
$softDeletes = ['withTrashed', 'withoutTrashed', 'onlyTrashed', 'restore', 'restoreOrCreate', 'createOrRestore'];

// ── ١. المُنشئُ عبر النموذج ──
$builder = array_merge(
    $methodsOf(Illuminate\Database\Eloquent\Builder::class),
    $methodsOf(Illuminate\Database\Query\Builder::class),
    $softDeletes,
);

// ── والنطاقاتُ المحلّيّة `scopeX` في نماذج التطبيق (والسماتِ التي تستعملها) ──
$scopes = [];
foreach (glob(__DIR__ . '/../../app/Models/*.php') as $file) {
    $class = 'App\\Models\\' . basename($file, '.php');
    if (! class_exists($class)) continue;
    foreach ((new ReflectionClass($class))->getMethods() as $m) {
        if (strlen($m->name) > 5 && str_starts_with($m->name, 'scope') && ctype_upper($m->name[5])) {
            $scopes[] = lcfirst(substr($m->name, 5));
        }
    }
}

// ── ٣. الحارسُ عبر مديرِ المصادقة ──
$guard = array_merge(
    $methodsOf(Illuminate\Contracts\Auth\Guard::class),
    $methodsOf(Illuminate\Contracts\Auth\StatefulGuard::class),
    $methodsOf(Illuminate\Auth\SessionGuard::class),
);

return ['parameters' => ['ignoreErrors' => [
    [
        'message' => '#^Call to an undefined static method App\\\\Models\\\\\w+::(?:' . $q(array_merge($builder, $scopes)) . ')\(\)\.$#',
        'identifier' => 'staticMethod.notFound',
        'reportUnmatched' => false,
    ],
    [
        // `Task::query()->active()` — النطاقُ على المُنشئ المُعمَّم
        'message' => '#^Call to an undefined method Illuminate\\\\Database\\\\Eloquent\\\\Builder<[^>]+>::(?:' . $q(array_merge($scopes, $softDeletes)) . ')\(\)\.$#',
        'identifier' => 'method.notFound',
        'reportUnmatched' => false,
    ],
    [
        // `@mixin Query\Builder` على مُنشئ Eloquent: بعد نداءٍ مُحالٍ (`orderBy`) يظنّ phpstan
        // السلسلةَ مُنشئَ استعلامٍ خاماً — فدالّةُ Eloquent (`with`/`whereHas`) أو نطاقٌ بعدها «غائبة»
        'message' => '#^Call to an undefined method Illuminate\\\\Database\\\\Query\\\\Builder::(?:' . $q(array_merge($methodsOf(Illuminate\Database\Eloquent\Builder::class), $softDeletes, $scopes)) . ')\(\)\.$#',
        'identifier' => 'method.notFound',
        'reportUnmatched' => false,
    ],
    [
        // ٢. أعمدةُ Eloquent — على النموذجِ المسمّى، والعامِّ، ومستخدمِ المصادقة
        'message' => '#^Access to an undefined property (?:App\\\\Models\\\\\w+|Illuminate\\\\Database\\\\Eloquent\\\\Model|Illuminate\\\\Contracts\\\\Auth\\\\Authenticatable)(?:\|[^:]+)?::\$\w+\.$#',
        'identifier' => 'property.notFound',
        'reportUnmatched' => false,
    ],
    [
        'message' => '#^Call to an undefined method Illuminate\\\\Contracts\\\\Auth\\\\Factory::(?:' . $q($guard) . ')\(\)\.$#',
        'identifier' => 'method.notFound',
        'reportUnmatched' => false,
    ],
    [
        // `optional($x)->y` — سحرٌ بتعريفِه: كلُّ نداءٍ وكلُّ خاصّيّةٍ تمرّ
        'message' => '#^(?:Call to an undefined method|Access to an undefined property) Illuminate\\\\Support\\\\Optional::#',
        'reportUnmatched' => false,
    ],
]]];
