import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/launcher_widgets/approvals_launcher_widget.dart';
import 'package:abos_mobile/core/launcher_widgets/launcher_widget_refresh.dart';
import 'package:abos_mobile/core/launcher_widgets/launcher_widget_store.dart';
import 'package:abos_mobile/core/launcher_widgets/today_launcher_widget.dart';
import 'package:abos_mobile/core/launcher_widgets/widget_sync_observer.dart';
import 'package:abos_mobile/core/records/approval_record.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/main.dart' show widgetDestination;

/// The two home-screen widgets.
///
/// <p>The launcher draws them in its own process and no test here can see a
/// launcher. What can be checked is everything up to the process boundary:
/// which strings are written, under which names, and that the names on this
/// side are the names on the other.
void main() {
  /// What was last written to each widget, in place of the platform channel.
  final written = <String, Map<String, String>>{};

  setUp(() {
    written.clear();
    LauncherWidgetStore.writer = (provider, values) async {
      written[provider] = Map.of(values);
    };
  });

  const today = TodayRecord({
    'date': '2026-09-27',
    'company': 'টেষ্ট কোম্পানী লিমিটেড',
    'branch': 'Head Office',
    'asOf': '2026-09-27T12:41:52+06:00',
    'sales': {'count': 12, 'amount': '45200.0000'},
    'collections': {'count': 7, 'amount': '31000.0000'},
    'cashInHand': {'amount': '18500.0000'},
    'dues': {'amount': '8125.5000', 'shops': 1},
    'approvals': {'pending': 3},
  });

  ApprovalRecord approval(String no, String at, {String amount = '2600.0000'}) =>
      ApprovalRecord({
        'id': 'id-$no',
        'documentType': 'PurchaseBill',
        'documentNo': no,
        'amount': amount,
        'requestedAt': at,
      });

  group('the figures widget', () {
    test('the four figures the contract sends are written as figures', () {
      final values = TodayLauncherWidget.valuesFor(today);

      expect(values['widget_sales_today'], '৳45,200');
      expect(values['widget_inflow_today'], '৳31,000');
      expect(values['widget_cash'], '৳18,500');
      expect(values['widget_receivable'], '৳8,125.5');
    });

    test('every cell the contract does not answer is a dash, not a zero', () {
      final values = TodayLauncherWidget.valuesFor(today);

      for (final key in const [
        'widget_sales_month', 'widget_sales_change',
        'widget_expense_today', 'widget_purchase_today',
        'widget_mfs', 'widget_bank', 'widget_in_transit',
        'widget_total_money', 'widget_payable', 'widget_stock',
      ]) {
        expect(values[key], '—', reason: key);
      }
      expect(values.values.where((v) => v.contains('৳0')), isEmpty);
    });

    test('a figure this account may not see is a dash, never ৳0', () {
      // Absent, not zero: docs/Contract §৮ rule ক. A manager without
      // accounts.till.view gets no cashInHand key at all.
      const withoutCash = TodayRecord({
        'company': 'টেষ্ট কোম্পানী লিমিটেড',
        'asOf': '2026-09-27T12:41:52+06:00',
        'sales': {'count': 0, 'amount': '0.0000'},
      });

      final values = TodayLauncherWidget.valuesFor(withoutCash);

      expect(values['widget_cash'], '—');
      // A real zero the server did send is a figure, and is drawn as one.
      expect(values['widget_sales_today'], '৳0');
    });

    test('it says whose figures these are, and the hour the server gave', () {
      final values = TodayLauncherWidget.valuesFor(
        today,
        // The phone's own clock, hours later. It must not be what is shown.
        at: DateTime(2026, 9, 27, 21, 5),
      );

      final asOf = today.asOf!;
      final hour = asOf.hour % 12 == 0 ? 12 : asOf.hour % 12;
      final clock = '$hour:${asOf.minute.toString().padLeft(2, '0')}';
      final line = values['widget_as_of']!;

      // The whole name, on the line that is as wide as the widget. In the
      // one-column corner cell it was cut to "টেষ্ট কোম্পানী লিমি…".
      expect(line, contains('টেষ্ট কোম্পানী লিমিটেড · Head Office'));
      expect(values['widget_company'], isEmpty);
      // The hour before the name: if a narrow widget cuts the line, it is
      // the branch that goes, never the hour.
      expect(line.indexOf(clock), lessThan(line.indexOf('টেষ্ট')));
      expect(line, contains('পর্যন্ত'));
      // Said once. "রাত রাত 12:30" is what a caller gets by adding the part
      // of day in front of a clock that already has it.
      expect(values['widget_as_of'], isNot(matches(r'(\S+) \1 ')));
    });

    test('every cell is written on every publish and on every clear',
        () async {
      await TodayLauncherWidget.publish(today);
      expect(written[TodayLauncherWidget.provider]!.keys,
          containsAll(TodayLauncherWidget.figureKeys));

      await TodayLauncherWidget.clear();
      final cleared = written[TodayLauncherWidget.provider]!;
      for (final key in TodayLauncherWidget.figureKeys) {
        expect(cleared[key], '—', reason: '$key survived a sign-out');
      }
      expect(cleared['widget_company'], isEmpty);
      expect(cleared['widget_as_of'], 'সাইন আউট করা হয়েছে');
    });
  });

  group('the approvals widget', () {
    test('an empty queue is a sentence, and the count is a real zero', () {
      final values = ApprovalsLauncherWidget.valuesFor(const []);

      expect(values['approvals_count'], '0');
      expect(values['approvals_empty'], 'এখন সইয়ের জন্য কিছু নেই।');
      expect(values['approvals_row_1'], isEmpty);
      expect(values['approvals_more'], isEmpty);
    });

    test('oldest first, named the way somebody quotes it down a phone', () {
      final values = ApprovalsLauncherWidget.valuesFor([
        approval('PBL-0003', '2026-09-27T11:00:00+06:00', amount: '400.0000'),
        approval('PBL-0001', '2026-09-27T09:00:00+06:00'),
      ]);

      expect(values['approvals_count'], '2');
      expect(values['approvals_row_1'], 'ক্রয় বিল · PBL-0001 · ৳2,600');
      expect(values['approvals_row_2'], 'ক্রয় বিল · PBL-0003 · ৳400');
      expect(values['approvals_row_3'], isEmpty);
      expect(values['approvals_empty'], isEmpty);
    });

    test('a queue longer than the widget says how many it is not showing',
        () {
      final values = ApprovalsLauncherWidget.valuesFor([
        for (var i = 1; i <= 5; i++)
          approval('PBL-000$i', '2026-09-27T0$i:00:00+06:00'),
      ]);

      expect(values['approvals_count'], '5');
      expect(values['approvals_row_3'], contains('PBL-0003'));
      expect(values['approvals_more'], 'আরও 2 টি');
    });

    test('another page on the server is a plus, not a number that stops',
        () {
      final values = ApprovalsLauncherWidget.valuesFor(
        [approval('PBL-0001', '2026-09-27T09:00:00+06:00')],
        hasMore: true,
      );

      expect(values['approvals_count'], '1+');
      expect(values['approvals_more'], isNotEmpty);
    });

    test('a document with no number and no amount still gets a line', () {
      final values = ApprovalsLauncherWidget.valuesFor([
        const ApprovalRecord({'id': 'x', 'documentType': 'StockTransfer'}),
      ]);

      expect(values['approvals_row_1'], 'মজুদ স্থানান্তর');
    });

    test('signed out is a dash, not an empty queue', () async {
      await ApprovalsLauncherWidget.clear();
      final cleared = written[ApprovalsLauncherWidget.provider]!;

      expect(cleared['approvals_count'], '—');
      expect(cleared['approvals_empty'], isEmpty);
      expect(cleared['approvals_as_of'], 'সাইন আউট করা হয়েছে');
    });
  });

  group('refreshing', () {
    DioException http(int status) => DioException(
          requestOptions: RequestOptions(path: '/x'),
          response: Response(
            requestOptions: RequestOptions(path: '/x'),
            statusCode: status,
          ),
          type: DioExceptionType.badResponse,
        );

    DioException offline() => DioException(
          requestOptions: RequestOptions(path: '/x'),
          type: DioExceptionType.connectionError,
        );

    test('both widgets are filled from one refresh', () async {
      await LauncherWidgetRefresh.refresh(
        fetchToday: () async => today,
        loadApprovals: () async => ApprovalPage(
          rows: [approval('PBL-0001', '2026-09-27T09:00:00+06:00')],
        ),
      );

      expect(written[TodayLauncherWidget.provider]!['widget_sales_today'],
          '৳45,200');
      expect(written[ApprovalsLauncherWidget.provider]!['approvals_count'],
          '1');
    });

    test('with no signal, what is on the launcher is left alone', () async {
      await LauncherWidgetRefresh.refresh(
        fetchToday: () async => throw offline(),
        loadApprovals: () async => throw offline(),
      );

      // Stale figures with their own hour on them beat a row of dashes.
      expect(written, isEmpty);
    });

    test('one door shut does not cost the other widget its figures',
        () async {
      await LauncherWidgetRefresh.refresh(
        fetchToday: () async => throw http(403),
        loadApprovals: () async => const ApprovalPage(rows: []),
      );

      final figures = written[TodayLauncherWidget.provider]!;
      expect(figures['widget_sales_today'], '—');
      // Not "signed out": this person is signed in and simply may not read
      // these figures.
      expect(figures['widget_as_of'], 'এই অ্যাকাউন্টে এই হিসাব দেখানো হয় না');
      expect(written[ApprovalsLauncherWidget.provider]!['approvals_count'],
          '0');
    });

    test('a role with no inbox has nothing waiting, not an error', () async {
      await LauncherWidgetRefresh.refresh(
        fetchToday: () async => today,
        loadApprovals: () async => throw http(403),
      );

      final queue = written[ApprovalsLauncherWidget.provider]!;
      expect(queue['approvals_count'], '0');
      expect(queue['approvals_empty'], isNotEmpty);
    });

    test('a server older than this build is said to be so', () async {
      await LauncherWidgetRefresh.refresh(
        fetchToday: () async => throw http(404),
        loadApprovals: () async => const ApprovalPage(rows: []),
      );

      expect(written[TodayLauncherWidget.provider]!['widget_as_of'],
          'এই হিসাব এখনো সার্ভারে নেই');
    });

    test('a widget that will not update disturbs nothing', () async {
      LauncherWidgetStore.writer =
          (_, __) async => throw StateError('no launcher here');

      await expectLater(
        LauncherWidgetRefresh.refresh(
          fetchToday: () async => today,
          loadApprovals: () async => const ApprovalPage(rows: []),
        ),
        completes,
      );
      await expectLater(LauncherWidgetRefresh.clear(), completes);
    });
  });

  group('on leaving the app', () {
    test('the widgets are refreshed, but not six times for six photos',
        () async {
      var runs = 0;
      var now = DateTime(2026, 9, 27, 10, 0);
      final observer = WidgetSyncObserver(
        signedIn: () => true,
        refresh: () async => runs++,
        clock: () => now,
      );

      expect(await observer.syncNow(), isTrue);
      now = now.add(const Duration(seconds: 30));
      expect(await observer.syncNow(), isFalse);
      now = now.add(const Duration(minutes: 3));
      expect(await observer.syncNow(), isTrue);

      expect(runs, 2);
    });

    test('nobody signed in, nothing fetched', () async {
      var runs = 0;
      final observer = WidgetSyncObserver(
        signedIn: () => false,
        refresh: () async => runs++,
      );

      expect(await observer.syncNow(), isFalse);
      // Otherwise the "signed out" line would be overwritten with the "not
      // for this account" one, which is a different and untrue sentence.
      expect(runs, 0);
    });
  });

  group('a tap on a widget', () {
    test('opens the page behind it', () {
      expect(widgetDestination(Uri.parse('abos://widget/approvals')),
          '/home/approvals');
      expect(widgetDestination(Uri.parse('abos://widget/today')), '/home');
    });

    test('an address this build does not know opens nothing in particular',
        () {
      expect(widgetDestination(null), isNull);
      expect(widgetDestination(Uri.parse('abos://widget/payroll')), isNull);
      expect(widgetDestination(Uri.parse('https://widget/approvals')), isNull);
    });
  });

  group('the two sides of the process boundary', () {
    String read(String path) => File(path).readAsStringSync();

    const kotlin = 'android/app/src/main/kotlin/com/abos/abos_mobile';
    const res = 'android/app/src/main/res';

    test('every name Dart writes under is a name Kotlin reads', () {
      // A typo on either side shows up as a dash on a home screen and
      // nowhere else: no error, no log, nothing to search for.
      final todayKotlin = read('$kotlin/TodayWidgetProvider.kt');
      for (final key in [
        ...TodayLauncherWidget.figureKeys,
        TodayLauncherWidget.company,
        TodayLauncherWidget.asOf,
      ]) {
        expect(todayKotlin, contains('"$key"'), reason: key);
      }

      final approvalsKotlin = read('$kotlin/ApprovalsWidgetProvider.kt');
      for (final key in [
        ApprovalsLauncherWidget.count,
        ...ApprovalsLauncherWidget.rows,
        ApprovalsLauncherWidget.empty,
        ApprovalsLauncherWidget.more,
        ApprovalsLauncherWidget.asOf,
      ]) {
        expect(approvalsKotlin, contains('"$key"'), reason: key);
      }
    });

    test('the layout has as many rows as Dart fills', () {
      final layout = read('$res/layout/approvals_widget.xml');
      final rows = RegExp(r'@\+id/approvals_row_\d').allMatches(layout).length;
      expect(rows, ApprovalsLauncherWidget.rowCapacity);
    });

    test('the approvals widget is two cells by two', () {
      // The owner's instruction, and the one line somebody tidying this
      // file is most likely to change back.
      final info = read('$res/xml/approvals_widget_info.xml');
      expect(info, contains('android:targetCellWidth="2"'));
      expect(info, contains('android:targetCellHeight="2"'));
      expect(info, contains('android:minWidth="110dp"'));
      expect(info, contains('android:minHeight="110dp"'));
    });

    test('both widgets are named in the manifest', () {
      // One the manifest does not name does not exist as far as the launcher
      // is concerned; it fails by not appearing in the widget picker.
      final manifest = read('android/app/src/main/AndroidManifest.xml');
      expect(manifest, contains('android:name=".TodayWidgetProvider"'));
      expect(manifest, contains('android:name=".ApprovalsWidgetProvider"'));
    });

    test('no layout uses a view RemoteViews refuses to inflate', () {
      // android.view.View is "not allowed to be inflated", and it takes the
      // whole widget down with it rather than just the one rule.
      for (final file in ['today_widget.xml', 'approvals_widget.xml']) {
        final layout = read('$res/layout/$file');
        expect(RegExp(r'<View[\s/>]').hasMatch(layout), isFalse, reason: file);
      }
    });

    test('no comment in the manifest or the layouts has a double hyphen',
        () {
      // Illegal in XML, and the merger names only the file when it meets
      // one. On 13 September it stopped the APK being built at all while
      // every Dart test was green.
      for (final path in [
        'android/app/src/main/AndroidManifest.xml',
        '$res/layout/today_widget.xml',
        '$res/layout/approvals_widget.xml',
        '$res/xml/today_widget_info.xml',
        '$res/xml/approvals_widget_info.xml',
        '$res/values/strings.xml',
        '$res/drawable/widget_background.xml',
      ]) {
        final comments = RegExp(r'<!--(.*?)-->', dotAll: true)
            .allMatches(read(path))
            .map((m) => m.group(1)!);
        for (final body in comments) {
          expect(body.contains('--'), isFalse, reason: path);
        }
      }
    });
  });
}
