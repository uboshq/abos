<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\SettingsService;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * অফারের অবস্থা বদলানোর একমাত্র দরজা — স্পেক §১৪।
 *
 * ── ⚠️ কেন একটাই দরজা ────────────────────────────────────────────────
 * ⓘ `status` ইচ্ছাকৃতভাবে `fillable`-এর বাইরে। ⛔ নাহলে একটা সাধারণ
 * সম্পাদনার অনুরোধেই কেউ খসড়া অফারকে সোজা `active` করে দিতে পারতেন,
 * আর অনুমোদনের গোটা ধাপটা নীরবে এড়ানো যেত।
 *
 * ⭐ তাই প্রতিটা বদল এখানে আসে, আর [[PromotionStatus::canBecome()]]
 * জিজ্ঞেস করে। ⓘ এটাই সেই আসল দরজা যা আগের দাবিটা মাপত না — ঐ দাবিটা
 * কেবল enum-এর মানচিত্র পড়ত।
 */
final class PromotionLifecycle
{
    private const SERIES = 'PROM';

    /**
     * ⭐ কোন অবস্থায় মেয়াদ বদলানো যায় — সেবা আর পাতা দুইটাই এটা পড়ে।
     *
     * ⚠️ পাতায় আলাদা করে লিখলে দুইটা তালিকা একদিন আলাদা হত: ⓘ বোতাম
     * দেখা যেত অথচ চাপলে ভুল আসত, বা উল্টো — কাজটা চলত অথচ বোতাম নেই।
     *
     * @var list<PromotionStatus>
     */
    public const RESCHEDULABLE = [PromotionStatus::APPROVED, PromotionStatus::ACTIVE, PromotionStatus::PAUSED];

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly SettingsService $settings,
        private readonly ApprovalEngine $engine,
    ) {}

    /**
     * ⭐ নতুন অফার — সবসময় খসড়া হয়ে জন্মায়।
     *
     * ── ⓘ একাধিক অফার একসাথে — মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর ─────
     * *"offer ghosonar somoyei tik korbe"*। ⓘ ফর্মে না দিলে কোম্পানির
     * শুরুর মানটা বসে (`promotion.combines_default`) — ⚠️ কিন্তু বসে
     * **এখনই**, অফারের নিজের ঘরে। ⛔ পরে সুইচ বদলালে এই অফারের আচরণ
     * বদলায় না।
     *
     * @param  array<string, mixed>  $data
     */
    public function draft(array $data): Promotion
    {
        $this->assertDatesMakeSense($data);

        $type = PromotionType::from((string) $data['type']);

        /*
         * ⛔ যে ধরন ইঞ্জিন চেনে না, সেটা দিয়ে অফার জন্মাতে দেওয়া হয় না।
         *
         * ⚠️ পর্দার ছাঁকনি একটা পাহারা, কিন্তু ফর্ম যে কেউ বদলে পাঠাতে
         * পারেন। ⓘ এখানে না থামালে *"লয়্যালটি"* অফার তৈরি হত, *"চলছে"*
         * দেখাত, আর বিলে কিছুই করত না।
         */
        if (! $type->isBuilt()) {
            throw ValidationException::withMessages([
                'type' => __('promotion::validation.type_not_built', ['type' => $type->label()]),
            ]);
        }

        $combines = PromotionCombines::tryFrom((string) ($data['combines'] ?? ''))
            ?? PromotionCombines::tryFrom((string) $this->settings->get('promotion.combines_default', 'best'))
            ?? PromotionCombines::BEST;

        return DB::transaction(function () use ($data, $type, $combines) {
            /*
             * ⛔ কেবল `fillable` ঘরগুলো ঢালা — পুরো ফর্ম নয়।
             *
             * ⓘ দরজার পরীক্ষায় ধরা (২৭ সেপ্টেম্বর): ফর্মে `type` আসে, আর
             * `type` ইচ্ছাকৃতভাবে `fillable`-এর বাইরে। ⚠️ এই বাড়ির মডেল
             * অচেনা ঘর চুপচাপ ফেলে না, ছুঁড়ে দেয় — তাই পর্দা থেকে প্রতিটা
             * নতুন অফার ৫০০ দিত। ⓘ সেবার নিজের পরীক্ষাগুলো `type` ছাড়া
             * ডাকত বলে কেউ দেখেনি; কেবল আসল দরজা দিয়ে ঢুকলে ধরা পড়ে।
             */
            $offer = new Promotion(array_intersect_key($data, array_flip((new Promotion)->getFillable())));

            $offer->code = $this->numbers->next(self::SERIES);
            $offer->type = $type;
            $offer->status = PromotionStatus::DRAFT;
            $offer->combines = $combines;
            $offer->created_by = auth()->id();
            $offer->save();

            return $offer;
        });
    }

    /** খসড়া → অনুমোদনের অপেক্ষায়। */
    public function submit(Promotion $offer): Promotion
    {
        return $this->moveTo($offer, PromotionStatus::SUBMITTED);
    }

    /**
     * অপেক্ষা → অনুমোদিত।
     *
     * ── ⛔ নিজের অফার নিজে অনুমোদন নয় ───────────────────────────────
     * ⓘ অনুমোদনের পুরো মানে হলো *"দ্বিতীয় একজন দেখেছেন"*। ⚠️ যিনি
     * বানালেন তিনিই সই দিলে ধাপটা একটা সাজসজ্জা হত — কাগজে দুই সই,
     * আসলে একজন।
     */
    public function approve(Promotion $offer): Promotion
    {
        /*
         * ⛔ কোম্পানি অনুমোদনের ছক বসালে এই এক-সইয়ের দরজা বন্ধ।
         *
         * ⓘ নাহলে পুরনো পথটা দিয়ে তিন স্তরের ছক এক সইয়েই পেরোনো যেত —
         * [[PromotionApprovalChain]]-এর সব নিয়ম (নির্মাতা সই দেন না, একজন দুই
         * স্তরে নয়) নীরবে এড়িয়ে।
         */
        if ($this->needsApproval() && $this->engine->requires(
            PromotionApprovalChain::MODULE,
            PromotionApprovalChain::ACTION,
            PromotionApprovalChain::amountOf($offer),
            class_basename($offer),
            ApprovalEngine::fieldsOf($offer),
        )) {
            throw ValidationException::withMessages(['status' => __('promotion::approval.use_the_chain')]);
        }

        if ($this->needsApproval() && (int) $offer->created_by === (int) auth()->id()) {
            throw ValidationException::withMessages([
                'status' => __('promotion::validation.cannot_approve_own'),
            ]);
        }

        return $this->moveTo($offer, PromotionStatus::APPROVED, [
            'approved_by' => auth()->id(),
            'approved_at' => Carbon::now(),
        ]);
    }

    /**
     * অনুমোদিত (বা থামানো) → চলছে।
     *
     * ── ⭐ অনুমোদনের সুইচ বন্ধ থাকলে কী হয় — পর্যালোচনার ২ নম্বর ─────
     * ⓘ `promotion.needs_approval = false` আর
     * `DRAFT->canBecome(ACTIVE) = false` — দুইটা একে অপরকে কাটত। ⛔ সুইচ
     * বন্ধ করলেও মানচিত্র পথটা আটকে রাখত, আর সুইচটা মিথ্যা বলত।
     *
     * ⭐ সমাধান: মানচিত্র কড়াই থাকে। ⓘ সুইচ বন্ধ থাকলে এই দরজা নিজেই
     * খসড়া → জমা → অনুমোদিত → চলছে হাঁটে, আর `approved_by`-তে যিনি চালু
     * করলেন তাঁর নাম বসে। ⚠️ ইতিহাসটা সৎ থাকে: কেউ আলাদা করে সই দেননি,
     * আর খাতাও সেটাই বলে।
     */
    public function activate(Promotion $offer): Promotion
    {
        if (! $this->needsApproval()) {
            if ($offer->status === PromotionStatus::DRAFT) {
                $offer = $this->moveTo($offer, PromotionStatus::SUBMITTED);
            }

            if ($offer->status === PromotionStatus::SUBMITTED) {
                $offer = $this->moveTo($offer, PromotionStatus::APPROVED, [
                    'approved_by' => auth()->id(),
                    'approved_at' => Carbon::now(),
                ]);
            }
        }

        return $this->moveTo($offer, PromotionStatus::ACTIVE);
    }

    public function pause(Promotion $offer): Promotion
    {
        return $this->moveTo($offer, PromotionStatus::PAUSED);
    }

    public function cancel(Promotion $offer): Promotion
    {
        return $this->moveTo($offer, PromotionStatus::CANCELLED);
    }

    /**
     * ⭐ মেয়াদ বদল — বাড়ানো **বা কমানো**।
     *
     * ── ⓘ মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর ২০২৬ ─────────────────────
     * *"barate hole barabe, aber komate hole komabe, off korte hole
     * korbe — sob bebostai thakbe"*।
     *
     * ── ⚠️ তাহলে পুরনো বিল বাঁচে কীসে ───────────────────────────────
     * ⛔ তারিখে নয় — মেয়াদ পিছিয়ে আনলে আগে কাটা বিল মেয়াদের বাইরে পড়ে।
     * ⭐ বাঁচে [[PromotionApplication]]-এ: সুবিধার অঙ্ক বিলের সারিতে জমে
     * যায়, অফারের কাগজ থেকে আর পড়া হয় না। ⓘ তাই এই দরজা কোনো পুরনো
     * সারি ছোঁয় না — ছোঁয়ার দরকারই নেই।
     *
     * ⛔ কেবল চলতি বা থামানো অফারে। ⓘ মেয়াদ শেষ বা বাতিল হওয়া অফার
     * চূড়ান্ত — তাকে ফেরাতে হলে নকল করে নতুন অফার।
     */
    public function reschedule(Promotion $offer, string $endsOn, ?string $endsAt = null): Promotion
    {
        if (! in_array($offer->status, self::RESCHEDULABLE, true)) {
            throw ValidationException::withMessages([
                'ends_on' => __('promotion::validation.cannot_reschedule', ['status' => $offer->status->label()]),
            ]);
        }

        $this->assertDatesMakeSense([
            'starts_on' => $offer->starts_on->toDateString(),
            'ends_on' => $endsOn,
            'starts_at' => $offer->starts_at,
            'ends_at' => $endsAt,
        ]);

        $offer->ends_on = $endsOn;
        $offer->ends_at = $endsAt;
        $offer->save();

        return $offer;
    }

    /**
     * ⭐ একমাত্র জায়গা যেখানে `status` লেখা হয়।
     *
     * @param  array<string, mixed>  $also
     */
    private function moveTo(Promotion $offer, PromotionStatus $next, array $also = []): Promotion
    {
        if (! $offer->status->canBecome($next)) {
            throw ValidationException::withMessages([
                'status' => __('promotion::validation.cannot_move', [
                    'from' => $offer->status->label(),
                    'to' => $next->label(),
                ]),
            ]);
        }

        $offer->status = $next;

        foreach ($also as $key => $value) {
            $offer->{$key} = $value;
        }

        $offer->save();

        return $offer;
    }

    /**
     * ⭐ ছকের শেষ সই পড়লে — কেবল [[PromotionApprovalChain::settle()]] ডাকে।
     */
    public function signedOff(Promotion $offer, int $by, Carbon $at): Promotion
    {
        return $this->moveTo($offer, PromotionStatus::APPROVED, ['approved_by' => $by, 'approved_at' => $at]);
    }

    /** ⓘ ছকের কেউ ফেরত পাঠালে — খসড়ায়, কারণটা ছক আর নিরীক্ষায় থাকে */
    public function sentBack(Promotion $offer): Promotion
    {
        return $this->moveTo($offer, PromotionStatus::DRAFT);
    }

    private function needsApproval(): bool
    {
        return (bool) $this->settings->get('promotion.needs_approval', true);
    }

    /**
     * ⛔ শেষ তারিখ শুরুর আগে নয় — স্পেক §২০।
     *
     * ⚠️ ছাড়া এমন অফার বসানো যেত যেটা **কোনোদিন** খাটে না, আর যিনি
     * বানালেন তিনি ভাবতেন ওটা চলছে — তালিকা *"চলছে"* বলত, ইঞ্জিন চুপ থাকত।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertDatesMakeSense(array $data): void
    {
        if (($data['starts_on'] ?? '') === '' || ($data['ends_on'] ?? '') === '') {
            return;
        }

        /*
         * ⚠️ তারিখ হিসেবে তুলনা, লেখা হিসেবে নয়।
         *
         * ⓘ পর্যালোচনায় ধরা (২৭ সেপ্টেম্বর): `date` নিয়ম *"2026-9-30"* মেনে
         * নেয়, আর লেখা হিসেবে *"2026-9-30"* > *"2026-10-05"* — তাই শুরুর আগে
         * শেষ হওয়া অফার ঢুকে যেত। ⭐ দুইটাই একই রূপে এনে তবে তুলনা।
         */
        $starts = Carbon::parse((string) $data['starts_on'])->toDateString();
        $ends = Carbon::parse((string) $data['ends_on'])->toDateString();

        if ($ends < $starts) {
            throw ValidationException::withMessages([
                'ends_on' => __('promotion::validation.ends_before_it_starts'),
            ]);
        }

        /* ⓘ একই দিনে শুরু ও শেষ হলে সময়টাও মানতে হয় */
        if ($ends === $starts && ! empty($data['starts_at']) && ! empty($data['ends_at'])
            && (string) $data['ends_at'] <= (string) $data['starts_at']) {
            throw ValidationException::withMessages([
                'ends_at' => __('promotion::validation.ends_before_it_starts'),
            ]);
        }
    }
}
