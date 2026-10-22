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

  /// এই পাতার তালিকার নিচে কতটা ফাঁকা লাগবে — কাঠামোর বোতাম বা পর্দার নিজের ([own]) থাকলে [extent], নইলে ০।
  /// ⓘ খাড়া বারের পাশে (ট্যাবলেট) বোতাম বারের মাথায় বসে, পাতার ওপরে ভাসে না ([FrameFab.inRail]) — তখন ০
  static double of(BuildContext context, {bool own = false}) {
    if (FrameFab.railSlotOf(context) != null) return 0;
    return own || FrameFab.drawsOver(context) ? extent : 0;
  }

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
///
/// <p>[railSlot]: খাড়া বার দেখানো হলে (৬০০dp বা চওড়া, বা আড়াআড়ি) Material 3-এর মতো পাতার মূল কাজ বারের মাথায় বসে
/// (NavigationRail-এর leading), পাতার ওপরে ভাসে না (fe, ২২ অক্টোবর ২০২৬: চওড়া ট্যাবলেটে বোতাম না-গড়ানো ছোট পাতার
/// মোট ঢেকে রাখত)। পর্দার নিজের বোতাম (BarFab) তখন নিজে আঁকে না, এখানে নিজের নাম আর কাজ রেখে দেয়; বার সেটা আঁকে।
class FrameFab extends InheritedWidget {
  const FrameFab({super.key, required this.drawn, required this.offered, this.railSlot, required super.child});

  final bool drawn;
  final Widget? offered;

  /// খাড়া বার দেখানো হলে বারের মাথার বোতামের ঘর; নইলে null
  final ValueNotifier<RailAction?>? railSlot;

  /// খাড়া বারের মাথার ঘর — বার দেখানো হলে, নইলে null
  static ValueNotifier<RailAction?>? railSlotOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<FrameFab>()?.railSlot;

  /// কাঠামো নিজে বোতাম আঁকছে কি না
  static bool drawsOver(BuildContext context) => context.dependOnInheritedWidgetOfExactType<FrameFab>()?.drawn ?? false;

  /// পর্দার Scaffold-এ বসানোর বোতাম, বা null
  static Widget? offeredTo(BuildContext context) => context.dependOnInheritedWidgetOfExactType<FrameFab>()?.offered;

  @override
  bool updateShouldNotify(FrameFab oldWidget) =>
      drawn != oldWidget.drawn || offered != oldWidget.offered || railSlot != oldWidget.railSlot;
}

/// খাড়া বারের মাথার বোতাম — পর্দার "＋ নতুন …"-এর একই নাম আর একই কাজ। [owner]: কে রেখেছে (চলে গেলে সে-ই সরায়)।
@immutable
class RailAction {
  const RailAction({required this.label, required this.icon, required this.onPressed, required this.owner});

  final String label;
  final IconData icon;
  final VoidCallback onPressed;
  final Object owner;
}
