/*
 * দিনের হাজিরার খাতা — খোঁজা আর বিভাগ পাতার ভিতরেই সারি লুকায়, পাতা আবার খোলে না; লুকানো সারিও জমা হয়, তাই একটা লেখাও হারায় না।
 * ⓘ না-সংরক্ষিত লেখা থাকতে তারিখ বদলালে বা পাতা ছাড়লে ব্রাউজার জিজ্ঞেস করে।
 */
export function daySheet () {
    return {
        term: '',
        department: '',
        shown: 0,
        was: '',
        saving: false,

        init () {
            // ⓘ থামা সংরক্ষণ থেকে ফেরা পাতা — ঘরে যা আছে তা এখনো জমা হয়নি
            this.was = document.getElementById('attendance-form')?.hasAttribute('data-unsaved') ? '-' : this.written()
            this.narrow()
            this.guard = (event) => {
                if (this.saving || this.written() === this.was) return
                event.preventDefault()
                event.returnValue = ''
            }
            window.addEventListener('beforeunload', this.guard)
        },

        destroy () {
            window.removeEventListener('beforeunload', this.guard)
        },

        /** এখন ফর্মে যা লেখা */
        written () {
            // ⓘ id ধরে, $refs নয় — মাঝে প্যানেলের নিজের x-data আছে
            const form = document.getElementById('attendance-form')
            return form ? JSON.stringify(Array.from(new FormData(form).entries()).filter(([k]) => k.startsWith('rows['))) : ''
        },

        narrow () {
            const term = this.term.trim().toLowerCase()
            let shown = 0

            for (const row of this.$root.querySelectorAll('[data-day-row]')) {
                const on = (term === '' || (row.dataset.find || '').toLowerCase().includes(term))
                    && (this.department === '' || row.dataset.department === this.department)

                row.style.display = on ? '' : 'none'
                if (on) shown++
            }

            this.shown = shown
        },

        saved () {
            this.saving = true
        },
    }
}
