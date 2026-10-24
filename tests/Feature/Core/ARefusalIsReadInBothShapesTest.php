<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\ReadsTheRefusal;
use Tests\TestCase;

/**
 * ⛔ ফেরানো অনুরোধের কারণ পড়া — সেশনের ভুলের থলে দুই আকারেই ([[ReadsTheRefusal]]; fe, ২৪ অক্টোবর ২০২৬)।
 *
 * <p>সেশন json-এ রাখা হয়, তাই পরীক্ষায় থলেটা কখনো ViewErrorBag, কখনো সাধারণ অ্যারে ({default: {format, messages}})।
 * `session('errors')->all()` অ্যারেতে Error দেয়, আর `assertSessionHasErrors` ভাঙলে দাবির নিজের বার্তা নয়, PHPUnit-এর
 * "Session is missing expected key [errors]" — মিউট্যান্ট সেই বার্তায় মরলে কোন পাহারা ভাঙল বোঝা যায় না। সাহায্যকারীটা দুই
 * আকারই পড়ে; এই দাবি দেখায় যে দুই আকারে একই কারণ আসে, আর থলে না থাকলে কিছুই আসে না।
 */
final class ARefusalIsReadInBothShapesTest extends TestCase
{
    use ReadsTheRefusal;

    public function test_the_bag_shape_gives_the_reason(): void
    {
        session()->put('errors', (new ViewErrorBag)->put('default', new MessageBag(['amount' => ['বকেয়ার বেশি']])));

        $this->assertSame('বকেয়ার বেশি', $this->refusalOn('amount'), '⛔ ViewErrorBag আকারে কারণটা পড়া গেল না');
        $this->assertSame(['amount' => ['বকেয়ার বেশি']], $this->refusalMessages(), '⛔ ViewErrorBag আকারের সব কারণ এল না');
        $this->assertNull($this->refusalOn('reason'), '⛔ যে ঘরে কারণ নেই, সেখানেও কিছু এল');
    }

    public function test_the_json_array_shape_gives_the_same_reason(): void
    {
        session()->put('errors', ['default' => ['format' => ':message', 'messages' => ['amount' => ['বকেয়ার বেশি']]]]);

        $this->assertSame('বকেয়ার বেশি', $this->refusalOn('amount'), '⛔ json-অ্যারে আকারে কারণটা পড়া গেল না');
        $this->assertSame(['amount' => ['বকেয়ার বেশি']], $this->refusalMessages(), '⛔ json-অ্যারে আকারের সব কারণ এল না');
    }

    public function test_no_bag_gives_no_reason(): void
    {
        session()->forget('errors');

        $this->assertSame([], $this->refusalMessages(), '⛔ থলে নেই, তবু কারণ এল');
        $this->assertNull($this->refusalOn('amount'), '⛔ থলে নেই, তবু একটা কারণ এল');
    }
}
