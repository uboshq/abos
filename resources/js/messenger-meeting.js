/*
 * মিটিংয়ে ডাকা — যে পাতাতেই থাকুন ছোট পপআপ, আর দলের কল / নতুন মিটিং শুরু। ⓘ খবরটা [[messenger-call.js]]-এর একই হালকা
 * জিজ্ঞাসায় আসে (`messenger:unread`); দুজনের কলের কিছুই এখানে নয়।
 */
import { api, paintAvatar, refusal, toast, urlFor } from './messenger-call.js'

const dismissed = new Set()
let now = null

export function showMeeting (box, meeting) {
    const usable = meeting && !dismissed.has(meeting.id)
    box.hidden = !usable
    now = usable ? meeting : null
    if (!usable) return
    paintAvatar(box.querySelector('[data-msgr-meet-avatar]'), meeting.peer)
    box.querySelector('[data-msgr-meet-name]').textContent = meeting.peer?.name || ''
    box.querySelector('[data-msgr-meet-title]').textContent = meeting.title || ''
}

/** ⓘ জানালাটা চাপার মুহূর্তেই খোলা — উত্তরের পরে খুললে ব্রাউজার আটকে দেয় */
export async function startMeeting (body, start = urlFor('meeting_start')) {
    const tab = window.open('', '_blank')
    const res = await api(start.replace('__KEY__', crypto.randomUUID()), { method: 'POST', body })
    if (!res.ok || !res.data?.data?.url) {
        tab?.close()
        toast(refusal(res, 'toast.failed'))
        return
    }
    if (tab) tab.location.href = res.data.data.url
    else window.location.href = res.data.data.url
}

export function bootMeetingInvite (doc = document) {
    const box = doc.querySelector('[data-msgr-meet]')
    window.addEventListener('messenger:meeting', (e) => startMeeting(e.detail || {}), true)
    if (!box) return
    window.addEventListener('messenger:unread', (e) => showMeeting(box, e.detail?.meeting || null))
    box.addEventListener('click', (e) => {
        const b = e.target instanceof Element ? e.target.closest('[data-msgr-meet-act]') : null
        if (!b || !now) return
        const id = now.id
        dismissed.add(id)
        box.hidden = true
        if (b.dataset.msgrMeetAct === 'open') window.open(urlFor('meeting').replace('__MEETING__', encodeURIComponent(id)), '_blank')
        else api(urlFor('meeting_decline').replace('__MEETING__', encodeURIComponent(id)), { method: 'POST', body: {} })
        now = null
    })
}
