@props([
    'documents' => [],
])

{{--
    ছাপার বোতাম — কাগজের মাপ সহ।

    ── কেন প্রতিটা মাপের জন্য আলাদা লিংক, একটা ড্রপডাউন নয় ──────────────
    দোকানে যে কাগজে ছাপা হবে সেটা মেশিনের উপর নির্ভর করে, ব্যবহারকারীর
    পছন্দের উপর নয়। কাউন্টারে ৮০mm রোল, অফিসে A4 — একই লোক দিনে দুইটাই
    ব্যবহার করেন। ড্রপডাউনে "মনে রাখা" পছন্দ থাকলে ভুল মেশিনে পাঠিয়ে
    কাগজ নষ্ট হত, তাই তিনটাই সরাসরি দেখা যায়।

    target="_blank" — PDF নতুন ট্যাবে খোলে, তাই ছাপার পর ব্যবহারকারী
    ডকুমেন্টের পাতাতেই থাকেন। একই ট্যাবে খুললে ফিরতে হত ব্রাউজারের পিছনে
    যাওয়ার বোতাম দিয়ে, আর PDF থেকে ফেরাটা সব ব্রাউজারে সমান কাজ করে না।

    @param $documents  ['লেবেল' => 'রুটের নাম' => ['param' => value]] আকারে
                       নয় — সরল রাখা হয়েছে: ['label' => ..., 'url' => ...]
--}}
@php
    $papers = \App\Core\Engines\Print\PaperSize::all();
@endphp

{{-- ⓘ "বাইরে চাপলে বন্ধ" পুরো মেনুর উপর — মালিক, ৪ অক্টোবর ২০২৬: "মাপ % kaj korena, click korlei udaw"।
     ⛔ আগে এটা কেবল বোতামে ছিল, তাই মেনুর ভিতরে (মাপের ঘরে) চাপাও "বাইরে" ধরা হত। --}}
