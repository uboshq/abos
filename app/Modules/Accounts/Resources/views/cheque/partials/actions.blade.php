{{--
    সিদ্ধান্ত সারি থেকেই — জমা, পাশ, ফেরত।

    আলাদা পাতায় পাঠালে প্রতিটা সিদ্ধান্তে যাওয়া-আসা করতে হত, আর দিনে
    দশটা চেকে সেটা অসহ্য।
--}}
@if ($cheque->isOpen())
    @can('accounts.cheque.manage')
        <div class="flex flex-wrap items-center justify-end gap-2">
            @if ($cheque->status === \App\Modules\Accounts\Models\Cheque::PENDING)
                <form method="POST" action="{{ route('accounts.cheque.deposit', $cheque) }}">
                    @csrf
                    <x-ui.button type="submit" tone="secondary">
                        {{ __('accounts::action.cheque_deposit') }}
                    </x-ui.button>
                </form>
            @endif

            <form method="POST" action="{{ route('accounts.cheque.clear', $cheque) }}"
                  class="flex items-center gap-1">
                @csrf
                @if (! $cheque->bank_account_id)
                    <select name="bank_account_id" required
                            class="h-(--spacing-field) rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-app) px-2">
                        @foreach ($banks as $bank)
                            <option value="{{ $bank->id }}">{{ $bank->label() }}</option>
                        @endforeach
                    </select>
                @endif
                <x-ui.button type="submit" tone="primary">
                    {{ __('accounts::action.cheque_clear') }}
                </x-ui.button>
            </form>

            {{--
                ফেরত — আদায়ে-পোস্ট-করা চেক (গ্রাহকের, সরাসরি বিক্রয়ের) হলে
                টাকাটা পোস্ট করেছিল আদায়ের কাগজ, চেক নিজে নয়। তাই ওগুলোর
                ফেরত যায় Sales-দরজায় (আদায় বাতিল হয়, বিল বকেয়া ফেরে)।
                Accounts নিচের স্তর, Sales-কে চেনে না — view কেবল রুট-নাম চেনে।
                বাকি (হাতে-তোলা/ক্রয়) চেক আগের মতোই ChequeService-এর পথে।
            --}}
            @php
                $bounceRoute = $cheque->postedByCollection()
                    ? route('sales.collection.cheque_bounce', $cheque)
                    : route('accounts.cheque.bounce', $cheque);
                $canBounce = ! $cheque->postedByCollection() || auth()->user()?->can('sales.collection.cancel');
            @endphp

            @if ($canBounce)
                <form method="POST" action="{{ $bounceRoute }}" class="flex items-center gap-1">
                    @csrf
                    <input type="text" name="bounce_reason" required minlength="3"
                           placeholder="{{ __('accounts::field.bounce_reason') }}"
                           class="h-(--spacing-field) w-44 rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-app) px-2">
                    <x-ui.button type="submit" tone="secondary">
                        {{ __('accounts::action.cheque_bounce') }}
                    </x-ui.button>
                </form>
            @endif
        </div>
    @endcan
@else
    <span class="text-2xs text-(--color-ink-muted)">{{ $cheque->cleared_on?->format('d M Y') }}</span>
@endif
