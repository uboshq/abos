        <section data-boxed class="relative h-full rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-selected) p-3"
                 style="box-shadow: inset var(--rail-tile-on-edge-w, 2px) 0 0 var(--color-brand-500)">

            {{-- ── সরবরাহকারী — মালিকের ক্রমের সাত নম্বর ────────────

                 *"…Warehouse, Supplier Details — ei krome bosaw"*

                 ⭐ ছয়টা ছোট ঘরের **নিচে**, একটাই চওড়া খোঁজার বার।
                 ⓘ কারণটা কাজের: কাউন্টারে সবচেয়ে বেশি যেটা ছোঁয়া হয়
                 সেটাই সবচেয়ে বড় হওয়া দরকার।

                 ⚠️ সরবরাহকারী বদলালেই গতবারের দরগুলো নতুন করে আসে।
                 দরগুলো সরবরাহকারী-ভেদে আলাদা; বাছাই বদলে পুরনো তালিকা
                 রেখে দিলে কার্টের সারিতে **অন্য একজনের দর** বসে থাকত —
                 নীরবে, আর ঠিক তখনই যখন মানুষটা ওই সংখ্যাটা দেখে
                 দরাদরি করছেন। --}}
            {{-- ── ক্রেতার ঘরের মতো — চিহ্ন বাঁয়ে, নাম ডানে ─────────────

                 মালিক (৬ সেপ্টেম্বর ২০২৬): *"Supplier sarch icon kaj korena,
                 Cust er moto koro"*।

                 ⛔ এখানে একটা `<select>` ছিল, আর চিহ্নটা কেবল **ফোকাস**
                 করত — চেপে কিছুই খুলত না। ⚠️ তিন হাজার সরবরাহকারীর তালিকায়
                 `<select>` কার্যত অব্যবহার্য: প্রথম অক্ষরের পরে ব্রাউজার আর
                 কিছু মেলায় না।

                 ⭐ এখন বিক্রয়ের কাউন্টারের হুবহু নিয়ম: চিহ্নে চাপলে তালিকা
                 খোলে, টাইপ করলে ছাঁকে, বাছলে বন্ধ হয় আর নামটা পাশে বসে।

                 ⓘ `<select>` গেছে, কিন্তু **মানটা যায়নি** — লুকানো ঘরে
                 `supplier_id` আগের মতোই সার্ভারে যায়। --}}
            {{-- ── মালিকের ছক (৬ সেপ্টেম্বর ২০২৬) ────────────────────────

                     [চিহ্ন]  সরবরাহকারীর নাম
                     Mobile: +8801711000002
                     চরপাড়া, ময়মনসিংহ
                     Received by: Al-Amin Shuvo (Owner)

                 ⓘ নিচের তিনটা সারি **বাক্সের একদম বাঁ কিনারা থেকে** — চিহ্নের
                 নিচে ইন্ডেন্ট করা নয়। ⚠️ ইন্ডেন্ট থাকলে ঠিকানার মতো লম্বা
                 লেখা আগেই কেটে যেত, আর বাঁ পাশে একটা খালি খাঁজ পড়ে থাকত।

                 ⭐ ঠিকানার কোনো লেবেল নেই — মালিক: *"sudu Address liko"*।
                 ⓘ ঠিকানা দেখলেই ঠিকানা বোঝা যায়; নামটা লিখে জায়গা নষ্ট
                 করার দরকার নেই। ⚠️ মোবাইল আর "কে বুঝে নিলেন" — দুইটাতেই
                 লেবেল থাকে, কারণ কাঁচা সংখ্যা বা কাঁচা নাম নিজে থেকে কিছু
                 বলে না।

                 ⛔ *"স্বত্বাধিকারী"* সারিটা বাদ — তিনি তিনটা সারিই চেয়েছেন। --}}
            <div class="mt-2 flex items-center gap-2">
                <button type="button"
                        @click="supplierPickerOpen = ! supplierPickerOpen"
                        :aria-expanded="supplierPickerOpen ? 'true' : 'false'"
                        aria-label="{{ __('purchase::field.supplier') }}"
                        :class="supplierPickerOpen
                            ? 'border-(--color-brand-600) bg-(--color-brand-600) text-(--color-brand-ink)'
                            : 'border-(--color-brand-500) bg-(--color-surface-card) text-(--color-brand-700) hover:bg-(--color-surface-hover)'"
                        {{-- ⓘ চিহ্নটা ৩০% ছোট — মালিকের নির্দেশ (৯ → ৬)। --}}
                        class="grid size-6 shrink-0 place-items-center rounded-(--radius-field)
                               border-2 transition-colors">
                    <x-ui.icon name="search" :size="12" />
                </button>

                {{-- ⭐ এক ধাপ ছোট — মালিকের নির্দেশ, ৬ সেপ্টেম্বর ২০২৬:
                     *"Supplier নামের হরফ এক ধাপ ছোট, তখন Akij Food & Beverage
                     পুরোটা ধরবে"*।

                     ⛔ চওড়া পর্দায় `xl:text-xl` উঠে যেত, আর তখন লম্বা নামগুলো
                     `truncate`-এ কেটে **তিনটা বিন্দু** হয়ে যেত। ⚠️ সরবরাহকারীর
                     নামটা কাটা মানে কাগজটা কার সাথে, সেটাই আধা-জানা।

                     ⓘ তাই ধাপটা তুলে দেওয়া হলো — সব মাপে `text-lg`। বড় পর্দায়
                     জায়গা আছে বলেই হরফ বড় করতে হবে, এমন নয়; **নামটা পুরো
                     দেখা যাওয়াই বড় হওয়ার চেয়ে দামি**। --}}
                <span class="min-w-0 flex-1 truncate text-lg font-semibold text-(--color-ink)"
                      :title="supplier?.name"
                      x-text="supplier?.name || @js(__('purchase::message.search_supplier'))"></span>
            </div>

            <div x-show="supplier" x-cloak class="mt-1 truncate text-sm text-(--color-ink)">
                <span class="text-(--color-ink-muted)">{{ __('purchase::field.mobile') }}:</span>
                <span class="num font-semibold" x-text="supplier?.phone || '—'"></span>
            </div>

            <div x-show="supplier" x-cloak class="mt-1 truncate text-sm text-(--color-ink)"
                 :title="supplier?.address"
                 x-text="supplier?.address || '—'"></div>

            <div x-show="supplier" x-cloak class="mt-1 truncate text-2xs text-(--color-ink-muted)">
                {{ __('purchase::field.received_by') }}:
                <span class="font-semibold text-(--color-ink)">{{ $receivedBy }}</span>
            </div>

            <input type="hidden" name="supplier_id" x-model="supplierId">

            {{-- ছাঁকনি — টাইপের সাথে সাথে, আর বাছা হলেই বন্ধ --}}
            <div x-show="supplierPickerOpen" x-cloak
                 @keydown.escape="supplierPickerOpen = false"
                 {{-- ⭐ **ভাসমান**, প্রবাহের ভিতরে নয় — মালিকের নির্দেশ,
                      ৬ সেপ্টেম্বর ২০২৬: *"ekhaneo same somossa, search korle box
                      soho nore"*।

                      ⛔ প্যানেলটা জায়গা **দখল করত**, তাই সরবরাহকারী খুঁজতে গেলেই
                      কার্ডটা লম্বা হত — আর পাশের চালান-কার্ডটাও তার সাথে টেনে
                      লম্বা হত (`h-full`), তাই **দুইটা কার্ড একসাথে বাড়ত**।
                      ⚠️ তিনি ছবিতে ঐ **তৈরি হওয়া বিশাল ফাঁকা জায়গাটাই** লাল
                      বাক্সে ঘিরে দেখিয়েছেন।

                      ⓘ বিক্রয়ের দুইটা পিকারে আজ এই একই সারাই বসেছে, আর ক্রয়ের
                      **পণ্যের** তালিকাটা আগে থেকেই ভাসত (`absolute z-20`) —
                      অর্থাৎ নিয়মটা এই ফাইলেই ছিল, কেবল সরবরাহকারীর ঘরে
                      পৌঁছায়নি।

                      ⚠️ `z-30` — পাশের চালান-কার্ডের উপরে থাকতে হবে। --}}
                 class="absolute inset-x-0 top-full z-30 mt-1 rounded-(--radius-card)
                        border-2 border-(--color-brand-500) bg-(--color-surface-card)
                        p-1.5 text-(--color-ink) shadow-lg">
                <input type="search" x-model="supplierTerm"
                       x-effect="supplierPickerOpen && $nextTick(() => $el.focus())"
                       placeholder="{{ __('purchase::message.search_supplier') }}"
                       class="h-(--spacing-field-dense) w-full rounded-(--radius-field)
                              border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">

                <ul class="mt-1.5 max-h-56 overflow-y-auto">
                    <template x-for="row in supplierMatches" :key="row.id">
                        <li>
                            <button type="button" @click="chooseSupplier(row.id)"
                                    :class="row.id === supplierId
                                        ? 'bg-(--color-surface-selected) font-semibold' : ''"
                                    class="w-full rounded-(--radius-field) px-2 py-1.5 text-start
                                           hover:bg-(--color-surface-hover)">
                                <span class="block truncate text-sm" x-text="row.name"></span>
                                <span class="num block text-2xs text-(--color-ink-muted)"
                                      x-text="row.phone || ''"></span>
                            </button>
                        </li>
                    </template>

                    <li x-show="supplierMatches.length === 0" x-cloak
                        class="px-2 py-2 text-2xs text-(--color-ink-muted)">
                        {{ __('purchase::message.no_supplier_match') }}
                    </li>
                </ul>
            </div>

            {{-- ⓘ এখানে আগে একটা `<dl>` ছিল — মোবাইল · স্বত্বাধিকারী ·
                 ঠিকানা · কে বুঝে নিলেন, চারটা `লেবেল : মান` জোড়া।

                 ⚠️ কথাগুলো হারায়নি — উপরে সরবরাহকারীর নামের নিচে
                 সারি হয়ে বসেছে, ক্রেতার ঘরের মতো। ⛔ দুই জায়গায়
                 রাখলে একই কথা দুইবার হত, আর একদিন একটা বদলাত আর
                 অন্যটা বদলাত না। --}}
        </section>
