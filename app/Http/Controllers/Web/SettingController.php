<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    protected array $keys = [
        'app.name'     => 'اسم النظام',
        'app.currency' => 'العملة الافتراضية',
        'app.company'  => 'اسم الشركة الأم',
    ];

    protected function gate(): void
    {
        abort_unless(auth()->user()?->role?->is_owner, 403);
    }

    public function edit()
    {
        $this->gate();
        $values = collect($this->keys)->map(fn ($label, $k) => setting($k, ''));

        return view('settings.form', ['keys' => $this->keys, 'values' => $values]);
    }

    public function update(Request $r)
    {
        $this->gate();
        foreach (array_keys($this->keys) as $k) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $r->input(str_replace('.', '_', $k), '')]);
        }
        Cache::forget('settings:all');

        return back()->with('ok', 'حُفظت الإعدادات');
    }
}
