import 'package:flutter/material.dart';

import '../../../core/locale/l10n.dart';
import '../../../core/messenger/messenger_api.dart';
import 'chat_bubbles.dart';
import 'wa_glyphs.dart';
import 'wa_palette.dart';

/// ⋮ মেনুর কাজ
enum ChatMenu { search, media, mute, members }

/// বার্তা চেপে ধরলে মাথার কাজ
enum ChatAct { reply, forward, copy, delete, info }

/// ⭐ চ্যাটের মাথা (নমুনার `.wh`) — পেছনে, অবতার, নাম আর অবস্থা, ভিডিও, কল, ⋮
class ChatHeader extends StatelessWidget {
  const ChatHeader({
    super.key,
    required this.thread,
    required this.pal,
    required this.online,
    required this.muted,
    required this.onBack,
    required this.onVideo,
    required this.onCall,
    required this.onMenu,
    this.typing = const [],
    this.calls = true,
  });

  final ChatThread thread;
  final WaPalette pal;

  /// অন্যজন অনলাইন কি (টানের `peer_online` থাকলে সেটা)
  final bool online;
  final bool muted;
  final VoidCallback onBack;
  final VoidCallback onVideo;
  final VoidCallback onCall;
  final ValueChanged<ChatMenu> onMenu;

  /// এই মুহূর্তে যাঁরা লিখছেন — থাকলে অবস্থার জায়গায় "… লিখছেন…" (সবুজ)
  final List<ChatPerson> typing;

  /// ⭐ কল আর ভিডিওর বোতাম — কেবল সার্ভার কল জানলে (`/me` features.calls); না জানলে বোতামই নেই
  final bool calls;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final t = thread;
    final String status;
    final busy = typing.isNotEmpty;
    if (busy) {
      status = t.isGroup ? l.msgChatTyping(typing.map((p) => p.name.split(' ').first).join(', ')) : l.msgChatTypingNow;
    } else if (t.isGroup) {
      status = t.onlineCount > 0 ? l.msgChatGroupStatus(t.memberCount, t.onlineCount) : l.msgChatGroupMembers(t.memberCount);
    } else {
      status = online ? l.msgChatOnline : (t.person?.role.isNotEmpty == true ? t.person!.role : t.subtitle);
    }
    return Material(
      color: pal.hdr,
      elevation: 0,
      child: DecoratedBox(
        decoration: BoxDecoration(color: pal.hdr, boxShadow: const [BoxShadow(color: Color(0x140B141A), offset: Offset(0, 1), blurRadius: 3)]),
        child: SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(0, 6, 0, 5),
            child: Row(children: [
              IconButton(
                key: const ValueKey('chat-back'),
                tooltip: l.msgChatBack,
                onPressed: onBack,
                icon: GlyphIcon(WaGlyphs.back, size: 22, color: pal.hdrInk),
              ),
              ChatAvatar(person: t.person, thread: t.isGroup ? t : null, size: 40, showOnline: !t.isGroup && online, ring: pal.hdr),
              const SizedBox(width: 10),
              Expanded(
                child: Semantics(
                  header: true,
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                    Text(t.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: pal.hdrInk, height: 1.25)),
                    if (status.isNotEmpty)
                      Text(status, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12, color: busy ? WaPalette.green : pal.meta, height: 1.25)),
                  ]),
                ),
              ),
              if (calls) ...[
              IconButton(
                key: const ValueKey('chat-video'),
                tooltip: t.isGroup ? l.msgChatGroupVideo : l.msgChatVideoCall,
                onPressed: onVideo,
                icon: GlyphIcon(WaGlyphs.video, size: 22, color: pal.hdrInk),
              ),
              IconButton(
                key: const ValueKey('chat-call'),
                tooltip: t.isGroup ? l.msgChatGroupCall : l.msgChatVoiceCall,
                onPressed: onCall,
                icon: GlyphIcon(WaGlyphs.phone, size: 22, color: pal.hdrInk),
              ),
              ],
              PopupMenuButton<ChatMenu>(
                key: const ValueKey('chat-menu'),
                tooltip: l.msgChatMore,
                icon: GlyphIcon(WaGlyphs.kebab, size: 22, color: pal.hdrInk),
                color: pal.sheet,
                elevation: 8,
                position: PopupMenuPosition.under,
                offset: const Offset(-2, -4),
                constraints: const BoxConstraints(minWidth: 210),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                onSelected: onMenu,
                itemBuilder: (context) => [
                  _item(ChatMenu.search, WaGlyphs.search, l.msgChatMenuSearch),
                  _item(ChatMenu.media, WaGlyphs.image, l.msgChatMenuMedia),
                  _item(ChatMenu.mute, muted ? WaGlyphs.bell : WaGlyphs.belloff, muted ? l.msgChatMenuUnmute : l.msgChatMenuMute),
                  if (t.isGroup) _item(ChatMenu.members, WaGlyphs.users, l.msgChatMenuMembers),
                ],
              ),
            ]),
          ),
        ),
      ),
    );
  }

  PopupMenuItem<ChatMenu> _item(ChatMenu v, Glyph g, String text) => PopupMenuItem<ChatMenu>(
        key: ValueKey('chat-menu-${v.name}'),
        value: v,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Row(children: [
          GlyphIcon(g, size: 19, color: pal.ico),
          const SizedBox(width: 12),
          Flexible(child: Text(text, style: TextStyle(fontSize: 14, color: pal.ink))),
        ]),
      );
}

