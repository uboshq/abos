import 'package:flutter/material.dart';

import '../locale/l10n.dart';

/// ⭐ লেখা অবস্থায় "পিছনে" — জিজ্ঞেস করে, তারপর যায় (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; Material 3: Dialogs;
/// Android: back navigation)।
///
/// ⚠️ আগে ১৭টা নতুন-কাগজের ফর্মে লেখা অবস্থায় ফোনের "পিছনে" বা উপরের তীর চাপলে কিছু না বলে ফর্ম বন্ধ হত, আর যা লেখা
/// হয়েছিল তা হারাত — মাঠে একটা লম্বা অর্ডার আবার গোড়া থেকে।
///
/// ⓘ [dirty] পর্দা নিজে বলে: কিছু লেখা হয়েছে আর এখনো পাঠানো হয়নি। খালি ফর্মে কিছু জিজ্ঞেস করে না। পাঠানোর পরে ফর্ম
/// নিজে `Navigator.pop()` দিয়ে বন্ধ হলে এই পাহারা খাটে না — `pop()` পাহারা মানে না, কেবল `maybePop()` আর ফোনের
/// "পিছনে" মানে — তাই সফল কাজের পরে অকারণ প্রশ্ন নেই।
class UnsavedGuard extends StatefulWidget {
  const UnsavedGuard({super.key, required this.dirty, required this.child});

  /// কিছু লেখা আছে যা এখনো পাঠানো হয়নি
  final bool dirty;
  final Widget child;

  /// ⭐ অ্যাপের কোথাও আধা-লেখা ফর্ম খোলা আছে কি না — শাখা বদলের আগে উপরের বার জিজ্ঞেস করে
  /// ("না রেখে বদলাবেন?"; ওপরের বার, নকশা ৩ §৭, ২০ অক্টোবর ২০২৬)
  static bool get anyDirty => _dirty.isNotEmpty;

  static final Set<State<UnsavedGuard>> _dirty = {};

  @override
  State<UnsavedGuard> createState() => _UnsavedGuardState();
}

class _UnsavedGuardState extends State<UnsavedGuard> {
  void _note() => widget.dirty ? UnsavedGuard._dirty.add(this) : UnsavedGuard._dirty.remove(this);

  @override
  void initState() {
    super.initState();
    _note();
  }

  @override
  void didUpdateWidget(UnsavedGuard oldWidget) {
    super.didUpdateWidget(oldWidget);
    _note();
  }

  @override
  void dispose() {
    UnsavedGuard._dirty.remove(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final dirty = widget.dirty;
    final child = widget.child;
    return PopScope(
      canPop: !dirty,
      onPopInvokedWithResult: (didPop, result) async {
        if (didPop) return;
        final leave = await confirmLeave(context);
        if (leave && context.mounted) Navigator.of(context).pop(result);
      },
      child: child,
    );
  }
}

/// "লেখা ফেলে চলে যাবেন?" — `true` হলে যাওয়া, `false` বা বাইরে চাপলে থাকা।
Future<bool> confirmLeave(BuildContext context) async {
  final answer = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      key: const ValueKey('unsaved-guard'),
      icon: const Icon(Icons.edit_note_rounded),
      title: Text(ctx.l10n.unsavedTitle),
      content: Text(ctx.l10n.unsavedBody),
      actions: [
        TextButton(
          key: const ValueKey('unsaved-stay'),
          onPressed: () => Navigator.of(ctx).pop(false),
          child: Text(ctx.l10n.unsavedStay),
        ),
        FilledButton(
          key: const ValueKey('unsaved-leave'),
          onPressed: () => Navigator.of(ctx).pop(true),
          child: Text(ctx.l10n.unsavedLeave),
        ),
      ],
    ),
  );
  return answer ?? false;
}
