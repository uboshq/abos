{{--
    নিয়মের বাইরে যা কিছু — এক পর্দায়।

    ── ⚠️ কেন এই পর্দাটা লাগে ─────────────────────────────────────────
    ⓘ এখানকার ছয় ধরনের প্রতিটাই **নীরব**: কিছুই ভাঙে না, কোনো পাতা লাল
    হয় না, আর ব্যবস্থাটা দেখতে ঠিকই লাগে। ⛔ কেউ খুঁজতে না গেলে কোনোদিন
    জানা যায় না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::exception.title') }}</x-slot:title>

    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-sunken) px-3 py-2 text-sm">
        {{ __('approval::exception.note') }}
    </p>

    @forelse ($rows as $kind => $group)
        <section data-boxed
                 class="mb-4 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">
                {{ __('approval::exception.kind.'.$kind) }}
                <span class="text-(--color-ink-muted)">· {{ count($group) }}</span>
            </h2>

            <ul class="mt-2 text-sm">
                @foreach ($group as $row)
                    <li class="flex flex-wrap justify-between gap-2 border-t border-(--color-border)
                               py-2 first:border-0 first:pt-0">
                        <span class="min-w-0 font-medium">{{ $row['what'] }}</span>
                        <span class="text-(--color-ink-muted)">{{ $row['detail'] }}</span>
                    </li>
                @endforeach
            </ul>

            {{-- ⚠️ কাটা পড়েছে কি না — লুকানো হয় না, লেখা হয়।

                 ⓘ এই দলটায় ঠিক সীমার সমান সারি মানে সম্ভবত আরও আছে।
                 ⛔ না বললে পাতাটা সম্পূর্ণ দেখাত, অথচ বাকিগুলো চুপচাপ
                 লুকিয়ে রাখত — আর এই পর্দার গোটা কারণটাই "যা চুপ করে
                 আছে তা দেখানো"। --}}
            @if (count($group) >= \App\Modules\Approval\Services\ApprovalExceptions::LOOK_AT)
                <p role="status" class="mt-2 text-2xs text-(--color-badge-warning-ink)">
                    {{ __('approval::exception.capped', [
                        'count' => \App\Modules\Approval\Services\ApprovalExceptions::LOOK_AT,
                    ]) }}
                </p>
            @endif
        </section>
    @empty
        <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)
                  p-4 text-sm text-(--color-ink-muted)">
            {{ __('approval::exception.none') }}
        </p>
    @endforelse
</x-layouts.app>