/// ⭐ বার্তা চেপে ধরলে মাথার জায়গায় কাজের সারি (নমুনা v7 `.abar`) — বাছাই বাতিল, গোনা, উত্তর · ফরোয়ার্ড · কপি · মুছুন · তথ্য
class ChatActionBar extends StatelessWidget {
  const ChatActionBar({super.key, required this.pal, required this.count, required this.onClear, required this.onAct, this.canCopy = true});

  final WaPalette pal;
  final int count;
  final VoidCallback onClear;
  final ValueChanged<ChatAct> onAct;
  final bool canCopy;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final acts = <(ChatAct, Glyph, String)>[
      (ChatAct.reply, WaGlyphs.reply, l.msgChatActReply),
      (ChatAct.forward, WaGlyphs.fwd, l.msgChatActForward),
      (ChatAct.copy, WaGlyphs.copy, l.msgChatActCopy),
      (ChatAct.delete, WaGlyphs.trash, l.msgChatActDelete),
      (ChatAct.info, WaGlyphs.info, l.msgChatActInfo),
    ];
    return Material(
      key: const ValueKey('chat-action-bar'),
      color: pal.hdr,
      child: DecoratedBox(
        decoration: BoxDecoration(color: pal.hdr, boxShadow: const [BoxShadow(color: Color(0x140B141A), offset: Offset(0, 1), blurRadius: 3)]),
        child: SafeArea(
          bottom: false,
          child: SizedBox(
            height: 62,
            child: Row(children: [
              IconButton(
                key: const ValueKey('chat-unselect'),
                tooltip: l.msgChatUnselect,
                onPressed: onClear,
                icon: GlyphIcon(WaGlyphs.back, size: 22, color: pal.hdrInk),
              ),
              Text('$count', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600, color: pal.hdrInk)),
              const Spacer(),
              for (final a in acts)
                if (a.$1 != ChatAct.copy || canCopy)
                  Semantics(
                    button: true,
                    label: a.$3,
                    child: InkResponse(
                      key: ValueKey('chat-act-${a.$1.name}'),
                      onTap: () => onAct(a.$1),
                      radius: 26,
                      child: SizedBox(
                        width: 52,
                        height: 52,
                        child: ExcludeSemantics(
                          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                            GlyphIcon(a.$2, size: 21, color: pal.hdrInk),
                            const SizedBox(height: 2),
                            Text(a.$3, maxLines: 1, overflow: TextOverflow.fade, softWrap: false,
                                style: TextStyle(fontSize: 10, height: 1.1, color: pal.hdrInk)),
                          ]),
                        ),
                      ),
                    ),
                  ),
              const SizedBox(width: 2),
            ]),
          ),
        ),
      ),
    );
  }
}

/// ⭐ কথোপকথনে খোঁজা — মাথার জায়গায় খোঁজার সারি
class ChatSearchBar extends StatelessWidget {
  const ChatSearchBar({
    super.key,
    required this.pal,
    required this.controller,
    required this.onClose,
    required this.onSubmit,
    required this.onPrev,
    required this.onNext,
    required this.at,
    required this.of,
    required this.searched,
  });

  final WaPalette pal;
  final TextEditingController controller;
  final VoidCallback onClose;
  final ValueChanged<String> onSubmit;
  final VoidCallback? onPrev;
  final VoidCallback? onNext;

  /// কোনটা (১ থেকে), কয়টার মধ্যে
  final int at;
  final int of;

  /// খোঁজা হয়েছে কি (না হলে গোনা দেখায় না)
  final bool searched;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    return Material(
      color: pal.hdr,
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(0, 6, 0, 5),
          child: Row(children: [
            IconButton(
              key: const ValueKey('chat-search-close'),
              tooltip: l.msgChatSearchClose,
              onPressed: onClose,
              icon: GlyphIcon(WaGlyphs.back, size: 22, color: pal.hdrInk),
            ),
            Expanded(
              child: TextField(
                key: const ValueKey('chat-search-field'),
                controller: controller,
                autofocus: true,
                textInputAction: TextInputAction.search,
                onSubmitted: onSubmit,
                style: TextStyle(fontSize: 16, color: pal.hdrInk),
                cursorColor: WaPalette.green,
                decoration: InputDecoration(
                  border: InputBorder.none,
                  enabledBorder: InputBorder.none,
                  focusedBorder: InputBorder.none,
                  disabledBorder: InputBorder.none,
                  filled: false,
                  hintText: l.msgChatSearchHint,
                  hintStyle: TextStyle(fontSize: 16, color: pal.ph),
                ),
              ),
            ),
            if (searched)
              Text(of == 0 ? l.msgChatSearchNone : l.msgChatSearchCount(at, of), style: TextStyle(fontSize: 13, color: pal.meta)),
            IconButton(
              key: const ValueKey('chat-search-prev'),
              tooltip: l.msgChatSearchPrev,
              onPressed: onPrev,
              icon: Icon(Icons.keyboard_arrow_up, color: pal.ico),
            ),
            IconButton(
              key: const ValueKey('chat-search-next'),
              tooltip: l.msgChatSearchNext,
              onPressed: onNext,
              icon: Icon(Icons.keyboard_arrow_down, color: pal.ico),
            ),
          ]),
        ),
      ),
    );
  }
}
