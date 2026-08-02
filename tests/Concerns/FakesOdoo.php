<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * محاكاة خادم أودو في الاختبارات — لا شيء في الحزمة كان يحاكي `/jsonrpc`.
 *
 * الخريطة تُفرِّق بالنداء: `common.authenticate` و`common.version` بمفتاحيهما،
 * و`execute_kw` بمفتاح `model.method` (مثل `sale.order.search_read`).
 * القيمة إمّا نتيجة جاهزة وإمّا closure تستلم (args, kw) وتعيد النتيجة.
 * والقيمة `['#error' => 'نص']` تعيد خطأ JSON-RPC كما يعيده أودو.
 */
trait FakesOdoo
{
    /** @param array<string, mixed> $map */
    protected function fakeOdoo(string $base, array $map): void
    {
        Http::fake([
            rtrim($base, '/') . '/jsonrpc' => function ($req) use ($map) {
                $p = $req->data()['params'] ?? [];
                $service = (string) ($p['service'] ?? '');
                $method = (string) ($p['method'] ?? '');
                $args = (array) ($p['args'] ?? []);

                if ($service === 'common') {
                    $key = 'common.' . $method;
                    $callArgs = $args; $kw = [];
                } else {                                  // object.execute_kw
                    // args: [db, uid, key, model, method, args, kw]
                    $key = ($args[3] ?? '?') . '.' . ($args[4] ?? '?');
                    $callArgs = (array) ($args[5] ?? []);
                    $kw = (array) ($args[6] ?? []);
                }

                $hit = $map[$key] ?? null;
                if ($hit === null) {
                    return Http::response(['jsonrpc' => '2.0',
                        'error' => ['message' => 'لا محاكاة للنداء: ' . $key]], 200);
                }
                $result = is_callable($hit) ? $hit($callArgs, $kw) : $hit;
                if (is_array($result) && array_key_exists('#error', $result)) {
                    return Http::response(['jsonrpc' => '2.0',
                        'error' => ['message' => (string) $result['#error']]], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'result' => $result], 200);
            },
        ]);
    }
}
