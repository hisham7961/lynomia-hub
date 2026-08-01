    {{-- **صندوقُ كتابةٍ حاضرٌ دائماً**: كان اللوحُ فارغاً بعبارة «اختر
         محادثة» — ومن دخل أولَ مرة لا يجد أين يكتب ولا زراً يُرسل.
         الآن الرسالةُ الأولى: تختار الزميل وتكتب وترسل في خطوةٍ واحدة. --}}
    <div class="card">
        <h3>✉️ رسالة جديدة</h3>
        <div class="sub" style="margin-bottom:10px">
            اختر زميلاً واكتب رسالتك — تصل فوراً مع إشعار، ولا يقرؤها غيرُكما.
        </div>
        @if ($errors->any())<div class="err" role="alert">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('dm.start') }}" enctype="multipart/form-data">
            @csrf
            <div class="fld" style="max-width:380px">
                <label for="new-to">إلى <b class="req">*</b></label>
                <select class="inp" id="new-to" name="to" required>
                    <option value="">— اختر زميلاً —</option>
                    @foreach ($all as $uid => $name)
                        <option value="{{ $uid }}" @selected(old('to') === $uid)>{{ $name }}</option>
                    @endforeach
                </select>
                @if (! count($all))<span class="sub">لا زملاء نشطون بعد — أضِف مستخدماً أولاً</span>@endif
            </div>
            <div class="fld fw" style="margin-top:8px">
                <label for="new-body">الرسالة <b class="req">*</b></label>
                <textarea class="inp" id="new-body" name="body" rows="4" required maxlength="4000"
                          placeholder="اكتب رسالتك هنا…">{{ old('body') }}</textarea>
            </div>
            <div class="toolbar" style="margin-top:10px">
                <button class="btn p" type="submit">📨 إرسال</button>
                <label class="btn ghost pointer">📎 أرفق ملفاً<input type="file" name="att" class="vh"></label>
            </div>
        </form>
    </div>
