/*!
 * lynomia-graph — عارضُ جرافِ العلاقات المحليّ (Work OS · الطور H · WP-H.2)
 *
 * ذاتيُّ التأليف وبلا أيّ اعتماد: لا مكتبةَ من الشبكة ولا خطوةَ بناء —
 * ملفٌّ واحدٌ يُخدَم من public/ (سابقةُ leaflet المحلية في هذا المستودع).
 * البياناتُ من الحمولة المضمَّنة في الصفحة (#graph-data) — المُرشَّحةُ خادميّاً
 * في RelationshipProjection — والعارضُ **يرسم ولا يُرشِّح**: لا قرارَ رؤيةٍ
 * واحداً هنا. التوسّعُ التدريجيُّ يطلب /graph/expand (نفسُ الأصل، نفسُ الحرّاس)
 * ويدمج ما يعود. الشجرةُ `<ul>` في الصفحة هي الأصلُ الدلاليُّ الكامل — هذا
 * الوضعُ إضاءةٌ بصريّةٌ فوقها لا بديلٌ عنها.
 */
(function () {
    'use strict';

    var dataEl = document.getElementById('graph-data');
    var svg = document.getElementById('gsvg');
    var viz = document.getElementById('gviz');
    var tree = document.getElementById('gtree');
    var btnTree = document.getElementById('gmode-tree');
    var btnViz = document.getElementById('gmode-viz');
    if (!dataEl || !svg || !viz || !tree) return;

    var P;
    try { P = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
    if (!P || !P.nodes || !P.nodes.length) return;

    // فضاءُ أسماء SVG من العنصر المضمَّن نفسِه — مُعرِّفُ XML لا طلبُ شبكة،
    // وقراءتُه من الـDOM تُبقي هذا الملفَّ خالياً من أيّ عنوانٍ مكتوب
    var NS = svg.namespaceURI;
    function el(tag, attrs) {
        var e = document.createElementNS(NS, tag);
        for (var k in attrs) e.setAttribute(k, attrs[k]);
        return e;
    }

    /* ── تبديلُ الوضعَين — el.hidden لا style.display (اصطلاحُ المنصّة) ── */
    function mode(showViz) {
        viz.hidden = !showViz;
        tree.hidden = showViz;
        if (btnTree) btnTree.className = showViz ? 'btn ghost sm' : 'btn sm p';
        if (btnViz) btnViz.className = showViz ? 'btn sm p' : 'btn ghost sm';
        if (showViz) render();
    }
    if (btnTree) btnTree.addEventListener('click', function () { mode(false); });
    if (btnViz) btnViz.addEventListener('click', function () { mode(true); });

    /* ── دمجُ توسّعٍ قادمٍ من الخادم: عُقدٌ وحوافُّ جديدةٌ فقط (المفتاحُ حَكَم) ── */
    function merge(q) {
        var have = {};
        P.nodes.forEach(function (n) { have[n.key] = true; });
        (q.nodes || []).forEach(function (n) {
            if (!have[n.key]) { have[n.key] = true; P.nodes.push(n); }
        });
        var ek = {};
        P.edges.forEach(function (e) { ek[e.from + '|' + e.to + '|' + e.via] = true; });
        (q.edges || []).forEach(function (e) {
            var k = e.from + '|' + e.to + '|' + e.via;
            if (!ek[k]) { ek[k] = true; P.edges.push(e); }
        });
        if (q.capped) P.capped = true;
    }

    /* ── توسيعُ عقدةٍ قفزةً واحدة — من الخادم بحرّاسه هو، لا من ذاكرة الصفحة ── */
    var busy = {};
    function expandNode(n) {
        if (busy[n.key]) return;
        busy[n.key] = true;
        var u = viz.dataset.expand + '?m=' + encodeURIComponent(n.module)
              + '&id=' + encodeURIComponent(n.id) + '&hops=1';
        fetch(u, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (q) {
                if (q) {
                    // قفزةُ الجارِ الجديد تُنسب لبُعده عن الجذر الحاليّ
                    (q.nodes || []).forEach(function (x) { x.hop = n.hop + x.hop; });
                    merge(q);
                    render();
                }
            })
            .catch(function () { /* فشلُ الشبكة لا يكسر الصفحة — الشجرةُ الدلاليّة قائمة */ })
            .then(function () { busy[n.key] = false; });
    }

    /* ── التخطيطُ الشعاعيّ الحتميّ: الجذرُ مركزاً وكلُّ قفزةٍ حلقة — ترتيبُ الحمولة كما هو ── */
    function render() {
        while (svg.firstChild) svg.removeChild(svg.firstChild);

        var W = Math.max(svg.clientWidth || 0, 640), H = Math.max(420, 180 + 150 * (P.hops || 1));
        svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
        var cx = W / 2, cy = H / 2;

        var rings = {};
        P.nodes.forEach(function (n) { (rings[n.hop] = rings[n.hop] || []).push(n); });

        var pos = {};
        Object.keys(rings).map(Number).sort(function (a, b) { return a - b; }).forEach(function (hop) {
            var ns = rings[hop];
            if (hop === 0) { ns.forEach(function (n) { pos[n.key] = [cx, cy]; }); return; }
            var r = Math.min(cx, cy) * hop / ((P.hops || 1) + 0.6);
            ns.forEach(function (n, i) {
                var a = (2 * Math.PI * i) / ns.length - Math.PI / 2;
                pos[n.key] = [cx + r * Math.cos(a), cy + r * Math.sin(a)];
            });
        });

        // الحوافُّ أولاً (تحت العُقد) — كلُّها بين طرفَين مُدرَجَين بالبناء
        P.edges.forEach(function (e) {
            var a = pos[e.from], b = pos[e.to];
            if (!a || !b) return;
            svg.appendChild(el('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1],
                stroke: 'currentColor', 'stroke-opacity': '0.25', 'stroke-width': '1.2' }));
        });

        P.nodes.forEach(function (n) {
            var p = pos[n.key];
            if (!p) return;
            var g = el('g', { transform: 'translate(' + p[0] + ',' + p[1] + ')', cursor: 'pointer' });
            g.appendChild(el('circle', { r: n.hop === 0 ? 14 : 9,
                fill: n.hop === 0 ? '#6d28d9' : '#0e7490', 'fill-opacity': '0.85',
                stroke: 'currentColor', 'stroke-opacity': '0.35' }));
            var t = el('text', { y: n.hop === 0 ? 30 : 24, 'text-anchor': 'middle',
                'font-size': '11', fill: 'currentColor', direction: 'rtl' });
            t.textContent = (n.label || '').slice(0, 28);
            g.appendChild(t);
            var title = el('title', {});
            title.textContent = n.module + ' — ' + n.label + ' (قفزة ' + n.hop + ')';
            g.appendChild(title);

            g.addEventListener('click', function () { expandNode(n); });
            g.addEventListener('dblclick', function () {
                window.location = viz.dataset.explore + '?m=' + encodeURIComponent(n.module)
                    + '&id=' + encodeURIComponent(n.id) + '&hops=' + (viz.dataset.hops || 2);
            });
            svg.appendChild(g);
        });

        // السقفُ الصريح يُقال في الوضعَين — لا اقتطاعَ يتوارى خلف رسمٍ جميل
        if (P.capped) {
            var w = el('text', { x: cx, y: 18, 'text-anchor': 'middle',
                'font-size': '12', fill: '#d97706', direction: 'rtl' });
            w.textContent = '⚠️ أُوقِف الإسقاطُ عند حدّ العُقد (' + P.max_nodes + ')';
            svg.appendChild(w);
        }
    }
})();
