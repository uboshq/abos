{{--
    রান্নাঘরের পর্দা — যে অর্ডারগুলো এখনো দেওয়া হয়নি।

    ── কেন এই পর্দাটা বাকি সব পর্দার মতো নয় ────────────────────────────
    এটা কেউ বসে পড়ে না। রাঁধুনি হাতে চামচ নিয়ে দূর থেকে তাকান, আর তিন
    সেকেন্ডে বুঝে নেন কোনটা ধরতে হবে। তাই বড় লেখা, বড় বোতাম, আর সারি
    নয় — কার্ড।

    ── কেন অপেক্ষার সময়টা সবচেয়ে বড় করে ───────────────────────────────
    কোন পদটা রাঁধা হবে সেটা রাঁধুনি নিজেই জানেন। যেটা তিনি জানেন না তা
    হলো **কোনটা সবচেয়ে বেশিক্ষণ বসে আছে** — আর ওটাই একমাত্র জিনিস যা
    ভুল হলে খদ্দের উঠে চলে যান।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.kitchen_tickets') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::menu.kitchen_tickets')"
                          :subtitle="__('inventory::message.tickets_note')" />
    </x-slot:header>

    <div x-data="{
             at: '{{ now()->format('H:i:s') }}',
             stale: false,
             /*
              * প্রতি দশ সেকেন্ড — বোর্ডের চেয়ে ঘন, কারণ প্রশ্নটা আলাদা।
              * "আর কয় প্লেট হবে" মিনিটে বদলায়; "নতুন অর্ডার এসেছে কি না"
              * সেকেন্ডে।
              */
             async pull() {
                 try {
                     const r = await fetch('{{ route('inventory.kitchen.feed') }}',
                                           { headers: { 'Accept': 'application/json' } });
                     if (! r.ok) { throw new Error(r.status); }
                     const d = await r.json();
                     this.at = d.at;
                     this.stale = false;

                     /* সংখ্যাটা বদলালে পাতাটা নতুন করে আনা হয়: কার্ডের
                        ভেতরটা হাতে সাজানোর চেয়ে সৎ, আর রান্নাঘরে কেউ
                        ফর্ম ভরে বসে নেই যেটা হারাতে পারে। */
                     if (d.tickets.length !== {{ $tickets->count() }}) {
                         window.location.reload();
                     }
                 } catch (e) {
                     this.stale = true;
                 }
             },
         }"
         x-init="setInterval(() => pull(), 10000)">

        <div class="mb-3 flex items-center gap-3">
            <span class="text-2xs text-(--color-ink-muted)">
                {{ __('inventory::message.checked_at') }} <span class="num" x-text="at"></span>
            </span>

            <span x-show="stale" x-cloak
                  class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 py-0.5
                         text-2xs text-(--color-badge-warning-ink)">
                {{ __('inventory::message.not_refreshing') }}
            </span>
        </div>

        @if ($tickets->isEmpty())
            <x-ui.empty-state :message="__('inventory::message.kitchen_quiet')" />
        @else
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($tickets as $ticket)
                    @php
                        $waiting = $ticket->waitingMinutes();
                    @endphp

                    {{-- দেরি হলে কার্ডটাই লাল। একটা ছোট ব্যাজ দূর থেকে
                         পড়া যায় না, আর এই পর্দাটা দূর থেকেই দেখা হয়। --}}
                    <section @class([
                        'rounded-(--radius-card) border p-4',
                        'border-(--color-danger) bg-(--color-badge-danger-bg)' => $waiting >= 15,
                        'border-(--color-border) bg-(--color-surface-card)' => $waiting < 15,
                    ])>
                        <div class="mb-2 flex items-baseline justify-between gap-2">
                            <span class="num text-2xs text-(--color-ink-muted)">{{ $ticket->document_no }}</span>

                            <span class="num text-lg font-bold">
                                {{ $waiting }}<span class="text-2xs font-normal">{{ __('inventory::field.minutes') }}</span>
                            </span>
                        </div>

                        <p class="text-xl font-semibold">
                            <span class="num">{{ (int) $ticket->qty }}</span> ×
                            {{ $ticket->product?->name() }}
                        </p>

                        @if ($ticket->note)
                            <p class="mt-1 text-sm text-(--color-ink-muted)">{{ $ticket->note }}</p>
                        @endif

                        <div class="mt-3 flex items-center gap-2">
                            @php
                                $next = match ($ticket->state) {
                                    \App\Modules\Inventory\Models\KitchenTicket::PLACED
                                        => [\App\Modules\Inventory\Models\KitchenTicket::COOKING, 'start_cooking'],
                                    \App\Modules\Inventory\Models\KitchenTicket::COOKING
                                        => [\App\Modules\Inventory\Models\KitchenTicket::READY, 'mark_ready'],
                                    default
                                        => [\App\Modules\Inventory\Models\KitchenTicket::SERVED, 'mark_served'],
                                };
                            @endphp

                            <span @class([
                                'rounded-(--radius-field) px-2 py-0.5 text-2xs',
                                'bg-(--color-badge-pending-bg) text-(--color-badge-pending-ink)'
                                    => $ticket->state === \App\Modules\Inventory\Models\KitchenTicket::COOKING,
                                'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                                    => $ticket->state === \App\Modules\Inventory\Models\KitchenTicket::READY,
                                'bg-(--color-surface-sunken) text-(--color-ink-muted)'
                                    => $ticket->state === \App\Modules\Inventory\Models\KitchenTicket::PLACED,
                            ])>
                                {{ __('inventory::state.'.$ticket->state) }}
                            </span>

                            <span class="flex-1"></span>

                            {{-- বড় বোতাম: রাঁধুনির হাত ভেজা, আর পর্দাটা
                                 হাতের নাগালেই থাকে। --}}
                            <form method="POST"
                                  action="{{ route('inventory.kitchen.advance', $ticket) }}">
                                @csrf
                                <input type="hidden" name="to" value="{{ $next[0] }}">

                                <button type="submit"
                                        class="min-h-11 rounded-(--radius-field) bg-(--color-brand-600) px-4
                                               font-semibold text-(--color-ink-inverse)
                                               transition-opacity hover:opacity-90">
                                    {{ __('inventory::action.'.$next[1]) }}
                                </button>
                            </form>
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
