<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_flag(auth()->user(), 'users'), 403);
    }

    public function index(Request $r)
    {
        $this->gate();
        $users = User::with('role')
            ->when($r->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'LIKE', "%$t%")->orWhere('email', 'LIKE', "%$t%")))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create()
    {
        $this->gate();
        return view('users.form', ['u' => null, 'roles' => Role::orderBy('name')->get()]);
    }

    public function store(Request $r)
    {
        $this->gate();
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:40',
            'job_title' => 'nullable|string|max:120',
            'role_id' => 'required|exists:roles,id',
            'status' => 'required|in:نشط,موقوف',
            'password' => ['required', 'string', password_rules()],
        ]);
        User::create($data);

        return redirect()->route('users.index')->with('ok', 'أُضيف المستخدم');
    }

    public function edit(User $user)
    {
        $this->gate();
        return view('users.form', ['u' => $user, 'roles' => Role::orderBy('name')->get()]);
    }

    public function update(Request $r, User $user)
    {
        $this->gate();
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => 'nullable|string|max:40',
            'job_title' => 'nullable|string|max:120',
            'role_id' => 'required|exists:roles,id',
            'status' => 'required|in:نشط,موقوف',
            'password' => ['nullable', 'string', password_rules()],
        ]);
        if (empty($data['password'])) unset($data['password']);
        $user->update($data);

        return redirect()->route('users.index')->with('ok', 'حُفظ المستخدم');
    }

    public function destroy(User $user)
    {
        $this->gate();
        abort_if($user->id === auth()->id(), 422, 'لا يمكنك حذف حسابك الحالي');
        $user->delete();

        return redirect()->route('users.index')->with('ok', 'حُذف المستخدم');
    }
}
