import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../../core/locale/l10n.dart';
import '../../../core/messenger/messenger_api.dart';
import 'chat_bubbles.dart';
import 'wa_glyphs.dart';
import 'wa_palette.dart';

/// ⭐ চ্যাটের ছোট পাতা আর শিট — ছবি-ফাইল-কাগজ, সদস্য, ছবি দেখা, ফরোয়ার্ড, ERP কাগজ বাছা, বার্তার তথ্য, মোছা।

/// চ্যাটের রঙে সাধারণ মাথা — পেছনে আর নাম
class WaPageHeader extends StatelessWidget {
  const WaPageHeader({super.key, required this.pal, required this.title, this.subtitle = ''});

  final WaPalette pal;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) => Material(
        color: pal.hdr,
        child: SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(0, 6, 8, 5),
            child: Row(children: [
              IconButton(
                tooltip: context.l10n.msgChatBack,
                onPressed: () => Navigator.of(context).maybePop(),
                icon: GlyphIcon(WaGlyphs.back, size: 22, color: pal.hdrInk),
              ),
              const SizedBox(width: 4),
              Expanded(
                child: Semantics(
                  header: true,
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                    Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600, color: pal.hdrInk)),
                    if (subtitle.isNotEmpty) Text(subtitle, style: TextStyle(fontSize: 12, color: pal.meta)),
                  ]),
                ),
              ),
            ]),
          ),
        ),
      );
}

Widget _calm(WaPalette pal, String text) => Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Text(text, textAlign: TextAlign.center, style: TextStyle(fontSize: 15, color: pal.meta)),
      ),
    );

String calmError(BuildContext context, Object? e) {
  final l = context.l10n;
  if (e is MessengerDoorsMissing) return l.msgChatDoorsMissing;
  if (e is MessengerNotAllowed) return e.message ?? l.msgChatNotAllowed;
  return l.msgChatLoadFailed;
}

/// ⭐ ছবি-ফাইল-কাগজ — `api.media`
class ChatMediaPage extends StatelessWidget {
  const ChatMediaPage({super.key, required this.load, required this.onOpen, required this.title});

  final Future<List<ChatMessage>> Function() load;
  final void Function(BuildContext context, ChatMessage m) onOpen;
  final String title;

  @override
  Widget build(BuildContext context) {
    final pal = WaPalette.of(context);
    final l = context.l10n;
    return Scaffold(
      backgroundColor: pal.sheet,
      body: Column(children: [
        WaPageHeader(pal: pal, title: l.msgChatMenuMedia, subtitle: title),
        Expanded(
          child: FutureBuilder<List<ChatMessage>>(
            future: load(),
            builder: (context, s) {
              if (s.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (s.hasError) return _calm(pal, calmError(context, s.error));
              final list = s.data ?? const [];
              if (list.isEmpty) return _calm(pal, l.msgChatMediaEmpty);
              return ListView.separated(
                itemCount: list.length,
                separatorBuilder: (_, __) => Divider(height: 1, indent: 72, color: pal.line),
                itemBuilder: (context, i) {
                  final m = list[i];
                  final (Glyph g, Color c, String name) = switch (m.kind) {
                    MessageKind.image => (WaGlyphs.image, const Color(0xFFBF59CF), m.text.isEmpty ? l.msgChatPhoto : m.text),
                    MessageKind.paper => (WaGlyphs.paper, const Color(0xFF16A34A), [m.paper?.title ?? '', m.paper?.no ?? ''].where((x) => x.isNotEmpty).join(' ')),
                    _ => (WaGlyphs.doc, const Color(0xFF7F66FF), m.attachment?.name ?? ''),
                  };
                  final size = fileSize(m.attachment?.size ?? 0);
                  return ListTile(
                    key: ValueKey('media-${m.id}'),
                    minTileHeight: 64,
                    leading: Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(8)),
                      alignment: Alignment.center,
                      child: GlyphIcon(g, size: 22, color: const Color(0xFFFFFFFF)),
                    ),
                    title: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: pal.ink, fontSize: 15)),
                    subtitle: Text([dayAndTime(context, m.at), if (size.isNotEmpty) size].join(' · '), style: TextStyle(color: pal.meta, fontSize: 12.5)),
                    onTap: () => onOpen(context, m),
                  );
                },
              );
            },
          ),
        ),
      ]),
    );
  }
}

