import 'dart:async';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../core/locale/l10n.dart';
import '../../../core/messenger/messenger_api.dart';
import '../../../core/profile/face_image.dart';
import 'wa_glyphs.dart';
import 'wa_palette.dart';

/// ⭐ চ্যাটের পাতার টুকরো — বুদবুদ, সময় আর টিক, তারিখ, কাগজ, ছবি, ফাইল, ভয়েস, অবস্থান (নমুনা 3nuwRUdn v5 "খ"/"গ")।

/// "18:05" — বুদবুদের ভেতরের সময়, ২৪ ঘণ্টা, ইংরেজি অঙ্ক
String bubbleTime(DateTime at) => DateFormat('HH:mm').format(at);

/// "0:24"
String voiceTime(Duration d) => '${d.inMinutes}:${(d.inSeconds % 60).toString().padLeft(2, '0')}';

/// "240 KB" · "1.2 MB"
String fileSize(int bytes) {
  if (bytes <= 0) return '';
  if (bytes < 1024 * 1024) return '${(bytes / 1024).round()} KB';
  return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
}

/// ফাইলের শেষাংশ, বড় হাতে ("PDF") — চার অক্ষরের বেশি নয়
String fileExt(String name) {
  final dot = name.lastIndexOf('.');
  if (dot < 0 || dot == name.length - 1) return 'FILE';
  final e = name.substring(dot + 1).toUpperCase();
  return e.length > 4 ? e.substring(0, 4) : e;
}

/// নামের প্রথম শব্দ — "রাকিব হাসান" → "রাকিব"
String firstName(String name) => name.trim().split(RegExp(r'\s+')).first;

Color _hexColour(String? hex, String seed) {
  if (hex != null) return Color(int.parse('FF${hex.substring(1)}', radix: 16));
  const fallback = [Color(0xFF4338CA), Color(0xFF0F766E), Color(0xFFBE185D), Color(0xFF4D7C0F), Color(0xFF0E7490), Color(0xFFEA580C)];
  return fallback[seed.codeUnits.fold<int>(0, (a, b) => a + b) % fallback.length];
}

/// অবতারের রং — নমুনার মতো হালকা থেকে গাঢ়ের ঢাল
LinearGradient avatarGradient(String? hex, String seed) {
  final end = _hexColour(hex, seed);
  return LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [Color.lerp(end, const Color(0xFFFFFFFF), .22)!, end]);
}

/// গ্রুপে বুদবুদের ভেতরের নামের রং — মানুষের রং থেকে, পটের সাথে পড়া যায় এমন
Color senderColour(ChatPerson p, WaPalette pal) => Color.lerp(_hexColour(p.colour, p.id), const Color(0xFFFFFFFF), pal.dark ? .45 : .15)!;

/// ⭐ গোল অবতার — নামের প্রথম অক্ষর (বা ছবি), অনলাইন হলে সবুজ বিন্দু; গ্রুপে তার চিহ্ন
class ChatAvatar extends StatelessWidget {
  const ChatAvatar({super.key, this.person, this.thread, required this.size, this.showOnline = false, this.ring});

  final ChatPerson? person;

  /// গ্রুপের অবতার (চিহ্নসহ)
  final ChatThread? thread;
  final double size;
  final bool showOnline;

  /// অনলাইন বিন্দুর চারপাশের রং (যে পটের ওপর বসে)
  final Color? ring;

  @override
  Widget build(BuildContext context) {
    final p = person;
    final g = thread;
    final photo = p?.photoUrl;
    final Widget inner;
    if (g != null && g.isGroup) {
      inner = Center(child: GlyphIcon(WaGlyphs.group(g.groupIcon), size: size * .55, color: const Color(0xFFFFFFFF)));
    } else if (photo != null) {
      inner =
          ClipOval(child: Image(image: FaceImage(photo), width: size, height: size, fit: BoxFit.cover, errorBuilder: (_, __, ___) => _initial(p!)));
    } else {
      inner = _initial(p);
    }
    final dot = math.max(11.0, size * .3);
    return ExcludeSemantics(
      child: SizedBox(
        width: size,
        height: size,
        child: Stack(clipBehavior: Clip.none, children: [
          Positioned.fill(
            child: DecoratedBox(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: g != null && g.isGroup ? avatarGradient(g.colour, g.id) : avatarGradient(p?.colour, p?.id ?? '?'),
              ),
              child: inner,
            ),
          ),
          if (showOnline)
            Positioned(
              right: 0,
              bottom: 0,
              child: Container(
                width: dot,
                height: dot,
                decoration: BoxDecoration(
                  color: WaPalette.online,
                  shape: BoxShape.circle,
                  border: Border.all(color: ring ?? WaPalette.light.hdr, width: 2.5),
                ),
              ),
            ),
        ]),
      ),
    );
  }

  Widget _initial(ChatPerson? p) => Center(
        child:
            Text(p?.initial ?? '?', style: TextStyle(color: const Color(0xFFFFFFFF), fontWeight: FontWeight.w700, fontSize: size * .38, height: 1)),
      );
}

