{{--
    যাত্রাপথ — কাগজটা কোথায় আছে, আর কী বাকি।

    ── ⛔ কেন সিদ্ধান্তের ছকটা এই প্রশ্নের উত্তর দেয় না ─────────────────
    ⓘ ঐ ছকটা বলে **যা হয়ে গেছে**। ⚠️ কিন্তু যিনি এখন সই দিচ্ছেন তাঁর
    প্রশ্ন উল্টো: *"আমার পরে আর কয়জন আছেন?"* — কারণ শেষ সইটা দিলে
    টাকাটা সত্যিই নড়ে, আর তার আগেরগুলো নড়ায় না।

    ⛔ যে ধাপে এখনো কেউ কিছু করেননি সেটা সিদ্ধান্তের ছকে **আসেই না**,
    আর ঠিক সেটাই এখানে দেখার জিনিস।

    ── ⓘ সারি স্তর ধরে, মানুষ ধরে নয় ───────────────────────────────────
    একই স্তরে তিনজন থাকতে পারেন (N-of-M)। ⚠️ মানুষ ধরে সারি করলে
    তিনজনের একটা ধাপ তিনটা ধাপ দেখাত, আর পাঠক ভাবতেন পথটা লম্বা।
--}}
<ol class="space-y-0">
    @foreach ($timeline as $step)
        @php
            /*
             * ⓘ রং নয়, লেখাই মূল — [[x-ui.badge]]-এর একই কারণে।
             * প্রতিটা অবস্থার নিজের লেখা আছে, তাই সাদাকালো ছাপাতেও পড়া যায়।
             */
            $done = in_array($step['state'], [
                \App\Models\ApprovalDecision::APPROVED,
                \App\Models\ApprovalDecision::REJECTED,
                \App\Models\ApprovalDecision::FORWARDED,
            ], true);

            $now = $step['state'] === 'now';
        @endphp

        <li class="flex gap-3 border-t border-(--color-border) py-2 first:border-0 first:pt-0">
            {{-- ⓘ চিহ্নটা `aria-hidden` — পাশের লেখাটাই স্ক্রিন-রিডারের জন্য।
                 ⛔ নাহলে "✓ ২ · সুপারভাইজার অনুমোদিত" দুইবার শোনা যেত। --}}
            <span aria-hidden="true"
                  @class([
                      'mt-0.5 grid size-5 shrink-0 place-items-center rounded-full text-2xs font-semibold',
                      'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $done
                          && $step['state'] === \App\Models\ApprovalDecision::APPROVED,
                      'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => $done
                          && $step['state'] !== \App\Models\ApprovalDecision::APPROVED,
                      'bg-(--color-badge-pending-bg) text-(--color-badge-pending-ink)' => $now,
                      'bg-(--color-surface-sunken) text-(--color-ink-muted)' => ! $done && ! $now,
                  ])>
                {{ $step['level'] }}
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-sm {{ $now ? 'font-semibold' : '' }}">
                    {{ $step['name'] ?? __('approval::field.level').' '.$step['level'] }}
                </p>

                <p class="text-2xs text-(--color-ink-muted)">
                    {{ $done
                        ? __('approval::status.'.$step['state'])
                        : ($now ? __('approval::message.step_now') : __('approval::message.step_waiting')) }}

                    {{-- ⭐ কয়জনের মধ্যে কয়জন — কেবল একের বেশি লাগলে।

                         ⓘ "১-এর ১" লেখাটা প্রতিটা সাধারণ ধাপে বসলে চোখ
                         ওটাকে আর পড়ত না, আর তখন যেখানে সত্যিই দুইজন লাগে
                         সেই সারিটাও একইরকম দেখাত। --}}
                    @if (($step['needed'] ?? 1) > 1)
                        · {{ __('approval::message.step_of', [
                            'signed' => $step['signed'] ?? 0,
                            'needed' => $step['needed'],
                        ]) }}
                    @endif

                    {{-- ⭐ এই ধাপে কত সময় গেল।

                         ⓘ গণনাটা আগের সিদ্ধান্ত থেকে — কারণটা
                         [[ApprovalInboxController::timelineOf()]]-তে লেখা।

                         ⛔ শূন্য ঘণ্টাও লেখা হয়, লুকানো হয় না:
                         *"সাথে সাথে সই"* একটা গুরুত্বপূর্ণ কথা — তখন
                         সম্ভবত কেউ কাগজটা পড়েননি। --}}
                    @if (($step['took'] ?? null) !== null)
                        · {{ __('approval::message.step_took', ['hours' => $step['took']]) }}
                    @endif
                </p>
            </div>
        </li>
    @endforeach
</ol>
