import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/quick_actions.dart';
import 'package:abos_mobile/core/router/deep_links.dart';

import '../support/widget_claims.dart';

/// ⭐ ৮ · দ্রুত কাজ — [QuickActionsWidget] (a6, ২০ অক্টোবর ২০২৬)
void main() {
  const kind = QuickActionsWidget();
  final asOf = DateTime.utc(2026, 10, 20, 10, 0);

  const full = {
    'actions': [
      {'key': 'order', 'link': '/sales/orders/create'},
      {'key': 'collect', 'link': '/home/collections'},
      {'key': 'voucher', 'link': '/accounts/voucher-list'},
      {'key': 'scan', 'link': '/home/scan'},
      {'key': 'approve', 'link': '/approvals'},
      {'key': 'notice', 'link': '/home/notices'},
    ],
  };

  test('the server\'s set shows exactly, in its order, with links unchanged', () {
    final doc = kind.build(full, asOf: asOf);
    expectWidgetRules(doc);
    expect([for (final a in doc.actions) a.icon], ['order', 'collect', 'voucher', 'scan', 'approve', 'notice']);
    expect([for (final a in doc.actions) a.label], ['নতুন অর্ডার', 'টাকা জমার খবর', 'ভাউচার', 'স্ক্যান', 'অনুমোদন', 'নোটিশ']);
    expect([for (final a in doc.actions) a.link],
        // ⛔ ফোনে আদায় লেখা নেই — "collect" টাকা জমার খবর খোলে (মালিক, ২০ নভেম্বর ২০২৬)
        ['/sales/orders/create', '/home/deposit-report', '/accounts/voucher-list', '/home/scan', '/approvals', '/home/notices']);
    expect(doc.empty, isNull);
  });

  test('every link is an app route or a web path the app opens (a4)', () {
    final doc = kind.build(full, asOf: asOf);
    for (final a in doc.actions) {
      expect(a.link.startsWith('/home/') || DeepLinks.targetFor(Uri.parse(a.link)) != null, isTrue, reason: a.link);
    }
  });

  test('a permission-filtered set shows only what came, in that order', () {
    final doc = kind.build({
      'actions': [
        {'key': 'scan', 'link': '/home/scan'},
        {'key': 'order', 'link': '/sales/orders/create'},
      ],
    }, asOf: asOf);
    expect([for (final a in doc.actions) a.icon], ['scan', 'order']);
  });

  test('an unknown key, or a button with no link, is dropped', () {
    final doc = kind.build({
      'actions': [
        {'key': 'payroll', 'link': '/hr/payroll'},
        {'key': 'collect', 'link': '/home/collections'},
        {'key': 'voucher', 'link': ''},
        {'key': 'approve'},
      ],
    }, asOf: asOf);
    expect([for (final a in doc.actions) a.icon], ['collect']);
  });

  test('nothing permitted says so', () {
    final doc = kind.build({'actions': []}, asOf: asOf);
    expect(doc.actions, isEmpty);
    expect(doc.empty, 'আপনার জন্য কোনো দ্রুত কাজ নেই');
    expectWidgetRules(doc);
    expect(kind.build({}, asOf: asOf).empty, 'আপনার জন্য কোনো দ্রুত কাজ নেই');
  });
}
