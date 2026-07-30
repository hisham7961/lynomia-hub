{{-- قسم التعليقات — يتوقع: $cModule $cRecordId $comments $users (id=>name) --}}
<div class="card" id="comments">
    <h3 style="margin-bottom:10px">💬 التعليقات <span class="bdg g">{{ $comments->count() }}</span></h3>

    @forelse ($comments as $c)
        @include('partials._comment', ['c' => $c, 'users' => $users, 'depth' => 0])
    @empty
        <div class="sub" style="padding:8px 0 14px">لا تعليقات بعد — كن أول من يعلّق</div>
    @endforelse

    <form method="POST" action="{{ route('comments.store') }}" enctype="multipart/form-data" class="cform">
        @csrf
        <input type="hidden" name="module" value="{{ $cModule }}">
        <input type="hidden" name="record_id" value="{{ $cRecordId }}">
        <textarea class="inp" name="body" rows="2" required maxlength="4000"
                  placeholder="اكتب تعليقاً… استخدم @الاسم لذكر زميل"></textarea>
        <div class="crow">
            <select class="inp" name="mention[]" multiple size="1" title="ذكر زملاء (اختياري)">
                @foreach ($users as $uid => $name)
                    @if ($uid !== auth()->id())<option value="{{ $uid }}">@ {{ $name }}</option>@endif
                @endforeach
            </select>
            <input class="inp" type="file" name="att" title="مرفق (اختياري)">
            <button class="btn p sm" type="submit">نشر</button>
        </div>
        @error('body')<div class="err">{{ $message }}</div>@enderror
    </form>
</div>
