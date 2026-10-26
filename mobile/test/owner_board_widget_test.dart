import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/owner_board.dart';

import 'support/widget_claims.dart';

/// ⭐ মালিকের পূর্ণ-পর্দা উইজেট — সার্ভারের `owner_board` সারাংশ থেকে নথি (মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 4, 42);
  const kind = OwnerBoardWidget();

  final full = <String, dynamic>{
    'company': 'ADI Corporation',
    'branch': null,
    'link': '/owner',
    'today': {
      'sales': {'count': 38, 'amount': '482350.0000'},
      'collections': {'count': 21, 'amount': '310000.0000'},
      'money': {'amount': '4077445.0000', 'cash': '124800.0000', 'mfs': '92430.0000', 'bank': '3860215.0000', 'transit': '0.0000'},
      'dues': {'amount': '6218400.0000', 'shops': 178, 'advance': '0.0000'},
      'approvals': {'pending': 7},
    },
    'parts': {
      'target': {'month': '2026-10', 'target': '20000000.00', 'achieved': '13600000.00', 'days_left': 12},
      'profit': {'month': '2026-10', 'gross': '1825400.00', 'margin': '13.4'},
      'expenses': {'month': '2026-10', 'total': '842600.00', 'rows': []},
      'cheques': {'total': '85000.00', 'rows': [{}, {}], 'link': '/finance/cheques'},
      'stock_alerts': {'count': 14, 'rows': [{}, {}, {}], 'link': '/inventory/stock'},
      'challans_today': {'moving': 9, 'link': '/sales/challans'},
      'notices': {'unread': 4},
    },
  };

  test('the whole business on one widget: today\'s figures, the month and the five signals', () {
    final doc = kind.build(full, asOf: asOf);
    expectWidgetRules(doc);

    expect(doc.title, 'ADI Corporation · সব শাখা');
    expect(doc.link, '/owner');
    expect(doc.hero!.figure.text, '৳4,82,350.00');
    expect(doc.hero!.caption!.text, 'আজ বিল হয়েছে · 38টা বিল', reason: 'পুরনো সার্ভার — মোট বিল, নিট নয়');
    final net = Map<String, dynamic>.from(full['today'] as Map)
      ..['sales'] = {'count': 38, 'amount': '470000.0000', 'label': 'নিট বিক্রি'};
    expect(kind.build({...full, 'today': net}, asOf: asOf).hero!.caption!.text, 'নিট বিক্রি · 38টা বিল');
    expect([for (final r in doc.rows) r.title.text], ['আজকের আদায়', 'নগদ + ব্যাংক + MFS', 'মোট বকেয়া', 'এ মাসের মোট লাভ', 'এ মাসের খরচ']);
    expect(doc.rows[2].amount!.text, '৳62,18,400.00');
    expect(doc.rows[1].second!.secret, isTrue, reason: 'নগদের অঙ্কও লুকানো যায়');

    expect(doc.bars.single.fraction, closeTo(0.68, 0.001));
    expect(doc.bars.single.value!.text, '68%');
    expect(doc.bars.single.label.text, 'অক্টোবর-এর লক্ষ্য · বাকি 12 দিন');

    expect([for (final a in doc.actions) a.label], ['7 অনুমোদন', '2 চেক', '14 মজুদ কম', '9 চালান পথে', '4 নোটিশ']);
    expect(doc.actions.first.link, '/home/approvals');
    expect(doc.actions[1].link, '/finance/cheques');
  });

  test('a part the owner does not get leaves no row and no button, never a zero', () {
    final today = Map<String, dynamic>.from(full['today'] as Map)..remove('money');
    final doc = kind.build({...full, 'today': today, 'parts': {'notices': {'unread': 1}}}, asOf: asOf);
    expectWidgetRules(doc);
    expect(doc.rows.map((r) => r.title.text), isNot(contains('নগদ + ব্যাংক + MFS')));
    expect(doc.rows.map((r) => r.title.text), isNot(contains('এ মাসের মোট লাভ')));
    expect(doc.bars, isEmpty);
    expect([for (final a in doc.actions) a.label], ['7 অনুমোদন', '1 নোটিশ']);
  });

  test('a chosen branch is named in the head', () {
    expect(kind.build({...full, 'branch': 'SL-Super Group'}, asOf: asOf).title, 'ADI Corporation · SL-Super Group');
  });

  test('with nothing at all it says so, it does not draw an empty board', () {
    final doc = kind.build({'company': 'ADI Corporation', 'today': {}, 'parts': {}}, asOf: asOf);
    expect(doc.empty, isNotNull);
    expect(doc.hero, isNull);
    expect(doc.rows, isEmpty);
    expect(doc.actions, isEmpty, reason: '⛔ না-পাওয়া ভাগের বোতাম (নোটিশ, অনুমোদন …)');
  });
}
