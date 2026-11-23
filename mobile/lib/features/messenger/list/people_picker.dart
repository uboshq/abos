import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../../core/locale/l10n.dart';
import '../../../core/messenger/messenger_api.dart';
import '../../../core/widgets/search_debouncer.dart';
import '../chat/chat_screen.dart';
import 'chat_list_look.dart';

/// ⭐ নিচ থেকে ওঠা সাদা শিটের রং — নমুনা 3nuwRUdn v5 "গ" ২-এর `.sheet` (আলোয় সাদা, WhatsApp-এর মতো); অন্ধকার থিমে (ক্লাসিকের
/// অন্ধকার, গ্লাস) নমুনার অন্ধকার `--wa-sheet` রং
@immutable
class SheetLook {
  const SheetLook._(
      {required this.bg,
      required this.ink,
      required this.muted,
      required this.field,
      required this.hint,
      required this.divider,
      required this.handle,
      required this.icon,
      required this.label,
      required this.ring,
      required this.lock});

  factory SheetLook.of(BuildContext context) => Theme.of(context).brightness == Brightness.dark
      ? const SheetLook._(
          bg: Color(0xFF1F2C34),
          ink: Color(0xFFE9EDEF),
          muted: Color(0xFF8696A0),
          field: Color(0xFF2A3942),
          hint: Color(0xFF8696A0),
          divider: Color(0xFF2A3942),
          handle: Color(0xFF3B4A54),
          icon: Color(0xFFAEBAC1),
          label: Color(0xFF21C063),
          ring: Color(0xFF1F2C34),
          lock: Color(0xFFFBBF24))
      : const SheetLook._(
          bg: Color(0xFFFFFFFF),
          ink: Color(0xFF111B21),
          muted: Color(0xFF667781),
          field: Color(0xFFF0F2F5),
          hint: Color(0xFF8696A0),
          divider: Color(0xFFE9EDEF),
          handle: Color(0xFFCBD2DC),
          icon: Color(0xFF54656F),
          label: Color(0xFF008069),
          ring: Color(0xFFFFFFFF),
          lock: Color(0xFFB45309));

  final Color bg;
  final Color ink;
  final Color muted;

  /// খোঁজা আর কথার গোল ঘর
  final Color field;
  final Color hint;
  final Color divider;
  final Color handle;

  /// × আর খোঁজার চিহ্ন
  final Color icon;

  /// "সাম্প্রতিক" — সবুজ
  final Color label;
  final Color ring;

  /// অনুমতি নেই — হলুদ-বাদামি লাইন
  final Color lock;
}

/// শিটের মাথা: হাতল, শিরোনাম আর ×
class SheetHead extends StatelessWidget {
  const SheetHead({super.key, required this.s, required this.title});

  final SheetLook s;
  final String title;

  @override
  Widget build(BuildContext context) => Column(mainAxisSize: MainAxisSize.min, children: [
        Container(
            width: 40,
            height: 4,
            margin: const EdgeInsets.fromLTRB(0, 4, 0, 4),
            decoration: BoxDecoration(color: s.handle, borderRadius: BorderRadius.circular(2))),
        Row(children: [
          Expanded(child: Text(title, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700, color: s.ink))),
          IconButton(
            key: const ValueKey('sheet-close'),
            tooltip: context.l10n.msgListClose,
            color: s.icon,
            onPressed: () => Navigator.of(context).maybePop(),
            icon: const Icon(Symbols.close),
          ),
        ]),
      ]);
}

/// গোল খোঁজার/লেখার ঘর (`.srch`, `.note`)
class SheetField extends StatelessWidget {
  const SheetField({super.key, required this.s, required this.controller, required this.hint, this.icon, this.onChanged, this.onSubmitted, this.fieldKey});

  final SheetLook s;
  final TextEditingController controller;
  final String hint;
  final IconData? icon;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final Key? fieldKey;

  @override
  Widget build(BuildContext context) => ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 48),
        child: DecoratedBox(
          decoration: BoxDecoration(color: s.field, borderRadius: BorderRadius.circular(24)),
          child: Row(children: [
            const SizedBox(width: 16),
            if (icon != null) ...[Icon(icon, size: 18, color: s.muted), const SizedBox(width: 10)],
            Expanded(
              child: TextField(
                key: fieldKey,
                controller: controller,
                onChanged: onChanged,
                onSubmitted: onSubmitted,
                style: TextStyle(fontSize: 14, color: s.ink),
                cursorColor: ChatListLook.green,
                decoration: InputDecoration(
                  isDense: true,
                  filled: false,
                  border: InputBorder.none,
                  enabledBorder: InputBorder.none,
                  focusedBorder: InputBorder.none,
                  hintText: hint,
                  hintStyle: TextStyle(fontSize: 13.5, color: s.hint),
                  contentPadding: const EdgeInsets.symmetric(vertical: 12),
                ),
              ),
            ),
            const SizedBox(width: 12),
          ]),
        ),
      );
}

/// ⭐ মানুষ বাছাই — "নতুন বার্তা" আর "আমি"-র কল। খোঁজে `MessengerApi.people` দিয়ে (রোলে যাঁদের সাথে কথা চলে, সার্ভারই ঠিক
/// করে)। চাপলে সেই মানুষ নিয়ে শিট বন্ধ; বন্ধ করলে null।
Future<ChatPerson?> pickPerson(BuildContext context, {required String title}) => showModalBottomSheet<ChatPerson>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: SheetLook.of(context).bg,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => _PeopleSheet(title: title),
    );

