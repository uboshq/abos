import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../../core/locale/l10n.dart';
import '../../../core/messenger/messenger_api.dart';
import '../../../core/messenger/messenger_push.dart';
import '../../../core/messenger/messenger_unread.dart';
import '../chat/chat_screen.dart';
import 'chat_list_look.dart';
import 'people_picker.dart';

/// ⭐ বার্তার তালিকা — মালিকের অনুমোদিত নমুনা 3nuwRUdn v5 "ক" (২১ নভেম্বর ২০২৬)। অ্যাপের নিজের উপরের আর নিচের বারের ভেতরে
/// খোলে (≡ মেনুর পাতার মতো; [HomeShell]), তিন রূপে: স্মার্ট, গ্লাস, ক্লাসিক।
///
/// <p>উপরে খোঁজার গোল ঘর, তারপর চিপ (সব · না-পড়া N · গ্রুপ · কাগজ), তারপর কথোপকথনের সারি; নিচে-ডানে সবুজ "নতুন বার্তা"।
/// ⛔ অনলাইনের সারি আর "বার্তা" শিরোনাম নেই (মালিকের নমুনায় নেই)।
///
/// <p>⭐ নতুন করে আনা: খুললে, নিচে টানলে, push-এ `chat` খবর এলে, অ্যাপে ফিরলে, চ্যাট থেকে ফিরলে, আর খোলা থাকলে প্রতি ১০ সেকেন্ডে
/// (পেছনে কখনো নয়)। সাথে উপরের বারের গোনাও ([MessengerUnread])। দরজা নেই → শান্ত খালি পাতা, ভাঙে না।
class ChatListPage extends ConsumerStatefulWidget {
  const ChatListPage({super.key, this.now});

  /// ঘড়ি — পরীক্ষার পথ
  final DateTime Function()? now;

  /// খোলা থাকলে কতক্ষণ পর পর
  static const Duration poll = Duration(seconds: 10);

  @override
  ConsumerState<ChatListPage> createState() => _ChatListPageState();
}

enum _State { loading, ready, missing, notAllowed, failed }

class _ChatListPageState extends ConsumerState<ChatListPage> with WidgetsBindingObserver {
  ThreadFilter _filter = ThreadFilter.all;
  final TextEditingController _query = TextEditingController();
  Timer? _debounce;
  Timer? _poll;
  StreamSubscription<MessengerEvent>? _push;

  _State _state = _State.loading;
  String? _deniedMessage;
  List<ChatThread> _threads = const [];
  int _unreadTotal = 0;
  bool _foreground = true;

