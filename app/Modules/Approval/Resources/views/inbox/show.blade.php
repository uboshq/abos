{{--
    একটা অনুরোধ — আর সিদ্ধান্তের দুইটা বোতাম।

    উপরে যা যা দেখে সিদ্ধান্ত নেওয়া হয়: কী চাওয়া হয়েছে, কত টাকার,
    কোন কাগজে, কে চেয়েছে, আর কেন। ডকুমেন্টটা খোলার লিংকও আছে — অঙ্ক
    দেখে সন্দেহ হলে কাগজটা না দেখে কেউ যেন "হ্যাঁ" না বলেন।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.inbox') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$labels[$approval->module.'.'.$approval->action] ?? $approval->module.' · '.$approval->action"
            :subtitle="$approval->requested_at?->format('d M Y, H:i')">
            <x-slot:actions>
                <x-ui.badge :tone="match ($approval->status) {
                    \App\Models\Approval::APPROVED => 'success',
                    \App\Models\Approval::REJECTED => 'danger',
                    \App\Models\Approval::CANCELLED => 'draft',
                    default => 'pending',
                }">
                    {{ __('approval::status.'.$approval->status) }}
                </x-ui.badge>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.requested_by') }}
                        </dt>
                        <dd class="text-sm">{{ $approval->requester?->name ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.amount') }}
                        </dt>
                        <dd class="tabular text-sm">
                            {{ $approval->amount === null ? '—' : \App\Core\Support\Money::format($approval->amount) }}
                        </dd>
                    </div>

                    <div class="sm:col-span-2">
                        <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.document') }}
                        </dt>
                        <dd class="text-sm">
                            @if ($document === null)
                                <span class="text-(--color-ink-muted)">{{ __('approval::message.document_gone') }}</span>
                            @elseif (method_exists($document, 'drillRoute'))
                                <a href="{{ route(...$document->drillRoute()) }}"
                                   class="text-(--color-brand-600) underline-offset-2 hover:underline">
                                    {{ $document->drillDocumentNo() }} — {{ $document->drillLabel() }}
                                </a>
                            @else
                                {{ class_basename($document).' #'.$approval->approvable_id }}
                            @endif
                        </dd>
                    </div>

                    @if ($approval->requested_reason)
                        <div class="sm:col-span-2">
                            <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                                {{ __('approval::field.reason') }}
                            </dt>
                            <dd class="text-sm">{{ $approval->requested_reason }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- সিদ্ধান্তের ইতিহাস — কোন স্তরে কে কী বলেছেন।

                 শুধু চূড়ান্ত অবস্থা রাখলে "তিন নম্বর স্তরে আটকে ছিল কেন"
                 প্রশ্নের উত্তর কখনো পাওয়া যেত না। --}}
            @if ($approval->decisions->isNotEmpty())
                <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                    <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-3 py-2 text-sm font-semibold">
                        {{ __('approval::field.decisions') }}
                    </h2>

                    <x-ui.table
                        :empty="__('approval::field.decisions')"
                        :rows="$approval->decisions"
                        :columns="[
                            ['key' => 'level', 'label' => __('approval::field.level'), 'width' => '5rem'],
                            ['key' => 'user', 'label' => __('approval::field.approver'),
                             'render' => fn ($d) => $d->user?->name],
                            ['key' => 'decision', 'label' => __('approval::field.status'), 'width' => '8rem',
                             'render' => fn ($d) => __('approval::status.'.$d->decision)],
                            ['key' => 'remarks', 'label' => __('approval::field.remarks')],
                            ['key' => 'decided_at', 'label' => __('approval::field.requested_at'), 'width' => '11rem',
                             'render' => fn ($d) => $d->decided_at?->format('d M Y, H:i')],
                        ]" />
                </div>
            @endif
        </div>

        <div class="space-y-3">
            @if ($approval->status === \App\Models\Approval::PENDING && $canDecide)
                <form method="POST" action="{{ route('approval.inbox.approve', $approval->id) }}"
                      class="rounded-(--radius-card) border border-(--color-border)
                             bg-(--color-surface-card) p-4">
                    @csrf

                    <label class="block">
                        <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.remarks') }}
                        </span>
                        <textarea name="remarks" rows="3" maxlength="500"
                                  class="w-full rounded-(--radius-field) border border-(--color-border)
                                         bg-(--color-surface-app) px-2 py-1.5 text-sm"></textarea>
                    </label>

                    <x-ui.button type="submit" tone="primary" class="mt-3 w-full">
                        {{ __('approval::action.approve') }}
                    </x-ui.button>
                </form>

                {{-- ফেরত পাঠাতে কারণ লেখা বাধ্যতামূলক — "না" শুনে মানুষ
                     প্রথমেই জানতে চান কেন, আর না জানলে একই অনুরোধ আবার
                     আসে। --}}
                <form method="POST" action="{{ route('approval.inbox.reject', $approval->id) }}"
                      class="rounded-(--radius-card) border border-(--color-border)
                             bg-(--color-surface-card) p-4">
                    @csrf

                    <label class="block">
                        <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.remarks') }} *
                        </span>
                        <textarea name="remarks" rows="3" maxlength="500" required
                                  class="w-full rounded-(--radius-field) border border-(--color-border)
                                         bg-(--color-surface-app) px-2 py-1.5 text-sm"></textarea>
                    </label>

                    <x-ui.button type="submit" tone="danger" class="mt-3 w-full">
                        {{ __('approval::action.reject') }}
                    </x-ui.button>
                </form>
            @elseif ($approval->status === \App\Models\Approval::PENDING)
                <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)
                          p-4 text-sm text-(--color-ink-muted)">
                    {{ $approval->requested_by === auth()->id()
                        ? __('approval::message.own_request')
                        : __('approval::message.not_your_turn') }}
                </p>
            @endif

            @if ($approval->status === \App\Models\Approval::PENDING && $approval->requested_by === auth()->id())
                <form method="POST" action="{{ route('approval.inbox.withdraw', $approval->id) }}">
                    @csrf
                    <x-ui.button type="submit" tone="secondary" class="w-full">
                        {{ __('approval::action.withdraw') }}
                    </x-ui.button>
                </form>
            @endif
        </div>
    </div>
</x-layouts.app>
