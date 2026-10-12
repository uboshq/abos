import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/navigation/nav_state.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⭐ মডিউল → মেনু → পর্দা (মালিক, ১২ অক্টোবর ২০২৬)। The tree only hangs the
/// doors the old grid already let a person open; these claims hold that, and
/// that no screen of the router is left without a door.

MenuItem _item(String path, String label, {String? key}) => MenuItem(
    key: key ?? path, label: label, icon: Icons.circle, routeName: path);

MenuItem _planned(String key, String label) => MenuItem(
    key: key, label: label, icon: Icons.schedule, routeName: '', planned: true);

void main() {
  test('every screen the router opens under /home has a place in the menu', () {
    final source = File('lib/core/router/app_router.dart').readAsStringSync();
    final home = source.substring(source.indexOf("path: '/home'"));
    final paths = RegExp(r"path: '([a-z\-]+)'")
        .allMatches(home)
        .map((m) => m.group(1)!)
        // ⓘ নিচের পাতা — দোকানের পাতার "জমার বিজ্ঞপ্তি" আর "খাতা" দোকানের পাতা থেকেই খোলে
        .where((p) => p != 'deposit' && p != 'ledger')
        .toSet();

    expect(paths, isNotEmpty);
    for (final path in paths) {
      expect(NavCatalog.screenDef(path), isNotNull,
          reason: '/home/$path has no place in the module menu — it would be unreachable');
    }
  });

  test('every tile the phone builds itself lands in a real module, never "অন্যান্য"', () {
    const everything = AuthUser(
      id: 'u1',
      name: 'মালিক',
      email: 'o@x',
      roles: ['super_admin'],
      permissions: [
        'sales.order.create', 'sales.order.view', 'sales.delivery.view',
        'sales.lead.view', 'sales.quotation.view', 'sales.delivery.update',
        'sales.shipment.view', 'sales.collection.view', 'supplier.view',
        'purchase.bill.view', 'sales.challan.create', 'sales.do.view',
        'customer.view', 'hr.attendance.self', 'accounts.voucher.create',
        'hr.claim.self',
      ],
    );
    final items = const MenuRepository().ordered(const [], everything);
    final tree = NavTree.from(items);

    for (final item in items) {
      final def = NavCatalog.screenDef(item.routeName);
      expect(def, isNotNull, reason: item.routeName);
      expect(tree.screen(item.routeName), isNotNull, reason: item.routeName);
    }
    for (final m in tree.modules) {
      expect(m.groups.map((g) => g.title), isNot(contains('অন্যান্য')));
    }
  });

  test('a person sees only the modules their menu opens', () {
    final tree = NavTree.from([
      _item('new-order', 'নতুন অর্ডার'),
      _item('customers', 'গ্রাহক তালিকা'),
    ]);

    expect(tree.modules.map((m) => m.code), ['sales', 'customer', 'reports'],
        reason: 'reports holds the dashboards list everybody had on the home');
    expect(tree.module('hr'), isNull);
    expect(tree.screen('attendance'), isNull);
    // ⓘ সার্ভারের নামই থাকে
    expect(tree.screen('customers')!.label, 'গ্রাহক তালিকা');
  });

  test('rows stand under their headings, in the catalogue order', () {
    final tree = NavTree.from([
      _item('today', 'আজকের হিসাব'),
      _item('leads', 'লিড'),
      _item('orders', 'অর্ডারের অবস্থা'),
      _item('new-order', 'নতুন অর্ডার'),
    ]);
    final sales = tree.module('sales')!;

    expect(sales.groups.map((g) => g.title), ['লেনদেন', 'মাঠের কাজ', 'রিপোর্ট']);
    expect(sales.groups.first.rows.map((r) => r.path), ['new-order', 'orders']);
  });

  test('two or more delivery screens gather behind one row; one stands alone', () {
    final many = NavTree.from([
      _item('deliveries', 'আজকের ডেলিভারি'),
      _item('loading', 'লোডিং শিট'),
      _item('tracking', 'ডেলিভারি ট্র্যাকিং'),
    ]);
    final parent = many.parent('group:delivery')!;
    expect(parent.children.map((c) => c.path), ['deliveries', 'loading', 'tracking']);
    expect(many.screen('loading'), isNotNull, reason: 'a child is still a screen');

    final one = NavTree.from([_item('tracking', 'ডেলিভারি ট্র্যাকিং')]);
    expect(one.parent('group:delivery'), isNull);
    expect(one.module('sales')!.groups.single.rows.single.path, 'tracking');
  });

  test('"coming soon" rows sit at the bottom of a live module only', () {
    final tree = NavTree.from([
      _item('orders', 'অর্ডারের অবস্থা'),
      _planned('sales.invoice.index', 'বিল'),
      _planned('finance.cheque.index', 'চেক'),
    ]);

    final sales = tree.module('sales')!;
    expect(sales.groups.last.title, NavCatalog.plannedGroup);
    expect(sales.groups.last.rows.single.planned, isTrue);
    expect(tree.module('finance'), isNull,
        reason: 'a tile with nothing that opens is not a door');
    expect(tree.screens.where((r) => r.planned), isEmpty);
  });

  test('the search finds screens by Bengali or English words', () {
    final tree = NavTree.from([
      _item('orders', 'অর্ডারের অবস্থা'),
      _item('new-order', 'নতুন অর্ডার'),
      _item('deliveries', 'আজকের ডেলিভারি'),
      _item('stock', 'হাতে থাকা মজুদ'),
      _item('dues', 'বকেয়া তালিকা'),
    ]);

    expect(tree.search('অর্ডার').map((r) => r.path), containsAll(['orders', 'new-order']));
    expect(tree.search('অর্ডার').first.path, 'orders',
        reason: 'a name that starts with the word comes first');
    expect(tree.search('চালান').map((r) => r.path), contains('deliveries'));
    expect(tree.search('Stock').map((r) => r.path), ['stock']);
    expect(tree.search('বাকি').map((r) => r.path), ['dues']);
    expect(tree.search('হাজিরা'), isEmpty, reason: 'no door, no hit');
    expect(tree.search('   '), isEmpty);
  });

  test('back goes one level up, and the home is the top', () {
    expect(const HomePage.subList('sales', 'group:delivery').up,
        const HomePage.module('sales'));
    expect(const HomePage.module('sales').up, HomePage.modules);
    expect(HomePage.modules.up, HomePage.home);
    expect(HomePage.me.up, HomePage.home);
    expect(HomePage.home.up, isNull);
  });

  group('favourites and recents', () {
    test('six favourites at most; a second press removes', () async {
      final prefs = NavPrefs(MemoryNavPrefsStore(), 'u1');
      for (final p in ['a', 'b', 'c', 'd', 'e', 'f']) {
        expect(await prefs.toggleFavourite(p), FavouriteChange.added);
      }
      expect(await prefs.toggleFavourite('g'), FavouriteChange.full);
      expect(await prefs.favourites(), ['a', 'b', 'c', 'd', 'e', 'f']);
      expect(await prefs.toggleFavourite('c'), FavouriteChange.removed);
      expect(await prefs.favourites(), ['a', 'b', 'd', 'e', 'f']);
    });

    test('recents: newest first, no repeats, eight at most', () async {
      final prefs = NavPrefs(MemoryNavPrefsStore(), 'u1');
      for (var i = 0; i < 10; i++) {
        await prefs.remember(RecentEntry(path: 'p$i', label: 'L$i'));
      }
      await prefs.remember(const RecentEntry(path: 'p5', label: 'L5'));
      final recents = await prefs.recents();
      expect(recents.map((e) => e.path).take(3), ['p5', 'p9', 'p8']);
      expect(recents, hasLength(NavPrefs.maxRecents));
    });

    test('each person on a shared phone keeps their own', () async {
      final store = MemoryNavPrefsStore();
      await NavPrefs(store, 'u1').toggleFavourite('orders');
      expect(await NavPrefs(store, 'u2').favourites(), isEmpty);
    });

    test('kept in the encrypted box — still there after the app opens again', () async {
      FakeSecureStorage.install();
      final harness = await HiveTestHarness.setUp();
      try {
        await NavPrefs(HiveNavPrefsStore(), 'u1').toggleFavourite('orders');
        await NavPrefs(HiveNavPrefsStore(), 'u1')
            .remember(const RecentEntry(path: 'customers/c1', label: 'রহিম স্টোর'));

        // A fresh store object — what the next launch opens.
        final again = NavPrefs(HiveNavPrefsStore(), 'u1');
        expect(await again.favourites(), ['orders']);
        expect((await again.recents()).single.label, 'রহিম স্টোর');
      } finally {
        await harness.tearDown();
      }
    });

    test('a store that will not open costs the list, not the screen', () async {
      final prefs = NavPrefs(_BrokenStore(), 'u1');
      expect(await prefs.favourites(), isEmpty);
      expect(await prefs.recents(), isEmpty);
      await prefs.remember(const RecentEntry(path: 'x', label: 'x'));
    });
  });
}

class _BrokenStore implements NavPrefsStore {
  @override
  Future<List<String>> read(String key) => Future.error(StateError('no box'));

  @override
  Future<void> write(String key, List<String> values) =>
      Future.error(StateError('no box'));
}