  MessengerApi get _api => ref.read(messengerApiProvider);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _push = MessengerPush.instance.events.listen((e) {
      if (e.type == 'chat') _load();
    });
    _poll = Timer.periodic(ChatListPage.poll, (_) {
      if (_foreground && mounted) _load();
    });
    _load();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    if (_foreground) _load();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _push?.cancel();
    _poll?.cancel();
    _debounce?.cancel();
    _query.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final filter = _filter;
    final query = _query.text.trim();
    try {
      final page = await _api.threads(filter: filter, query: query);
      if (!mounted || filter != _filter || query != _query.text.trim()) return;
      setState(() {
        _threads = page.threads;
        _unreadTotal = page.unreadTotal;
        _state = _State.ready;
      });
      MessengerUnread.count.value = page.unreadTotal;
    } on MessengerDoorsMissing {
      if (mounted) setState(() => _state = _State.missing);
      MessengerUnread.count.value = null;
    } on MessengerNotAllowed catch (e) {
      if (mounted) {
        setState(() {
          _state = _State.notAllowed;
          _deniedMessage = e.message;
        });
      }
      MessengerUnread.count.value = null;
    } catch (_) {
      // ⓘ আগে কিছু দেখানো থাকলে সেটাই থাকে — কেবল প্রথমবার ব্যর্থ হলে ব্যর্থতার পাতা
      if (mounted && _state != _State.ready) setState(() => _state = _State.failed);
    }
  }

  void _pick(ThreadFilter f) {
    if (f == _filter) return;
    setState(() => _filter = f);
    _load();
  }

  void _onQuery(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), _load);
  }

  Future<void> _open(ChatThread t) async {
    await openChat(context, t);
    if (mounted) _load();
  }

  Future<void> _new() async {
    final person = await pickPerson(context, title: context.l10n.msgListNew);
    if (person == null || !mounted) return;
    await messagePerson(context, ref.read(messengerApiProvider), person);
    if (mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    final t = ChatListLook.of(context);
    final l = context.l10n;
    final bottom = MediaQuery.paddingOf(context).bottom;
    final now = (widget.now ?? DateTime.now)();

    final head = <Widget>[
      _SearchPill(t: t, controller: _query, hint: l.msgListSearch, onChanged: _onQuery),
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 2),
        child: Wrap(spacing: 8, runSpacing: 6, children: [
          _Chip(t: t, k: 'all', label: l.msgListAll, on: _filter == ThreadFilter.all, onTap: () => _pick(ThreadFilter.all)),
          _Chip(
              t: t,
              k: 'unread',
              label: _unreadTotal > 0 ? l.msgListUnreadCount('$_unreadTotal') : l.msgListUnread,
              on: _filter == ThreadFilter.unread,
              onTap: () => _pick(ThreadFilter.unread)),
          _Chip(t: t, k: 'groups', label: l.msgListGroups, on: _filter == ThreadFilter.groups, onTap: () => _pick(ThreadFilter.groups)),
          _Chip(t: t, k: 'papers', label: l.msgListPapers, on: _filter == ThreadFilter.papers, onTap: () => _pick(ThreadFilter.papers)),
        ]),
      ),
      const SizedBox(height: 2),
    ];

    Widget? calm;
    switch (_state) {
      case _State.loading:
        calm = const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()));
      case _State.missing:
        calm = _Calm(t: t, icon: Symbols.forum, text: l.msgListNotOpenYet);
      case _State.notAllowed:
        calm = _Calm(t: t, icon: Symbols.lock, text: _deniedMessage ?? l.msgListNotAllowed);
      case _State.failed:
        calm = _Calm(t: t, icon: Symbols.cloud_off, text: l.msgListLoadFailed, action: l.msgListRetry, onAction: _load);
      case _State.ready:
        if (_threads.isEmpty) {
          calm = _Calm(
              t: t,
              icon: Symbols.forum,
              text: _query.text.trim().isEmpty && _filter == ThreadFilter.all ? l.msgListEmpty : l.msgListNoMatch);
        }
    }
    final doorsOpen = _state != _State.missing && _state != _State.notAllowed;

    final list = RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const ValueKey('msg-list'),
        physics: const AlwaysScrollableScrollPhysics(),
        // ⓘ শেষ সারি "নতুন বার্তা"-র ওপরে শেষ হয় — বোতাম কখনো তথ্য ঢাকে না (fab_never_hides_data)
        padding: EdgeInsets.only(top: 8, bottom: bottom + 14 + 54 + 12),
        children: [
          ...head,
          if (calm != null)
            calm
          else
            for (final (i, th) in _threads.indexed)
              _Row(t: t, thread: th, now: now, last: i == _threads.length - 1, onTap: () => _open(th)),
        ],
      ),
    );

    return ColoredBox(
      color: t.bg,
      child: Stack(children: [
        Positioned.fill(child: list),
        if (doorsOpen)
          Positioned(
            right: 16,
            bottom: bottom + 14,
            child: _NewButton(label: l.msgListNew, onTap: _new),
          ),
      ]),
    );
  }
}

/// নমুনার `.srch` — ৪৪ উঁচু গোল ঘর, বাঁয়ে খোঁজার চিহ্ন
class _SearchPill extends StatelessWidget {
  const _SearchPill({required this.t, required this.controller, required this.hint, required this.onChanged});

  final ChatListLook t;
  final TextEditingController controller;
  final String hint;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 48),
          child: DecoratedBox(
            decoration: BoxDecoration(color: t.search, borderRadius: BorderRadius.circular(24)),
            child: Row(children: [
              const SizedBox(width: 16),
              Icon(Symbols.search, size: 18, color: t.muted),
              const SizedBox(width: 10),
              Expanded(
                child: TextField(
                  key: const ValueKey('msg-search'),
                  controller: controller,
                  onChanged: onChanged,
                  textInputAction: TextInputAction.search,
                  style: TextStyle(fontSize: 14.5, color: t.ink),
                  cursorColor: t.glass ? ChatListLook.onColour : ChatListLook.green,
                  decoration: InputDecoration(
                    isDense: true,
                    filled: false,
                    border: InputBorder.none,
                    enabledBorder: InputBorder.none,
                    focusedBorder: InputBorder.none,
                    hintText: hint,
                    hintStyle: TextStyle(fontSize: 14.5, color: t.muted),
                    contentPadding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                ),
              ),
              const SizedBox(width: 12),
            ]),
          ),
        ),
      );
}

