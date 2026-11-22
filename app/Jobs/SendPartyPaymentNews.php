<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Services\PartyAutoSend;
use App\Core\Support\CompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ⭐ "টাকা পেয়েছি" — রসিদ পাকা হওয়ার পরে, সারি থেকে (নমুনা HUXqpfhxng8823eEFrvH54, ট্যাব ঙ; [[PartyAutoSend::receiptPosted()]])।
 *
 * ⓘ সারিতে, কারণ পোস্ট কখনো ধীর হবে না — Cloud API-র ডাক কয়েক সেকেন্ড নিতে পারে। ⓘ কোম্পানি কাজের সাথে বাঁধা: সারির কাজ চলে
 *   কোনো প্রসঙ্গ ছাড়া ([[DomainEvent]]-এর একই কারণ)। ⓘ একবারই চেষ্টা — খবর একবার না গেলে খাতায় "ব্যর্থ" থাকে, আবার পাঠানো
 *   খাতার "আবার পাঠান"-এ; দুইবার চললেও খাতায় আগের সারি দেখে থামে।
 */
final class SendPartyPaymentNews implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $companyId,
        public readonly string $morph,
        public readonly int $documentId,
        public readonly string $documentType,
    ) {}

    public function handle(PartyAutoSend $auto): void
    {
        CompanyContext::forCompany($this->companyId, fn () => $auto->sendPaymentNews($this->morph, $this->documentId, $this->documentType));
    }
}