/// ⭐ সময় আর টিক — বুদবুদের নিচে ডানে; গ্রুপে আমার বার্তায় আগে "4 জন পড়েছেন · "
class BubbleMeta extends StatelessWidget {
  const BubbleMeta({super.key, required this.message, required this.pal});

  final ChatMessage message;
  final WaPalette pal;

  @override
  Widget build(BuildContext context) {
    final m = message;
    final colour = m.mine ? pal.metaMe : pal.meta;
    final l = context.l10n;
    final pre = m.mine && m.readCount > 0 ? '${l.msgChatReadBy(m.readCount)} · ' : '';
    final Widget? tick;
    final String? said;
    if (!m.mine) {
      tick = null;
      said = null;
    } else {
      switch (m.state) {
        case MessageState.sending:
          tick = Padding(padding: const EdgeInsets.only(left: 2), child: GlyphIcon(WaGlyphs.clock, size: 12, color: colour));
          said = l.msgChatTickSending;
        case MessageState.failed:
          tick = const Icon(Icons.error, size: 15, color: WaPalette.failed);
          said = l.msgChatFailedRetry;
        case MessageState.sent:
          tick = GlyphIcon(WaGlyphs.tk1, size: 17, color: colour);
          said = l.msgChatTickSent;
        case MessageState.delivered:
          tick = GlyphIcon(WaGlyphs.tk2, size: 17, color: colour);
          said = l.msgChatTickDelivered;
        case MessageState.read:
          tick = const GlyphIcon(WaGlyphs.tk2, size: 17, color: WaPalette.read);
          said = l.msgChatTickRead;
      }
    }
    return Semantics(
      label: said,
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Text('$pre${bubbleTime(m.at)}', style: TextStyle(fontSize: 11, color: colour, height: 1.2)),
        if (tick != null) ...[const SizedBox(width: 3), tick],
      ]),
    );
  }
}

/// বুদবুদের আকার — গোল কোণা, প্রথমটায় লেজ (নমুনার `.bub.tail::before`)
class _BubblePainter extends CustomPainter {
  _BubblePainter({required this.colour, required this.tail, required this.mine});

  final Color colour;
  final bool tail;
  final bool mine;

  Path _shape(Size s) {
    const r = Radius.circular(7.5);
    final rr = RRect.fromRectAndCorners(Offset.zero & s,
        topLeft: tail && !mine ? Radius.zero : r, topRight: tail && mine ? Radius.zero : r, bottomLeft: r, bottomRight: r);
    final p = Path()..addRRect(rr);
    if (tail) {
      final t = Path();
      if (mine) {
        t
          ..moveTo(s.width - 1, 0)
          ..lineTo(s.width + 8, 0)
          ..lineTo(s.width - 1, 13)
          ..close();
      } else {
        t
          ..moveTo(-8, 0)
          ..lineTo(1, 0)
          ..lineTo(1, 13)
          ..close();
      }
      return Path.combine(PathOperation.union, p, t);
    }
    return p;
  }

  @override
  void paint(Canvas canvas, Size size) {
    final shape = _shape(size);
    canvas.drawPath(
        shape.shift(const Offset(0, 1)),
        Paint()
          ..color = const Color(0x210B141A)
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, .5));
    canvas.drawPath(shape, Paint()..color = colour);
  }

  @override
  bool shouldRepaint(_BubblePainter old) => old.colour != colour || old.tail != tail || old.mine != mine;
}

/// বুদবুদের খোল
class BubbleShell extends StatelessWidget {
  const BubbleShell({super.key, required this.mine, required this.tail, required this.colour, required this.padding, required this.child});

  final bool mine;
  final bool tail;
  final Color colour;
  final EdgeInsets padding;
  final Widget child;

  @override
  Widget build(BuildContext context) => CustomPaint(
        painter: _BubblePainter(colour: colour, tail: tail, mine: mine),
        child: Padding(padding: padding, child: child),
      );
}

