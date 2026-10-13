<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Services\PrintQueue;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ একই বিল দুই ট্যাবে একসাথে ছাপলে দুইটাই "আসল" কাগজ হত (১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা; [[PrintQueue::claim()]])।
 *
 * ⓘ দুই অনুরোধ একই মুহূর্তে সারিটা পড়ে — দুইজনের হাতেই গোনা ০। আগে সীমা দেখা হত সেই বাসি কপিতে, আর গোনা লেখা হত
 * `বাসি + ১`: দুইটাই সীমা পেরোত, দুইটাই DUPLICATE ছাড়া ছাপা হত, খাতায় গোনা ১। এখানে "একই মুহূর্ত" মানে দুইটা আলাদা বাসি কপি।
 */
final class TwoPrintsAtOnceAreNotBothOriginalsTest extends TestCase
{
    use RefreshDatabase;

    private PrintQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // ⓘ ছাড়ানোর চাবি ছাড়া একজন — নইলে সীমা কাউকেই থামায় না
        $clerk = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $this->actingAs($clerk);
        $this->assertFalse($clerk->can('sales.reprint.override'));

        $this->queue = app(PrintQueue::class);
    }

    public function test_two_stale_copies_count_two_prints_and_the_second_is_a_duplicate(): void
    {
        [$first, $second] = $this->twoStaleCopies(7101);

        $this->queue->printed($first);
        $this->assertSame(2, $this->queue->printed($second)->printed_count, '⛔ দুইবার ছাপা, খাতায় গোনা ১ — দ্বিতীয়টা বাসি কপি থেকে লেখা');

        [$a, $b] = $this->twoStaleCopies(7102);

        $this->assertSame(1, $this->queue->claim($a)->printed_count);
        $this->assertSame(2, $this->queue->claim($b)->printed_count, '⛔ একসাথে দুই ছাপা — দ্বিতীয়টাও আসল কাগজ হয়ে বেরোল, DUPLICATE নেই');
    }

    public function test_the_limit_stops_the_second_of_two_prints_at_once(): void
    {
        app(SettingsService::class)->set('sales.reprint_limit', 1);

        [$a, $b] = $this->twoStaleCopies(7103);

        $this->queue->claim($a);

        try {
            $this->queue->claim($b);
            $this->fail('⛔ সীমা ১, তবু একসাথে দুই ছাপা দুইটাই পেরোল — বাসি কপিতে সীমা দেখা হয়েছে');
        } catch (ValidationException) {
            $this->assertSame(1, PrintJob::query()->withoutGlobalScopes()->findOrFail($b->id)->printed_count, 'থামানো ছাপা গোনায় পড়ল');
        }
    }

    /** @return array{0: PrintJob, 1: PrintJob} একই সারির দুইটা কপি, দুইটাই ছাপার আগের গোনা নিয়ে */
    private function twoStaleCopies(int $documentId): array
    {
        $first = $this->queue->queue(PrintJob::INVOICE, $documentId, 'a4', 'RACE-'.$documentId);
        $second = PrintJob::query()->withoutGlobalScopes()->findOrFail($first->id);
        $this->assertSame([0, 0], [$first->printed_count, $second->printed_count]);

        return [$first, $second];
    }
}
