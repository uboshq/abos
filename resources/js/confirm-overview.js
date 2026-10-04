/*
 * ⭐ নিশ্চিতের আগে সারাংশ — ওয়েবের পপ-আপ (মালিক, ৪ অক্টোবর ২০২৬): *"নিশ্চিত করুন botam caple ekta overvew dekhabe,
 * r web e ei overvew ta popup e dekhabe tar por nischit korbe ba khosora rakbe … sob kichutei"*।
 *
 * ── কীভাবে ──────────────────────────────────────────────────────────
 * যে ফর্মে `data-confirm-overview="<ঠিকানা>"` বসানো, তার "নিশ্চিত" জমা প্রথমবার থামে; ফর্মের ঘরগুলো ঐ ঠিকানায়
 * যায়, সার্ভার সারাংশ আঁকে (কেন্দ্রীয় ইঞ্জিন [[ConfirmOverview]] → [[x-ui.confirm-overview]]-এর ভিতরের অংশ), আর
 * পাতার `<dialog data-confirm-overview-dialog>`-এ দেখায়। পপ-আপের তিন বোতাম:
 *   "নিশ্চিত করুন" — ফর্ম এবার সত্যিই জমা; "খসড়া রাখুন" — `save_as_draft=1` দিয়ে জমা; "ফিরে যান" — বন্ধ।
 * ⓘ কোন বোতামে পপ-আপ: যে জমা-বোতামে `data-overview-trigger` (খসড়ার বোতাম সরাসরি জমা দেয়, তার সারাংশ লাগে না)।
 *
 * ── ⚠️ কেন `capture` ─────────────────────────────────────────────────
 * [[one-submit.js]] জমা হলেই ফর্মে "জমা হয়েছে" বসায় আর বোতাম বন্ধ করে। এই শ্রোতা আগে চলে (capture), থামালে
 * `defaultPrevented` — তাই একবার-জমার পাহারা এটাকে জমা গোনে না, আর পপ-আপের পরের আসল জমা আটকায় না।
 *
 * ⛔ পপ-আপ কেবল দেখায়। আসল পাহারা (সীমা, সই, লট, দর) সার্ভারে নিশ্চিতের সময় — JS বন্ধ থাকলেও নিয়ম ভাঙে না।
 * ⓘ CSP-Alpine নয় — সাধারণ শ্রোতা, তাই ব্লেডের কোনো অ্যাট্রিবিউটে যুক্তি লিখতে হয় না।
 */
export function guardConfirmOverview (root = document, fetcher = (...args) => fetch(...args)) {
    root.addEventListener('submit', async (event) => {
        const form = event.target

        if (!(form instanceof HTMLFormElement) || !form.dataset.confirmOverview) {
            return
        }

        const trigger = event.submitter
        if (!trigger || !trigger.hasAttribute('data-overview-trigger')) {
            return
        }

        // ⓘ সারাংশ দেখে "নিশ্চিত" চাপা হয়েছে — এবার আসল জমা
        if (form.dataset.overviewSeen === '1') {
            delete form.dataset.overviewSeen
            return
        }

        event.preventDefault()
        event.stopImmediatePropagation()

        const dialog = root.querySelector('[data-confirm-overview-dialog]')
        const body = dialog?.querySelector('[data-confirm-overview-body]')
        if (!dialog || !body) {
            return
        }

        const data = new FormData(form)
        // ⛔ এক-জমার চিহ্ন সারাংশে যায় না — নাহলে পাহারা ([[OneSubmitPerForm]]) চিহ্নটা এখানেই খরচ করে, আর আসল
        // "নিশ্চিত"/"হালনাগাদ" ফেরে "জমা হয়েছে — একটু পরে তালিকায় দেখুন" বলে, কিছুই না বদলে (INV-0002, ৪ অক্টোবর ২০২৬)
        data.delete('_once')
        const token = form.querySelector('input[name="_token"]')?.value ?? ''

        body.textContent = dialog.dataset.loading ?? ''
        openDialog(dialog)

        try {
            const response = await fetcher(form.dataset.confirmOverview, {
                method: 'POST',
                body: data,
                headers: { 'X-CSRF-TOKEN': token, Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
            body.innerHTML = await response.text()
        } catch (e) {
            body.textContent = dialog.dataset.failed ?? ''
        }

        const blocks = body.querySelector('[data-overview-blocks="1"]') !== null
        const confirm = dialog.querySelector('[data-overview-confirm]')
        if (confirm) {
            confirm.disabled = blocks
        }

        dialog.onclick = (click) => {
            const button = click.target instanceof Element ? click.target.closest('[data-overview-confirm], [data-overview-draft], [data-overview-back]') : null
            if (!button) {
                return
            }
            click.preventDefault()
            closeDialog(dialog)

            if (button.hasAttribute('data-overview-back')) {
                return
            }

            const wantsDraft = button.hasAttribute('data-overview-draft')

            // ⓘ কাউন্টারের মতো ফর্মে খসড়া নিজেই এক জমা-বোতাম (`name="save_as_draft" value="1"`) — HTML কেবল চাপা
            // বোতামের মান পাঠায়, তাই খসড়া মানে ঐ বোতাম দিয়েই জমা (তাতে পপ-আপ নেই)
            const draftButton = form.querySelector('button[name="save_as_draft"][value="1"]:not([data-overview-trigger])')
            if (wantsDraft && draftButton && typeof form.requestSubmit === 'function') {
                form.requestSubmit(draftButton)
                return
            }

            const draft = form.querySelector('input[name="save_as_draft"]')
            if (draft) {
                draft.value = wantsDraft ? '1' : '0'
            }

            form.dataset.overviewSeen = '1'
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(trigger)
            } else {
                form.submit()
            }
        }
    }, true)
}

function openDialog (dialog) {
    if (typeof dialog.showModal === 'function') {
        dialog.showModal()
    } else {
        dialog.setAttribute('open', '')
    }
}

function closeDialog (dialog) {
    if (typeof dialog.close === 'function') {
        dialog.close()
    } else {
        dialog.removeAttribute('open')
    }
}
