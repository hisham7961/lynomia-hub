    <div class="card kid">
        <h3>⚖️ مراجعة الأدوار والصلاحيات</h3>
        <table class="mini">
            @foreach ($roles as $r)
                <tr>
                    <td>{{ $r->name }} @if ($r->is_owner)<span class="bdg bad">مالك — صلاحية مطلقة</span>@endif
                        <div class="sub">{{ $r->users }} مستخدم · {{ $r->is_owner ? 'كل الوحدات' : $r->mods . ' وحدة' }}{{ $r->scope === 'proj' ? ' · محدود بمشاريعه' : '' }}{{ count($r->flags) ? ' · أعلام: ' . implode('، ', $r->flags) : '' }}</div></td>
                </tr>
            @endforeach
        </table>
        <div style="margin-top:8px"><a class="btn ghost xs" href="{{ route('roles.index') }}">إدارة الأدوار ←</a></div>
    </div>
