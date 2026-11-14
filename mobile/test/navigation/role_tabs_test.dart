import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/navigation/role_tabs.dart';
import 'package:abos_mobile/core/web_inside/web_inside.dart';

import 'bar_harness.dart';

/// ⭐ বারের নকশা ৩, অংশ ৪ আর ৭ — খাঁটি নিয়মগুলো: ভূমিকা চাবি থেকে, চার ঘর + শেষে "আমি" (১৩ নভেম্বর ২০২৬, মালিক: "ছবি নিচের বারে"), লাল গোনা, TalkBack-এর বাক্য, ＋ নতুন, বারের ধরন।
void main() {
  List<BarTab> tabs(String role, {Set<String>? modulesOn}) =>
      RoleTabs.tabsFor(rolePermissions[role]!, modulesOn: modulesOn);
  List<String> labels(List<BarTab> t) => [for (final x in t) RoleTabs.specs[x]!.label];

  group('the role comes from permissions, never from a role name', () {
    test('owner or director: হোম · অনুমোদন · রিপোর্ট · সব মডিউল · আমি', () {
      expect(RoleTabs.roleOf(rolePermissions['owner']!), BarRole.owner);
      expect(labels(tabs('owner')), ['হোম', 'অনুমোদন', 'রিপোর্ট', 'সব মডিউল', 'আমি']);
    });

    test('SR: হোম · অর্ডার · আদায় · সব মডিউল · আমি', () {
      expect(RoleTabs.roleOf(rolePermissions['sr']!), BarRole.sr);
      expect(labels(tabs('sr')), ['হোম', 'অর্ডার', 'আদায়', 'সব মডিউল', 'আমি']);
    });

    test('depot: হোম · চালান · মজুদ · সব মডিউল · আমি', () {
      expect(RoleTabs.roleOf(rolePermissions['depot']!), BarRole.depot);
      expect(labels(tabs('depot')), ['হোম', 'চালান', 'মজুদ', 'সব মডিউল', 'আমি']);
    });

    test('accounts: হোম · অনুমোদন · ভাউচার · সব মডিউল · আমি', () {
      expect(RoleTabs.roleOf(rolePermissions['accounts']!), BarRole.accounts);
      expect(labels(tabs('accounts')), ['হোম', 'অনুমোদন', 'ভাউচার', 'সব মডিউল', 'আমি']);
    });

    test('somebody with none of the four keys gets home, all modules and আমি only', () {
      expect(RoleTabs.roleOf(const ['customer.view']), BarRole.other);
      expect(RoleTabs.tabsFor(const ['customer.view']), [BarTab.home, BarTab.modules, BarTab.me]);
    });

    test('a tab whose permission is missing never appears', () {
      // SR without the collection key: no আদায়
      expect(RoleTabs.tabsFor(const ['sales.order.create', 'sales.order.view']),
          [BarTab.home, BarTab.orders, BarTab.modules, BarTab.me]);
      // accounts without the approval key: no অনুমোদন
      expect(RoleTabs.tabsFor(const ['accounts.voucher.create']), [BarTab.home, BarTab.vouchers, BarTab.modules, BarTab.me]);
      // owner without the report keys: no রিপোর্ট
      expect(RoleTabs.tabsFor(const ['executive.view', 'approval.decide']),
          [BarTab.home, BarTab.approvals, BarTab.modules, BarTab.me]);
    });

    test('a module the company switched off for the phone takes its tab away', () {
      expect(tabs('sr', modulesOn: {'customer'}), [BarTab.home, BarTab.modules, BarTab.me]);
      expect(tabs('owner', modulesOn: {'sales', 'customer'}), [BarTab.home, BarTab.reports, BarTab.modules, BarTab.me]);
      expect(tabs('depot', modulesOn: {'inventory'}), [BarTab.home, BarTab.stock, BarTab.modules, BarTab.me]);
    });

    test('no tab outside the role ever comes in, whatever else the person may open', () {
      // the owner can see stock and challans, but those are the depot's tabs
      expect(tabs('owner'), isNot(contains(BarTab.stock)));
      expect(tabs('owner'), isNot(contains(BarTab.challans)));
      for (final role in rolePermissions.keys) {
        // ⓘ ১৩ নভেম্বর ২০২৬ — মালিকের সিদ্ধান্ত "ছবি নিচের বারে": ভূমিকার চার ঘর + শেষে "আমি" = পাঁচ (Material 3-এর সীমা)
        expect(tabs(role).length, 5, reason: role);
        expect(tabs(role).first, BarTab.home);
        expect(tabs(role)[3], BarTab.modules, reason: role);
        expect(tabs(role).last, BarTab.me, reason: role);
      }
    });
  });

  group('the badge', () {
    test('1 to 99 as the number, above that 99+, nothing at zero', () {
      expect(RoleTabs.badgeFor(1).text, '1');
      expect(RoleTabs.badgeFor(99).text, '99');
      expect(RoleTabs.badgeFor(100).text, '99+');
      expect(RoleTabs.badgeFor(4321).text, '99+');
      expect(RoleTabs.badgeFor(0).kind, TabBadgeKind.none);
      expect(RoleTabs.badgeFor(null).kind, TabBadgeKind.none);
    });

    test('only a dot when the count is unknown (more waiting than one page told)', () {
      expect(RoleTabs.badgeFor(25, more: true).kind, TabBadgeKind.dot);
      expect(RoleTabs.badgeFor(150, more: true).text, '99+');
    });

    test('TalkBack reads it in Bengali', () {
      final spec = RoleTabs.specs[BarTab.approvals]!;
      expect(RoleTabs.spokenLabel(spec, RoleTabs.badgeFor(5)), 'অনুমোদন, ৫টা অপেক্ষায়');
      expect(RoleTabs.spokenLabel(spec, RoleTabs.badgeFor(42)), 'অনুমোদন, ৪২টা অপেক্ষায়');
      expect(RoleTabs.spokenLabel(spec, RoleTabs.badgeFor(120)), 'অনুমোদন, ৯৯টার বেশি অপেক্ষায়');
      expect(RoleTabs.spokenLabel(spec, RoleTabs.badgeFor(3, more: true)), 'অনুমোদন, নতুন অপেক্ষায়');
      expect(RoleTabs.spokenLabel(spec, RoleTabs.badgeFor(0)), 'অনুমোদন');
    });
  });

  group('which tab a page belongs to (a notification or link opens the right tab)', () {
    test('the tab pages and their papers', () {
      expect(RoleTabs.owningTab('/home/approvals'), BarTab.approvals);
      expect(RoleTabs.owningTab('/home/orders'), BarTab.orders);
      expect(RoleTabs.owningTab('/home/new-order'), BarTab.orders);
      expect(RoleTabs.owningTab('/home/paper/order/o1'), BarTab.orders);
      expect(RoleTabs.owningTab('/home/paper/collection/c1'), BarTab.collections);
      expect(RoleTabs.owningTab('/home/paper/voucher/v1'), BarTab.vouchers);
      expect(RoleTabs.owningTab('/home/challans'), BarTab.challans);
      expect(RoleTabs.owningTab('/home/stock'), BarTab.stock);
      expect(RoleTabs.owningTab('/home/vouchers/expense'), BarTab.vouchers);
    });

    test('a web link from a notification is read through its address', () {
      expect(RoleTabs.owningTab('/home/${WebPaths.navPathFor('/approvals/12')}'), BarTab.approvals);
      expect(RoleTabs.owningTab('/home/${WebPaths.navPathFor('/sales/orders/9')}'), BarTab.orders);
      expect(RoleTabs.owningTab('/home/${WebPaths.navPathFor('/reports/sales')}'), BarTab.reports);
      expect(RoleTabs.owningTab('/home/${WebPaths.navPathFor('/hr/leave')}'), isNull);
    });

    test('other pages stay with the tab they were opened from', () {
      expect(RoleTabs.owningTab('/home'), isNull);
      expect(RoleTabs.owningTab('/home/customers/c1'), isNull);
      expect(RoleTabs.owningTab('/home/notifications'), isNull);
    });
  });

  group('＋ নতুন only where something can be created, and only with the key', () {
    test('order: only with sales.order.create', () {
      final fab = RoleTabs.fabFor('/home/orders', rolePermissions['sr']!);
      expect(fab?.label, 'নতুন অর্ডার');
      expect(fab?.route, '/home/new-order');
      // ⓘ অর্ডারের পাতা কাঠামোর বোতাম নিজের Scaffold-এ বসায় (মোটের বারের ওপরে) — FrameFab.offeredTo
      expect(fab?.drawnByScreen, isTrue);
      expect(RoleTabs.fabFor('/home/orders', const ['sales.order.view']), isNull);
    });

    test('collection: only when the server lets this phone collect', () {
      expect(RoleTabs.fabFor('/home/collections', rolePermissions['sr']!, mayCollect: true)?.label, 'নতুন আদায়');
      expect(RoleTabs.fabFor('/home/collections', rolePermissions['sr']!), isNull);
    });

    test('voucher and expense: only with accounts.voucher.create', () {
      expect(RoleTabs.fabFor('/home/vouchers', rolePermissions['accounts']!)?.label, 'নতুন ভাউচার');
      expect(RoleTabs.fabFor('/home/vouchers/expense', rolePermissions['accounts']!)?.label, 'নতুন ভাউচার');
      expect(RoleTabs.fabFor('/home/vouchers', rolePermissions['depot']!), isNull);
      expect(RoleTabs.fabFor('/home/claims', const ['hr.claim.self'])?.label, 'নতুন দাবি');
    });

    test('pages where nothing is created have none', () {
      for (final path in ['approvals', 'reports', 'challans', 'stock', 'customers', 'notifications']) {
        expect(RoleTabs.fabFor('/home/$path', rolePermissions['owner']!, mayCollect: true), isNull, reason: path);
      }
    });

    test('the home\'s ＋ নতুন lists what this role may create', () {
      final owner = RoleTabs.fabFor('/home', rolePermissions['owner']!, mayCollect: true)!;
      expect(owner.label, 'নতুন');
      expect([for (final c in owner.choices) c.label],
          ['নতুন অর্ডার', 'নতুন আদায়', 'নতুন খরচ', 'নতুন ভাউচার', 'খরচের দাবি']);
      expect([for (final c in RoleTabs.fabFor('/home', rolePermissions['sr']!)!.choices) c.label], ['নতুন অর্ডার']);
      expect(RoleTabs.fabFor('/home', rolePermissions['depot']!), isNull);
    });

    test('a switched-off module takes its choice and its button away', () {
      expect(RoleTabs.fabFor('/home/orders', rolePermissions['sr']!, modulesOn: {'customer'}), isNull);
      expect(RoleTabs.fabFor('/home', rolePermissions['sr']!, modulesOn: {'customer'}), isNull);
    });
  });

  group('which bar for which window (Material 3 window size classes)', () {
    test('phones in portrait: the bottom bar', () {
      for (final w in [320.0, 360.0, 412.0, 599.0]) {
        expect(RoleTabs.layoutFor(Size(w, 800)), BarLayout.bottom, reason: '$w');
      }
    });

    test('600dp or more, or a phone on its side: the rail', () {
      expect(RoleTabs.layoutFor(const Size(600, 960)), BarLayout.rail);
      expect(RoleTabs.layoutFor(const Size(800, 1280)), BarLayout.rail);
      expect(RoleTabs.layoutFor(const Size(800, 360)), BarLayout.rail);
      expect(RoleTabs.layoutFor(const Size(560, 320)), BarLayout.rail);
    });

    test('840dp or more: the side menu stays open', () {
      expect(RoleTabs.layoutFor(const Size(839, 1200)), BarLayout.rail);
      expect(RoleTabs.layoutFor(const Size(840, 1200)), BarLayout.extendedRail);
      expect(RoleTabs.layoutFor(const Size(1280, 800)), BarLayout.extendedRail);
    });
  });
}
