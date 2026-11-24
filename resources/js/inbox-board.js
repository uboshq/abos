/*
 * ⭐ The approval inbox board — rose, navy and blue only (owner "ok", 23 Nov 2026, sample YRFmwfF7 v5).
 *
 * ⓘ Four small jobs, nothing else:
 *   1. fit the board to the screen, so each side scrolls on its own (`--board-h`);
 *   2. a kind's tick picks every pickable row of that kind (⛔ money papers have no box — owner's decision 5 — and
 *      the server checks again in [[BulkApproval]]);
 *   3. the pick bar says how much is picked (how many is on the button — never twice);
 *   4. a click on a row (not on a link or a box) opens that paper on the right.
 * ⓘ The bulk button's label is [[approval-keys.js]]'s job (same `data-inbox-pick` boxes) — not done twice here.
 */
const money = (value) => {
    const [whole, part = '00'] = Number(value).toFixed(2).split('.')
    const last3 = whole.slice(-3)
    const rest = whole.slice(0, -3)

    return (rest ? rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' : '') + last3 + '.' + part
}

export function inboxBoard (root = document, win = window) {
    const board = root.querySelector('[data-inbox-board]')

    if (! board) {
        return
    }

    const fit = () => {
        const top = board.getBoundingClientRect().top + (win.scrollY || 0)
        // ⓘ stop at the bottom of the page's own frame (the shell's footer sits under it), not at the window's edge
        const frame = board.closest('main')?.getBoundingClientRect()
        const bottom = frame && frame.bottom > top ? Math.min(win.innerHeight, frame.bottom + (win.scrollY || 0)) : win.innerHeight

        board.style.setProperty('--board-h', Math.max(560, bottom - top - 40) + 'px')
    }

    fit()
    win.addEventListener('resize', fit)

    const pickedSum = root.querySelector('[data-board-picked-sum]')

    const tally = () => {
        const boxes = [...root.querySelectorAll('[data-inbox-pick]:checked')]
        const cents = boxes.reduce((sum, box) => sum + Math.round(Number(box.dataset.amount || 0) * 100), 0)

        if (pickedSum) {
            // ⓘ nothing picked → no amount (the sample)
            pickedSum.textContent = boxes.length === 0 ? '' : '৳' + money(cents / 100)
        }

        root.querySelectorAll('[data-board-group-pick]').forEach((group) => {
            const mine = [...root.querySelectorAll('[data-board-in]')].filter((box) => box.dataset.boardIn === group.dataset.boardGroupPick)
            const on = mine.filter((box) => box.checked).length

            group.checked = mine.length > 0 && on === mine.length
            group.indeterminate = on > 0 && on < mine.length
        })
    }

    root.addEventListener('change', (event) => {
        const group = event.target.closest?.('[data-board-group-pick]')

        // ⓘ the bar's round tick picks every row that has a tick
        if (event.target.matches?.('[data-board-pick-all]')) {
            const on = event.target.checked

            root.querySelectorAll('[data-inbox-pick]').forEach((box) => {
                box.checked = on
                box.dispatchEvent(new Event('change', { bubbles: true }))
            })
        }

        if (group) {
            root.querySelectorAll('[data-board-in]').forEach((box) => {
                if (box.dataset.boardIn === group.dataset.boardGroupPick) {
                    box.checked = group.checked
                    box.dispatchEvent(new Event('change', { bubbles: true }))
                }
            })
        }

        if (group || event.target.matches?.('[data-inbox-pick]')) {
            tally()
        }
    })

    board.addEventListener('click', (event) => {
        const row = event.target.closest?.('[data-board-open]')

        if (! row || event.target.closest('a, button, input, label, select, textarea')) {
            return
        }

        win.location.assign(row.dataset.boardOpen)
    })

    tally()
}
