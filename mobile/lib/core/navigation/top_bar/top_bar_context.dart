import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// ⭐ উপরের বারের যা সব পর্দায় একই — কোম্পানি, শাখা, নিজের ছবি আর হোমের কাজগুলো (খোঁজা, বিজ্ঞপ্তি, ছবির মেনু, শাখা বদল)।
///
/// <p>হোম ([HomeShell]) এটা লেখে — `/me` বা জমা নাম পেলেই, শাখা বদলের পরে আবার; ভেতরের পর্দার বার ([AbosScreenBar])
/// কেবল পড়ে। নতুন কোনো সার্ভার-ডাক নেই: হোম যা জানে, তা-ই। হোম নেই (একা পর্দার টেস্ট) → null, বার তখন সাদামাটা শিরোনাম।
@immutable
class TopBarContext {
  const TopBarContext({
    required this.company,
    this.companyCode = '',
    required this.branch,
    this.designation,
    this.avatar,
    this.moduleOf,
    required this.search,
    required this.openNotifications,
    required this.openMenu,
    this.switchBranch,
    this.copilot,
    this.quick,
  });

  final String company;
  final String companyCode;
  final String branch;
  final String? designation;
  final ImageProvider? avatar;

  /// `/home/<path>`-এর প্রথম অংশ (যেমন `customers`) → সেই পর্দার মডিউলের নাম ("বিক্রয়"); না জানলে null
  final String? Function(String path)? moduleOf;

  /// হোমের খোঁজা — যে পর্দা থেকে ডাকা, তার context-এ
  final Future<void> Function(BuildContext context) search;

  /// বিজ্ঞপ্তির তালিকা
  final Future<void> Function(BuildContext context) openNotifications;

  /// ছবির মেনু — [top] বারের নিচের কিনারা; মেনু বন্ধ হলে future শেষ
  final Future<void> Function(BuildContext context, double top) openMenu;

  /// শাখা বদল — null মানে বদলানোর কিছু নেই (এক কোম্পানি, এক শাখা)
  final Future<void> Function(BuildContext context)? switchBranch;

  /// ⭐ উপরের মগজ — কোপাইলটের জিজ্ঞাসা, পুরো পর্দায় ([page] = যে পাতা থেকে খোলা); null মানে কোপাইলটের চাবি নেই
  final Future<void> Function(BuildContext context, String page)? copilot;

  /// ⭐ দ্রুত কাজ — ≡ আর Material 3-এর ＋ (0.4.58; মালিক, ১৬ ও ২০ নভেম্বর ২০২৬): টাকা জমার খবর, নতুন অর্ডার, আদায়, অনুমোদন …
  final Future<void> Function(BuildContext context)? quick;
}

/// হোম লেখে, প্রতিটা পর্দার বার পড়ে
final topBarContextProvider = StateProvider<TopBarContext?>((ref) => null);