/// ⭐ লেখার পরে সময় "ভাসে" ডানে (নমুনার `.mt{float:right}`) — শেষ লাইনে জায়গা থাকলে সেখানেই, না থাকলে নিচে
class FloatMeta extends StatelessWidget {
  const FloatMeta({super.key, required this.text, required this.meta, required this.style});

  final InlineSpan text;
  final Widget meta;
  final TextStyle style;

  @override
  Widget build(BuildContext context) => Stack(clipBehavior: Clip.none, children: [
        Text.rich(
          TextSpan(style: style, children: [
            text,
            WidgetSpan(
              alignment: PlaceholderAlignment.bottom,
              child: Padding(
                padding: const EdgeInsets.only(left: 12),
                child: ExcludeSemantics(
                    child: Visibility(visible: false, maintainSize: true, maintainAnimation: true, maintainState: true, child: meta)),
              ),
            ),
          ]),
        ),
        Positioned(right: 0, bottom: -4, child: meta),
      ]);
}

/// লেখায় খোঁজার শব্দ রাঙানো
InlineSpan highlightSpan(String text, String query, {required bool current, required WaPalette pal}) {
  if (query.isEmpty) return TextSpan(text: text);
  final lower = text.toLowerCase();
  final q = query.toLowerCase();
  final spans = <InlineSpan>[];
  var i = 0;
  while (true) {
    final at = lower.indexOf(q, i);
    if (at < 0) break;
    if (at > i) spans.add(TextSpan(text: text.substring(i, at)));
    spans.add(TextSpan(
      text: text.substring(at, at + q.length),
      style: TextStyle(
        backgroundColor: current ? const Color(0xFFFFB02E) : const Color(0x80FFD54F),
        color: current ? const Color(0xFF111B21) : null,
      ),
    ));
    i = at + q.length;
  }
  if (i < text.length) spans.add(TextSpan(text: text.substring(i)));
  return TextSpan(children: spans);
}

/// ⭐ বুদবুদের ভেতরের কাজগুলো — পর্দা দেয়
class BubbleActions {
  const BubbleActions({
    required this.onOpenPaper,
    required this.onRetry,
    required this.onFile,
    required this.onImage,
    required this.onLocation,
    required this.onVoice,
    required this.image,
    required this.voice,
    required this.downloading,
  });

  final void Function(PaperRef paper) onOpenPaper;
  final void Function(ChatMessage m) onRetry;
  final void Function(ChatMessage m) onFile;
  final void Function(ChatMessage m) onImage;
  final void Function(ChatMessage m) onLocation;
  final void Function(ChatMessage m) onVoice;

  /// ছবির বাইট (পাঠানোর পথের ছবি ফোনেই আছে); না পেলে null
  final Future<Uint8List?> Function(ChatMessage m) image;
  final ValueNotifier<VoiceNow> voice;
  final ValueNotifier<Set<String>> downloading;
}

/// কোন ভয়েস বার্তা চলছে, কতদূর
@immutable
class VoiceNow {
  const VoiceNow({this.id, this.playing = false, this.position = Duration.zero, this.duration = Duration.zero});

  final String? id;
  final bool playing;
  final Duration position;
  final Duration duration;
}

/// ⭐ একটা বার্তার সারি — বুদবুদ, লেজ, গ্রুপে অবতার আর নাম
class MessageRow extends StatelessWidget {
  const MessageRow({
    super.key,
    required this.message,
    required this.first,
    required this.group,
    required this.pal,
    required this.actions,
    this.query = '',
    this.currentMatch = false,
    this.me,
  });

  final ChatMessage message;

  /// একই দিকের ধারার প্রথমটা — লেজ আর ওপরে বেশি ফাঁক
  final bool first;
  final bool group;
  final WaPalette pal;
  final BubbleActions actions;
  final String query;
  final bool currentMatch;

  /// আমি (আমার ভয়েস বার্তার অবতার)
  final ChatPerson? me;

  @override
  Widget build(BuildContext context) {
    final m = message;
    final mine = m.mine;
    final colour = mine ? pal.me : pal.th;
    final ink = TextStyle(fontSize: 14, height: 1.38, color: pal.ink);
    final showSender = group && !mine && first && m.sender != null;
    final name = showSender
        ? Padding(
            padding: const EdgeInsets.only(bottom: 1),
            child: Text(m.sender!.name, style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: senderColour(m.sender!, pal))),
          )
        : null;
    final meta = BubbleMeta(message: m, pal: pal);
    final r = m.kind == MessageKind.deleted ? null : m.replyTo;
    final textLike = m.kind == MessageKind.text || m.kind == MessageKind.voice || m.kind == MessageKind.system;
    final quote = r == null
        ? null
        : Padding(
            padding: textLike ? const EdgeInsets.only(bottom: 5) : const EdgeInsets.fromLTRB(4, 2, 4, 5),
            child: ReplyQuote(reply: r, pal: pal),
          );

