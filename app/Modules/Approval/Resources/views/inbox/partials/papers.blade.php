{{--
    ⭐ কাগজের সংযুক্তি — ব্যাংক বা বিকাশের স্লিপ সই দেওয়ার পাতাতেই, ২৮ সেপ্টেম্বর ২০২৬।

    ⛔ আগে স্লিপ থাকত ভাউচারের পাতায়; সইকারী সেখানে না গেলে দেখতেন না, আর
    ভাউচার দেখার চাবি না থাকলে যেতেও পারতেন না। ⓘ এখন ছবি এখানেই দেখা যায়,
    PDF লিংকে খোলে। ⚠️ খোলার অনুমতি download-এর দরজাই ঠিক করে
    ([[AttachmentController::download()]]) — অপেক্ষমাণ অনুমোদনের সইকারী বা
    অনুরোধকারী; এই পাতা কেবল দেখায়, নিজে কিছু ছাড় দেয় না।
--}}
@php
    $sourceType = $document::drillSourceType();
    $papers = app(\App\Core\Engines\Attachment\AttachmentEngine::class)->listFor(
        app(\App\Core\Engines\Drill\DrillResolver::class)->moduleFor($sourceType) ?? '',
        $sourceType,
        (int) $document->getKey(),
    );
@endphp

@if ($papers->isNotEmpty())
    <div data-approval-papers
         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <h2 class="text-sm font-semibold">{{ __('core.attachment.title') }}</h2>

        <ul class="mt-3 grid gap-3 sm:grid-cols-2">
            @foreach ($papers as $paper)
                <li class="min-w-0">
                    @if (str_starts_with((string) $paper->mime_type, 'image/'))
                        <a href="{{ route('attachment.download', $paper) }}">
                            <img src="{{ route('attachment.download', $paper) }}"
                                 alt="{{ $paper->original_name }}" loading="lazy"
                                 class="max-h-[90vh] w-full rounded-(--radius-field) border border-(--color-border)">
                        </a>
                    @endif

                    <a href="{{ route('attachment.download', $paper) }}"
                       class="mt-1 block truncate text-sm text-(--color-brand-500) underline-offset-2 hover:underline">
                        {{ $paper->original_name }}
                    </a>
                    <span class="tabular text-2xs text-(--color-ink-muted)">{{ $paper->humanSize() }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
