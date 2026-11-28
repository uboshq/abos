<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Modules\Finance\Http\Controllers\CapitalController;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ⭐ সইয়ের অপেক্ষার মূলধনের খসড়া বদলাতে গেলে কারণটা সত্যি বলে — "অনুমোদনের অপেক্ষায়", "আগেই খাতায় বসেছে" নয় (২৮ নভেম্বর ২০২৬, abos-b1)।
 *
 * ⛔ কী ভুল ছিল: [[CapitalService::assertStillADraft()]] আর পর্দার একই পাহারায় প্রথম শর্তটাই লাভ-থেকে-মূলধন আর ভাউচারে-বাঁধা খসড়া
 *   ধরত, আর "আগেই খাতায় বসেছে" বলে থামত। দ্বিতীয় শর্ত — "অনুমোদনের অপেক্ষায়; অনুমোদনকারী না বলতে পারেন, বা ভাউচার বাতিল" —
 *   কখনো পৌঁছাত না (PHPStan: `null !== null` সবসময় মিথ্যা)। মানুষ ভাবতেন কাগজটা পোস্ট হয়ে গেছে, আর কী করবেন তা জানতেন না।
 * ⓘ থামা একই — কেবল বার্তা। সারি লাগে না: পাহারা কেবল সারির ঘর পড়ে।
 */
final class ACapitalDraftHeldForApprovalSaysSoTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: int|null, 3: string|null}> */
    public static function cases(): iterable
    {
        yield 'profit draft, waiting' => [CapitalEntry::DRAFT, CapitalEntry::PROFIT, null, 'capital_held_for_signature'];
        yield 'cash draft tied to a voucher' => [CapitalEntry::DRAFT, CapitalEntry::CASH, 41, 'capital_held_for_signature'];
        yield 'posted profit' => [CapitalEntry::POSTED, CapitalEntry::PROFIT, 41, 'capital_already_posted'];
        yield 'posted cash' => [CapitalEntry::POSTED, CapitalEntry::CASH, null, 'capital_already_posted'];
        yield 'opening draft' => [CapitalEntry::DRAFT, CapitalEntry::OPENING, null, 'capital_already_posted'];
        yield 'free cash draft' => [CapitalEntry::DRAFT, CapitalEntry::CASH, null, null];
    }

    #[DataProvider('cases')]
    public function test_the_service_names_the_true_reason(string $status, string $inKind, ?int $voucherId, ?string $key): void
    {
        $this->assertReason(app(CapitalService::class), $status, $inKind, $voucherId, $key);
    }

    #[DataProvider('cases')]
    public function test_the_screen_names_the_true_reason(string $status, string $inKind, ?int $voucherId, ?string $key): void
    {
        $this->assertReason(app(CapitalController::class), $status, $inKind, $voucherId, $key);
    }

    private function assertReason(object $guard, string $status, string $inKind, ?int $voucherId, ?string $key): void
    {
        $entry = new CapitalEntry;
        $entry->forceFill(['status' => $status, 'in_kind' => $inKind, 'voucher_id' => $voucherId, 'document_no' => 'CAP-0007']);

        $check = new \ReflectionMethod($guard, 'assertStillADraft');

        try {
            $check->invoke($guard, $entry);
        } catch (ValidationException $e) {
            $this->assertNotNull($key, '⛔ মুক্ত খসড়া থামল: '.$e->getMessage());
            $this->assertSame(__('finance::validation.'.$key, ['no' => 'CAP-0007']), $e->errors()['status'][0] ?? null, '⛔ থামার কারণ ভুল');

            return;
        }

        $this->assertNull($key, '⛔ থামার কথা ছিল ('.$key.'), থামেনি');
    }
}
