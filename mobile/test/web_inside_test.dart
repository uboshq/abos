import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_module.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/web_inside/web_files.dart';
import 'package:abos_mobile/core/web_inside/web_inside.dart';
import 'package:abos_mobile/features/web_inside/web_inside_screen.dart';

/// ⭐ অ্যাপের ভিতরে ওয়েবের পর্দা — মালিক, ১৩ অক্টোবর ২০২৬: *"web e na dhuke jar jar kaj 100% app ei korte pare"*।
/// The claims: a web row becomes a door under its own module and is found by the search; a native screen is never
/// offered twice; the window stays on ABOS; files, the code and refusals are handled in Bengali.

MenuModule _module(String code, String label, Map<String, List<MenuRouteEntry>> groups) =>
    MenuModule(code: code, label: label, section: '', order: 0, groups: groups);

MenuRouteEntry _row(String route, String label, {String? web, bool planned = false}) =>
    MenuRouteEntry(label: label, route: route, planned: planned, web: web);

/// ⓘ A spinner turns behind the code dialog, so the frames are pumped for a while rather than settled.
Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  group('the web path rides one path segment', () {
    test('round trip', () {
      for (final path in ['/accounts/chart', '/system/users?tab=roles&q=রহিম', '/']) {
        final nav = WebPaths.navPathFor(path);
        expect(WebPaths.isWeb(nav), isTrue);
        expect(nav.split('/').length, 2, reason: 'one segment under web/');
        expect(WebPaths.webPathOf(nav.substring('web/'.length)), path);
      }
    });

    test('a code that is not ours, or another host, opens nothing', () {
      expect(WebPaths.webPathOf('%%%'), isNull);
      expect(WebPaths.webPathOf(base64Url.encode(utf8.encode('//evil.example/x'))), isNull);
      expect(WebPaths.webPathOf(base64Url.encode(utf8.encode('https://evil.example/x'))), isNull);
    });
  });

  group('the window stays on ABOS', () {
    test('same host stays, everything else leaves', () {
      final host = WebPaths.allowedHost;
      expect(WebPaths.staysInside(Uri.parse('https://$host/accounts')), isTrue);
      expect(WebPaths.staysInside(Uri.parse('https://evil.example/accounts')), isFalse);
      expect(WebPaths.staysInside(Uri.parse('https://$host.evil.example/')), isFalse);
      expect(WebPaths.staysInside(Uri.parse('javascript:alert(1)')), isFalse);
      expect(WebPaths.staysInside(Uri.parse('tel:01711000000')), isFalse);
      expect(WebPaths.staysInside(Uri.parse('about:blank')), isTrue);
    });
  });

  group('files', () {
    test('a download, an export or a pdf is a file; a page is not', () {
      expect(WebFiles.isDownload(Uri.parse('https://x/documents/invoice/5/pdf')), isTrue);
      expect(WebFiles.isDownload(Uri.parse('https://x/reports/sales/export')), isTrue);
      expect(WebFiles.isDownload(Uri.parse('https://x/backup/runs/3/download')), isTrue);
      expect(WebFiles.isDownload(Uri.parse('https://x/customers?export=csv')), isTrue);
      expect(WebFiles.isDownload(Uri.parse('https://x/files/a.xlsx')), isTrue);
      expect(WebFiles.isDownload(Uri.parse('https://x/customers')), isFalse);
      expect(WebFiles.isDownload(Uri.parse('https://x/sales/invoices/5')), isFalse);
    });

    test('the server\'s file name wins, and it is made safe', () {
      final uri = Uri.parse('https://x/reports/sales/export');
      expect(WebFiles.fileNameFor(uri, contentDisposition: 'attachment; filename="sales-oct.xlsx"'), 'sales-oct.xlsx');
      expect(WebFiles.fileNameFor(uri, contentDisposition: "attachment; filename*=UTF-8''%E0%A6%AC%E0%A6%BF%E0%A6%B2.pdf"),
          'বিল.pdf');
      expect(WebFiles.fileNameFor(Uri.parse('https://x/documents/1/pdf'), mime: 'application/pdf'), 'pdf.pdf');
      expect(WebFiles.fileNameFor(uri, contentDisposition: 'attachment; filename="../../etc/x"'), isNot(contains('/')));
    });

    test('a posted file is read back; anything else is not a file', () {
      final posted = jsonEncode({
        'url': 'https://x/a/export',
        'disposition': 'attachment; filename="a.csv"',
        'mime': 'text/csv; charset=UTF-8',
        'data': base64Encode(utf8.encode('a,b')),
      });
      final file = WebFiles.decodePosted(posted, Uri.parse('https://x/'));
      expect(file, isNotNull);
      expect(file!.name, 'a.csv');
      expect(file.mime, 'text/csv');
      expect(utf8.decode(file.bytes), 'a,b');
      expect(WebFiles.decodePosted('{"error":403}', Uri.parse('https://x/')), isNull);
      expect(WebFiles.decodePosted('not json', Uri.parse('https://x/')), isNull);
    });

    test('a JSON-quoted page is unquoted for printing', () {
      expect(WebFiles.unquote('"<html>\\u003Cb\\u003E</html>"'), '<html><b></html>');
      expect(WebFiles.unquote('<html></html>'), '<html></html>');
    });
  });

  group('the menu', () {
    test('/me\'s web path is read only when it is a path on this site', () {
      expect(MenuRouteEntry.fromJson({'label': 'a', 'route': 'r', 'web': '/accounts'}).web, '/accounts');
      expect(MenuRouteEntry.fromJson({'label': 'a', 'route': 'r', 'web': 'https://evil.example/'}).web, isNull);
      expect(MenuRouteEntry.fromJson({'label': 'a', 'route': 'r', 'web': '//evil.example/'}).web, isNull);
      expect(MenuRouteEntry.fromJson({'label': 'a', 'route': 'r'}).web, isNull, reason: 'an older server sends nothing');
    });

    test('every web row without a native screen becomes a door; a native screen is not offered twice', () {
      final menu = [
        _module('sales', 'বিক্রয়', {
          'transactions': [
            _row('sales.invoice.index', 'বিক্রয় বিল', web: '/sales/invoices'),
            _row('sales.invoice.index', 'বাতিল বিল', web: '/sales/invoices?status=cancelled'),
            _row('sales.order.index', 'অর্ডার', web: '/sales/orders'),
            _row('sales.return.index', 'ফেরত', web: '/sales/returns'),
            _row('sales.direct.create', 'কাউন্টার'),
            _row('sales.pos.index', 'POS', web: '/sales/pos', planned: true),
          ],
        }),
        _module('system_admin', 'সিস্টেম প্রশাসন', {
          'settings': [
            _row('system_admin.user.index', 'ব্যবহারকারী', web: '/system/users'),
            _row('system_admin.user.index', 'ব্যবহারকারী', web: '/system/users'),
          ],
        }),
      ];
      const native = [
        MenuItem(key: 'sales.invoice.index', label: 'বিক্রয় বিল', icon: Icons.receipt, routeName: 'invoices'),
      ];

      final web = const MenuRepository().webTiles(menu, native);
      final paths = web.map((i) => i.webPath).toList();

      expect(paths, ['/sales/invoices?status=cancelled', '/sales/returns', '/system/users']);
      expect(web.every((i) => i.isWeb && WebPaths.isWeb(i.routeName)), isTrue);
      expect(web.last.module, 'system_admin');
      expect(web.last.moduleLabel, 'সিস্টেম প্রশাসন');
      expect(web.last.group, 'settings');
      expect(WebPaths.titleOf(web.last.routeName), 'ব্যবহারকারী');
    });

    test('a web row hangs in its own module and heading; a module only the web knows gets its own tile', () {
      final items = [
        const MenuItem(key: 'customer.index', label: 'গ্রাহক', icon: Icons.people, routeName: 'customers'),
        MenuItem(
            key: 'web:/accounts/chart',
            label: 'হিসাবের খাতা',
            icon: Icons.language,
            routeName: WebPaths.navPathFor('/accounts/chart'),
            module: 'accounts',
            moduleLabel: 'হিসাব',
            group: 'master',
            webPath: '/accounts/chart'),
        MenuItem(
            key: 'web:/system/users',
            label: 'ব্যবহারকারী',
            icon: Icons.language,
            routeName: WebPaths.navPathFor('/system/users'),
            module: 'system_admin',
            moduleLabel: 'সিস্টেম প্রশাসন',
            group: 'settings',
            webPath: '/system/users'),
      ];

      final tree = NavTree.from(items);

      final accounts = tree.module('accounts');
      expect(accounts, isNotNull);
      expect(accounts!.groups.map((g) => g.title), contains('তালিকা'));
      final chart = tree.screen(WebPaths.navPathFor('/accounts/chart'));
      expect(chart, isNotNull);
      expect(chart!.web, isTrue);

      final admin = tree.module('system_admin');
      expect(admin, isNotNull, reason: 'a module only the web knows still gets its tile');
      expect(admin!.label, 'সিস্টেম প্রশাসন');
      expect(admin.groups.single.title, 'সেটিংস');
      expect(tree.modules.indexOf(admin), greaterThan(tree.modules.indexOf(tree.module('customer')!)),
          reason: 'the catalogue\'s own tiles first');

      // ⭐ খোঁজা ওয়েবের পর্দাও পায় — নামে আর মডিউলের নামে
      expect(tree.search('খাতা').map((r) => r.path), contains(WebPaths.navPathFor('/accounts/chart')));
      expect(tree.search('প্রশাসন').map((r) => r.path), contains(WebPaths.navPathFor('/system/users')));
      expect(tree.moduleLabel('system_admin'), 'সিস্টেম প্রশাসন');
    });

    test('with no web rows (an older server, or the switch off) the tree is what it was', () {
      const items = [MenuItem(key: 'customer.index', label: 'গ্রাহক', icon: Icons.people, routeName: 'customers')];
      final tree = NavTree.from(items);
      expect(tree.screens.where((r) => r.web), isEmpty);
      expect(tree.module('system_admin'), isNull);
    });
  });

  group('the window', () {
    Widget host(Widget child) => MaterialApp(home: child);

    testWidgets('it asks for the two-step code like the web sign-in, then opens on this phone', (tester) async {
      final asked = <String?>[];
      await tester.pumpWidget(host(WebInsideScreen(
        webPath: '/accounts/chart',
        title: 'হিসাবের খাতা',
        deviceId: () async => 'phone-1',
        askKey: (path, {code}) async {
          asked.add(code);
          return code == '123456'
              ? const WebKeyIssued('https://abos.example/handoff/k')
              : const WebKeyNeedsCode(wasWrong: false, message: 'দুই ধাপের কোডটা দিন।');
        },
        webBuilder: (context, url, headers) => Text('$url ${headers[WebInsideScreen.deviceHeader]}'),
      )));
      await _settle(tester);

      expect(find.text('দুই ধাপের কোডটা দিন।'), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('web-inside-code')), '123456');
      await tester.tap(find.text('খুলুন'));
      await _settle(tester);

      expect(asked, [null, '123456']);
      expect(find.text('https://abos.example/handoff/k phone-1'), findsOneWidget);
      expect(find.text('হিসাবের খাতা'), findsOneWidget);
    });

    testWidgets('a refusal is said in Bengali with a way to try again', (tester) async {
      var calls = 0;
      await tester.pumpWidget(host(WebInsideScreen(
        webPath: '/system/users',
        title: 'ব্যবহারকারী',
        deviceId: () async => 'phone-1',
        askKey: (path, {code}) async {
          calls++;
          return const WebKeyRefused('এই পর্দাটা অ্যাপের ভিতরে খোলা যায় না।');
        },
        webBuilder: (context, url, headers) => const Text('opened'),
      )));
      await _settle(tester);

      expect(find.text('এই পর্দাটা অ্যাপের ভিতরে খোলা যায় না।'), findsOneWidget);
      expect(find.text('opened'), findsNothing);
      await tester.tap(find.byKey(const ValueKey('web-inside-retry')));
      await _settle(tester);
      expect(calls, 2);
    });

    testWidgets('declining the code opens nothing', (tester) async {
      await tester.pumpWidget(host(WebInsideScreen(
        webPath: '/accounts/chart',
        title: 'খাতা',
        deviceId: () async => 'phone-1',
        askKey: (path, {code}) async => const WebKeyNeedsCode(wasWrong: false, message: 'কোড দিন'),
        webBuilder: (context, url, headers) => const Text('opened'),
      )));
      await _settle(tester);
      await tester.tap(find.text('থাক'));
      await _settle(tester);

      expect(find.text('opened'), findsNothing);
      expect(find.textContaining('দুই ধাপের কোড ছাড়া'), findsOneWidget);
    });

    testWidgets('an address that is not ours opens nothing and asks the server nothing', (tester) async {
      var calls = 0;
      await tester.pumpWidget(host(WebInsideScreen(
        webPath: null,
        title: 'ABOS',
        askKey: (path, {code}) async {
          calls++;
          return const WebKeyIssued('https://abos.example/handoff/k');
        },
        webBuilder: (context, url, headers) => const Text('opened'),
      )));
      await _settle(tester);
      expect(calls, 0);
      expect(find.text('opened'), findsNothing);
    });
  });

  test('signing out clears the window\'s cookies, and a failure does not stop the sign-out', () async {
    var cleared = 0;
    WebInsideSession.clearer = () async => cleared++;
    await WebInsideSession.clear();
    expect(cleared, 1);

    WebInsideSession.clearer = () async => throw StateError('no platform');
    await WebInsideSession.clear();
  });
}
