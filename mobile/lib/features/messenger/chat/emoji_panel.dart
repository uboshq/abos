import 'package:flutter/material.dart';

import '../../../core/locale/l10n.dart';
import 'wa_glyphs.dart';
import 'wa_palette.dart';

/// ⭐ ইমোজির ঘর — কিবোর্ডের জায়গায় খোলে (নমুনা 3nuwRUdn v7 "খ", ইমোজি): খোঁজা, ৬টা ভাগ, ৮ ঘরের সারি; চাপলে লেখায় বসে।
///
/// <p>ⓘ ইমোজি ফোনের নিজের রঙিন ফন্টে আঁকা; খোঁজা চলে ইংরেজি নাম দিয়ে (ইমোজির নিজের নাম — বাংলা নামের তালিকা পরে)।
class EmojiPanel extends StatefulWidget {
  const EmojiPanel({super.key, required this.pal, required this.onPick});

  final WaPalette pal;
  final ValueChanged<String> onPick;

  /// ⓘ এই চালুতে শেষে বাছা — নমুনার "সাম্প্রতিক"-এর শুরুর আটটা দিয়ে শুরু
  static final List<String> recent = ['👍', '🙏', '✅', '😊', '👌', '🎉', '❤️', '😂'];

  static void remember(String e) {
    recent.remove(e);
    recent.insert(0, e);
    if (recent.length > 24) recent.removeLast();
  }

  /// ভাগ → (ইমোজি, ইংরেজি নাম)
  static const List<List<(String, String)>> groups = [
    [],
    [
      ('😀', 'grin smile happy'), ('😃', 'smile happy'), ('😄', 'smile laugh'), ('😁', 'grin beam'), ('😆', 'laugh'),
      ('😅', 'sweat smile'), ('😂', 'joy laugh tears'), ('🤣', 'rofl laugh'), ('🙂', 'slight smile'), ('😉', 'wink'),
      ('😊', 'blush smile'), ('😇', 'angel innocent'), ('😍', 'love heart eyes'), ('🤩', 'star struck'), ('😘', 'kiss'),
      ('😋', 'yum tasty'), ('😎', 'cool sunglasses'), ('🤔', 'think'), ('🤗', 'hug'), ('😐', 'neutral'),
      ('😶', 'silent'), ('🙄', 'eye roll'), ('😏', 'smirk'), ('😮', 'surprised wow'), ('😴', 'sleep'),
      ('😌', 'relieved'), ('😢', 'cry sad'), ('😭', 'sob cry'), ('😡', 'angry'), ('🥳', 'party celebrate'),
      ('🤝', 'handshake deal'), ('👏', 'clap'), ('💪', 'strong muscle'), ('🙌', 'raise hands'), ('👋', 'wave hello bye'),
      ('👍', 'thumbs up ok yes'), ('🙏', 'pray thanks please'), ('👌', 'ok perfect'), ('✌️', 'victory peace'), ('🤞', 'fingers crossed luck'),
    ],
    [
      ('🌳', 'tree'), ('🌴', 'palm tree'), ('🌱', 'seedling plant'), ('🌿', 'herb leaf'), ('🍀', 'clover luck'),
      ('🍁', 'maple leaf'), ('🌸', 'blossom flower'), ('🌹', 'rose flower'), ('🌻', 'sunflower'), ('🌼', 'flower'),
      ('☀️', 'sun'), ('🌤️', 'sun cloud'), ('⛅', 'cloud'), ('🌧️', 'rain'), ('⛈️', 'storm'),
      ('🌈', 'rainbow'), ('🌙', 'moon night'), ('⭐', 'star'), ('🔥', 'fire'), ('💧', 'water drop'),
      ('🌊', 'wave sea'), ('🐄', 'cow'), ('🐐', 'goat'), ('🐓', 'rooster'), ('🐟', 'fish'),
      ('🐶', 'dog'), ('🐱', 'cat'), ('🐦', 'bird'), ('🦋', 'butterfly'), ('🐝', 'bee'),
    ],
    [
      ('🍚', 'rice'), ('🍛', 'curry rice'), ('🍲', 'stew pot'), ('🥘', 'pan food'), ('🍜', 'noodles'),
      ('🍞', 'bread'), ('🥚', 'egg'), ('🍗', 'chicken'), ('🐟', 'fish'), ('🥗', 'salad'),
      ('🍎', 'apple'), ('🍌', 'banana'), ('🥭', 'mango'), ('🍉', 'watermelon'), ('🍊', 'orange'),
      ('🍇', 'grapes'), ('🍍', 'pineapple'), ('🥥', 'coconut'), ('🍰', 'cake'), ('🎂', 'birthday cake'),
      ('🍬', 'candy sweet'), ('🍩', 'donut'), ('☕', 'tea coffee'), ('🍵', 'tea'), ('🥤', 'drink'),
      ('🧃', 'juice'), ('🥛', 'milk'), ('🍦', 'ice cream'), ('🍪', 'cookie biscuit'), ('🧁', 'cupcake'),
    ],
    [
      ('❤️', 'red heart love'), ('🧡', 'orange heart'), ('💛', 'yellow heart'), ('💚', 'green heart'), ('💙', 'blue heart'),
      ('💜', 'purple heart'), ('🖤', 'black heart'), ('🤍', 'white heart'), ('💕', 'two hearts'), ('💞', 'hearts'),
      ('💖', 'sparkling heart'), ('💗', 'growing heart'), ('💘', 'heart arrow'), ('💝', 'heart gift'), ('💔', 'broken heart'),
      ('❣️', 'heart exclamation'), ('😍', 'love eyes'), ('😘', 'kiss'), ('🥰', 'love smile'), ('💐', 'bouquet flowers'),
      ('🌹', 'rose'), ('🎁', 'gift present'), ('🎉', 'party popper'), ('🎊', 'confetti'), ('✨', 'sparkles'),
    ],
    [
      ('🇧🇩', 'bangladesh flag'), ('🇮🇳', 'india flag'), ('🇸🇦', 'saudi arabia flag'), ('🇦🇪', 'uae flag'), ('🇬🇧', 'uk flag'),
      ('🇺🇸', 'usa flag'), ('🇨🇳', 'china flag'), ('🇯🇵', 'japan flag'), ('🇲🇾', 'malaysia flag'), ('🇸🇬', 'singapore flag'),
      ('🏳️', 'white flag'), ('🏴', 'black flag'), ('🏁', 'chequered flag finish'), ('🚩', 'red flag'), ('🎌', 'crossed flags'),
      ('✅', 'check done yes'), ('❌', 'cross no'), ('⚠️', 'warning'), ('📌', 'pin'), ('📎', 'paperclip'),
    ],
  ];

