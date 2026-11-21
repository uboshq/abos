import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../../core/locale/l10n.dart';
import '../../../core/messenger/messenger_api.dart';
import 'chat_list_look.dart';
import 'people_picker.dart';

/// ⭐ "বার্তায় পাঠান" — কাকে পাঠাবেন (মালিকের অনুমোদিত নমুনা 3nuwRUdn v5 "গ" ২, ২১ নভেম্বর ২০২৬)।
///
/// <p>হাতল, "বার্তায় পাঠান" আর ×; কাগজের কার্ড (নীল ঘরে কাগজের চিহ্ন, "<নাম> <নম্বর>", জানা থাকলে "টাকা · পার্টি" — খালি অংশ
/// নেই); খোঁজা "কাকে পাঠাবেন? নাম খুঁজুন"; "সাম্প্রতিক" — শেষের কথোপকথন, মানুষ আর গ্রুপ দুটোই; প্রতিটার ডানে গোল টিক-ঘর
/// (কয়েকজন একসাথে, বাছা হলে সবুজ)। যাঁর এই কাগজ খোলার অনুমতি নেই, তাঁর নিচে হলুদ তালার লাইন — তবু বাছা যায় (তিনি শুধু
/// নম্বর দেখবেন)। নিচে ইচ্ছা হলে একটা কথা, আর সবুজ "পাঠান · N জন" (০ হলে বন্ধ)।
///
/// <p>প্রাপক যায় [MessengerApi.sendPaper]-এর `to`-তে: একজন মানুষ `person:<userId>`, একটা গ্রুপ `thread:<threadId>` (একজনের সাথের
/// কথোপকথন বাছলেও সেই মানুষ — `person:`, যাতে খুঁজে বাছা আর সাম্প্রতিক থেকে বাছা একই প্রাপক হয়)।
Future<void> showSendPaperSheet(BuildContext context, PaperRef paper) async {
  final messenger = ScaffoldMessenger.maybeOf(context);
  final words = context.l10n;
  final sent = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    backgroundColor: SheetLook.of(context).bg,
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
    builder: (_) => SendPaperSheet(paper: paper),
  );
  if (sent == true) {
    messenger
      ?..hideCurrentSnackBar()
      ..showSnackBar(
          SnackBar(key: const ValueKey('send-paper-done'), content: Text(words.msgListSent), behavior: SnackBarBehavior.floating));
  }
}

/// ⭐ কেবল প্রাপক বাছাই — একই শিট (সাম্প্রতিক মানুষ ও গ্রুপ, খোঁজা, গোল টিক-ঘর), কিন্তু পাঠায় না: "পাঠান · N জন" চাপলে বাছা
/// প্রাপকের তালিকা ফেরত দেয় (`person:<userId>` · `thread:<threadId>`), বন্ধ করলে null। চ্যাটের "ফরোয়ার্ড"-এর জন্য।
/// [paper] দিলে মাথায় কাগজের কার্ড আর যাঁর অনুমতি নেই তাঁর হলুদ তালার লাইন; [title] না দিলে "বার্তায় পাঠান"।
Future<List<String>?> pickRecipients(BuildContext context, {String? title, PaperRef? paper}) => showModalBottomSheet<List<String>>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: SheetLook.of(context).bg,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => SendPaperSheet(paper: paper, pickOnly: true, title: title),
    );

/// কাগজের পর্দা যা জানে তা থেকে [PaperRef] — [title] যেমন "ক্রয় বিল · PB-2609-0007" হলে নম্বরটা নাম থেকে বাদ
PaperRef paperRefFor({required String type, required String id, required String title, String? number}) {
  final no = (number ?? '').trim();
  var name = title.trim();
  if (no.isNotEmpty && name.contains(no)) {
    name = name.replaceAll(no, '').replaceAll(RegExp(r'^[·:|\s-]+|[·:|\s-]+$'), '').trim();
  }
  return PaperRef(type: type, id: id, no: no.isEmpty ? title.trim() : no, title: no.isEmpty ? '' : name);
}

class SendPaperSheet extends ConsumerStatefulWidget {
  const SendPaperSheet({super.key, this.paper, this.pickOnly = false, this.title});

  /// পাঠানোর কাগজ — [pickOnly]-এ না দিলে কার্ড আর তালার লাইন নেই
  final PaperRef? paper;

