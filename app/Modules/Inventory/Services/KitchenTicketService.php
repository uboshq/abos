<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\KitchenTicket;
use App\Modules\Inventory\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * রান্নাঘরে টিকিট পাঠানো, আর তার অবস্থা এগোনো — রেস্টুরেন্টের ধাপ ৪।
 *
 * ── কেন এটা লাগল ─────────────────────────────────────────────────────
 * ধাপ ১–৩-এর পর কাউন্টার জানে কয় প্লেট হবে, আর বিক্রিতে উপকরণও কমে।
 * কিন্তু **রান্নাঘর কিছুই জানে না**। কেউ কাগজ নিয়ে দৌড়ায়, নয়তো চেঁচিয়ে
 * বলে — আর ব্যস্ত সময়ে দুইটাই হারায়।
 *
 * ── কেন কেবল অর্ডারে-রান্না খাবারের টিকিট ────────────────────────────
 * হাঁড়ির খাবার সকালেই রান্না হয়ে গেছে; ওর জন্য টিকিট মানে রাঁধুনিকে
 * এমন কিছু করতে বলা যা ইতিমধ্যেই করা। পর্দাটা তখন কাগজে ভরে যেত, আর
 * সত্যিকারের অর্ডার তার মধ্যে হারাত।
 */
final class KitchenTicketService
{
    public function __construct(
        private readonly RecipeService $recipes,
    ) {}

    /**
     * একটা কাগজের অর্ডারে-রান্না পদগুলোর টিকিট তোলা।
     *
     * ── কেন দুইবার ডাকলেও দুইবার টিকিট হয় না ────────────────────────
     * নিশ্চিতকরণ একবারই ঘটার কথা, কিন্তু "ঘটার কথা" আর "ঘটে" এক নয় —
     * দুইটা ট্যাব, একটা দ্বিতীয় ক্লিক, একটা রি-ট্রাই। রান্নাঘরে দুইটা
     * একই টিকিট মানে দুইবার রান্না, আর একবারের টাকা।
     *
     * @param  list<array{product: Product, qty: string}>  $lines
     * @return list<KitchenTicket>
     */
    public function raise(
        string $sourceType,
        int $sourceId,
        string $documentNo,
        array $lines,
        ?int $branchId = null,
        ?string $note = null,
    ): array {
        $companyId = CompanyContext::id();

        if ($companyId === null) {
            throw new RuntimeException('Cannot raise a kitchen ticket without a company in context.');
        }

        return DB::transaction(function () use (
            $sourceType, $sourceId, $documentNo, $lines, $branchId, $note, $companyId
        ) {
            $already = KitchenTicket::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->exists();

            if ($already) {
                return [];
            }

            $made = [];

            foreach ($lines as $line) {
                if (! $this->recipes->consumesOnSale($line['product'])) {
                    continue;
                }

                $made[] = KitchenTicket::query()->create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId ?? CompanyContext::branchId(),
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'document_no' => $documentNo,
                    'product_id' => $line['product']->id,
                    'qty' => $line['qty'],
                    'state' => KitchenTicket::PLACED,
                    'placed_at' => now(),
                    'note' => $note,
                    'created_by' => auth()->id(),
                ]);
            }

            return $made;
        });
    }

    /**
     * পরের ধাপে নেওয়া — আর কেবল পরেরটাতেই।
     *
     * ── কেন যেকোনো অবস্থায় লাফ দেওয়া যায় না ─────────────────────────
     * "হয়েছে" চাপার আগে "শুরু" চাপতেই হয়। লাফ দিতে দিলে `started_at`
     * খালি থেকে যেত, আর তখন "রাঁধতে গড়ে কত লাগে" প্রশ্নের উত্তর
     * অর্ধেক টিকিটে থাকত না — অথচ ওটাই রান্নাঘরের একমাত্র মাপ।
     *
     * ব্যস্ত সময়ে ভুল করে দুইবার চাপা সহজ, তাই একই অবস্থায় আবার
     * চাপলে কিছুই হয় না — ভুল নয়, নীরব।
     */
    public function advance(KitchenTicket $ticket, string $to): KitchenTicket
    {
        if ($ticket->state === $to) {
            return $ticket;
        }

        $next = [
            KitchenTicket::PLACED => KitchenTicket::COOKING,
            KitchenTicket::COOKING => KitchenTicket::READY,
            KitchenTicket::READY => KitchenTicket::SERVED,
        ];

        if (($next[$ticket->state] ?? null) !== $to) {
            throw new RuntimeException(__('inventory::message.ticket_wrong_step'));
        }

        $ticket->forceFill([
            'state' => $to,
            'started_at' => $to === KitchenTicket::COOKING ? now() : $ticket->started_at,
            'ready_at' => $to === KitchenTicket::READY ? now() : $ticket->ready_at,
            'served_at' => $to === KitchenTicket::SERVED ? now() : $ticket->served_at,
        ])->save();

        return $ticket;
    }

    /**
     * কাগজ বাতিল হলে তার টিকিটও যায়।
     *
     * ── কেন মুছে ফেলা, "বাতিল" লিখে রাখা নয় ─────────────────────────
     * নিয়ম ৫ বলে কাগজ মুছতে নেই — কিন্তু টিকিট কাগজ নয়, ওটা একটা
     * নির্দেশ। বাতিল বিলের নির্দেশ রান্নাঘরের পর্দায় "বাতিল" লেখা
     * নিয়ে বসে থাকলে ব্যস্ত সময়ে চোখ ওটাতেও আটকাত, আর কী রাঁধা
     * হয়েছিল সেটা তখন কেউ বলতে পারত না।
     *
     * যা ঘটেছিল তার প্রমাণ অডিটে থাকে ([[IsAudited]])।
     */
    public function withdraw(string $sourceType, int $sourceId): int
    {
        return KitchenTicket::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->delete();
    }
}