<div x-data="{ open: false }" @click.outside="open = false" class="relative print-hide">
    <button type="button"
            @click="open = ! open"
            :aria-expanded="open ? 'true' : 'false'"
            class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field)
                   border border-(--color-border) px-3 text-sm transition-colors
                   hover:bg-(--color-surface-hover)">
        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
            <path d="M7 3h10v4H7V3ZM5 9h14a2 2 0 0 1 2 2v6h-4v4H7v-4H3v-6a2 2 0 0 1 2-2Zm4 8h6v4H9v-4Z"/>
        </svg>
        {{ __('core.print.print') }}
    </button>

    <div x-show="open"
         x-cloak
         class="absolute end-0 z-20 mt-1 w-64 overflow-hidden rounded-(--radius-card)
                border border-(--color-border) bg-(--color-surface-card) shadow-lg">

        @foreach ($documents as $document)
            @php
                /*
                    ⭐ মালিকের বসানো মাপ — ২০ সেপ্টেম্বর ২০২৬।

                    ⓘ তিনটা মাপই দেখা যায় (মেশিন বদলালে এক ক্লিকে অন্যটা),
                    কিন্তু কোনটা "তাঁর মাপ" সেটা চোখে পড়ে। ⚠️ এটা না দেখালে
                    কেউ জানতেন না সেটিংসে কী বসানো আছে, আর প্রতিবার আন্দাজ
                    করে বাছতেন — যেটা ঠিক আগের অবস্থাই।
                */
                $chosen = isset($document['paper_setting'])
                    ? app(\App\Core\Services\SettingsService::class)->get($document['paper_setting'])
                    : null;

                $counts = isset($document['type'], $document['id'])
                    ? app(\App\Core\Services\PaperTrail::class)->countsFor($document['type'], (int) $document['id'])
                    : null;
            @endphp

            <div class="border-b border-(--color-border) last:border-b-0">
                <div class="px-3 pt-2 text-2xs font-medium text-(--color-ink-muted)">
                    {{ $document['label'] }}
                </div>

                {{--
                    ⭐ মাপ % — মালিকের বাছাই (খ), ১ অক্টোবর ২০২৬ ([[PrintScale]])।

                    ⓘ মাপ বাছা হয় PDF খোলার **আগে**: কাগজের বোতাম আর "মাপ %" একটা GET ফর্মে, তাই একটা চাপ = একটা
                    আঁকা = একবার গোনা। ⛔ PDF খোলার পরে মাপ বদলে আবার খোলার পথ রাখলে প্রতিটা আবার-খোলা হয় আবার
                    গোনা হত, নয়তো DUPLICATE ছাড়া বাড়তি কপি। ঘর ফাঁকা = স্বয়ংক্রিয় (একটু উপচানো বিল এক পাতায়);
                    ব্রাউজারের ▲▼ হলো −/+, আর তালিকায় ৫০·৭৫·১০০·১৫০·২০০। কোনো JS নেই।
                --}}
                @php
                    $action = strtok($document['url'], '?');
                    parse_str((string) parse_url($document['url'], PHP_URL_QUERY), $carried);
                    unset($carried['paper'], $carried['scale'], $carried['download']);
                @endphp
                <form method="GET" action="{{ $action }}" target="_blank">
                    @foreach ($carried as $key => $value)
                        @if (is_scalar($value))
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    {{-- ⓘ ফাইলের বোতামের কাগজ — কাগজের বোতাম নিজের মান পরে পাঠায়, আর সেটাই জেতে --}}
                    <input type="hidden" name="paper" value="{{ $chosen ?? \App\Core\Engines\Print\PaperSize::A4 }}">

                    <div class="flex gap-1 p-2">
                        @foreach ($papers as $paper)
                            <button type="submit" name="paper" value="{{ $paper }}"
                               class="min-w-0 flex-1 truncate rounded-(--radius-field) border px-2 py-1 text-center text-2xs
                                      transition-colors hover:bg-(--color-surface-hover)
                                      {{ $paper === $chosen
                                          ? 'border-(--color-brand-500) font-semibold'
                                          : 'border-(--color-border)' }}">
                                {{ \App\Core\Engines\Print\PaperSize::of($paper)->label() }}
                            </button>
                        @endforeach
                    </div>

                    <label class="flex items-center gap-2 px-3 pb-2 text-2xs text-(--color-ink-muted)">
                        {{ __('core.print.scale') }}
                        <input type="number" name="scale" inputmode="numeric"
                               min="{{ \App\Core\Engines\Print\PrintScale::MIN }}"
                               max="{{ \App\Core\Engines\Print\PrintScale::MAX }}"
                               step="{{ \App\Core\Engines\Print\PrintScale::STEP }}"
                               placeholder="{{ __('core.print.scale_auto') }}"
                               list="print-scale-{{ $loop->index }}"
                               class="w-20 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-2 py-1 text-end text-2xs text-(--color-ink)">
                        <datalist id="print-scale-{{ $loop->index }}">
                            @foreach ([50, 75, 100, 150, 200] as $pick)
                                <option value="{{ $pick }}"></option>
                            @endforeach
                        </datalist>
                    </label>

                    {{-- ⭐ ফাইল — মালিকের কথায়: *"sathe pdf o zate dwa zay"*। ⓘ ফাইলটা ঠিক ঐ কাগজ, একই মাপে, নতুন
                         করে আঁকা নয় — কেবল `download=1`, তাই গ্রাহকের কপি আর আমাদের কপি কোনোদিন আলাদা হয় না। --}}
                    @isset($document['share'])
                        <div class="px-2 pb-2">
                            <button type="submit" name="download" value="1"
                                    class="w-full rounded-(--radius-field) border border-(--color-border)
                                           px-2 py-1 text-center text-2xs transition-colors
                                           hover:bg-(--color-surface-hover)">
                                {{ __('core.print.as_file') }}
                            </button>
                        </div>
                    @endisset
                </form>

                @isset($document['share'])
                    <div class="flex gap-1 px-2 pb-2">
                        <form method="POST" action="{{ route('paper.share') }}" class="flex-1">
                            @csrf
                            <input type="hidden" name="route" value="{{ $document['share']['route'] }}">
                            @foreach ($document['share']['params'] ?? [] as $key => $value)
                                <input type="hidden" name="params[{{ $key }}]" value="{{ $value }}">
                            @endforeach
                            <input type="hidden" name="document_type" value="{{ $document['type'] }}">
                            <input type="hidden" name="document_id" value="{{ $document['id'] }}">
                            <input type="hidden" name="document_no" value="{{ $document['no'] ?? '' }}">
                            <input type="hidden" name="paper" value="{{ $chosen ?? \App\Core\Engines\Print\PaperSize::A4 }}">

                            <button type="submit"
                                    class="w-full rounded-(--radius-field) border border-(--color-brand-500)
                                           px-2 py-1 text-2xs font-medium text-(--color-brand-700)
                                           transition-colors hover:bg-(--color-surface-hover)">
                                {{ __('core.print.send_to_customer') }}
                            </button>
                        </form>
                    </div>
                @endisset

                {{-- ⓘ "ছেপে দিয়েছি" আর "তিনি খুলেছেন" আলাদা সংখ্যা — গ্রাহক
                     "পাইনি" বললে এই লাইনটাই তর্ক থামায়। --}}
                @if ($counts && array_sum($counts) > 0)
                    {{-- ⭐ সংখ্যাটা লিংক — মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬:
                         *"সব জায়গায় হাইপার লিংক দেওয়ার কথা"*।

                         ⓘ আর এখানে কারণটা আরও সোজা: "৪ বার ছাপা" নিজে কোনো
                         প্রশ্নের উত্তর নয়। ⚠️ আসল প্রশ্ন "কে পাঠাল, কখন" —
                         সেটা পিছনের তালিকায়, তাই গোটা লাইনটাই সেখানে নিয়ে যায়। --}}
                    <a href="{{ route('paper.history', ['type' => $document['type'], 'id' => $document['id']]) }}"
                       class="block px-3 pb-2 text-2xs text-(--color-ink-muted) underline
                              decoration-dotted underline-offset-2 transition-colors
                              hover:text-(--color-ink) hover:bg-(--color-surface-hover)">
                        {{ __('core.print.printed_times', ['n' => $counts['printed']]) }}
                        @if ($counts['downloaded'] > 0)
                            · {{ __('core.print.downloaded_times', ['n' => $counts['downloaded']]) }}
                        @endif
                        @if ($counts['shared'] > 0)
                            · {{ __('core.print.sent_times', ['n' => $counts['shared']]) }}
                        @endif
                        @if ($counts['opened'] > 0)
                            · {{ __('core.print.opened_times', ['n' => $counts['opened']]) }}
                        @endif
                    </a>
                @endif
            </div>
        @endforeach
    </div>
</div>