/// "আজ 10:45" · "গতকাল 18:05" · "18/11/2026 10:45"
String dayAndTime(BuildContext context, DateTime at, {DateTime? now}) {
  final n = now ?? DateTime.now();
  final today = DateTime(n.year, n.month, n.day);
  final day = DateTime(at.year, at.month, at.day);
  final l = context.l10n;
  final d = today.difference(day).inDays;
  final label = d == 0
      ? l.msgChatToday
      : d == 1
          ? l.msgChatYesterday
          : '${at.day.toString().padLeft(2, '0')}/${at.month.toString().padLeft(2, '0')}/${at.year}';
  return '$label ${bubbleTime(at)}';
}

/// ⭐ সদস্যের তালিকা — `api.members`
class ChatMembersPage extends StatelessWidget {
  const ChatMembersPage({super.key, required this.thread, required this.load});

  final ChatThread thread;
  final Future<List<ChatPerson>> Function() load;

  @override
  Widget build(BuildContext context) {
    final pal = WaPalette.of(context);
    final l = context.l10n;
    return Scaffold(
      backgroundColor: pal.sheet,
      body: Column(children: [
        WaPageHeader(pal: pal, title: l.msgChatMenuMembers, subtitle: thread.title),
        Expanded(
          child: FutureBuilder<List<ChatPerson>>(
            future: load(),
            builder: (context, s) {
              if (s.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (s.hasError) return _calm(pal, calmError(context, s.error));
              final list = s.data ?? const [];
              if (list.isEmpty) return _calm(pal, l.msgChatMembersEmpty);
              return ListView(children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                  child: Text(l.msgChatGroupMembers(list.length),
                      style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: WaPalette.green)),
                ),
                for (final p in list)
                  ListTile(
                    key: ValueKey('member-${p.id}'),
                    minTileHeight: 64,
                    leading: ChatAvatar(person: p, size: 42, showOnline: p.online, ring: pal.sheet),
                    title: Text(p.name, style: TextStyle(color: pal.ink, fontSize: 15, fontWeight: FontWeight.w600)),
                    subtitle: p.role.isEmpty ? null : Text(p.role, style: TextStyle(color: pal.meta, fontSize: 12.5)),
                  ),
              ]);
            },
          ),
        ),
      ]),
    );
  }
}

/// ⭐ ছবি বড় করে — আঙুলে বড়-ছোট
class ChatPhotoPage extends StatelessWidget {
  const ChatPhotoPage({super.key, required this.bytes, this.caption = ''});

  final Uint8List bytes;
  final String caption;

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: const Color(0xFF0B141A),
        body: Stack(children: [
          Positioned.fill(child: InteractiveViewer(maxScale: 5, child: Center(child: Image.memory(bytes)))),
          SafeArea(
            child: IconButton(
              tooltip: context.l10n.msgChatClose,
              onPressed: () => Navigator.of(context).maybePop(),
              icon: const GlyphIcon(WaGlyphs.back, size: 24, color: Color(0xFFE9EDEF)),
            ),
          ),
          if (caption.isNotEmpty)
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(caption, textAlign: TextAlign.center, style: const TextStyle(color: Color(0xFFE9EDEF), fontSize: 15)),
                ),
              ),
            ),
        ]),
      );
}

