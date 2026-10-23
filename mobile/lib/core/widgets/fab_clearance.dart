import 'package:flutter/widgets.dart';

// ⭐ "＋ নতুন" কখনো তথ্য ঢাকে না — স্থির অবস্থায়, স্ক্রলের মাঝে, শেষে; যে কোনো চওড়ায়, যে কোনো অক্ষরের মাপে (মালিকের নিয়ম,
// ০.৪.৪৭ থেকে)। ফোনে বোতাম আর পাতার ওপরে ভাসে না: পর্দার নিচের বারের ভেতরে বসে (Material 3 BottomAppBar +
// FloatingActionButtonLocation.endContained-এর মতো; [ActionBottomBar], nav_bottom_bar.dart), তালিকা বারের ওপরেই শেষ হয় —
// তাই আগের ৮৮dp নিচের ফাঁক (FabClearance) আর লাগে না, সরানো হয়েছে (০.৪.৪৮)। ট্যাবলেটে আগের মতো খাড়া বারের মাথায়।

/// ⭐ কাঠামোর "＋ নতুন" — ScreenFrame এটা পাতার চারপাশে বসায়।
///
/// <p>[offered]: কাঠামোর বোতাম, পর্দা নিজের নিচের বারে বসাবে — যে পর্দার নিচে নিজের মোটের বার আছে (অর্ডার), সে এটা
/// নিজের [ActionBottomBar]-এ মোটের পাশে বসায়।
///
/// <p>[railSlot]: খাড়া বার দেখানো হলে (৬০০dp বা চওড়া, বা আড়াআড়ি) Material 3-এর মতো পাতার মূল কাজ বারের মাথায় বসে
/// (NavigationRail-এর leading), পাতার ওপরে ভাসে না (fe, ২২ অক্টোবর ২০২৬: চওড়া ট্যাবলেটে বোতাম না-গড়ানো ছোট পাতার
/// মোট ঢেকে রাখত)। পর্দার নিজের বোতাম (BarFab) তখন নিজে আঁকে না, এখানে নিজের নাম আর কাজ রেখে দেয়; বার সেটা আঁকে।
class FrameFab extends InheritedWidget {
  const FrameFab({super.key, required this.offered, this.railSlot, required super.child});

  final Widget? offered;

  /// খাড়া বার দেখানো হলে বারের মাথার বোতামের ঘর; নইলে null
  final ValueNotifier<RailAction?>? railSlot;

  /// খাড়া বারের মাথার ঘর — বার দেখানো হলে, নইলে null
  static ValueNotifier<RailAction?>? railSlotOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<FrameFab>()?.railSlot;

  /// পর্দার Scaffold-এ বসানোর বোতাম, বা null
  static Widget? offeredTo(BuildContext context) => context.dependOnInheritedWidgetOfExactType<FrameFab>()?.offered;

  @override
  bool updateShouldNotify(FrameFab oldWidget) =>
      offered != oldWidget.offered || railSlot != oldWidget.railSlot;
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