    Widget body;
    EdgeInsets pad;
    switch (m.kind) {
      case MessageKind.paper:
        pad = const EdgeInsets.fromLTRB(4, 4, 4, 6);
        body = Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          if (name != null) Padding(padding: const EdgeInsets.fromLTRB(5, 2, 5, 3), child: name),
          if (quote != null) quote,
          if (m.paper != null) PaperCard(paper: m.paper!, pal: pal, onOpen: () => actions.onOpenPaper(m.paper!)),
          if (m.text.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(5, 5, 5, 0),
              child: FloatMeta(text: highlightSpan(m.text, query, current: currentMatch, pal: pal), meta: meta, style: ink),
            )
          else
            _blockMeta(meta),
        ]);
      case MessageKind.image:
        pad = const EdgeInsets.fromLTRB(3, 3, 3, 6);
        body = Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          if (name != null) Padding(padding: const EdgeInsets.fromLTRB(6, 3, 6, 3), child: name),
          if (quote != null) quote,
          _Photo(message: m, pal: pal, actions: actions),
          if (m.text.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(5, 5, 5, 0),
              child: FloatMeta(text: highlightSpan(m.text, query, current: currentMatch, pal: pal), meta: meta, style: ink),
            )
          else
            _blockMeta(meta),
        ]);
      case MessageKind.file:
        pad = const EdgeInsets.fromLTRB(4, 4, 4, 6);
        body = Column(crossAxisAlignment: CrossAxisAlignment.end, mainAxisSize: MainAxisSize.min, children: [
          if (name != null) Align(alignment: Alignment.centerLeft, child: Padding(padding: const EdgeInsets.fromLTRB(5, 2, 5, 3), child: name)),
          if (quote != null) quote,
          _FileTile(message: m, pal: pal, actions: actions, query: query, current: currentMatch),
          if (m.text.isNotEmpty)
            Align(
              alignment: Alignment.centerLeft,
              child: Padding(
                  padding: const EdgeInsets.fromLTRB(5, 5, 5, 0),
                  child: Text.rich(highlightSpan(m.text, query, current: currentMatch, pal: pal), style: ink)),
            ),
          _blockMeta(meta),
        ]);
      case MessageKind.voice:
        pad = const EdgeInsets.fromLTRB(9, 6, 8, 7);
        body = Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          if (name != null) name,
          if (quote != null) quote,
          _Voice(message: m, pal: pal, actions: actions, avatarOf: mine ? me : m.sender, meta: meta),
        ]);
      case MessageKind.location:
        pad = const EdgeInsets.fromLTRB(4, 4, 4, 6);
        body = Column(crossAxisAlignment: CrossAxisAlignment.end, mainAxisSize: MainAxisSize.min, children: [
          if (name != null) Align(alignment: Alignment.centerLeft, child: Padding(padding: const EdgeInsets.fromLTRB(5, 2, 5, 3), child: name)),
          if (quote != null) quote,
          _LocationTile(message: m, pal: pal, onTap: () => actions.onLocation(m)),
          _blockMeta(meta),
        ]);
      case MessageKind.deleted:
        pad = const EdgeInsets.fromLTRB(9, 6, 8, 7);
        body = Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          if (name != null) name,
          FloatMeta(
            text: TextSpan(text: context.l10n.msgChatDeleted),
            meta: meta,
            style: ink.copyWith(fontStyle: FontStyle.italic, color: pal.meta),
          ),
        ]);
      case MessageKind.text:
      case MessageKind.system:
        pad = const EdgeInsets.fromLTRB(9, 6, 8, 7);
        body = Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          if (name != null) name,
          if (quote != null) quote,
          FloatMeta(text: highlightSpan(m.text, query, current: currentMatch, pal: pal), meta: meta, style: ink),
        ]);
    }

    Widget bubble = BubbleShell(mine: mine, tail: first, colour: colour, padding: pad, child: body);
    if (mine && m.state == MessageState.failed) {
      bubble = Semantics(
        button: true,
        label: context.l10n.msgChatFailedRetry,
        child: GestureDetector(behavior: HitTestBehavior.opaque, onTap: () => actions.onRetry(m), child: bubble),
      );
    }

    return LayoutBuilder(builder: (context, c) {
      final max = c.maxWidth * .82;
      final limited = ConstrainedBox(constraints: BoxConstraints(maxWidth: max), child: bubble);
      final Widget row;
      if (group && !mine) {
        row = Row(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Transform.translate(
            offset: const Offset(-6, 0),
            child: first && m.sender != null ? ChatAvatar(person: m.sender, size: 28) : const SizedBox(width: 28),
          ),
          const SizedBox(width: 6),
          Flexible(child: ConstrainedBox(constraints: BoxConstraints(maxWidth: max - 28), child: bubble)),
        ]);
      } else {
        row = limited;
      }
      return Padding(
        padding: EdgeInsets.only(top: first ? 9 : 2),
        child: Align(alignment: mine ? Alignment.centerRight : Alignment.centerLeft, child: row),
      );
    });
  }

  Widget _blockMeta(Widget meta) =>
      Padding(padding: const EdgeInsets.fromLTRB(0, 3, 2, 0), child: Align(alignment: Alignment.centerRight, widthFactor: 1, child: meta));
}

