@props([
    'message' => null,

    /*
     * সেটের একটা নাম, ইমোজি নয়।
     *
     * খালি অবস্থায় ছবিটা বড় হয়ে বসে, আর ইমোজি ওই মাপে তার নিজের রং
     * নিয়ে আসে — পর্দার বাকি সব যখন শান্ত ধূসর, তখন একটা রঙিন বাক্স
     * "কিছু নেই" কথাটার চেয়ে জোরে চেঁচায়। আঁকা ছবিটা কালি নেয়, তাই
     * সে জানে তার জায়গা কোথায়।
     */
    'icon' => 'inventory',
])

{{--
    Empty state — সেকশন ১৫.১৭।

    ফাঁকা তালিকা মানে ব্যবহারকারী আটকে গেছে। শুধু "কিছু নেই" লিখলে সে জানে
    না এরপর কী করতে হবে। তাই অন্তত একটা করণীয় থাকতে হবে — slot-এ বোতাম দিন।
--}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    {{-- disabled-এর ধূসর, muted নয়: এটা তথ্য নয়, জায়গাটা ভরে রাখা --}}
    <x-ui.icon :name="$icon" :size="40" class="mb-3 text-(--color-ink-disabled)" />

    <p class="text-(--color-ink-muted)">
        {{ $message ?? __('core.empty.nothing_here') }}
    </p>

    @if (! $slot->isEmpty())
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
            {{ $slot }}
        </div>
    @endif
</div>
