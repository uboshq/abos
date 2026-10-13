import 'package:flutter/foundation.dart';

import 'nav_prefs.dart';

/// ⭐ খোলা কাগজ "সাম্প্রতিক"-এ — DO, আদেশ, উদ্ধৃতি, আদায়, ক্রয়, ভাউচার, দাবি (মালিক, ১২ অক্টোবর ২০২৬)।
///
/// <p>Each paper's own screen calls [note] once the paper has loaded — the
/// number is only known then — and the line lands in the same per-person
/// encrypted list as the screens ([NavPrefs], eight at most). It reopens
/// through `/home/paper/<kind>/<id>` (app_router.dart → [PaperRoute]),
/// behind the same module switch as the paper's own list.
class RecentPapers {
  const RecentPapers._();

  /// The signed-in person's list — set by the home while it is alive; null
  /// signed out (and then nothing is noted).
  static NavPrefs? active;

  /// Bumped after every note, so the home redraws its "সাম্প্রতিক".
  static final ValueNotifier<int> changes = ValueNotifier<int>(0);

  /// kind → (the list screen whose door the paper sits behind, its name).
  static const Map<String, (String, String)> kinds = {
    'do': ('delivery-orders', 'ডেলিভারি অর্ডার'),
    'order': ('delivery-orders', 'বিক্রয় আদেশ'),
    'quotation': ('quotations', 'উদ্ধৃতি'),
    'money-in': ('collections', 'টাকা আদায়'),
    'purchase': ('purchases', 'ক্রয়'),
    'voucher': ('vouchers', 'ভাউচার'),
    'claim': ('claims', 'খরচের দাবি'),
    // ⭐ বিক্রয় বিল আর চালান (মালিক, ১৩ অক্টোবর ২০২৬)
    'invoice': ('invoices', 'বিক্রয় বিল'),
    'challan': ('challans', 'চালান'),
  };

  /// `paper/<kind>/<id>`, with the purchase's own kind (`bill`, `receipt` …)
  /// as `?k=` — the one paper whose screen needs it.
  static String path(String kind, String id, {String? extra}) =>
      'paper/$kind/${Uri.encodeComponent(id)}'
      '${extra == null || extra.isEmpty ? '' : '?k=${Uri.encodeQueryComponent(extra)}'}';

  /// The list screen a recent `paper/...` line sits behind — null when the
  /// line is not a paper or its kind is unknown to this build.
  static String? listPathOf(String recentPath) {
    if (!recentPath.startsWith('paper/')) return null;
    final kind = recentPath.split('/').elementAtOrNull(1);
    return kinds[kind]?.$1;
  }

  /// "DO-0412 · ডেলিভারি অর্ডার" under "সাম্প্রতিক". Never throws: a list
  /// that cannot be written costs the line, never the paper's screen.
  static Future<void> note({
    required String kind,
    required String id,
    required String number,
    String? extra,
  }) async {
    final prefs = active;
    final type = kinds[kind]?.$2;
    if (prefs == null || type == null || id.isEmpty) return;
    final label = number.trim().isEmpty ? type : '${number.trim()} · $type';
    try {
      await prefs.remember(
          RecentEntry(path: path(kind, id, extra: extra), label: label));
      changes.value++;
    } catch (_) {}
  }
}