/// নমুনার `.chips span` — বাছা হলে হালকা সবুজ পট, গাঢ় সবুজ লেখা
class _Chip extends StatelessWidget {
  const _Chip({required this.t, required this.k, required this.label, required this.on, required this.onTap});

  final ChatListLook t;
  final String k;
  final String label;
  final bool on;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
        button: true,
        selected: on,
        inMutuallyExclusiveGroup: true,
        child: InkWell(
          key: ValueKey('msg-chip-$k'),
          onTap: onTap,
          borderRadius: BorderRadius.circular(999),
          // ⓘ দেখতে সরু বড়ি, চাপার জায়গা ৪৮ উঁচু
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 48),
            child: Center(
              widthFactor: 1,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 4),
                decoration: BoxDecoration(color: on ? t.chipOn : t.chip, borderRadius: BorderRadius.circular(999)),
                child: Text(label, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: on ? t.chipOnInk : t.muted)),
              ),
            ),
          ),
        ),
      );
}

/// নমুনার `.crow` — অবতার ৫০, নাম আর সময়, ছোট পদবির লাইন, শেষ বার্তা আর না-পড়ার সবুজ বড়ি
class _Row extends StatelessWidget {
  const _Row({required this.t, required this.thread, required this.now, required this.last, required this.onTap});

  final ChatListLook t;
  final ChatThread thread;
  final DateTime now;
  final bool last;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final th = thread;
    final unread = th.unread > 0;
    final at = th.lastAt ?? th.last?.at;
    final time = at == null ? '' : chatListTime(at, now, l);
    final subtitle = th.subtitle.isNotEmpty ? th.subtitle : (th.person?.role ?? '');
    final lastLine = _LastLine(t: t, thread: th, unread: unread);
    return InkWell(
      key: ValueKey('msg-row-${th.id}'),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Row(children: [
          ChatAvatar(size: 50, ring: t.ring, thread: th),
          const SizedBox(width: 14),
          Expanded(
            child: Container(
              padding: const EdgeInsets.symmetric(vertical: 11),
              decoration: BoxDecoration(border: last ? null : Border(bottom: BorderSide(color: t.divider))),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Row(crossAxisAlignment: CrossAxisAlignment.baseline, textBaseline: TextBaseline.alphabetic, children: [
                  Expanded(
                    child: Text(th.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(fontSize: 15.5, fontWeight: FontWeight.w700, color: t.ink, height: 1.3)),
                  ),
                  const SizedBox(width: 6),
                  Text(time,
                      key: ValueKey('msg-time-${th.id}'),
                      style: TextStyle(
                          fontSize: 12,
                          height: 1.3,
                          fontWeight: unread ? FontWeight.w700 : FontWeight.w400,
                          color: unread ? t.unreadTime : t.muted)),
                ]),
                if (subtitle.isNotEmpty)
                  Text(subtitle,
                      maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 11.5, color: t.muted, height: 1.3)),
                Padding(
                  padding: const EdgeInsets.only(top: 1),
                  child: Row(children: [
                    Expanded(child: lastLine),
                    if (unread) ...[
                      const SizedBox(width: 6),
                      Semantics(
                        label: l.msgListUnreadSpoken('${th.unread}'),
                        child: ExcludeSemantics(
                          child: Container(
                            key: ValueKey('msg-unread-${th.id}'),
                            constraints: const BoxConstraints(minWidth: 21, minHeight: 21),
                            padding: const EdgeInsets.symmetric(horizontal: 6),
                            alignment: Alignment.center,
                            decoration: BoxDecoration(color: ChatListLook.badge, borderRadius: BorderRadius.circular(11)),
                            child: Text(th.unread > 99 ? '99+' : '${th.unread}',
                                style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: ChatListLook.onColour)),
                          ),
                        ),
                      ),
                    ],
                  ]),
                ),
              ]),
            ),
          ),
        ]),
      ),
    );
  }
}

/// শেষ বার্তার লাইন — আমার হলে টিক আর "আপনি:", গ্রুপে পাঠানোর নাম; কাগজে চিহ্ন আর নম্বর, ভয়েসে মাইক, ছবিতে ছবির চিহ্ন
class _LastLine extends StatelessWidget {
  const _LastLine({required this.t, required this.thread, required this.unread});

