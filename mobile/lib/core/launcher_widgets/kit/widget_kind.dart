import 'widget_doc.dart';

/// উইজেটের মাপ, Android-এর ঘর ধরে (কলাম × সারি)
enum WidgetSize {
  s2x1(2, 1),
  s2x2(2, 2),
  s4x1(4, 1),
  s4x2(4, 2),
  s4x6(4, 6);

  const WidgetSize(this.columns, this.rows);

  final int columns;
  final int rows;
}

/// ⭐ একটা উইজেটের Dart অংশ — যা প্রত্যেকে লেখে (পরিকল্পনা ৪, অংশ ৯; নিয়ম `E:\ABOS\app-widgets-ভিত্তি.md`)।
///
/// <p>[key] সার্ভারের সারাংশ-দরজার চাবির সাথে হুবহু এক (`GET /api/v1/widgets/summary` → `widgets.<key>`)। [build] খাঁটি:
/// নেটওয়ার্ক নয়, ঘড়ি নয় ([asOf] দেওয়া হয়), কেবল সারাংশ থেকে নথি — তাই দাবি-টেস্ট সহজ।
abstract class LauncherWidgetKind {
  const LauncherWidgetKind();

  /// সার্ভারের চাবি আর Android-এর উইজেটের নাম — `sales_today`
  String get key;

  /// উইজেট বাছার তালিকায় নাম — "আজকের বিক্রি"
  String get title;

  /// যে মাপে বসানো যায়; প্রথমটা শুরুর মাপ
  List<WidgetSize> get sizes;

  /// ফোনের নিজের সারাংশ — যে উইজেটের তথ্য সার্ভারে নেই (যেমন সিঙ্কের অবস্থা)। null হলে (সাধারণ) সার্ভারের উত্তর থেকে
  /// নেওয়া হয়; না-null হলে সার্ভারে এই চাবি খোঁজা হয় না (a6-এর অনুরোধ, ২০ অক্টোবর ২০২৬)।
  Map<String, dynamic>? localSummary() => null;

  /// এই উইজেটের তথ্য কেবল ফোনেরই ([localSummary]) — সার্ভারে কখনো থাকে না। তখন [localSummary] null দিলে (যেমন পেছনের
  /// নতুন isolate, যেখানে ফোন এখনো কিছু দেখেনি) উইজেট যেমন ছিল তেমন থাকে; সার্ভারে খুঁজে "নেই" ধরে বন্ধ করা নয়
  /// (a6-এর ধরা, ২০ অক্টোবর ২০২৬)।
  bool get answersLocally => false;

  /// সার্ভারের সারাংশ (`widgets.<key>`) থেকে নথি। সারাংশ না থাকলে (অনুমতি নেই) এটা ডাকা হয় না।
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf});
}