  @override
  State<EmojiPanel> createState() => _EmojiPanelState();
}

class _EmojiPanelState extends State<EmojiPanel> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  final _heads = List.generate(6, (_) => GlobalKey());
  int _tab = 0;

  static final List<Glyph> _tabGlyphs = [WaGlyphs.clock, WaGlyphs.smile, WaGlyphs.leaf, WaGlyphs.cup, WaGlyphs.heart, WaGlyphs.flag];

  @override
  void dispose() {
    _search.dispose();
    _scroll.dispose();
    super.dispose();
  }

  List<String> _names(BuildContext context) {
    final l = context.l10n;
    return [l.msgChatEmojiRecent, l.msgChatEmojiFaces, l.msgChatEmojiNature, l.msgChatEmojiFood, l.msgChatEmojiLove, l.msgChatEmojiFlags];
  }

  void _pick(String e) {
    EmojiPanel.remember(e);
    widget.onPick(e);
  }

  void _jump(int i) {
    setState(() => _tab = i);
    final c = _heads[i].currentContext;
    if (c != null) Scrollable.ensureVisible(c, duration: const Duration(milliseconds: 200));
  }

  Widget _grid(List<String> list) => LayoutBuilder(builder: (context, c) {
        final w = c.maxWidth / 8;
        return Wrap(children: [
          for (final e in list)
            Semantics(
              button: true,
              label: e,
              child: InkWell(
                onTap: () => _pick(e),
                child: SizedBox(width: w, height: 48, child: Center(child: Text(e, style: const TextStyle(fontSize: 24, height: 1)))),
              ),
            ),
        ]);
      });

  @override
  Widget build(BuildContext context) {
    final pal = widget.pal;
    final names = _names(context);
    final q = _search.text.trim().toLowerCase();
    final Widget body;
    if (q.isNotEmpty) {
      final found = <String>{
        for (final g in EmojiPanel.groups)
          for (final (e, words) in g)
            if (words.contains(q)) e,
      }.toList();
      body = ListView(padding: const EdgeInsets.fromLTRB(8, 2, 8, 14), children: [_grid(found)]);
    } else {
      body = ListView(controller: _scroll, padding: const EdgeInsets.fromLTRB(8, 2, 8, 14), children: [
        for (var i = 0; i < 6; i++) ...[
          Padding(
            key: _heads[i],
            padding: const EdgeInsets.fromLTRB(4, 8, 4, 2),
            child: Text(names[i], style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: pal.meta)),
          ),
          _grid(i == 0 ? EmojiPanel.recent : [for (final (e, _) in EmojiPanel.groups[i]) e]),
        ],
      ]);
    }
    return Container(
      key: const ValueKey('emoji-panel'),
      height: 292,
      decoration: BoxDecoration(color: pal.sheet, border: Border(top: BorderSide(color: pal.line))),
      child: Column(children: [
        Container(
          margin: const EdgeInsets.fromLTRB(12, 10, 12, 6),
          height: 38,
          padding: const EdgeInsets.symmetric(horizontal: 14),
          decoration: BoxDecoration(color: const Color(0x1F7F7F7F), borderRadius: BorderRadius.circular(19)),
          child: Row(children: [
            GlyphIcon(WaGlyphs.search, size: 18, color: pal.meta),
            const SizedBox(width: 8),
            Expanded(
              child: TextField(
                controller: _search,
                onChanged: (_) => setState(() {}),
                style: TextStyle(fontSize: 14, color: pal.ink),
                decoration: InputDecoration(
                  isCollapsed: true,
                  border: InputBorder.none,
                  enabledBorder: InputBorder.none,
                  focusedBorder: InputBorder.none,
                  disabledBorder: InputBorder.none,
                  filled: false,
                  hintText: context.l10n.msgChatEmojiSearch,
                  hintStyle: TextStyle(fontSize: 14, color: pal.meta),
                ),
              ),
            ),
          ]),
        ),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 6),
          decoration: BoxDecoration(border: Border(bottom: BorderSide(color: pal.line))),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceAround, children: [
            for (var i = 0; i < 6; i++)
              Semantics(
                button: true,
                selected: _tab == i,
                label: names[i],
                child: InkWell(
                  onTap: () => _jump(i),
                  child: Container(
                    width: 48,
                    height: 48,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      border: Border(bottom: BorderSide(color: _tab == i ? WaPalette.green : const Color(0x00000000), width: 2)),
                    ),
                    child: GlyphIcon(_tabGlyphs[i], size: 21, color: _tab == i ? WaPalette.green : pal.meta),
                  ),
                ),
              ),
          ]),
        ),
        Expanded(child: body),
      ]),
    );
  }
}