/// উদ্ধৃতির ছোট লেখা — লেখা, না থাকলে ধরন ("ছবি", "ভয়েস বার্তা" …)
String replyText(BuildContext context, MessageKind kind, String text) {
  if (text.trim().isNotEmpty) return text;
  final l = context.l10n;
  return switch (kind) {
    MessageKind.image => l.msgChatPhoto,
    MessageKind.voice => l.msgChatVoice,
    MessageKind.file => l.msgChatTrayFile,
    MessageKind.paper => l.msgChatTrayPaper,
    MessageKind.location => l.msgChatLocation,
    MessageKind.deleted => l.msgChatDeleted,
    _ => '',
  };
}

/// ⭐ বুদবুদের ভেতরের উদ্ধৃতি (নমুনার `.qt`) — রঙিন বাঁ কিনারা, নাম, আগের লেখা
class ReplyQuote extends StatelessWidget {
  const ReplyQuote({super.key, required this.reply, required this.pal});

  final ReplyRef reply;
  final WaPalette pal;

  @override
  Widget build(BuildContext context) {
    final who = reply.mine ? context.l10n.msgChatYou : (reply.sender?.name ?? '');
    final edge = reply.sender != null && !reply.mine ? senderColour(reply.sender!, pal) : WaPalette.green;
    return Container(
      decoration: BoxDecoration(
        color: pal.inset,
        borderRadius: BorderRadius.circular(5),
        border: Border(left: BorderSide(color: edge, width: 4)),
      ),
      padding: const EdgeInsets.fromLTRB(8, 4, 8, 5),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
        if (who.isNotEmpty) Text(who, style: TextStyle(color: edge, fontSize: 12.5, fontWeight: FontWeight.w700, height: 1.35)),
        Text(replyText(context, reply.kind, reply.text),
            maxLines: 3, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12.5, height: 1.35, color: pal.meta)),
      ]),
    );
  }
}

/// ⭐ ERP কাগজের কার্ড (নমুনার `.paper`) — সবুজ চিহ্ন, "রসিদ RCV-0142", টাকা · পক্ষ, অবস্থার চিহ্ন, "খুলুন"।
/// ⓘ অনুমতি না থাকলে সার্ভার টাকা/পক্ষ/অবস্থা খালি পাঠায় — খালিগুলো লুকানো; নম্বর থাকে। (মালিক, নমুনা v6: তালার লাইন নেই)
class PaperCard extends StatelessWidget {
  const PaperCard({super.key, required this.paper, required this.pal, required this.onOpen});

  final PaperRef paper;
  final WaPalette pal;
  final VoidCallback onOpen;

  static bool isMoney(String type) {
    final t = type.toLowerCase();
    return t.contains('receipt') || t.contains('deposit') || t.contains('money') || t.contains('collection');
  }