/// ⭐ কাকে ফরোয়ার্ড — সাম্প্রতিক কথোপকথন আর মানুষ (একাধিক বাছা যায়); ফেরত `thread:<id>` / `person:<id>`-এর তালিকা
/// (`POST /messenger/forward`-এর `to`)। ⓘ যাঁর সাথে একজনের কথোপকথন আগেই আছে তিনি "মানুষ"-এ আবার আসেন না।
Future<List<String>?> showForwardPicker(BuildContext context,
    {required Future<ThreadPage> Function() load, Future<List<ChatPerson>> Function()? people}) {
  final pal = WaPalette.of(context);
  return showModalBottomSheet<List<String>>(
    context: context,
    isScrollControlled: true,
    backgroundColor: pal.sheet,
    barrierColor: WaPalette.scrim,
    elevation: 0,
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
    builder: (context) => _ForwardSheet(pal: pal, load: load, people: people),
  );
}

class _ForwardSheet extends StatefulWidget {
  const _ForwardSheet({required this.pal, required this.load, this.people});

  final WaPalette pal;
  final Future<ThreadPage> Function() load;
  final Future<List<ChatPerson>> Function()? people;

  @override
  State<_ForwardSheet> createState() => _ForwardSheetState();
}

class _ForwardSheetState extends State<_ForwardSheet> {
  late final Future<(List<ChatThread>, List<ChatPerson>)> _page = _fetch();

  /// `thread:<id>` / `person:<id>` — বাছাইয়ের ক্রমে
  final List<String> _chosen = [];

  Future<(List<ChatThread>, List<ChatPerson>)> _fetch() async {
    final page = await widget.load();
    var people = const <ChatPerson>[];
    final load = widget.people;
    if (load != null) {
      try {
        people = await load();
      } catch (_) {
        // ⓘ মানুষের তালিকা না এলেও কথোপকথনে পাঠানো যায়
      }
    }
    final direct = {
      for (final t in page.threads)
        if (!t.isGroup && t.person != null) t.person!.id,
    };
    return (page.threads, [for (final p in people) if (!direct.contains(p.id)) p]);
  }

  void _toggle(String key, bool? on) => setState(() {
        _chosen.remove(key);
        if (on == true) _chosen.add(key);
      });

  Widget _head(String text) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
        child: Text(text, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: WaPalette.green)),
      );

  @override
  Widget build(BuildContext context) {
    final pal = widget.pal;
    final l = context.l10n;
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * .7,
        child: Column(children: [
          Container(width: 40, height: 4, margin: const EdgeInsets.only(top: 10, bottom: 8), decoration: BoxDecoration(color: pal.line, borderRadius: BorderRadius.circular(2))),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 6),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(l.msgChatForwardTitle, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700, color: pal.ink)),
            ),
          ),
          Expanded(
            child: FutureBuilder<(List<ChatThread>, List<ChatPerson>)>(
              future: _page,
              builder: (context, s) {
                if (s.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
                if (s.hasError) return _calm(pal, calmError(context, s.error));
                final (threads, people) = s.data ?? (const <ChatThread>[], const <ChatPerson>[]);
                if (threads.isEmpty && people.isEmpty) return _calm(pal, l.msgChatForwardEmpty);
                Widget tile(String key, Key widgetKey, Widget avatar, String title, String sub) => CheckboxListTile(
                      key: widgetKey,
                      value: _chosen.contains(key),
                      onChanged: (v) => _toggle(key, v),
                      activeColor: WaPalette.green,
                      checkboxShape: const CircleBorder(),
                      secondary: avatar,
                      title: Text(title, style: TextStyle(color: pal.ink, fontSize: 15, fontWeight: FontWeight.w600)),
                      subtitle: sub.isEmpty ? null : Text(sub, style: TextStyle(color: pal.meta, fontSize: 12)),
                    );
                return ListView(children: [
                  if (threads.isNotEmpty && people.isNotEmpty) _head(l.msgChatForwardChats),
                  for (final t in threads)
                    tile('thread:${t.id}', ValueKey('forward-${t.id}'),
                        ChatAvatar(person: t.person, thread: t.isGroup ? t : null, size: 42, showOnline: t.person?.online ?? false, ring: pal.sheet),
                        t.title, t.subtitle),
                  if (people.isNotEmpty) _head(l.msgChatForwardPeople),
                  for (final p in people)
                    tile('person:${p.id}', ValueKey('forward-person-${p.id}'), ChatAvatar(person: p, size: 42, showOnline: p.online, ring: pal.sheet),
                        p.name, [p.designation, p.branch].where((x) => x.isNotEmpty).join(' · ')),
                ]);
              },
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 6, 14, 12),
            child: SizedBox(
              width: double.infinity,
              height: 48,
              child: FilledButton.icon(
                key: const ValueKey('forward-send'),
                style: FilledButton.styleFrom(backgroundColor: WaPalette.green, foregroundColor: const Color(0xFFFFFFFF), shape: const StadiumBorder()),
                onPressed: _chosen.isEmpty ? null : () => Navigator.of(context).pop([..._chosen]),
                icon: const GlyphIcon(WaGlyphs.send, size: 18, color: Color(0xFFFFFFFF)),
                label: Text(l.msgChatForwardSend(_chosen.length)),
              ),
            ),
          ),
        ]),
      ),
    );
  }
}

