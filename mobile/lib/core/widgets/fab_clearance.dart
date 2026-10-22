import 'package:flutter/widgets.dart';

/// ⭐ ভাসমান "＋ নতুন" কখনো তথ্য ঢাকে না (Material 3: স্ক্রল করা লেখা FAB-এর নিচে আটকে থাকে না; fe, ২২ অক্টোবর ২০২৬)।
///
/// <p>বোতাম ৫৬dp উঁচু, পাতার নিচ থেকে ১৬dp ওপরে বসে; তার ওপরে আরও ১৬dp ফাঁকা — তাই FAB-ওয়ালা পাতার তালিকার নিচে অন্তত
/// [extent] = ৮৮dp ফাঁকা থাকে, আর তালিকা শেষ পর্যন্ত স্ক্রল করলে শেষ সারি আর মোট বোতামের ওপরে পুরো দেখা যায়। ফোন,
/// ট্যাবলেট, খাড়া বার — সব বিন্যাসে একই (চওড়া পাতায়ও বোতাম ডান-নিচে ভাসে)।
///
/// <p>কাঠামোর বোতাম (ScreenFrame, যেমন হোম) হলে পাতা নিজে জানে না — [of] কাঠামো থেকে ([FrameFab]) পড়ে। যে পর্দা নিজে
/// বোতাম আঁকে (আদায়, ভাউচার, দাবি …) সে `own:`-এ বলে দেয়। ⓘ বোতাম না থাকলে তালিকা যেমন ছিল তেমন।
abstract final class FabClearance {
  /// ৫৬ (বোতাম) + ১৬ (বোতামের নিচের ফাঁক) + ১৬ (বোতাম আর শেষ সারির মাঝে)
  static const double extent = 56 + 16 + 16;

  /// এই পাতার তালিকার নিচে কতটা ফাঁকা লাগবে — কাঠামোর বোতাম বা পর্দার নিজের ([own]) থাকলে [extent], নইলে ০
  static double of(BuildContext context, {bool own = false}) => own || FrameFab.drawsOver(context) ? extent : 0;

  /// তালিকার [padding], নিচেরটা অন্তত [of]
  static EdgeInsets pad(BuildContext context, EdgeInsets padding, {bool own = false}) {
    final need = of(context, own: own);
    return padding.bottom >= need ? padding : padding.copyWith(bottom: need);
  }
}

/// ⭐ কাঠামোর "＋ নতুন" — ScreenFrame এটা পাতার চারপাশে বসায়।
///
/// <p>[drawn]: কাঠামো নিজে বোতাম আঁকছে, পাতার ওপরে ভাসছে — পাতার তালিকা [FabClearance] দিয়ে নিচে জায়গা রাখে।
/// [offered]: কাঠামোর বোতাম, পর্দা নিজের Scaffold-এ বসাবে — যে পর্দার নিচে নিজের বার আছে (অর্ডারের মোটের বার), সে
/// এটা নিজের `floatingActionButton`-এ বসায়, তাতে বোতাম মোটের বারের ওপরে ভাসে, "সর্বমোট" ঢাকে না।
class FrameFab extends InheritedWidget {
  const FrameFab({super.key, required this.drawn, required this.offered, required super.child});

  final bool drawn;
  final Widget? offered;

  /// কাঠামো নিজে বোতাম আঁকছে কি না
  static bool drawsOver(BuildContext context) => context.dependOnInheritedWidgetOfExactType<FrameFab>()?.drawn ?? false;

  /// পর্দার Scaffold-এ বসানোর বোতাম, বা null
  static Widget? offeredTo(BuildContext context) => context.dependOnInheritedWidgetOfExactType<FrameFab>()?.offered;

  @override
  bool updateShouldNotify(FrameFab oldWidget) => drawn != oldWidget.drawn || offered != oldWidget.offered;
}