  @override
  Widget build(BuildContext context) {
    final p = paper;
    final sub = [p.amount, p.party].where((s) => s.trim().isNotEmpty).join(' · ');
    final title = [p.title, p.no].where((s) => s.trim().isNotEmpty).join(' ');
    return Container(
      width: 252,
      decoration: BoxDecoration(color: pal.inset, borderRadius: BorderRadius.circular(6)),
      padding: const EdgeInsets.only(top: 9),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
            Row(children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(color: const Color(0xFF16A34A), borderRadius: BorderRadius.circular(8)),
                alignment: Alignment.center,
                child: isMoney(p.type)
                    ? GlyphIcon(WaGlyphs.deposit, size: 22, color: const Color(0xFFFFFFFF), label: '\u09F3')
                    : const GlyphIcon(WaGlyphs.paper, size: 22, color: Color(0xFFFFFFFF)),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(title, style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: pal.ink, height: 1.3)),
                  if (sub.isNotEmpty) Text(sub, style: TextStyle(fontSize: 12, color: pal.meta, height: 1.3)),
                ]),
              ),
            ]),
            if (p.status.trim().isNotEmpty) ...[
              const SizedBox(height: 7),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
                decoration: BoxDecoration(color: const Color(0xFFFEF3C7), borderRadius: BorderRadius.circular(999)),
                child: Text(p.status, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Color(0xFF92400E))),
              ),
            ],
          ]),
        ),
        const SizedBox(height: 7),
        Container(
          decoration: BoxDecoration(border: Border(top: BorderSide(color: pal.lineIn))),
          child: InkWell(
            key: ValueKey('paper-open-${p.type}-${p.id}'),
            onTap: onOpen,
            borderRadius: const BorderRadius.vertical(bottom: Radius.circular(6)),
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 48, minWidth: double.infinity),
              child: Center(
                child: Text(context.l10n.msgChatPaperOpen, style: TextStyle(color: pal.link, fontWeight: FontWeight.w700, fontSize: 14)),
              ),
            ),
          ),
        ),
      ]),
    );
  }
}

class _Photo extends StatefulWidget {
  const _Photo({required this.message, required this.pal, required this.actions});

  final ChatMessage message;
  final WaPalette pal;
  final BubbleActions actions;

  @override
  State<_Photo> createState() => _PhotoState();
}

class _PhotoState extends State<_Photo> {
  late Future<Uint8List?> _bytes = widget.actions.image(widget.message);

  @override
  void didUpdateWidget(_Photo old) {
    super.didUpdateWidget(old);
    if (old.message.id != widget.message.id) _bytes = widget.actions.image(widget.message);
  }

  @override
  Widget build(BuildContext context) => Semantics(
        button: true,
        label: widget.message.text.isEmpty ? context.l10n.msgChatPhoto : widget.message.text,
        child: GestureDetector(
          onTap: () => widget.actions.onImage(widget.message),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: SizedBox(
              width: 220,
              height: 150,
              child: FutureBuilder<Uint8List?>(
                future: _bytes,
                builder: (context, s) {
                  final b = s.data;
                  if (b != null && b.isNotEmpty) {
                    return Image.memory(b, fit: BoxFit.cover, gaplessPlayback: true, errorBuilder: (_, __, ___) => _empty());
                  }
                  return _empty();
                },
              ),
            ),
          ),
        ),
      );

  Widget _empty() => ColoredBox(
        color: widget.pal.inset,
        child: Center(child: GlyphIcon(WaGlyphs.image, size: 36, color: widget.pal.meta)),
      );
}

class _FileTile extends StatelessWidget {
  const _FileTile({required this.message, required this.pal, required this.actions, required this.query, required this.current});

  final ChatMessage message;
  final WaPalette pal;
  final BubbleActions actions;
  final String query;
  final bool current;

  @override
  Widget build(BuildContext context) {
    final a = message.attachment;
    final name = a?.name ?? '';
    final ext = fileExt(name);
    final size = fileSize(a?.size ?? 0);
    return Semantics(
      button: true,
      label: '${context.l10n.msgChatDownload}: $name',
      child: InkWell(
        onTap: () => actions.onFile(message),
        borderRadius: BorderRadius.circular(6),
        child: Container(
          width: 250,
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
          decoration: BoxDecoration(color: pal.inset, borderRadius: BorderRadius.circular(6)),
          child: Row(children: [
            Container(
              width: 34,
              height: 40,
              decoration: BoxDecoration(color: const Color(0xFFE5484D), borderRadius: BorderRadius.circular(5)),
              alignment: Alignment.bottomCenter,
              padding: const EdgeInsets.only(bottom: 5),
              child: Text(ext, style: const TextStyle(color: Color(0xFFFFFFFF), fontSize: 9, fontWeight: FontWeight.w700, height: 1)),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: ExcludeSemantics(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text.rich(highlightSpan(name, query, current: current, pal: pal),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600, color: pal.ink, height: 1.3)),
                  Text(size.isEmpty ? ext : '$ext · $size', style: TextStyle(fontSize: 11.5, color: pal.meta, height: 1.3)),
                ]),
              ),
            ),
            const SizedBox(width: 10),
            ValueListenableBuilder<Set<String>>(
              valueListenable: actions.downloading,
              builder: (context, busy, _) => Container(
                width: 32,
                height: 32,
                decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: pal.meta, width: 1.5)),
                alignment: Alignment.center,
                child: busy.contains(message.id)
                    ? SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: pal.meta))
                    : GlyphIcon(WaGlyphs.download, size: 16, color: pal.meta),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}

class _Voice extends StatelessWidget {
  const _Voice({required this.message, required this.pal, required this.actions, required this.avatarOf, required this.meta});