  final ChatListLook t;
  final ChatThread thread;
  final bool unread;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final m = thread.last;
    if (m == null) return const SizedBox.shrink();
    final colour = unread ? t.ink : t.muted;
    final style = TextStyle(fontSize: 14, color: colour, height: 1.3);
    final spans = <InlineSpan>[];
    WidgetSpan icon(IconData i, {Color? c, double size = 16, double fill = 0}) => WidgetSpan(
          alignment: PlaceholderAlignment.middle,
          child: Padding(padding: const EdgeInsets.only(right: 4), child: Icon(i, size: size, color: c ?? colour, fill: fill)),
        );
    if (m.mine) {
      switch (m.state) {
        case MessageState.sending:
          spans.add(icon(Symbols.schedule, size: 15));
        case MessageState.failed:
          spans.add(icon(Symbols.error, size: 15, c: Theme.of(context).colorScheme.error));
        case MessageState.sent:
          spans.add(icon(Symbols.done, size: 17, c: t.muted));
        case MessageState.delivered:
          spans.add(icon(Symbols.done_all, size: 17, c: t.muted));
        case MessageState.read:
          spans.add(icon(Symbols.done_all, size: 17, c: ChatListLook.read));
      }
      spans.add(TextSpan(text: '${l.msgListYou} '));
    } else if (thread.isGroup && m.sender != null) {
      final first = m.sender!.name.trim().split(RegExp(r'\s+')).first;
      if (first.isNotEmpty) spans.add(TextSpan(text: '${l.msgListSender(first)} '));
    }
    switch (m.kind) {
      case MessageKind.paper:
        final p = m.paper;
        spans.add(icon(Symbols.description, c: colour));
        if (p != null && p.no.isNotEmpty) {
          spans.add(TextSpan(text: p.no, style: const TextStyle(fontWeight: FontWeight.w600)));
        }
        final rest = m.text.isNotEmpty ? m.text : (p?.title ?? '');
        if (rest.isNotEmpty) spans.add(TextSpan(text: ' · $rest'));
      case MessageKind.voice:
        spans.add(icon(Symbols.mic, fill: 1));
        spans.add(TextSpan(text: l.msgListVoice(voiceLength(m.attachment?.durationMs ?? 0))));
      case MessageKind.image:
        spans.add(icon(Symbols.image));
        spans.add(TextSpan(text: m.text.isNotEmpty ? m.text : l.msgListPhoto));
      case MessageKind.file:
        spans.add(icon(Symbols.draft));
        final name = m.attachment?.name ?? '';
        spans.add(TextSpan(text: name.isNotEmpty ? name : (m.text.isNotEmpty ? m.text : l.msgListFile)));
      case MessageKind.location:
        spans.add(icon(Symbols.location_on));
        spans.add(TextSpan(text: l.msgListLocation));
      case MessageKind.text:
      case MessageKind.system:
        spans.add(TextSpan(text: m.text));
    }
    return Text.rich(TextSpan(children: spans), maxLines: 1, overflow: TextOverflow.ellipsis, style: style);
  }
}

/// নমুনার `.fab` — সবুজ, কোনা ১৬, উঁচু ৫৪, পেনসিল আর "নতুন বার্তা"
class _NewButton extends StatelessWidget {
  const _NewButton({required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
        button: true,
        label: label,
        excludeSemantics: true,
        child: Material(
          color: ChatListLook.green,
          elevation: 4,
          shadowColor: const Color(0x66000000),
          borderRadius: BorderRadius.circular(16),
          child: InkWell(
            key: const ValueKey('msg-new'),
            onTap: onTap,
            borderRadius: BorderRadius.circular(16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 54),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(18, 0, 20, 0),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  const Icon(Symbols.edit, size: 21, color: ChatListLook.onColour),
                  const SizedBox(width: 8),
                  Text(label, style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w700, color: ChatListLook.onColour)),
                ]),
              ),
            ),
          ),
        ),
      );
}

/// দরজা নেই, অনুমতি নেই, ব্যর্থ বা খালি — শান্ত এক লাইন, মাঝখানে
class _Calm extends StatelessWidget {
  const _Calm({required this.t, required this.icon, required this.text, this.action, this.onAction});

  final ChatListLook t;
  final IconData icon;
  final String text;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) => Padding(
        key: const ValueKey('msg-calm'),
        padding: const EdgeInsets.fromLTRB(32, 48, 32, 16),
        child: Column(children: [
          Icon(icon, size: 40, color: t.muted),
          const SizedBox(height: 10),
          Text(text, textAlign: TextAlign.center, style: TextStyle(fontSize: 14.5, color: t.muted, height: 1.4)),
          if (action != null) ...[
            const SizedBox(height: 8),
            TextButton(onPressed: onAction, child: Text(action!)),
          ],
        ]),
      );
}
