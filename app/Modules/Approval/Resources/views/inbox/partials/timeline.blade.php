{{--
    যাত্রাপথ — কাগজটা কোথায় আছে, কে কখন কী বলেছেন, আর কী বাকি।

    ── ⛔ কেন সিদ্ধান্তের ছকটা এই প্রশ্নের উত্তর দেয় না ─────────────────
    ⓘ ঐ ছকটা বলে **যা হয়ে গেছে**। ⚠️ কিন্তু যিনি এখন অনুমোদন দিচ্ছেন তাঁর প্রশ্ন উল্টো: *"আমার পরে আর কয়জন আছেন?"* — কারণ
    শেষ অনুমোদনটা দিলে টাকাটা সত্যিই নড়ে। ⛔ যে ধাপে এখনো কেউ কিছু করেননি সেটা সিদ্ধান্তের ছকে **আসেই না**।

    ── ⭐ প্রতিটা ধাপে কে, কখন, কী মন্তব্যে — দফা ১০ (অনুমোদন পর্দা ভাগ ২, ২৭ অক্টোবর ২০২৬) ──
    ⓘ দায়িত্ব অর্পণে হলে "রহিমের হয়ে", ফরোয়ার্ড হলে কার কাছে — শিকলটা যেন না ছেঁড়ে। সবার আগে "তৈরি" — কে কখন চেয়েছিলেন।

    ── ⓘ সারি স্তর ধরে, মানুষ ধরে নয় ───────────────────────────────────
    একই স্তরে তিনজন থাকতে পারেন (N-of-M)। ⚠️ মানুষ ধরে সারি করলে তিনজনের একটা ধাপ তিনটা ধাপ দেখাত।
--}}
<ol class="space-y-0" data-approval-journey>
    @isset($approval)
        <li class="flex gap-3 py-2 first:pt-0" data-journey-created>
            <span aria-hidden="true" class="mt-1.5 size-2.5 shrink-0 rounded-full bg-(--color-success)"></span>
            <p class="min-w-0 text-xs">
                <span class="font-medium">{{ __('approval::field.created') }}</span>
                — {{ $approval->requesterName() }} · <span class="num">{{ \App\Core\Support\DateFormat::spoken($approval->requested_at) }}</span>
            </p>
        </li>
    @endisset

    @foreach ($timeline as $step)
        @php
            /*
             * ⓘ রং নয়, লেখাই মূল — [[x-ui.badge]]-এর একই কারণে। প্রতিটা অবস্থার নিজের লেখা আছে, তাই সাদাকালো ছাপাতেও পড়া যায়।
             */
            $done = in_array($step['state'], [
                \App\Models\ApprovalDecision::APPROVED,
                \App\Models\ApprovalDecision::REJECTED,
                \App\Models\ApprovalDecision::FORWARDED,
                \App\Models\ApprovalDecision::RETURNED,
            ], true);

            $now = $step['state'] === 'now';
        @endphp

        <li class="flex gap-3 border-t border-(--color-border) py-2">
            {{-- ⓘ চিহ্নটা `aria-hidden` — পাশের লেখাটাই স্ক্রিন-রিডারের জন্য --}}
            <span aria-hidden="true"
                  @class([
                      'mt-1.5 size-2.5 shrink-0 rounded-full',
                      'bg-(--color-success)' => $done && $step['state'] === \App\Models\ApprovalDecision::APPROVED,
                      'bg-(--color-danger)' => $done && $step['state'] !== \App\Models\ApprovalDecision::APPROVED,
                      'bg-(--color-warning)' => $now,
                      'bg-(--color-surface-sunken)' => ! $done && ! $now,
                  ])></span>

            <div class="min-w-0 flex-1 text-xs">
                <p class="{{ $now ? 'font-semibold' : 'font-medium' }}">
                    {{ __('approval::field.level') }} <span class="num">{{ $step['level'] }}</span>@if ($step['name']) · {{ $step['name'] }}@endif
                    @if ($now)
                        — {{ __('approval::message.step_now') }}
                    @elseif (! $done)
                        — <span class="text-(--color-ink-muted)">{{ __('approval::message.step_waiting') }}</span>
                    @endif

                    {{-- ⭐ কয়জনের মধ্যে কয়জন — কেবল একের বেশি লাগলে ("১-এর ১" প্রতিটা ধাপে বসলে চোখ ওটা আর পড়ত না) --}}
                    @if (($step['needed'] ?? 1) > 1)
                        · {{ __('approval::message.step_of', ['signed' => $step['signed'] ?? 0, 'needed' => $step['needed']]) }}
                    @endif

                    {{-- ⭐ এই ধাপে কত সময় গেল — আগের সিদ্ধান্ত থেকে; ⛔ শূন্য ঘণ্টাও লেখা: "সাথে সাথে" মানে সম্ভবত কেউ পড়েননি --}}
                    @if (($step['took'] ?? null) !== null)
                        · {{ __('approval::message.step_took', ['hours' => $step['took']]) }}
                    @endif
                </p>

                @foreach ($step['by'] ?? [] as $who)
                    <p class="mt-0.5 text-(--color-ink-muted)" data-journey-decision="{{ $who['decision'] }}">
                        <span class="text-(--color-ink)">{{ $who['name'] }}</span>
                        @if ($who['for'])
                            ({{ __('approval::message.on_behalf_of', ['name' => $who['for']]) }})
                        @endif
                        — {{ __('approval::status.'.$who['decision']) }}@if ($who['to']) → {{ $who['to'] }}@endif
                        · <span class="num">{{ \App\Core\Support\DateFormat::spoken($who['at']) }}</span>
                        @if (filled($who['remarks']))
                            · “{{ $who['remarks'] }}”
                        @endif
                    </p>
                @endforeach
            </div>
        </li>
    @endforeach
</ol>
