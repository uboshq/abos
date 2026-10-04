<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * লিড — লেখা, বদলানো, আর একবারই গ্রাহক বানানো।
 *
 * ── ⛔ কেন গ্রাহক বানানো এখানে নিজে লেখা নয় ─────────────────────────────
 * গ্রাহক তিনটা দরজা দিয়ে ঢোকে, আর চারটা নিয়ম [[CustomerService::create()]]
 * পাহারা দেয়: ফোন মিললে আটকানো, বাংলা নাম বাধ্যতামূলক কি না, এক পয়েন্টে
 * এক পরিবেশক, আর সিরিজ থেকে কোড ও খোলা ব্যালেন্স। ⚠️ এখানে
 * `Customer::create()` লিখলে চারটাই পাশ কাটানো যেত — লিড হয়ে ঘুরে এলে
 * এক ফোনে দুই গ্রাহক। তাই রূপান্তর **কেবল** ঐ সার্ভিস ডাকে।
 */
final class LeadService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly CustomerService $customers,
    ) {}

    /** যে লিডগুলো এই মানুষ দেখতে পান — প্রতিটা দরজা এখান দিয়ে। */
    public function visibleTo(User $user): Builder
    {
        return Lead::query()->visibleTo($user);
    }

    /**
     * একটা লিড — না দেখতে পারলে ৪০৪, ৪০৩ নয়।
     *
     * ⓘ ৪০৩ বললে জানিয়ে দেওয়া হত যে ঐ নম্বরে একটা লিড আছে — অন্যের
     * খোঁজের খবর। ৪০৪ কিছুই বলে না।
     */
    public function find(int $id, User $user): Lead
    {
        return $this->visibleTo($user)->findOrFail($id);
    }

    /**
     * কাকে লিডের মালিক বানানো যায় — এই কোম্পানির সক্রিয় সদস্য, যাঁর
     * লিড দেখার চাবি আছে।
     *
     * @return Collection<int, User>
     */
    public function owners(): Collection
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->can('sales.lead.view'))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Lead
    {
        $data['owner_user_id'] = $this->ownerFor($data['owner_user_id'] ?? null, $actor, null);
        $this->assertStatus($data);

        return DB::transaction(function () use ($data, $actor) {
            return Lead::create([
                ...$this->only($data),
                'document_no' => $this->numbers->next('LD'),
                'status' => $data['status'] ?? Lead::NEW,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Lead $lead, array $data, User $actor): Lead
    {
        $this->assertStillALead($lead);

        $data['owner_user_id'] = $this->ownerFor($data['owner_user_id'] ?? null, $actor, $lead);
        $this->assertStatus($data);

        $lead->update($this->only($data));

        return $lead->fresh();
    }

    /**
     * ⭐ লিড থেকে গ্রাহক — ঠিক একবার।
     *
     * ── ⛔ কেন সারিটা তালা দিয়ে আবার পড়া হয় ──────────────────────────
     * দুইটা ট্যাব, অথবা দুইবার চাপা বাটন: দুইটা অনুরোধই পুরনো সারি দেখে
     * "এখনো লিড" ভাবত, আর দুইজন গ্রাহক তৈরি হত। ⓘ `lockForUpdate()`
     * দ্বিতীয়টাকে প্রথমটার শেষ পর্যন্ত দাঁড় করায়; তারপর সে দেখে লিডটা
     * আর লিড নেই।
     *
     * ⓘ ফোন মিললে [[CustomerService]] নিজেই থামায় — লিডের দোকানটা যদি
     * আগে থেকেই গ্রাহক হন, সেটা এখানেই ধরা পড়ে।
     *
     * @param  array<string, mixed>  $customerData  ফর্ম থেকে: name_en, name_bn,
     *                                               party_type_id, location_id, allow_duplicate
     */
    public function convert(Lead $lead, array $customerData, User $actor): Customer
    {
        // ⛔ দরজার চাবি কন্ট্রোলারে আছে, তবু এখানেও — সার্ভিস অন্য পথেও ডাকা যায়
        if (! $actor->can('sales.lead.manage') || ! $actor->can('customer.create')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($lead, $customerData, $actor) {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            $this->assertStillALead($locked);

            // হারানো লিড আগে খোলা হোক — নাহলে "হারিয়েছি" আর "গ্রাহক" দুইটাই সত্যি বলত
            if ($locked->status === Lead::LOST) {
                throw ValidationException::withMessages([
                    'lead' => __('sales::crm.lost_cannot_convert'),
                ]);
            }

            $customer = $this->customers->create([
                'name_en' => $customerData['name_en'] ?? $locked->name,
                'name_bn' => $customerData['name_bn'] ?? null,
                'owner_name' => $locked->contact_person,
                'phone' => $locked->phone,
                ...$this->addressFields($locked->address),
                'location_id' => $customerData['location_id'] ?? $locked->location_id,
                'party_type_id' => $customerData['party_type_id'] ?? null,
                'allow_duplicate' => (bool) ($customerData['allow_duplicate'] ?? false),

                // লিড কোনো পাওনা নিয়ে আসে না — শূন্য স্পষ্ট করে, কারণ খোলা
                // ব্যালেন্সের bccomp ফাঁকা স্ট্রিং পেলে ভেঙে পড়ে
                'opening_balance' => '0',

                /*
                 * ⛔ বাকির সীমা শূন্য — অডিট §১.২: তৈরির সময় সীমা বসানো যায় না।
                 * ⓘ সীমা বাড়ানো গ্রাহকের পাতা থেকে, সইসহ; লিডের পথ সেটা এড়াতে পারে না।
                 */
                'credit_limit' => '0',
            ]);

            $locked->forceFill([
                'status' => Lead::CONVERTED,
                'customer_id' => $customer->id,
                'converted_at' => now(),
                'converted_by' => $actor->id,
            ])->save();

            /*
             * ⓘ এই লিডের চলমান সুযোগগুলো এখন গ্রাহকের।
             *
             * লিডের সংযোগ থাকে (ইতিহাস), শুধু গ্রাহকের ঘরটা ভরে — নাহলে
             * গ্রাহকের পাতা থেকে "এই দোকানের সাথে কী কী চলছে" দেখা যেত না।
             */
            Opportunity::query()
                ->where('lead_id', $locked->id)
                ->whereNull('customer_id')
                ->update(['customer_id' => $customer->id]);

            return $customer;
        });
    }

    /**
     * কে মালিক হবেন।
     *
     * ⛔ সবার-দেখার চাবি ছাড়া কেউ অন্যের নামে লিড বসাতে বা সরাতে পারেন না —
     * নাহলে নিজের লিড অন্যের ঘাড়ে চাপিয়ে নিজের তালিকা থেকে "হারিয়ে"
     * ফেলা যেত, অথবা অন্যের নামে খোঁজ বসিয়ে তাঁর তালিকা ভরানো যেত।
     */
    private function ownerFor(mixed $requested, User $actor, ?Lead $existing): ?int
    {
        if (! $actor->can('sales.lead.manage')) {
            return $existing?->owner_user_id ?? $actor->id;
        }

        if (blank($requested)) {
            return $existing?->owner_user_id ?? $actor->id;
        }

        $member = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->whereKey((int) $requested)
            ->exists();

        if (! $member) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('sales::crm.owner_not_member'),
            ]);
        }

        return (int) $requested;
    }

    /** @param  array<string, mixed>  $data */
    private function assertStatus(array $data): void
    {
        $status = $data['status'] ?? Lead::NEW;

        if (! in_array($status, Lead::SETTABLE, true)) {
            throw ValidationException::withMessages([
                'status' => __('sales::crm.status_not_settable'),
            ]);
        }

        // হারানো লিডের কারণ লাগে — নাহলে "কেন হারাই" প্রশ্নের উত্তর থাকে না
        if ($status === Lead::LOST && blank($data['lost_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'lost_reason' => __('sales::crm.lost_needs_reason'),
            ]);
        }
    }

    private function assertStillALead(Lead $lead): void
    {
        if ($lead->isConverted()) {
            throw ValidationException::withMessages([
                'lead' => __('sales::crm.already_converted'),
            ]);
        }
    }

    /**
     * ঠিকানা কোন ঘরে — বাংলা অক্ষর থাকলে বাংলায়।
     *
     * @return array<string, string|null>
     */
    private function addressFields(?string $address): array
    {
        if (blank($address)) {
            return [];
        }

        return preg_match('/\p{Bengali}/u', $address)
            ? ['address_bn' => $address]
            : ['address_en' => $address];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function only(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'contact_person', 'phone', 'address', 'location_id', 'source',
            'owner_user_id', 'status', 'lost_reason', 'notes',
        ]));
    }
}
