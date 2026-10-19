<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Http\Middleware\RemembersAPhoneWrite;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * ⭐ ফোন যে লেখায় একবারের চাবি পাঠায়, সার্ভার সেই চাবি মনে রাখে — পূর্ণ নিরীক্ষা §৯ ⚠️৮, ১৮ অক্টোবর ২০২৬।
 *
 * ⛔ আগে অনুমোদন দেওয়া/ফেরানো/ফেরত, ভাউচার পাকা, গেট-আউট, ডেলিভারি, ফেরত নিশ্চিত, DO আর অর্ডার জমা, অনুমোদিত পরিমাণ,
 * উদ্ধৃতি জমা/ফেরানো আর লোডিং-এর "প্যাক হয়েছে" — চাবি এলেও সার্ভার দেখত না: উত্তর হারিয়ে ফোন আবার পাঠালে দ্বিতীয়বার
 * চলত (দ্বিতীয় সই, দ্বিতীয় গেট-আউটের চেষ্টা)।
 *
 * ⓘ ঐচ্ছিক ধরন (চাবি ছাড়া পুরনো বিল্ড চলে); চাবি থাকলে একই উত্তর ফেরে ([[RemembersAPhoneWrite]], যার নিজের আচরণের দাবি আলাদা)।
 */
final class EveryKeyedPhoneWriteIsRememberedTest extends TestCase
{
    /** ফোন [OnceKey] দিয়ে পাঠায় এমন দরজা — নামের শেষাংশ */
    private const KEYED = [
        'approvals.approve',
        'approvals.reject',
        'approvals.send_back',
        'voucher.post',
        'scan.gate_out',
        'scan.deliver',
        'loading.packed',
        'return.confirm',
        'delivery_order.submit',
        'delivery_order.approved_quantities',
        'order.submit',
        'order.approved_quantities',
        'quotation.submit',
        'quotation.reject',
        // ⓘ আগে থেকেই (a5, 390d4c841) — এখানে থাকে যাতে কেউ সরালে লাল হয়
        'register.leave_approve',
        'register.leave_reject',
        'delegation.store',
        'delegation.destroy',
        'desk.print_settle',
    ];

    public function test_every_keyed_phone_write_goes_through_the_memory(): void
    {
        $missing = [];

        foreach (self::KEYED as $suffix) {
            $routes = array_values(array_filter(
                Router::getRoutes()->getRoutes(),
                fn (Route $r) => str_starts_with((string) $r->uri(), 'api/') && str_ends_with((string) $r->getName(), '.'.$suffix),
            ));

            $this->assertNotEmpty($routes, '⛔ দাবির ভিত্তি নেই — ফোনের দরজা '.$suffix.' পাওয়া গেল না');

            foreach ($routes as $route) {
                $remembers = array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, RemembersAPhoneWrite::class));
                if ($remembers === []) {
                    $missing[] = $route->getName().' ('.$route->uri().')';
                }
            }
        }

        $this->assertSame([], $missing, '⛔ ফোন চাবি পাঠায়, সার্ভার মনে রাখে না — আবার পাঠালে দ্বিতীয়বার চলে');
    }
}
