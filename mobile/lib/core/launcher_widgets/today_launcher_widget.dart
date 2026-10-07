import '../privacy/phone_privacy.dart';
import '../records/money.dart';
import '../records/today_record.dart';
import '../widgets/day_part.dart';
import 'launcher_widget_store.dart';

/// The figures widget: the day's money on the home screen.
///
/// <p><b>The layout is the owner's full list; the wiring is the contract's.</b>
/// He asked for selling, spending, buying and collecting, for today, this
/// month and against last month, then where the money is, then what is owed
/// either way and what stands on the shelf. `GET /dashboard/today`
/// (docs/Contract §৮) answers four of those today: sales, collections, cash
/// in hand and dues. Those four are written as figures; every other cell is
/// written as a dash.
///
/// <p>⛔ <b>No key is read that the contract does not name.</b> The six
/// payload bugs of 12 September were screens reading keys the server had
/// never sent, drawing nothing for a month while every test passed. When the
/// contract grows a month figure or a bank balance, the line that fills its
/// cell is added here, against the key as written there, and not before.
///
/// <p>A dash is not a zero. Zero is a fact about the business that somebody
/// acts on; a dash says nothing is known. That is §৮ rule ক, one process
/// further out.
class TodayLauncherWidget {
  const TodayLauncherWidget._();

  /// The Kotlin class that draws it (TodayWidgetProvider.kt).
  static const provider = 'TodayWidgetProvider';

  /// Matches TodayWidgetProvider's companion object. Both sides name the same
  /// strings, because a typo here shows up as a dash on a home screen and
  /// nowhere else: no error, no log, nothing to search for.
  static const salesToday = 'widget_sales_today';
  static const inflowToday = 'widget_inflow_today';
  static const cash = 'widget_cash';
  static const receivable = 'widget_receivable';
  static const company = 'widget_company';
  static const asOf = 'widget_as_of';

  /// Every figure cell the layout has. Clearing and publishing both walk this
  /// list, so neither can forget a cell: a forgotten one is how a stale
  /// number survives a sign-out.
  static const figureKeys = [
    salesToday, 'widget_sales_month', 'widget_sales_change',
    'widget_expense_today', 'widget_expense_month', 'widget_expense_change',
    'widget_purchase_today', 'widget_purchase_month', 'widget_purchase_change',
    inflowToday, 'widget_inflow_month', 'widget_inflow_change',
    cash, 'widget_mfs', 'widget_bank', 'widget_in_transit',
    'widget_total_money',
    receivable, 'widget_payable', 'widget_stock',
  ];

  static const dash = '—';

  /// Put a day's figures on the home screen.
  /// ⛔ টাকার অঙ্ক কেবল মালিকের সুইচে ([[PhonePrivacy.widgetAmounts]]); নইলে "•••"
  static Future<void> publish(TodayRecord today, {DateTime? at}) async =>
      LauncherWidgetStore.write(provider,
          valuesFor(today, at: at, showAmounts: await PhonePrivacy.widgetAmounts()));

  /// What [publish] writes, as a plain map: every cell, figure or dash.
  ///
  /// <p>Pure, so it can be checked without a launcher.
  static Map<String, String> valuesFor(TodayRecord today, {DateTime? at, bool showAmounts = true}) {
    String? shown(String? figure) => figure == null || showAmounts ? figure : PhonePrivacy.hidden;
    final figures = <String, String?>{
      salesToday: shown(_taka(today.sales)),
      inflowToday: shown(_taka(today.collections)),
      cash: shown(_taka(today.cashInHand)),
      receivable: shown(_taka(today.dues)),
    };

    return {
      for (final key in figureKeys) key: figures[key] ?? dash,
      // The corner cell above the row labels is left empty. It is one
      // column wide, and a company name cut to "টেষ্ট কোম্পানী লিমি…" there
      // cannot tell two companies with the same first word apart. Seen on a
      // launcher on 27 September; the name now goes on the bottom line,
      // which is as wide as the widget.
      company: '',
      asOf: [
        // The hour first, so that when the line is too long for a narrow
        // widget it is the branch that is cut, never the hour. And the hour
        // the server said these were true, not the hour the phone wrote them
        // down: a figure fetched at nine from a cache filled at six is a six
        // o'clock figure.
        '${banglaClock(today.asOf ?? at ?? DateTime.now())} পর্যন্ত',
        if (today.company != null) today.company!,
        if (today.branch != null) today.branch!,
      ].join(' · '),
    };
  }

  /// Wipes the figures on sign-out, so a home screen never shows one person's
  /// cash in hand after they have signed out, possibly to a different person
  /// at a different company on the same phone.
  static Future<void> clear() => _blank('সাইন আউট করা হয়েছে');

  /// Says the figures are not for this account, rather than that nobody is
  /// signed in. Both look the same on a launcher, a row of dashes, and they
  /// are not the same thing at all: a rep who is signed in and simply may not
  /// read the company's cash was otherwise told, on their own home screen,
  /// that they had been signed out.
  static Future<void> unavailable() =>
      _blank('এই অ্যাকাউন্টে এই হিসাব দেখানো হয় না');

  /// The server is older than this build and has no such door yet.
  static Future<void> absentOnServer() =>
      _blank('এই হিসাব এখনো সার্ভারে নেই');

  static Future<void> _blank(String reason) => LauncherWidgetStore.write(
        provider,
        {
          for (final key in figureKeys) key: dash,
          company: '',
          asOf: reason,
        },
      );

  /// Null for an absent block, which becomes a dash. Never "৳0" for a figure
  /// the server did not send.
  static String? _taka(TodayFigure? figure) {
    final amount = figure?.amount;
    return amount == null ? null : Money.taka(amount);
  }
}