/// একজনের সাথের কথোপকথন খোলে (না থাকলে সার্ভার বানায়) — তালিকার "নতুন বার্তা" আর "আমি"-র "বার্তা"
Future<void> messagePerson(BuildContext context, MessengerApi api, ChatPerson person) async {
  try {
    final thread = await api.direct(person.id);
    if (context.mounted) await openChat(context, thread);
  } catch (e) {
    if (!context.mounted) return;
    final l = context.l10n;
    final text = switch (e) {
      MessengerNotAllowed(:final message) => message ?? l.msgListNotAllowed,
      MessengerDoorsMissing() => l.msgListNotOpenYet,
      _ => l.msgListLoadFailed,
    };
    ScaffoldMessenger.maybeOf(context)
      ?..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(text), behavior: SnackBarBehavior.floating));
  }
}

class _PeopleSheet extends ConsumerStatefulWidget {
  const _PeopleSheet({required this.title});

  final String title;

  @override
  ConsumerState<_PeopleSheet> createState() => _PeopleSheetState();
}

class _PeopleSheetState extends ConsumerState<_PeopleSheet> {
  final TextEditingController _q = TextEditingController();
  late final _typed = SearchDebouncer(onSearch: (_) => _load());
  List<ChatPerson>? _people;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _typed.dispose();
    _q.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final q = _q.text.trim();
    try {
      final people = await ref.read(messengerApiProvider).people(query: q);
      if (!mounted || q != _q.text.trim()) return;
      setState(() {
        _people = people;
        _error = null;
      });
    } catch (e) {
      if (!mounted) return;
      final l = context.l10n;
      setState(() => _error = switch (e) {
            MessengerNotAllowed(:final message) => message ?? l.msgListNotAllowed,
            MessengerDoorsMissing() => l.msgListNotOpenYet,
            _ => l.msgListLoadFailed,
          });
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = SheetLook.of(context);
    final l = context.l10n;
    final people = _people;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.8,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            SheetHead(s: s, title: widget.title),
            SheetField(
              s: s,
              fieldKey: const ValueKey('people-search'),
              controller: _q,
              hint: l.msgListFindPerson,
              icon: Symbols.search,
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
            ),
            const SizedBox(height: 6),
            Expanded(
              child: _error != null
                  ? Center(child: Text(_error!, textAlign: TextAlign.center, style: TextStyle(color: s.muted)))
                  : people == null
                      ? const Center(child: CircularProgressIndicator())
                      : people.isEmpty
                          ? Center(child: Text(l.msgListNoMatch, style: TextStyle(color: s.muted)))
                          : ListView(children: [
                              for (final (i, p) in people.indexed)
                                PersonRow(
                                  key: ValueKey('people-row-${p.id}'),
                                  s: s,
                                  avatar: ChatAvatar(size: 42, ring: s.ring, person: p),
                                  name: p.name,
                                  sub: p.role,
                                  last: i == people.length - 1,
                                  onTap: () => Navigator.of(context).pop(p),
                                ),
                            ]),
            ),
          ]),
        ),
      ),
    );
  }
}

/// নমুনার `.prow` — অবতার ৪২, নাম, ছোট লাইন (বা হলুদ তালার লাইন), ডানে ইচ্ছা হলে কিছু
class PersonRow extends StatelessWidget {
  const PersonRow(
      {super.key,
      required this.s,
      required this.avatar,
      required this.name,
      required this.sub,
      this.locked,
      this.trailing,
      this.last = false,
      this.onTap,
      this.selected});

  final SheetLook s;
  final Widget avatar;
  final String name;
  final String sub;

  /// অনুমতি নেই — এই লাইন [sub]-এর জায়গায়, তালার চিহ্নসহ
  final String? locked;
  final Widget? trailing;
  final bool last;
  final VoidCallback? onTap;

  /// বাছাইয়ের সারি হলে (কাগজ পাঠানো) — TalkBack-এ "বাছা"
  final bool? selected;

  @override
  Widget build(BuildContext context) => Semantics(
        button: true,
        selected: selected,
        child: InkWell(
          onTap: onTap,
          child: Row(children: [
            avatar,
            const SizedBox(width: 12),
            Expanded(
              child: Container(
                constraints: const BoxConstraints(minHeight: 48),
                padding: const EdgeInsets.symmetric(vertical: 8),
                decoration: BoxDecoration(border: last ? null : Border(bottom: BorderSide(color: s.divider))),
                child: Row(children: [
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                      Text(name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(fontSize: 14.5, fontWeight: FontWeight.w700, color: s.ink, height: 1.25)),
                      if (locked != null)
                        Row(children: [
                          Icon(Symbols.lock, size: 12, color: s.lock),
                          const SizedBox(width: 3),
                          Expanded(
                            child: Text(locked!,
                                key: const ValueKey('person-locked'), style: TextStyle(fontSize: 11.5, color: s.lock, height: 1.25)),
                          ),
                        ])
                      else if (sub.isNotEmpty)
                        Text(sub,
                            maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 11.5, color: s.muted, height: 1.25)),
                    ]),
                  ),
                  if (trailing != null) ...[const SizedBox(width: 8), trailing!],
                ]),
              ),
            ),
          ]),
        ),
      );
}