  final ChatMessage message;
  final WaPalette pal;
  final BubbleActions actions;
  final ChatPerson? avatarOf;
  final Widget meta;

  static List<double> bars(List<double> wave, int n) {
    if (wave.isEmpty) return List.filled(n, .3);
    return [
      for (var i = 0; i < n; i++)
        () {
          final from = (i * wave.length / n).floor();
          final to = math.max(from + 1, ((i + 1) * wave.length / n).floor());
          var peak = 0.0;
          for (var j = from; j < to && j < wave.length; j++) {
            peak = math.max(peak, wave[j]);
          }
          return peak;
        }(),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final a = message.attachment;
    final total = Duration(milliseconds: a?.durationMs ?? 0);
    final b = bars(a?.waveform ?? const [], 30);
    final l = context.l10n;
    final bubble = message.mine ? pal.me : pal.th;
    return ValueListenableBuilder<VoiceNow>(
      valueListenable: actions.voice,
      builder: (context, now, _) {
        final mineNow = now.id == message.id;
        final playing = mineNow && now.playing;
        final dur = mineNow && now.duration > Duration.zero ? now.duration : total;
        final progress = mineNow && dur.inMilliseconds > 0 ? (now.position.inMilliseconds / dur.inMilliseconds).clamp(0.0, 1.0) : 0.0;
        return SizedBox(
          width: 250,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Row(children: [
              SizedBox(
                width: 44,
                height: 44,
                child: Stack(clipBehavior: Clip.none, children: [
                  ChatAvatar(person: avatarOf, size: 44),
                  Positioned(
                    right: -3,
                    bottom: -2,
                    child: Container(
                      width: 18,
                      height: 18,
                      decoration: BoxDecoration(color: WaPalette.green, shape: BoxShape.circle, border: Border.all(color: bubble, width: 2)),
                      alignment: Alignment.center,
                      child: GlyphIcon(WaGlyphs.micf, size: 10, color: const Color(0xFFFFFFFF)),
                    ),
                  ),
                ]),
              ),
              const SizedBox(width: 4),
              Semantics(
                button: true,
                label: '${l.msgChatVoice} ${voiceTime(total)} — ${playing ? l.msgChatVoicePause : l.msgChatVoicePlay}',
                child: InkResponse(
                  key: ValueKey('voice-play-${message.id}'),
                  onTap: () => actions.onVoice(message),
                  radius: 22,
                  child: SizedBox(
                    width: 40,
                    height: 48,
                    child: Center(child: GlyphIcon(playing ? WaGlyphs.pause : WaGlyphs.play, size: 24, color: pal.ico)),
                  ),
                ),
              ),
              const SizedBox(width: 4),
              Expanded(
                child: ExcludeSemantics(
                  child: SizedBox(
                    height: 30,
                    child: LayoutBuilder(builder: (context, c) {
                      final played = (progress * b.length).round();
                      return Stack(clipBehavior: Clip.none, alignment: Alignment.centerLeft, children: [
                        Row(children: [
                          for (var i = 0; i < b.length; i++) ...[
                            if (i > 0) const SizedBox(width: 2),
                            Expanded(
                              child: Container(
                                height: math.max(3, b[i] * 30),
                                decoration: BoxDecoration(
                                  borderRadius: BorderRadius.circular(2),
                                  color: i < played ? WaPalette.green : pal.meta.withValues(alpha: .55),
                                ),
                              ),
                            ),
                          ],
                        ]),
                        Positioned(
                          left: progress * c.maxWidth - 6,
                          top: 9,
                          child: Container(width: 12, height: 12, decoration: const BoxDecoration(color: WaPalette.green, shape: BoxShape.circle)),
                        ),
                      ]);
                    }),
                  ),
                ),
              ),
            ]),
            Padding(
              padding: const EdgeInsets.only(left: 92),
              child: Row(children: [
                Text(voiceTime(mineNow && now.position > Duration.zero ? now.position : total), style: TextStyle(fontSize: 11, color: pal.metaMe)),
                const Spacer(),
                meta,
              ]),
            ),
          ]),
        );
      },
    );
  }
}

