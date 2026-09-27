import '../records/approval_record.dart';
import '../records/money.dart';
import '../widgets/day_part.dart';
import 'launcher_widget_store.dart';

/// The second home-screen widget: what is waiting for a signature. Two cells
/// by two.
///
/// <p>Separate from the figures one because the two answer different
/// questions. The figures widget answers "how is the business" and is read
/// at a glance. This answers "is anything waiting for me", which is a queue,
/// and an empty queue is itself the news.
///
/// <p>Everything is formatted here and written across as strings. The Kotlin
/// side stores and displays; it does no arithmetic and no formatting.
class ApprovalsLauncherWidget {
  const ApprovalsLauncherWidget._();

  /// The Kotlin class that draws it (ApprovalsWidgetProvider.kt).
  static const provider = 'ApprovalsWidgetProvider';

  /// Matches ApprovalsWidgetProvider's companion object.
  static const count = 'approvals_count';
  static const rows = ['approvals_row_1', 'approvals_row_2', 'approvals_row_3'];
  static const empty = 'approvals_empty';
  static const more = 'approvals_more';
  static const asOf = 'approvals_as_of';

  /// How many rows the layout has. RemoteViews cannot loop, so this is a
  /// property of the XML and not a preference; the two have to agree, and
  /// [rows] is where they do.
  static int get rowCapacity => rows.length;

  static Future<void> publish(
    List<ApprovalRecord> waiting, {
    bool hasMore = false,
    DateTime? at,
  }) =>
      LauncherWidgetStore.write(
          provider, valuesFor(waiting, hasMore: hasMore, at: at));

  /// What [publish] writes. Pure, so it can be checked without a launcher.
  ///
  /// <p>[hasMore] is the server saying there is another page. The count is
  /// then "50+" rather than "50": a number that stops at the page size would
  /// be read as the whole queue on exactly the day the queue is longest.
  static Map<String, String> valuesFor(
    List<ApprovalRecord> waiting, {
    bool hasMore = false,
    DateTime? at,
  }) {
    final lines = approvalLines(waiting);
    final hidden = waiting.length - lines.length;

    return {
      count: '${waiting.length}${hasMore ? '+' : ''}',
      for (var i = 0; i < rows.length; i++)
        rows[i]: i < lines.length ? lines[i] : '',
      // A sentence somebody can act on. Blank lines are a widget that looks
      // broken, and the difference matters most on the morning when there
      // really is nothing to sign.
      empty: waiting.isEmpty ? 'এখন সইয়ের জন্য কিছু নেই।' : '',
      more: hidden > 0 || hasMore
          ? 'আরও ${hidden > 0 ? '$hidden' : ''}${hasMore ? '+' : ''} টি'
          : '',
      asOf: '${banglaClock(at ?? DateTime.now())} পর্যন্ত',
    };
  }

  /// Wipe it on sign-out, for the same reason the figures widget is wiped:
  /// whoever picks this phone up next is not the person whose approvals
  /// these are, and a queue on a launcher does not know that.
  static Future<void> clear() => LauncherWidgetStore.write(provider, {
        // A dash, not a zero: a zero would claim an empty queue for a
        // company nobody is signed into.
        count: '—',
        for (final row in rows) row: '',
        empty: '',
        more: '',
        asOf: 'সাইন আউট করা হয়েছে',
      });
}

/// One line per waiting document, oldest first.
///
/// <p>Oldest first because a queue is worked from the front, and the thing
/// that has been waiting longest is the one somebody is chasing. The
/// endpoint's own order is not promised to be anything.
///
/// <p>Each line names the document rather than describing it: "ক্রয় বিল ·
/// PB-2609-0007 · ৳125,000" is what a person quotes down a phone. Where the
/// server sends no document number there is nothing to quote, so the type
/// and the amount carry the line alone.
List<String> approvalLines(List<ApprovalRecord> waiting) {
  final sorted = [...waiting]..sort((a, b) {
      final left = a.requestedAt;
      final right = b.requestedAt;
      if (left == null && right == null) return 0;
      if (left == null) return 1;
      if (right == null) return -1;
      return left.compareTo(right);
    });

  return [
    for (final approval in sorted.take(ApprovalsLauncherWidget.rowCapacity))
      [
        approval.documentTypeLabel,
        if (approval.documentNo != null) approval.documentNo!,
        if (approval.amount != null) Money.taka(approval.amount),
      ].join(' · ')
  ];
}
