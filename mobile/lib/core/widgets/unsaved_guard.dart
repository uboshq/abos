import 'package:flutter/material.dart';

/// ⭐ লেখা অবস্থায় "পিছনে" — জিজ্ঞেস করে, তারপর যায় (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; Material 3: Dialogs;
/// Android: back navigation)।
///
/// ⚠️ আগে ১৭টা নতুন-কাগজের ফর্মে লেখা অবস্থায় ফোনের "পিছনে" বা উপরের তীর চাপলে কিছু না বলে ফর্ম বন্ধ হত, আর যা লেখা
/// হয়েছিল তা হারাত — মাঠে একটা লম্বা অর্ডার আবার গোড়া থেকে।
///
/// ⓘ [dirty] পর্দা নিজে বলে: কিছু লেখা হয়েছে আর এখনো পাঠানো হয়নি। খালি ফর্মে কিছু জিজ্ঞেস করে না। পাঠানোর পরে ফর্ম
/// নিজে `Navigator.pop()` দিয়ে বন্ধ হলে এই পাহারা খাটে না — `pop()` পাহারা মানে না, কেবল `maybePop()` আর ফোনের
/// "পিছনে" মানে — তাই সফল কাজের পরে অকারণ প্রশ্ন নেই।
class UnsavedGuard extends StatelessWidget {
  const UnsavedGuard({super.key, required this.dirty, required this.child});

  /// কিছু লেখা আছে যা এখনো পাঠানো হয়নি
  final bool dirty;
  final Widget child;

  @override
  Widget build(BuildContext context) {
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
      title: const Text('লেখা ফেলে চলে যাবেন?'),
      content: const Text('যা লিখেছেন তা এখনো পাঠানো হয়নি — চলে গেলে হারিয়ে যাবে।'),
      actions: [
        TextButton(
          key: const ValueKey('unsaved-stay'),
          onPressed: () => Navigator.of(ctx).pop(false),
          child: const Text('থাকুন'),
        ),
        FilledButton(
          key: const ValueKey('unsaved-leave'),
          onPressed: () => Navigator.of(ctx).pop(true),
          child: const Text('ফেলে যান'),
        ),
      ],
    ),
  );
  return answer ?? false;
}