  /// true = পাঠায় না, বাছা প্রাপকের তালিকা নিয়ে বন্ধ হয় ([pickRecipients])
  final bool pickOnly;

  /// শিটের শিরোনাম — না দিলে "বার্তায় পাঠান"
  final String? title;

  @override
  ConsumerState<SendPaperSheet> createState() => _SendPaperSheetState();
}

/// একটা বাছাইযোগ্য সারি — মানুষ বা গ্রুপ
class _Pick {
  const _Pick({required this.to, required this.name, required this.sub, this.person, this.thread, this.locked = false});

  /// `person:<id>` বা `thread:<id>`
  final String to;
  final String name;
  final String sub;
  final ChatPerson? person;
  final ChatThread? thread;
  final bool locked;
}

class _SendPaperSheetState extends ConsumerState<SendPaperSheet> {
  final TextEditingController _q = TextEditingController();
  final TextEditingController _note = TextEditingController();
  Timer? _debounce;

  List<ChatThread>? _recent;
  Map<String, ChatPerson> _access = const {};
  List<ChatPerson>? _found;
  final Set<String> _chosen = {};
  bool _sending = false;
  String? _error;

  /// একই পাঠানো দুবার যায় না — আবার চাপলেও একই চিহ্ন ([OnceKey])
  late final String _clientId = '${DateTime.now().microsecondsSinceEpoch}-${Random().nextInt(1 << 30)}';

  MessengerApi get _api => ref.read(messengerApiProvider);

