import '../../locale/app_locale.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';

/// ⭐ ৮ · "দ্রুত কাজ" — এক চাপে নতুন অর্ডার, টাকা আদায়, ভাউচার, স্ক্যান, সই, নোটিশ (a6, ২০ অক্টোবর ২০২৬; চুক্তি
/// `E:\ABOS\app-widgets-ভিত্তি.md` §২)।
///
/// <p>সারাংশ: `{"actions": [{"key", "link"}]}` — সার্ভার আগেই অনুমতি ধরে ছেঁকেছে। ফোন ঠিক সেগুলোই দেখায়, সেই ক্রমে।
/// ⛔ অচেনা key বাদ (আঁকিয়ে যে ছবি চেনে না, তার বোতাম নয়); link ছাড়া বোতামও বাদ — চাপলে কোথাও যায় না এমন বোতাম নয়।
class QuickActionsWidget extends LauncherWidgetKind {
  const QuickActionsWidget();

  /// key → বোতামের লেখা; key-ই আঁকিয়ের ছবির নাম ([WidgetAction.icon])
  static Map<String, String> get labels {
    final l = AppLocale.l10n;
    return {
      'order': l.launcherWidgetQuickOrder,
      // ⛔ "টাকা আদায়" নয় — "টাকা জমার খবর" (মালিক, ২০ নভেম্বর ২০২৬: ফোনে আদায় লেখা নেই); ছবির নাম `collect` যেমন ছিল
      'collect': l.lookQuickDeposit,
      'voucher': l.launcherWidgetQuickVoucher,
      'scan': l.launcherWidgetQuickScan,
      'approve': l.launcherWidgetQuickSign,
      'notice': l.launcherWidgetNotices,
    };
  }

  /// টাকা জমার খবরের পাতা — `collect` বোতাম সবসময় এখানে যায়
  static const String depositReportLink = '/home/deposit-report';

  @override
  String get key => 'quick_actions';

  @override
  String get title => AppLocale.l10n.launcherWidgetQuickName;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x1, WidgetSize.s2x1];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final labels = QuickActionsWidget.labels;
    final actions = <WidgetAction>[
      for (final a in (summary['actions'] as List? ?? const []))
        if (a is Map && labels.containsKey(a['key']) && (a['link'] ?? '').toString().isNotEmpty)
          WidgetAction(
              label: labels[a['key']]!,
              icon: a['key'] as String,
              // ⛔ পুরনো সার্ভারের "আদায়" লিংক (তালিকা বা নতুন আদায়) — সোজা টাকা জমার খবরে (২০ নভেম্বর ২০২৬)
              link: a['key'] == 'collect' ? depositReportLink : a['link'].toString()),
    ];
    return WidgetDoc(
      title: title,
      asOf: asOf,
      actions: actions,
      empty: actions.isEmpty ? AppLocale.l10n.launcherWidgetQuickEmpty : null,
    );
  }
}
