<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ গ্রাহকের কাগজের খোলা লিংকে গতির সীমা ছিল না (পুরো-ERP অডিট, নিরাপত্তা; fe, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ লগইন ছাড়া খোলা পথ, তাই একই আইপি থেকে মিনিটে ৬০-এর পরে ৪২৯। ⚠️ নিজের ঝুড়িতে: অন্য দরজার গোনা এখানে যোগ হয় না।
 */
final class ASharedLinkHasASpeedLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_paper_link_stops_one_address_after_sixty_tries_a_minute(): void
    {
        $guess = fn (int $i) => route('paper.shared', str_pad((string) $i, 64, 'a', STR_PAD_LEFT));

        foreach (range(1, 60) as $i) {
            $this->get($guess($i))->assertNotFound();
        }

        $this->get($guess(61))->assertStatus(429);

        // ⓘ অন্য আইপি নিজের গোনায়
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->get($guess(62))->assertNotFound();
    }
}
