    <div class="card kid">
        <h3>🪄 عدّة الانطلاق</h3>
        <div class="sub" style="margin-bottom:8px">
            مسارات عمل جاهزة + قواعد تنبيه متوقفة (تفعّلها من شاشتها) +
            <b>مكتبة مؤشرات KPI</b> محسوبةً من بياناتك —
            تُنشأ مرةً واحدة بالاسم فلا تكرار مهما ضغطت.
        </div>
        <form method="POST" action="{{ route('ops.starters') }}"
              data-confirm="توليد مسارات العمل وقواعد التنبيه ومكتبة مؤشرات KPI الجاهزة الآن؟">
            @csrf<button class="btn ghost xs">🪄 توليد العدّة الآن</button>
        </form>
    </div>
