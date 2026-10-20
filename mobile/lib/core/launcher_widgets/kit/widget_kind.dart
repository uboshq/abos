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

  /// সার্ভারের সারাংশ (`widgets.<key>`) থেকে নথি। সারাংশ না থাকলে (অনুমতি নেই) এটা ডাকা হয় না।
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf});
}
