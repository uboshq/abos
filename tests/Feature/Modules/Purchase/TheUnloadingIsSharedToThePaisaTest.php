<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Modules\Purchase\Services\UnloadingSplit;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ আনলোডিংয়ের ভাগ পয়সা পর্যন্ত, যোগফল হুবহু — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"; ২০ নভেম্বর ২০২৬ দাবি; [[UnloadingSplit::split()]])।
 *
 * ⓘ মালিকের উদাহরণ: ১০০ কার্টন, ৫০ টাকা, ১০ কার্টন হাতে ১ টাকা = ১০ → বাকি ৪০ টাকা ৯০ কার্টনে কার্টনপ্রতি সমান।
 * ⛔ প্রতিটা ভাগ পয়সায় গোল, শেষ সমান-ভাগের সারি বাকিটা নেয় — নইলে তিন ভাগের ৩.৩৩ × ৩ = ৯.৯৯, এক পয়সা হারাত।
 */
final class TheUnloadingIsSharedToThePaisaTest extends TestCase
{
    public function test_the_owners_example_gives_ten_then_shares_forty_per_carton(): void
    {
        $shares = UnloadingSplit::split('50', [
            'big' => ['cartons' => '10', 'rate' => '1'],
            'a' => ['cartons' => '30', 'rate' => null],
            'b' => ['cartons' => '60', 'rate' => null],
        ]);

        $this->assertSame(['big' => '10.0000', 'a' => '13.3300', 'b' => '26.6700'], $shares,
            '⛔ মালিকের উদাহরণ মেলেনি — হাতের সারি ১০, বাকি ৪০ কার্টনপ্রতি ০.৪৪৪৪…, শেষ সারি বাকিটা।');
        $this->assertSame('50.0000', $this->sum($shares), '⛔ যোগফল দেওয়া অঙ্ক নয়।');
    }

    public function test_the_paisa_left_by_rounding_lands_on_the_last_shared_line(): void
    {
        $shares = UnloadingSplit::split('10', [
            1 => ['cartons' => '1', 'rate' => null],
            2 => ['cartons' => '1', 'rate' => null],
            3 => ['cartons' => '1', 'rate' => null],
        ]);

        $this->assertSame([1 => '3.3300', 2 => '3.3300', 3 => '3.3400'], $shares, '⛔ গোল করার বাকি পয়সা শেষ সারিতে যায়নি।');
        $this->assertSame('10.0000', $this->sum($shares));

        // ⓘ হাতের সারি শেষে থাকলেও বাকি পয়সা শেষ **সমান-ভাগের** সারিতে
        $mixed = UnloadingSplit::split('20', [
            'x' => ['cartons' => '1', 'rate' => null],
            'y' => ['cartons' => '2', 'rate' => null],
            'z' => ['cartons' => '5', 'rate' => '2'],
        ]);
        $this->assertSame(['x' => '3.3300', 'y' => '6.6700', 'z' => '10.0000'], $mixed);
        $this->assertSame('20.0000', $this->sum($mixed));
    }

    public function test_when_every_line_is_hand_set_the_rest_goes_to_the_last_line(): void
    {
        $shares = UnloadingSplit::split('15', [
            'a' => ['cartons' => '5', 'rate' => '1'],
            'b' => ['cartons' => '5', 'rate' => '1'],
        ]);

        $this->assertSame(['a' => '5.0000', 'b' => '10.0000'], $shares, '⛔ সবাই হাতে বসানো — বাকি ৫ টাকা শেষ সারিতে যায়নি।');
        $this->assertSame('15.0000', $this->sum($shares));
    }

    public function test_hand_set_lines_above_the_amount_paid_are_refused(): void
    {
        try {
            UnloadingSplit::split('5', ['big' => ['cartons' => '10', 'rate' => '1'], 'small' => ['cartons' => '5', 'rate' => null]]);
            $this->fail('⛔ হাতের সারিগুলো (১০) দেওয়া অঙ্কের (৫) বেশি, তবু ভাগ হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('unload_amount', $e->errors());
        }
    }

    public function test_nothing_paid_shares_nothing(): void
    {
        $this->assertSame(['a' => '0.0000', 'b' => '0.0000'],
            UnloadingSplit::split('0', ['a' => ['cartons' => '3', 'rate' => '1'], 'b' => ['cartons' => '4', 'rate' => null]]));
        $this->assertSame([], UnloadingSplit::split('50', []));
    }

    public function test_the_expected_amount_is_cartons_times_their_rate(): void
    {
        // ⓘ ১০ কার্টন × ১ + ৯০ কার্টন × ০.৪০ = ৪৬.০০
        $this->assertSame('46.00', UnloadingSplit::expected([
            ['cartons' => '10', 'rate' => '1'],
            ['cartons' => '90', 'rate' => null],
        ], '0.40'));

        $this->assertSame('2.5000', UnloadingSplit::cartons('30', '12'), 'কার্টন = মূল একক ÷ প্যাকের মাপ');
        $this->assertSame('30.0000', UnloadingSplit::cartons('30', '0'), 'মাপ শূন্য হলে মূল এককই কার্টন');
    }

    /** @param  array<int|string, string>  $shares */
    private function sum(array $shares): string
    {
        return array_reduce($shares, fn (string $s, string $v) => bcadd($s, $v, 4), '0');
    }
}