class _LocationTile extends StatelessWidget {
  const _LocationTile({required this.message, required this.pal, required this.onTap});

  final ChatMessage message;
  final WaPalette pal;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final lat = message.lat, lng = message.lng;
    final where = lat == null || lng == null ? '' : '${lat.toStringAsFixed(5)}, ${lng.toStringAsFixed(5)}';
    return Semantics(
      button: true,
      label: '${context.l10n.msgChatLocation} — ${context.l10n.msgChatOpenMap}',
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(6),
        child: Container(
          width: 250,
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
          decoration: BoxDecoration(color: pal.inset, borderRadius: BorderRadius.circular(6)),
          child: ExcludeSemantics(
            child: Row(children: [
              Container(
                width: 40,
                height: 40,
                decoration: const BoxDecoration(color: Color(0xFF1FA855), shape: BoxShape.circle),
                alignment: Alignment.center,
                child: GlyphIcon(WaGlyphs.pin, size: 22, color: const Color(0xFFFFFFFF)),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.l10n.msgChatLocation, style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: pal.ink)),
                  if (where.isNotEmpty) Text(where, style: TextStyle(fontSize: 11.5, color: pal.meta)),
                ]),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

/// ⭐ তারিখের চিহ্ন — "আজ" · "গতকাল" · "18 নভেম্বর 2026"
class DateChip extends StatelessWidget {
  const DateChip({super.key, required this.text, required this.pal});

  final String text;
  final WaPalette pal;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 12, bottom: 4),
        child: Center(
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            decoration: BoxDecoration(color: pal.chip, borderRadius: BorderRadius.circular(7.5), boxShadow: WaPalette.bubbleShadow),
            child: Text(text, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: pal.chipInk)),
          ),
        ),
      );
}

/// ⭐ গ্রুপের খবরের লাইন — "ফারজানা নাসিরকে গ্রুপে যোগ করেছেন" (নমুনার `.sysl`)
class SystemChip extends StatelessWidget {
  const SystemChip({super.key, required this.text, required this.pal});

  final String text;
  final WaPalette pal;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 4, bottom: 2),
        child: Center(
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            decoration: BoxDecoration(color: pal.chip, borderRadius: BorderRadius.circular(7.5), boxShadow: WaPalette.bubbleShadow),
            child: Text(text, textAlign: TextAlign.center, style: TextStyle(fontSize: 12, color: pal.chipInk)),
          ),
        ),
      );
}

/// ⭐ "লিখছেন…" — তিন বিন্দু তাঁদের বুদবুদে, নিচে ছোট লাইন (নমুনার `.typing`)
class TypingBubble extends StatefulWidget {
  const TypingBubble({super.key, required this.people, required this.pal});

  final List<ChatPerson> people;
  final WaPalette pal;

  @override
  State<TypingBubble> createState() => _TypingBubbleState();
}

class _TypingBubbleState extends State<TypingBubble> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1200));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final still = MediaQuery.maybeDisableAnimationsOf(context) ?? false;
    if (still) {
      _c.stop();
    } else if (!_c.isAnimating) {
      unawaited(_c.repeat());
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final names = widget.people.map((p) => firstName(p.name)).join(', ');
    final pal = widget.pal;
    return Padding(
      padding: const EdgeInsets.only(top: 9),
      child: Align(
        alignment: Alignment.centerLeft,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          BubbleShell(
            mine: false,
            tail: true,
            colour: pal.th,
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            child: ExcludeSemantics(
              child: AnimatedBuilder(
                animation: _c,
                builder: (context, _) => Row(mainAxisSize: MainAxisSize.min, children: [
                  for (var i = 0; i < 3; i++) ...[
                    if (i > 0) const SizedBox(width: 4),
                    _dot(i),
                  ],
                ]),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 3, 4, 0),
            child: Text(context.l10n.msgChatTyping(names), style: TextStyle(fontSize: 11, color: pal.chipInk)),
          ),
        ]),
      ),
    );
  }

  Widget _dot(int i) {
    // নমুনার @keyframes bl: 0–60–100% স্থির (.35), 30%-এ উঁচু আর পুরো
    var t = (_c.value - i * .2 / 1.2) % 1.0;
    if (t < 0) t += 1;
    final up = t < .6 ? math.sin(t / .6 * math.pi) : 0.0;
    return Transform.translate(
      offset: Offset(0, -3 * up),
      child: Container(
        width: 7,
        height: 7,
        decoration: BoxDecoration(shape: BoxShape.circle, color: widget.pal.meta.withValues(alpha: .35 + .65 * up)),
      ),
    );
  }
}
