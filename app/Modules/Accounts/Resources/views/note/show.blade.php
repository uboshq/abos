{{--
    একটা নোট — কী, কাকে, কত, আর কোন কাগজের বিপরীতে।

    ⭐ খসড়া অবস্থায় বইয়ে কিছুই যায়নি; "নিশ্চিত করুন" চাপলে তবেই দাখিলা
    বসে ([[NoteService::confirm()]])। ⓘ বাতিল করলে দাখিলা ফিরিয়ে নেওয়া হয়,
    মুছে ফেলা হয় না — বই মোছা যায় না।
--}}
@php
    use App\Core\Support\Money;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $note->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$note->document_no"
                          :subtitle="$note->isCredit()
                              ? __('accounts::note.credit_note')
                              : __('accounts::note.debit_note')">
            <x-slot:actions>
                {{-- ⭐ ছাপা — মাপ, ফাইল আর কতবার ছাপা হলো ([[NotePrintController]]); মালিক, ৩ অক্টোবর ২০২৬ --}}
                <x-ui.print-menu :documents="[[
                    'label' => $note->document_no,
                    'url' => route('accounts.note.print', $note),
                    'paper_setting' => 'accounts.print.paper.note',
                    'type' => 'accounts_note',
                    'id' => $note->id,
                    'no' => $note->document_no,
                ]]" />

                @can('accounts.note.manage')
                    @if ($note->isDraft())
                        <form method="POST" action="{{ route('accounts.note.confirm', $note) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">
                                {{ __('accounts::note.confirm') }}
                            </x-ui.button>
                        </form>
                    @endif

                    @unless ($note->isCancelled())
                        {{-- ⚠️ কারণ বাধ্যতামূলক — নোট নিজেই একটা ব্যাখ্যার কাগজ,
                             আর ব্যাখ্যাহীন বাতিল পরে কেউ বুঝত না --}}
                        <form method="POST" action="{{ route('accounts.note.cancel', $note) }}"
                              x-data="reasonPrompt({ question: @js(__('accounts::note.cancel_reason_prompt')), empty: @js(__('core.form.cancel_needs_reason')) })"
                              @submit="ask($event)">
                            @csrf
                            <input type="hidden" name="cancel_reason" x-ref="reason">
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('accounts::note.cancel') }}
                            </x-ui.button>
                        </form>
                    @endunless
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    {{-- ⭐ উল্টো কাগজের সূত্র (মালিকের সংস্করণ ২, ৪ অক্টোবর ২০২৬) --}}
    @include('accounts::partials.reversal-reference', ['type' => 'note', 'id' => $note->id])

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    @if ($note->isCancelled())
        <p role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                               text-sm text-(--color-badge-danger-ink)">
            {{ __('accounts::note.cancelled') }}
            @if (filled($note->cancel_reason))
                — {{ $note->cancel_reason }}
            @endif
        </p>
    @endif

    <section data-boxed
             class="max-w-screen-2xl rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::note.party') }}</dt>
                {{-- ⭐ নামটা তাঁর নিজের পাতায় যায় — মালিকের নিয়ম --}}
                <dd class="font-medium">
                    @if ($partyRoute)
                        <a href="{{ route($partyRoute[0], $partyRoute[1]) }}"
                           class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ $partyName }}</a>
                    @else
                        {{ $partyName }}
                    @endif
                </dd>
            </div>

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.date') }}</dt>
                <dd class="font-medium">{{ $note->trx_date?->format('d M Y') }}</dd>
            </div>

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::note.reason') }}</dt>
                <dd class="font-medium">{{ __('accounts::note.reason_'.$note->reason) }}</dd>
            </div>

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::note.against_no') }}</dt>
                <dd class="font-medium">{{ $note->against_no ?: '—' }}</dd>
            </div>

            {{-- ⭐ দুই খাত — পক্ষের আর অন্য পাশের (সমন্বয়কের শর্ত, ৩ অক্টোবর ২০২৬; [[NoteAccounts]]) --}}
            <div data-note-control>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::note.control_account') }}</dt>
                <dd class="font-medium">{{ $accounts['control']->code }} — {{ $accounts['control']->name() }}</dd>
            </div>

            <div data-note-other>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::note.other_account') }}</dt>
                <dd class="font-medium">{{ $accounts['other']->code }} — {{ $accounts['other']->name() }}</dd>
            </div>

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::note.amount') }}</dt>
                <dd class="font-medium tabular-nums">{{ Money::format($note->amount) }}</dd>
            </div>

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::note.tax_amount') }}</dt>
                <dd class="font-medium tabular-nums">{{ Money::format($note->tax_amount) }}</dd>
            </div>

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::note.total') }}</dt>
                <dd class="font-semibold tabular-nums">{{ Money::format($note->total) }}</dd>
            </div>

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('core.table.status') }}</dt>
                <dd>@include('accounts::note.partials.status', ['note' => $note])</dd>
            </div>

            @if (filled($note->narration))
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.narration') }}</dt>
                    <dd>{{ $note->narration }}</dd>
                </div>
            @endif
        </dl>

        {{-- ⓘ খসড়া মানে বইয়ে এখনো কিছুই যায়নি — সেটা লেখা থাকা দরকার,
             নাহলে মানুষ ধরে নেন কাগজটা কাটা হয়ে গেছে --}}
        @if ($note->isDraft())
            <p class="mt-4 text-sm text-(--color-ink-muted)">{{ __('accounts::note.saved') }}</p>
        @endif
    </section>
</x-layouts.app>
