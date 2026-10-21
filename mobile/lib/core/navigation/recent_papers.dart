import 'package:flutter/foundation.dart';

import '../locale/app_locale.dart';
import 'nav_prefs.dart';
import 'paper_kinds.dart';

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
  static Map<String, (String, String)> get kinds {
    final l = AppLocale.l10n;
    return {
      'do': ('delivery-orders', l.paperKindDeliveryOrder),
      'order': ('delivery-orders', l.paperKindSalesOrder),
      'quotation': ('quotations', l.paperKindQuotation),
      'money-in': ('collections', l.paperKindCollection),
      'purchase': ('purchases', l.paperKindPurchase),
      'voucher': ('vouchers', l.paperKindVoucher),
      'claim': ('claims', l.paperKindClaim),
    // ⭐ বাকি মডিউল, দ্বিতীয় ভাগ (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬); ভাড়ার দিক (`pay`/`let`) `?k=`-এ
      'deposit': ('deposits', l.paperKindDeposit),
      'bank-loan': ('bank-loans', l.paperKindBankLoan),
      'rent': ('rents', l.paperKindRent),
      'hand-loan': ('hand-loans', l.paperKindHandLoan),
      'document': ('documents', l.paperKindDocument),
      'payslip': ('payslips', l.paperKindPayslip),
    // ⭐ বিক্রয় বিল আর চালান (মালিক, ১৩ অক্টোবর ২০২৬)
      'invoice': ('invoices', l.paperKindInvoice),
      'challan': ('challans', l.paperKindChallan),
    // ⭐ ক্রয়, বিক্রয়ের বাকি কাগজ আর স্থানান্তর — ফোনের নিজের পর্দায় (মালিক, ১৭ অক্টোবর ২০২৬; [PaperKinds])
      ...PaperKinds.all,
    };
  }

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

  /// "DO-0412 · রহিম স্টোর · মিরপুর" under "সাম্প্রতিক" — the number, then
  /// the party the screen already holds ([party], the customer with its point
  /// — "নাম · পয়েন্ট"); without a party, "DO-0412 · ডেলিভারি অর্ডার" as
  /// before. Never throws: a list that cannot be written costs the line,
  /// never the paper's screen.
  ///
  /// ⭐ নম্বরের পাশে পক্ষ — মালিক, ১৯ অক্টোবর ২০২৬: "নম্বরের পাশে গ্রাহকের নাম আর পয়েন্টের নাম দিলে সহজে বোঝা যায়।"
  /// ⓘ লেবেলটা লেখা হয়েই জমা থাকে — পুরনো লাইন ("নম্বর · ধরন") যেমন ছিল তেমনই খোলে; কাগজ আবার খুললে নতুন লেবেলে বসে।
  static Future<void> note({
    required String kind,
    required String id,
    required String number,
    String? extra,
    String? party,
  }) async {
    final prefs = active;
    final type = kinds[kind]?.$2;
    if (prefs == null || type == null || id.isEmpty) return;
    final label = labelOf(type: type, number: number, party: party);
    try {
      await prefs.remember(
          RecentEntry(path: path(kind, id, extra: extra), label: label));
      changes.value++;
    } catch (_) {}
  }

  /// The line's words — "নম্বর · পক্ষ", or "নম্বর · ধরন" without a party,
  /// or the type alone without a number.
  static String labelOf({required String type, required String number, String? party}) {
    final no = number.trim();
    final who = party?.trim() ?? '';
    if (no.isEmpty) return who.isEmpty ? type : '$type · $who';
    return '$no · ${who.isEmpty ? type : who}';
  }
}