/// ERP কাগজ বাছাইয়ের এক সারি
@immutable
class PaperChoice {
  const PaperChoice({required this.paper, required this.label});

  final PaperRef paper;
  final String label;
}

/// ⭐ "কোন কাগজ পাঠাবেন?" — সাম্প্রতিক খোলা কাগজ
Future<PaperRef?> showPaperPicker(BuildContext context, {required Future<List<PaperChoice>> Function() load}) {
  final pal = WaPalette.of(context);
  final l = context.l10n;
  return showModalBottomSheet<PaperRef>(
    context: context,
    backgroundColor: pal.sheet,
    barrierColor: WaPalette.scrim,
    elevation: 0,
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
    builder: (context) => SafeArea(
      child: FutureBuilder<List<PaperChoice>>(
        future: load(),
        builder: (context, s) {
          final list = s.data ?? const <PaperChoice>[];
          return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Center(
              child: Container(width: 40, height: 4, margin: const EdgeInsets.only(top: 10, bottom: 8), decoration: BoxDecoration(color: pal.line, borderRadius: BorderRadius.circular(2))),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 6),
              child: Text(l.msgChatPaperPickTitle, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700, color: pal.ink)),
            ),
            if (s.connectionState != ConnectionState.done)
              const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()))
            else if (list.isEmpty)
              Padding(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), child: Text(l.msgChatPaperPickEmpty, style: TextStyle(color: pal.meta, fontSize: 14)))
            else
              Flexible(
                child: ListView(shrinkWrap: true, children: [
                  for (final c in list)
                    ListTile(
                      key: ValueKey('paper-pick-${c.paper.type}-${c.paper.id}'),
                      minTileHeight: 56,
                      leading: Container(
                        width: 40,
                        height: 40,
                        decoration: BoxDecoration(color: const Color(0xFF16A34A), borderRadius: BorderRadius.circular(8)),
                        alignment: Alignment.center,
                        child: const GlyphIcon(WaGlyphs.paper, size: 22, color: Color(0xFFFFFFFF)),
                      ),
                      title: Text(c.label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: pal.ink, fontSize: 15)),
                      subtitle: c.paper.title.isEmpty ? null : Text(c.paper.title, style: TextStyle(color: pal.meta, fontSize: 12.5)),
                      onTap: () => Navigator.of(context).pop(c.paper),
                    ),
                ]),
              ),
          ]);
        },
      ),
    ),
  );
}

