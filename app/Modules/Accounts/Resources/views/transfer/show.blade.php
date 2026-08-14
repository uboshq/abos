{{--
    একটা হস্তান্তর — আর তার স্লিপ।

    স্লিপে দুইটা সইয়ের জায়গা থাকে: যে দিল, আর যে নিল। কাগজে-কলমে ওই
    দুইটা সই-ই একমাত্র প্রমাণ, আর সেটাই এই ব্যবস্থার আসল কারণ। পর্দায়
    নাম দুইটা দেখা যায়, কাগজে সইয়ের ঘর।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $transfer->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$transfer->document_no"
                          :subtitle="__('accounts::menu.money_transfer')">
            <x-slot:actions>
                @if ($transfer->isPending())
                    @can('accounts.transfer.confirm')
                        <form method="POST" action="{{ route('accounts.transfer.confirm', $transfer) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">
                                {{ __('accounts::action.receive') }}
                            </x-ui.button>
                        </form>
                    @endcan
                @endif

                @unless ($transfer->isCancelled())
                    @can('accounts.transfer.create')
                        <form method="POST" action="{{ route('accounts.transfer.cancel', $transfer) }}"
                              x-data="{ ask() {
                                  const r = prompt('{{ __('accounts::message.cancel_reason_prompt') }}');
                                  if (! r) return false;
                                  this.$refs.reason.value = r;
                                  return true;
                              } }"
                              @submit="if (! ask()) $event.preventDefault()">
                            @csrf
                            <input type="hidden" name="cancel_reason" x-ref="reason">
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('core.action.cancel') }}
                            </x-ui.button>
                        </form>
                    @endcan
                @endunless
                {{-- স্লিপটা — দুইজনের সইয়ের কাগজ।

                     বাতিল করা হস্তান্তরেও ছাপা যায়, ইচ্ছাকৃতভাবে: কাগজে
                     "বাতিল" লেখা ওঠে, আর ওই কাগজটাই প্রমাণ যে হস্তান্তরটা
                     হয়নি। ছাপা বন্ধ করলে বাতিলের কোনো কাগজ থাকত না। --}}
                <x-ui.print-menu :documents="[
                    ['label' => __('accounts::print.handover_title'),
                     'url' => route('accounts.transfer.print', $transfer)],
                ]" />
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($transfer->isPending())
        {{-- অপেক্ষমাণ অবস্থাটা স্পষ্ট করে বলা দরকার: দাতা মনে করছে টাকা
             চলে গেছে, অথচ হিসাবে এখনো তার কাছেই — আর সেটাই ইচ্ছাকৃত। --}}
        <div role="status"
             class="mb-4 rounded-(--radius-card) border border-(--color-warning)
                    bg-(--color-badge-warning-bg) px-4 py-3 text-sm text-(--color-badge-warning-ink)">
            {{ __('accounts::message.still_with_giver', ['name' => $transfer->fromTill?->name()]) }}
        </div>
    @endif

    @if ($transfer->isCancelled())
        <div role="status"
             class="mb-4 rounded-(--radius-card) border border-(--color-danger)
                    bg-(--color-badge-danger-bg) px-4 py-3 text-sm text-(--color-badge-danger-ink)">
            <p class="font-semibold">{{ __('accounts::message.transfer_is_cancelled') }}</p>
            <p class="mt-1">{{ $transfer->cancel_reason }}</p>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">{{ __('accounts::field.amount') }}</h2>
            <p class="num mt-1 text-2xl font-semibold">{{ \App\Core\Support\Money::format($transfer->amount) }}</p>

            <div class="mt-3">
                @include('accounts::transfer.partials.status', ['transfer' => $transfer])
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4
                        lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <p class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.moved_from') }}</p>
                    <p class="text-sm font-medium">{{ $transfer->fromTill?->name() }}</p>

                    <p class="mt-2 flex items-center gap-2 text-sm">
                        @if ($transfer->giver)
                            <x-ui.avatar :user="$transfer->giver" size="sm" />
                            {{ $transfer->giver->name }}
                        @else
                            <span class="text-(--color-ink-muted)">—</span>
                        @endif
                    </p>
                </div>

                <div>
                    <p class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.moved_to') }}</p>
                    <p class="text-sm font-medium">{{ $transfer->destinationName() }}</p>

                    <p class="mt-2 flex items-center gap-2 text-sm">
                        @if ($transfer->receiver)
                            <x-ui.avatar :user="$transfer->receiver" size="sm" />
                            {{ $transfer->receiver->name }}
                        @else
                            <span class="text-(--color-ink-muted)">—</span>
                        @endif
                    </p>
                </div>
            </div>

            <dl class="mt-4 grid gap-x-4 gap-y-2 border-t border-(--color-border) pt-3 sm:grid-cols-3">
                @foreach ([
                    'accounts::field.date' => \App\Core\Support\DateFormat::format($transfer->trx_date),
                    'core.company.branch' => $transfer->branch?->name(),
                    'core.table.narration' => $transfer->narration,
                ] as $label => $value)
                    @if (filled($value))
                        <div>
                            <dt class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dt>
                            <dd class="text-sm">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>

            @if ($transfer->isConfirmed())
                <p class="mt-3 border-t border-(--color-border) pt-3 text-2xs text-(--color-ink-muted)">
                    {{ __('accounts::message.received_at', [
                        'name' => $transfer->confirmer?->name ?? '—',
                        'at' => \App\Core\Support\DateFormat::formatWithTime($transfer->confirmed_at) ?? '—',
                    ]) }}
                </p>
            @endif
        </section>
    </div>
</x-layouts.app>
