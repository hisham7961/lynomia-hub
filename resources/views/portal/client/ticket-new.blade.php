{{--
    بلاغٌ جديد (الجولة 2 · G7) — نموذجٌ بأربعة حقولٍ لا غير: الموضوع والوصف
    والأولويّة والمشروع. ما سواه يُختم خادميّاً (الحالة «جديدة»، والقناة، والمنسوبُ
    إليه، وعميلُه) — فلا يُسنِد العميلُ لأحدٍ ولا يكتب حالةً ولا ملاحظةً داخليّة.
    وقائمةُ المشاريع **مشاريعُه هو**، والخادمُ يُعيد الفحصَ عند الحفظ (٤٠٤ لغيرها).
--}}
@extends('layouts.portal')
@section('title', 'بلاغ جديد')
@section('content')

<div class="hero">
    <div><h2>🎫 أبلغ عن مشكلة</h2>
        <div class="sub">صف ما يحدث وسيصلك ردُّ الفريق على هذه الصفحة.</div></div>
    <a class="btn ghost sm" href="{{ route('portal.tickets') }}">← تذاكري</a>
</div>

<div class="card">
    <form method="POST" action="{{ route('portal.ticket.store') }}">
        @csrf
        <div class="fld @error('subject') haserr @enderror">
            <label for="t-subject">الموضوع <b class="req" aria-hidden="true">*</b></label>
            <input class="inp @error('subject') err @enderror" id="t-subject" name="subject" required
                   maxlength="300" value="{{ old('subject') }}" placeholder="مثال: الموقع يعطي خطأ 502 منذ الصباح">
            @error('subject')<span class="ferr">{{ $message }}</span>@enderror
        </div>

        <div class="fld fw @error('body') haserr @enderror">
            <label for="t-body">الوصف <b class="req" aria-hidden="true">*</b></label>
            <textarea class="inp @error('body') err @enderror" id="t-body" name="body" rows="5" required
                      maxlength="5000" placeholder="ما الذي حدث؟ ومتى بدأ؟ وما الخطوات التي تصل إليه؟">{{ old('body') }}</textarea>
            @error('body')<span class="ferr">{{ $message }}</span>@enderror
        </div>

        <div class="fld @error('priority') haserr @enderror">
            <label for="t-priority">الأولوية <b class="req" aria-hidden="true">*</b></label>
            <select class="inp @error('priority') err @enderror" id="t-priority" name="priority" required>
                @foreach ($priorities as $p)
                    <option value="{{ $p }}" @selected(old('priority', 'متوسطة') === $p)>{{ $p }}</option>
                @endforeach
            </select>
            @error('priority')<span class="ferr">{{ $message }}</span>@enderror
        </div>

        <div class="fld @error('project') haserr @enderror">
            <label for="t-project">المشروع</label>
            <select class="inp @error('project') err @enderror" id="t-project" name="project">
                <option value="">— بلا مشروع محدّد —</option>
                @foreach ($projects as $p)
                    <option value="{{ $p->id }}" @selected((string) old('project') === (string) $p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
            @error('project')<span class="ferr">{{ $message }}</span>@enderror
        </div>

        {{-- حسابٌ على أكثر من عميل: يقول لأيّهم البلاغ بدل أن يُخمَّن له --}}
        @if (($clients->count() ?? 0) > 1)
            <div class="fld @error('client') haserr @enderror">
                <label for="t-client">الجهة</label>
                <select class="inp" id="t-client" name="client">
                    @foreach ($clients as $c)
                        <option value="{{ $c->id }}" @selected((string) old('client') === (string) $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
                @error('client')<span class="ferr">{{ $message }}</span>@enderror
            </div>
        @endif

        <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn p" type="submit">إرسال البلاغ</button>
            <a class="btn ghost" href="{{ route('portal.tickets') }}">إلغاء</a>
        </div>
    </form>
</div>

@endsection