/// ⭐ বার্তার তথ্য (নমুনা v7 `.isheet`) — আমার বার্তায় প্রতিজনের পৌঁছেছে/পড়েছে; অন্যের বার্তায় কখন পেয়েছেন
Future<void> showMessageInfo(BuildContext context, {required ChatMessage message, required Future<List<MessageReceipt>> Function() load, DateTime? now}) {
  final pal = WaPalette.of(context);
  return showModalBottomSheet<void>(
    context: context,
    backgroundColor: pal.sheet,
    barrierColor: WaPalette.scrim,
    elevation: 0,
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
    builder: (context) {
      final l = context.l10n;
      Widget row(String what, DateTime? at, {bool read = false}) => Container(
            padding: const EdgeInsets.symmetric(vertical: 9),
            decoration: BoxDecoration(border: Border(top: BorderSide(color: pal.line))),
            child: Row(children: [
              GlyphIcon(WaGlyphs.tk2, size: 18, color: read ? WaPalette.read : pal.meta),
              const SizedBox(width: 10),
              Expanded(child: Text(what, style: TextStyle(fontSize: 14, color: pal.ink))),
              Text(at == null ? l.msgChatInfoNotYet : dayAndTime(context, at, now: now), style: TextStyle(fontSize: 13, color: pal.meta)),
            ]),
          );
      Widget body;
      if (!message.mine) {
        body = row(l.msgChatInfoReceived, message.at);
      } else {
        body = FutureBuilder<List<MessageReceipt>>(
          future: load(),
          builder: (context, s) {
            if (s.connectionState != ConnectionState.done) return const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()));
            if (s.hasError) return Padding(padding: const EdgeInsets.symmetric(vertical: 12), child: Text(calmError(context, s.error), style: TextStyle(color: pal.meta)));
            final list = s.data ?? const <MessageReceipt>[];
            if (list.length == 1) {
              return Column(children: [row(l.msgChatTickDelivered, list.first.deliveredAt), row(l.msgChatTickRead, list.first.readAt, read: true)]);
            }
            return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              for (final r in list) ...[
                Padding(
                  padding: const EdgeInsets.only(top: 10, bottom: 2),
                  child: Text(r.person.name, style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: pal.ink)),
                ),
                row(l.msgChatTickDelivered, r.deliveredAt),
                row(l.msgChatTickRead, r.readAt, read: true),
              ],
            ]);
          },
        );
      }
      return SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 16, 18, 8),
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(l.msgChatInfoTitle, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: pal.ink)),
              const SizedBox(height: 8),
              body,
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(
                  key: const ValueKey('info-ok'),
                  onPressed: () => Navigator.of(context).pop(),
                  child: Text(l.msgChatOk, style: TextStyle(color: pal.link, fontWeight: FontWeight.w700, fontSize: 14.5)),
                ),
              ),
            ]),
          ),
        ),
      );
    },
  );
}

/// মোছার উত্তর
enum DeleteFor { everyone, me }

/// ⭐ "বার্তাটি মুছবেন?" — নিজের বার্তায় সবার কাছ থেকে আর আমার কাছ থেকে; অন্যের বার্তায় কেবল আমার কাছ থেকে
Future<DeleteFor?> askDelete(BuildContext context, {required bool mine}) {
  final pal = WaPalette.of(context);
  return showDialog<DeleteFor>(
    context: context,
    barrierColor: WaPalette.scrim,
    builder: (context) {
      final l = context.l10n;
      Widget b(String text, VoidCallback onTap, Key key) => Align(
            alignment: Alignment.centerRight,
            child: TextButton(
              key: key,
              onPressed: onTap,
              child: Text(text, style: TextStyle(color: pal.link, fontWeight: FontWeight.w700, fontSize: 14.5)),
            ),
          );
      return Dialog(
        backgroundColor: pal.sheet,
        // ⓘ নমুনার `.dlg` ছায়া; রূপের ডায়ালগ-থিম (গ্লাসের আভা) নয়
        elevation: 12,
        shadowColor: const Color(0x4D000000),
        surfaceTintColor: const Color(0x00000000),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 20, 10, 8),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Padding(padding: const EdgeInsets.only(left: 2, bottom: 10), child: Text(l.msgChatDeleteAsk, style: TextStyle(fontSize: 15, color: pal.ink))),
            if (mine) b(l.msgChatDeleteEveryone, () => Navigator.of(context).pop(DeleteFor.everyone), const ValueKey('delete-everyone')),
            b(l.msgChatDeleteMe, () => Navigator.of(context).pop(DeleteFor.me), const ValueKey('delete-me')),
            b(l.msgChatCancel, () => Navigator.of(context).pop(), const ValueKey('delete-cancel')),
          ]),
        ),
      );
    },
  );
}