  @override
  void initState() {
    super.initState();
    _loadRecent();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _q.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _loadRecent() async {
    try {
      final page = await _api.threads();
      final people = widget.paper == null ? const <ChatPerson>[] : await _api.people(paper: widget.paper);
      if (!mounted) return;
      setState(() {
        _recent = page.threads;
        _access = {for (final p in people) p.id: p};
      });
    } catch (e) {
      if (mounted) setState(() => _error = _say(e, context.l10n.msgListLoadFailed));
    }
  }

  Future<void> _search() async {
    final q = _q.text.trim();
    if (q.isEmpty) {
      setState(() => _found = null);
      return;
    }
    try {
      final people = await _api.people(query: q, paper: widget.paper);
      if (!mounted || q != _q.text.trim()) return;
      setState(() => _found = people);
    } catch (e) {
      if (mounted) setState(() => _error = _say(e, context.l10n.msgListLoadFailed));
    }
  }

  String _say(Object e, String fallback) => switch (e) {
        MessengerNotAllowed(:final message) => message ?? context.l10n.msgListNotAllowed,
        MessengerDoorsMissing() => context.l10n.msgListNotOpenYet,
        _ => fallback,
      };

  List<_Pick> get _rows {
    final found = _found;
    if (found != null) {
      return [
        for (final p in found)
          _Pick(to: 'person:${p.id}', name: p.name, sub: p.role, person: p, locked: widget.paper != null && !p.canOpenPaper),
      ];
    }
    return [
      for (final t in _recent ?? const <ChatThread>[])
        if (t.isGroup)
          _Pick(to: 'thread:${t.id}', name: t.title, sub: t.subtitle, thread: t)
        else if (t.person != null)
          _Pick(
            to: 'person:${t.person!.id}',
            name: t.title,
            sub: t.subtitle.isNotEmpty ? t.subtitle : t.person!.role,
            thread: t,
            locked: widget.paper != null && !(_access[t.person!.id]?.canOpenPaper ?? t.person!.canOpenPaper),
          ),
    ];
  }

  Future<void> _send() async {
    if (_chosen.isEmpty || _sending) return;
    if (widget.pickOnly || widget.paper == null) {
      Navigator.of(context).pop(_chosen.toList());
      return;
    }
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      await _api.sendPaper(widget.paper!, _chosen.toList(), note: _note.text.trim(), clientId: _clientId);
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) {
        setState(() {
          _sending = false;
          _error = _say(e, context.l10n.msgListSendFailed);
        });
      }
    }
  }

  void _toggle(String to) => setState(() => _chosen.contains(to) ? _chosen.remove(to) : _chosen.add(to));

  @override
  Widget build(BuildContext context) {
    final s = SheetLook.of(context);
    final l = context.l10n;
    final p = widget.paper;
    final rows = _rows;
    final head = p == null ? '' : [p.title, p.no].where((x) => x.trim().isNotEmpty).join(' ');
    final small = p == null ? '' : [p.amount, p.party].where((x) => x.trim().isNotEmpty).join(' · ');

    final Widget list = _recent == null && _found == null && _error == null
        ? const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()))
        : ListView(
            key: const ValueKey('send-paper-list'),
            shrinkWrap: true,
            padding: EdgeInsets.zero,
            children: [
              if (_found == null && rows.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 10, bottom: 2),
                  child: Text(l.msgListRecent, style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: s.label)),
                ),
              if (_found != null && rows.isEmpty)
                Padding(padding: const EdgeInsets.all(16), child: Center(child: Text(l.msgListNoMatch, style: TextStyle(color: s.muted)))),
              for (final (i, r) in rows.indexed)
                PersonRow(
                  key: ValueKey('send-row-${r.to}'),
                  s: s,
                  avatar: ChatAvatar(size: 42, ring: s.ring, person: r.person, thread: r.thread),
                  name: r.name,
                  sub: r.sub,
                  locked: r.locked ? l.msgListNoPaperAccess : null,
                  last: i == rows.length - 1,
                  selected: _chosen.contains(r.to),
                  trailing: _Check(on: _chosen.contains(r.to)),
                  onTap: () => _toggle(r.to),
                ),
            ],
          );

    final n = _chosen.length;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.85),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 18),
          child: Column(
            key: const ValueKey('send-paper-sheet'),
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SheetHead(s: s, title: widget.title ?? l.msgListSendPaper),
              // ⓘ কাগজের কার্ড (`.pc`)
              if (p != null)
                Container(
                  key: const ValueKey('send-paper-card'),
                  margin: const EdgeInsets.only(top: 4),
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                  decoration: BoxDecoration(color: s.field, borderRadius: BorderRadius.circular(10)),
                  child: Row(children: [
                    Container(
                      width: 36,
                      height: 36,
                      decoration: BoxDecoration(color: const Color(0xFF2563EB), borderRadius: BorderRadius.circular(9)),
                      child: const Icon(Symbols.description, size: 20, color: ChatListLook.onColour),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(head, style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: s.ink, height: 1.3)),
                        if (small.isNotEmpty) Text(small, style: TextStyle(fontSize: 12, color: s.muted, height: 1.3)),
                      ]),
                    ),
                  ]),
                ),
              const SizedBox(height: 10),
              SheetField(
                s: s,
                fieldKey: const ValueKey('send-paper-search'),
                controller: _q,
                hint: l.msgListSendWho,
                icon: Symbols.search,
                onChanged: (_) {
                  _debounce?.cancel();
                  _debounce = Timer(const Duration(milliseconds: 300), _search);
                },
              ),
              Flexible(child: list),
              const SizedBox(height: 8),
              if (!widget.pickOnly) SheetField(s: s, fieldKey: const ValueKey('send-paper-note'), controller: _note, hint: l.msgListNote),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child:
                      Text(_error!, key: const ValueKey('send-paper-error'), style: TextStyle(color: Theme.of(context).colorScheme.error)),
                ),
              const SizedBox(height: 10),
              FilledButton.icon(
                key: const ValueKey('send-paper-go'),
                onPressed: n == 0 || _sending ? null : _send,
                style: FilledButton.styleFrom(
                  backgroundColor: ChatListLook.green,
                  foregroundColor: ChatListLook.onColour,
                  disabledBackgroundColor: ChatListLook.green.withValues(alpha: 0.45),
                  disabledForegroundColor: ChatListLook.onColour,
                  minimumSize: const Size.fromHeight(48),
                  shape: const StadiumBorder(),
                  textStyle: Theme.of(context).textTheme.labelLarge?.copyWith(fontSize: 15, fontWeight: FontWeight.w700),
                ),
                icon: const Icon(Symbols.send, size: 18),
                label: Text(l.msgListSendTo('$n')),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// গোল টিক-ঘর (`.ck`) — বাছা হলে সবুজ ভরাট, সাদা টিক
class _Check extends StatelessWidget {
  const _Check({required this.on});

  final bool on;

  @override
  Widget build(BuildContext context) => Container(
        width: 22,
        height: 22,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: on ? ChatListLook.green : null,
          border: Border.all(color: on ? ChatListLook.green : const Color(0xFF8696A0), width: 2),
        ),
        child: on ? const Icon(Symbols.check, size: 14, weight: 700, color: ChatListLook.onColour) : null,
      );
}
