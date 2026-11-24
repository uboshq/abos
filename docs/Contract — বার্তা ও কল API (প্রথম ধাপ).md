# Contract — Messenger and calls API, phase 1

**Date:** 21 November 2026
**Two sides:** the server is `fe/msg` (abos-63). The app is `fe-app-msg`, built by abos-7c (abos-3b): `mobile/lib/core/messenger/messenger_api.dart` and `messenger_push.dart`.
**Sample:** https://claude.ai/artifact/3nuwRUdnxgD5rbNtoQzVWP v5. Build what is drawn and nothing more.
**Server module:** `app/Modules/Messaging`. The module code is `messaging`; the API path is `/api/v1/messenger/…`.

⭐ The app wrote its contract first. The server follows the app's field names exactly. The server only **adds** fields and doors; it never renames or removes one.

---

## 0 · Common rules

- **Guard:** the same as every phone door. `auth:sanctum`, `throttle:app` (300/min), the app token ability, company context, and `RefuseModulesOffOnThePhone:messaging` (`403 module_off`).
- **Key:** every door needs `messaging.use`. Creating a group needs `messaging.group.create`.
- **403 body:** `{ "error": code, "message": "a Bengali sentence" }`. The app shows the `message` ([MessengerNotAllowed]).

| `error` | When |
|---|---|
| `forbidden` | Not an admin for a group action. Note: a missing `messaging.use` is a plain 403 (Laravel's own body plus a Bengali `message`), and a thread the caller is not in is a **404** ("exists" is information too) |
| `may_not_talk` | The role allow-list forbids this pair |
| `group_create_denied` | The caller has no `messaging.group.create` |
| `paper_denied` | The **sender** may not open the paper themselves |

| Other status | Meaning |
|---|---|
| `422` | Laravel validation, plus too-big or wrong-type files |
| `503 calls_off` | LiveKit is not set up |

- **IDs are strings:**
  - thread = UUID v7 (the house `public_id`);
  - message = a number as a string; it grows, so it doubles as the cursor;
  - person = the user id as a string;
  - call = ULID.
- **Dates:** ISO-8601 with an offset.

---

## 1 · Objects (the app's `fromJson` names)

**person** (`ChatPerson`):
`id`, `name`, `designation` (first role's display name), `branch`, `photo_url` (null = initials), `colour` (`#RRGGBB` or null), `online` (seen in the last 60 s), `can_open_paper` (only when `people` is called with a paper).

**thread** (`ChatThread`):
`id`, `kind` (`direct`|`group`), `title`, `subtitle` ("হিসাবরক্ষক · TD শাখা" or "গ্রুপ · 5 জন"), `person` (direct only), `group_icon` (`users` in phase 1), `colour`, `last` (message), `last_at`, `unread`, `muted`, `member_count`, `online_count`.

**message** (`ChatMessage`):
- Basic fields: `id`, `thread_id`, `client_id`, `kind`, `at`, `mine`, `sender` (person; null when `mine`), `text`, `state`, `read_count`, `attachment`, `paper`, `lat`, `lng`.
- `kind` is one of `text`, `image`, `file`, `voice`, `paper`, `location`, `system`.
- `text` holds the message itself, or the caption under an image or paper, or the line of a `system` message (for example "নাসির ফারজানাকে গ্রুপে যোগ করেছেন").
- `state` (sent / delivered / read):
  - `sent`: stored on the server;
  - `delivered`: every other member has fetched it;
  - `read`: every other member has read it.
- `read_count`: in a group, for my own message, how many members have read it.
- `reply_to` is `{ id, kind, text (short preview), sender: person, mine }` or null (sample v7).
- `forwarded` (bool) — a copy sent on from another thread.
- `kind: "deleted"` — deleted for everyone: `text` "", `attachment`, `paper`, `lat`, `lng` null; the app shows "এই বার্তাটি মুছে ফেলা হয়েছে".
- **Unread** counts only other people's live messages: never system lines, tombstones, or messages hidden "for me".

**attachment** (`ChatAttachment`):
- Fields: `url`, `name`, `mime`, `size` (bytes), `duration_ms`, `waveform` (a list of 0–1 values).
- `url` is guarded: the app sends the same Bearer token. Only members of the thread get the file; anyone else gets 404.
- *Server addition:* `thumb_url` (images, 400 px) and `width`/`height`.

**paper** (`PaperRef`):
- Fields: `type`, `id`, `no`, `title`, `amount` ("৳1,24,500", formatted on the server, English digits), `party`, `status`, `can_open`, `path`.
- `type` is the server's own naming (`SalesInvoice`, `Voucher`, …), the same as `DocumentActionsSheet.type` and `/api/v1/documents/{type}/{id}`.
- `id` is the paper's `public_id`.
- When `can_open` is **false**, only `type`, `id`, `no` and `title` come; `amount`, `party` and `status` are empty strings. The paper itself opens only through its own door (`/documents/{type}/{id}`), which applies its own policy. The card grants nothing.
- `path` is `null` in phase 1.

---

## 2 · Doors: the app's list, as the server serves it

| Door | Body / query | Answer |
|---|---|---|
| `GET /messenger/threads` | `filter=all\|unread\|groups\|papers`, `q` | `{ data: [thread], unread_total }`, newest first, 50 |
| `GET /messenger/threads/{id}/messages` | `before` or `after` (message id) | `{ data: [message] }`, oldest first, 30 a page |
| `POST /messenger/threads/{id}/messages` | JSON: `kind=text`, `text`, `client_id` · `kind=location`, `lat`, `lng`, `client_id` · multipart: `kind=image\|file\|voice`, `file`, `client_id`, `duration_ms`, `waveform` ("0.12,0.80,…"), optional `text` | `201 { data: message }`. The same `client_id` again returns `200` with the first copy |
| `POST /messenger/threads/{id}/read` | `up_to` (message id) | `204` |
| `POST /messenger/threads/{id}/mute` | `muted` (bool) | `{ data: thread }` |
| `GET /messenger/threads/{id}/members` | | `{ data: [person] }` |
| `GET /messenger/threads/{id}/media` | optional `kind=image\|file\|paper` | `{ data: [message] }`, newest first, 100 |
| `GET /messenger/people` | `q`, optionally `paper_type` + `paper_id` | `{ data: [person] }`: only people this user may talk to. With a paper, each has `can_open_paper` |
| `POST /messenger/direct` | `person` | `{ data: thread }`: finds or creates |
| `POST /messenger/papers` | `paper_type`, `paper_id`, `to: ["person:12", "thread:01J…"]`, `note`, `client_id` | `201 { data: { sent: 2, thread_ids: [...] } }`; `403 paper_denied` if the sender may not open it |
| `GET /messages/x/papers/find` (web only) | `q` (number; empty = most recent) | `{ data: [{ type, id, no, title, amount, party }] }` — only papers the sender may open; the composer's attach → "ERP কাগজ" |
| `POST /messenger/calls` | `person`, `video` (bool) | `201 { data: { call_id, url, token, video, peer, room } }` |
| `POST /messenger/calls/{id}/answer` | | `{ data: { call_id, url, token, video, peer, room } }`: callee only, while ringing |
| `POST /messenger/calls/{id}/decline` | | `204`: callee only |
| `POST /messenger/calls/{id}/end` | | `204`: either side. Ringing → `missed`; answered → `ended` + duration |

### Server additions (the sample draws these; the app can add them when ready)

| Door | Body / query | Answer | Why |
|---|---|---|---|
| `GET /messenger/threads/{id}/messages?after=` → `meta` | | `meta: { typing: [person], peer_online: bool, states: [{ id, state, read_count }] }` | "রাকিব লিখছেন…", the online dot, and ticks changing on my sent messages (last 100) without a refetch |
| `POST /messenger/threads/{id}/typing` | | `204` | at most once every 3 s while typing; shown for 6 s |
| `POST /messenger/delivered` | `marks: { "<thread id>": "<last message id>" }` | `204` | the "পৌঁছেছে" tick after a push or a background fetch. ⓘ `GET …/messages` also marks delivered by itself |
| `GET /messenger/threads/{id}/search` | `q` | `{ data: [message] }` newest first, 50 | ⋮ → "কথোপকথনে খুঁজুন" |
| `POST /messenger/groups` | `title`, `people: ["12","14"]` | `201 { data: thread }` | needs `messaging.group.create` |
| `POST /messenger/threads/{id}/members` | `people: [...]` | `{ data: thread }` | group admin only; writes the "… যোগ করেছেন" system line |
| `GET /messenger/calls` | | `{ data: [{ call_id, video, direction: in\|out, peer, status, at, answered_at, ended_at, duration_s }] }` | the call log |
| `GET /messenger/calls` with any of these | `kind` (missed, in, out), `person` (user id), `q` (the other side's name), `cursor` (the answer's `next`), `limit` (1–100) | `{ data: [the same call row], next: string\|null }` | one page of my own history; with none of them the answer is the row above, unchanged |
| `GET /messenger/calls/people` | `person` (optional) | `{ data: [{ peer, calls, answered, missed, seconds, last_at, last }] }` | my calls added up per colleague; `last` is a call row |

---

## 3 · Uploads (inside the send)

| kind | Accepted | Limit | What the server does |
|---|---|---|---|
| image | jpeg, png, webp | 10 MB in | Strips EXIF and GPS, longest side 2000 px, ≤ 800 KB (the deposit slip's engine) |
| file | pdf, xlsx, xls, docx, doc, csv, txt, zip | 20 MB | Kept as sent |
| voice | m4a, aac (audio/mp4, audio/aac, audio/x-m4a, audio/m4a) | 5 MB | Kept as sent |

Files sit on the **private** disk. They are not public URLs.

---

## 4 · Calls (LiveKit on the office Mac mini, `wss://rtc.univer.com.bd`)

- One room per call: `call-<call id>`.
- The token is signed with HS256:
  - `iss` = the API key;
  - `sub` = the user id;
  - `name` = the display name;
  - `video` = `{ roomJoin: true, room, canPublish: true, canSubscribe: true }`;
  - it is valid for 2 hours.
- Settings live in `.env`: `LIVEKIT_URL`, `LIVEKIT_API_KEY` and `LIVEKIT_API_SECRET`.
- A call nobody answers becomes `missed` after **45 s**. A sweep runs every minute, and the server also checks whenever it reads a call.

---

## 5 · Push (FCM `abos_alerts` channel)

The `data` field is exactly what `messenger_push.dart` reads: `{ open: "messenger", type, thread_id, message_id, call_id, video, from }`. Every value is a string.

| type | When | Title / body |
|---|---|---|
| `chat` | a new message | Title: the sender ("Sender · Group" in a group). Body: the text, cut to 80 characters, or "📷 ছবি", "📄 ফাইল", "🎤 ভয়েস", "🧾 INV-0517" (number only), or "📍 অবস্থান" |
| `call` | an incoming call, high priority | Title: the caller. Body: "ABOS ভয়েস কল আসছে…" or "ABOS ভিডিও কল আসছে…" |
| `call_end` | the caller hung up, or nobody answered: closes the ringing screen | data only; `status` = `missed`, `ended`, `declined` |

- A muted thread sends no `chat` push.
- A call always rings.
- The sender's own phones get no push.
- No money ever appears on the lock screen.

---

## 6 · Realtime on cPanel (no websockets)

The host is cPanel: no websockets and no long-running process.

1. FCM wakes the phone.
2. While a chat is open, the app polls `…/messages?after=` every **3 s**, slowing to **5 s** after 30 s of quiet.
3. While the list is open, the app polls `/messenger/threads` every **10 s**.
4. Nothing polls in the background.

Typing, online and ticks ride in the same `meta`. One open chat costs 20 requests a minute or less, against a limit of 300.

---

## 7 · Privacy, and what phase 1 leaves out

**Privacy**
- Messages stay on the company's own server.
- Normally nobody reads another person's messages. The owner or an admin can read a thread only through the web audit page, which needs the `messaging.audit` key and a written reason. Every such read is recorded in the audit trail. The app has no API for this.

**Message actions (sample v7, owner-approved)**
- `POST /messenger/forward` `{ message_ids, to: ["person:12","thread:…"], client_id }` → `201 { data: { sent, thread_ids } }`.
- `DELETE /messenger/messages/{id}?for=me|everyone` → `204`. `everyone` only for one's own message within 60 minutes, else `403 delete_not_allowed`.
- `GET /messenger/messages/{id}/info` → `{ data: [ { person, delivered_at, read_at } ] }` for every member except the sender.
- `meta.states` reports `{ id, state: "deleted" }` for any message in the last 100.
- Paper send and forward check **every** target first; one refused target means nothing is sent.
- A `client_id` belongs to one message in one thread; reusing it in another thread is `422`.

**Not in the samples, so not built in phase 1**
- editing a message;
- reactions;
- group calls and video meetings (phase 2).
