// قياسُ أداءٍ تحت حِمل — أوّلُ قياسٍ في المشروع. كلُّ رقمٍ مقيسٌ لا مُقدَّر.
import { chromium } from 'playwright';
const BASE = 'http://127.0.0.1:8095';

const PAGES = ['/admin/roles','/morning','/collab','/reports/daily'];
const CONC = parseInt(process.argv[2] || '10', 10);   // مستخدمون متزامنون
const ROUNDS = parseInt(process.argv[3] || '4', 10);  // جولاتٌ لكلِّ صفحة

// جلسةُ المالكِ مرّةً، ثمّ تُعاد الكعكةُ في كلِّ طلب
const b = await chromium.launch();
const ctx = await b.newContext();
const pg = await ctx.newPage();
await pg.goto(BASE + '/login', { waitUntil:'domcontentloaded' });
await pg.fill('input[name="email"]','owner@lynomia.com');
await pg.fill('input[name="password"]','Demo!2026x');
await Promise.all([pg.waitForLoadState('domcontentloaded'), pg.click('button[type="submit"]')]);
if (pg.url().includes('/login')) { console.error('login failed'); process.exit(1); }
const cookies = (await ctx.cookies()).map(c=>`${c.name}=${c.value}`).join('; ');
await b.close();

const pct = (a,p) => a.length ? a.slice().sort((x,y)=>x-y)[Math.min(a.length-1, Math.floor(a.length*p))] : 0;

async function hit(path) {
  const t0 = performance.now();
  try {
    const r = await fetch(BASE + path, { headers: { cookie: cookies }, redirect:'manual' });
    await r.arrayBuffer();
    return { ms: performance.now() - t0, status: r.status };
  } catch (e) { return { ms: performance.now() - t0, status: 0 }; }
}

console.log(`حِمل: ${CONC} متزامنين × ${ROUNDS} جولات لكلِّ صفحة\n`);
console.log('الصفحة'.padEnd(18), 'ن'.padStart(4), 'p50'.padStart(7), 'p95'.padStart(7),
            'أقصى'.padStart(7), 'أخطاء'.padStart(6));
const report = [];
for (const p of PAGES) {
  const all = [];
  let bad = 0;
  for (let r = 0; r < ROUNDS; r++) {
    const batch = await Promise.all(Array.from({length: CONC}, () => hit(p)));
    for (const x of batch) { all.push(x.ms); if (x.status >= 400 || x.status === 0) bad++; }
  }
  const row = { page:p, n:all.length, p50:Math.round(pct(all,0.5)), p95:Math.round(pct(all,0.95)),
                max:Math.round(Math.max(...all)), errors:bad };
  report.push(row);
  console.log(p.padEnd(18), String(row.n).padStart(4), String(row.p50).padStart(7),
              String(row.p95).padStart(7), String(row.max).padStart(7), String(bad).padStart(6));
}
console.log('\nJSON:' + JSON.stringify(report));
