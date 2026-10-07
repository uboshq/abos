<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Support\CompanyContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ব্যবসায় একটা ঘটনা ঘটে গেছে — আর্কিটেকচার §৭, প্ল্যান WP-0.3।
 *
 * ── নাম অতীত কালে, সবসময় ─────────────────────────────────────────────
 * `InvoiceConfirmed`, `SupplierDeactivated` — `ConfirmInvoice` নয়।
 * পার্থক্যটা ব্যাকরণের নয়, ক্ষমতার: বর্তমান কালের নাম মানে একটা আদেশ,
 * আর আদেশ ব্যর্থ হতে পারে। ইভেন্ট ব্যর্থ হয় না — যা ঘটে গেছে তা ঘটেই
 * গেছে। শ্রোতা "না" বলতে পারে না, শুধু সাড়া দিতে পারে।
 *
 * ── পেলোডে কী থাকে, আর কেন এত কম ─────────────────────────────────────
 * `public_id`, কোম্পানি, কে করল, কখন — আর যা না দিলে শ্রোতা কাজই করতে
 * পারবে না। পুরো মডেলটা পাঠানো হয় না, ইচ্ছাকৃতভাবে:
 *
 *   ১. মডেল পাঠালে শ্রোতা তার ভেতরের ঘরগুলোর উপর নির্ভর করত, আর তখন
 *      একটা কলামের নাম বদলালে অন্য মডিউল ভাঙত — অথচ সেটাই এড়ানোর জন্য
 *      ইভেন্ট।
 *   ২. `public_id` দিলে শ্রোতা নিজের দরকার মতো নিজেই খুঁজে নেয়, আর
 *      সেই খোঁজাটা তার নিজের মডিউলের কোড দিয়ে হয়।
 *
 * তবু বলে রাখা: **শ্রোতা যেন ডাটাবেজে ফিরে গিয়ে "এটা কি এখনো সত্যি"
 * জিজ্ঞেস না করে।** ইভেন্টটাই সত্য; সেটা নিয়ে সন্দেহ থাকলে ইভেন্টটাই
 * অপ্রয়োজনীয়।
 *
 * ── যা কখনো ইভেন্টে যাবে না ──────────────────────────────────────────
 * হিসাবের দাখিলা, স্টক চলাচল, নম্বর ইস্যু। ওগুলো একই ট্রানজেকশনে সরাসরি
 * সার্ভিস কল, আর সেটা আলোচনার বিষয় নয়।
 *
 * কারণটা সহজ: ইভেন্ট একদিন হারায় — শ্রোতা ব্যতিক্রম ছোড়ে, কিউ পড়ে
 * যায়, কেউ নিবন্ধন করতে ভুলে যায়। বিজ্ঞপ্তি হারালে কেউ একটা ইমেইল কম
 * পান। **দাখিলা হারালে খাতা মেলে না**, আর "eventually consistent
 * ledger" বলে কিছু নেই — খাতা হয় মেলে, নয় মেলে না।
 */
abstract class DomainEvent
{
    public readonly string $eventId;

    public readonly Carbon $occurredAt;

    public readonly int $companyId;

    public readonly ?int $actorId;

    /**
     * @param  string  $publicId  যে রেকর্ডটা নিয়ে ঘটনা — UUID, ক্রমিক আইডি নয়
     * @param  array<string, scalar|null>  $payload  শ্রোতার যা না হলেই নয়
     */
    public function __construct(
        public readonly string $publicId,
        public readonly array $payload = [],
        ?int $companyId = null,
        ?int $actorId = null,
    ) {
        /*
         * ইভেন্টের নিজের একটা পরিচয় — একই ঘটনা দুইবার এলে চেনা যায়।
         *
         * এখন কিউ নেই, তাই দুইবার আসার কথা নয়। কিন্তু যেদিন কিউ আসবে
         * সেদিন "at least once" পৌঁছানোই স্বাভাবিক, আর তখন শ্রোতাকে
         * বলতে হবে "এটা কি আগে দেখেছি"। পরিচয়টা তখন যোগ করলে পুরনো
         * শ্রোতাগুলো সেটা ছাড়াই লেখা থাকত।
         */
        $this->eventId = (string) Str::uuid7();
        $this->occurredAt = Carbon::now();

        /*
         * কোম্পানি পেলোডের অংশ, প্রসঙ্গের নয়।
         *
         * শ্রোতা চললে প্রসঙ্গ বদলে যেতে পারে — বিশেষ করে যেদিন কিউ
         * আসবে, তখন শ্রোতা অন্য প্রক্রিয়ায় চলবে যেখানে CompanyContext
         * বলে কিছু নেই। কোম্পানিটা ঘটনার সাথে বাঁধা না থাকলে শ্রোতা
         * ভুল কোম্পানির ডেটায় হাত দিত, আর সেটা টের পাওয়া যেত অনেক পরে।
         */
        $this->companyId = $companyId ?? CompanyContext::id() ?? 0;
        $this->actorId = $actorId ?? auth()->id();
    }

    /**
     * ঘটনার নাম — ক্লাসের নামই, namespace ছাড়া।
     *
     * লগে, Governance-এর পর্দায় ও ভবিষ্যতের কিউতে এই নামটাই যায়।
     * হাতে লিখতে দিলে একদিন ক্লাস আর নাম আলাদা হয়ে যেত, আর তখন
     * "InvoiceConfirmed কোথায় লেখা" প্রশ্নের উত্তর খুঁজে পাওয়া যেত না।
     */
    final public function name(): string
    {
        return class_basename(static::class);
    }

    /**
     * এই ঘটনার একটা মান — লগ ও ভবিষ্যতের কিউর জন্য।
     *
     * @return array<string, mixed>
     */
    final public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'name' => $this->name(),
            'public_id' => $this->publicId,
            'company_id' => $this->companyId,
            'actor_id' => $this->actorId,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'payload' => $this->payload,
        ];
    }
}
