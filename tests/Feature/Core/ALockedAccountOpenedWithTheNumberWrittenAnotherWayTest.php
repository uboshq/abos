<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Security\CredentialCheck;
use App\Core\Security\LoginLock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ মোবাইল নম্বর অন্যভাবে লিখে লগইনের তালা এড়ানো যেত (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[LoginLock::canonical()]])।
 *
 * ⚠️ খোঁজা নম্বরকে ছাঁদে আনত, তালা গুনত কাঁচা লেখা — "01711000000", "01711-000000", "০১৭১১০০০০০০" একই মানুষের তিনটা আলাদা
 * তালা। আট ভুলে পনেরো মিনিটের তালা কার্যত নিজের হাতে খোলা। ⭐ এখন সব লেখা এক তালায়।
 */
final class ALockedAccountOpenedWithTheNumberWrittenAnotherWayTest extends TestCase
{
    use RefreshDatabase;

    public function test_failures_in_every_spelling_of_one_number_count_on_one_lock(): void
    {
        User::factory()->create(['mobile' => '01711000000', 'password' => 'Right-pass-2026', 'is_active' => true]);
        $check = app(CredentialCheck::class);
        $spellings = ['01711000000', '01711-000000', '০১৭১১০০০০০০', '0171 1000000'];

        for ($i = 0; $i < LoginLock::TRIES; $i++) {
            try {
                $check->verify($spellings[$i % count($spellings)], 'wrong-guess-'.$i);
            } catch (ValidationException) {
                // ⓘ ভুল আন্দাজ
            }
        }

        try {
            $check->verify('(017)11000000', 'Right-pass-2026');
            $this->fail('⛔ নম্বর অন্যভাবে লিখে তালাবদ্ধ অ্যাকাউন্টে ঢোকা গেল');
        } catch (ValidationException $e) {
            $this->assertSame(__('auth.locked', ['minutes' => LoginLock::MINUTES]), $e->errors()['identifier'][0] ?? '', 'তালার বার্তা নয়');
        }
    }
}
