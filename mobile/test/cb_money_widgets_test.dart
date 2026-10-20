import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/bank_reconcile.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/cheques.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/credit_limits.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/expenses.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/money_in_hand.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/profit.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';

import 'support/widget_claims.dart';

/// ⭐ cb-র ছয় উইজেটের দাবি — টাকা হাতে (২), বাকির সীমা (১২), চেক (১৩), খরচ (১৬), লাভ (১৭), ব্যাংক মেলানো (২০)
/// (নকশা ১৯ অক্টোবর; দলের নিয়ম E:\ABOS\app-widgets-ভিত্তি.md; ২০ অক্টোবর ২০২৬)।
///
/// প্রতিটায়: দলের চার নিয়ম ([expectWidgetRules]) — টাকা গোপন-যোগ্য আর ইংরেজি অঙ্কে, বেতন নেই, নাম · পয়েন্ট · শাখা, asOf;
/// আর নিজের: খালি হলে `empty`, সারির link ঠিক পাতায়, সারি ৮-এর বেশি নয়, সার্ভারের অঙ্ক হুবহু।
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 4, 30);

  Iterable<WidgetText> secretTexts(WidgetDoc doc) => doc.texts.where((t) => t.secret);

  group('২ · টাকা হাতে', () {
    const kind = MoneyInHandWidget();

    test('নগদ, ব্যাংক, MFS আর মোট — লাখের কমায়, গোপন-যোগ্য; পথে থাকলে তবেই সারি', () {
      final doc = kind.build({
        'cash': '124800.0000', 'bank': '3860215.0000', 'mfs': '92430.0000', 'transit': '0.0000',
        'total': '4077445.0000', 'link': '/accounts/cash-book',
      }, asOf: asOf);

      expectWidgetRules(doc);
      expect(doc.hero!.figure.text, '৳40,77,445.00');
      expect(doc.rows.map((r) => r.amount!.text), ['৳1,24,800.00', '৳38,60,215.00', '৳92,430.00']);
      expect(doc.rows.every((r) => r.amount!.secret), isTrue, reason: '⛔ টাকা তালাবন্ধ ফোনে লুকায় না');
      expect(doc.link, '/accounts/cash-book');

      final moving = kind.build({'cash': '1', 'bank': '1', 'mfs': '1', 'transit': '5000', 'total': '3'}, asOf: asOf);
      expect(moving.rows.length, 4, reason: 'পথে থাকা টাকার সারি নেই');
    });
  });

  group('১২ · বাকির সীমা', () {
    const kind = CreditLimitsWidget();

    test('গ্রাহক নাম · পয়েন্ট · শাখা; সীমা পেরোলে লাল, ৯০%-এর উপরে কমলা; ৮টার বেশি সারি নয়, বাকিগুলো "আরও"', () {
      final rows = [
        {'name': 'Rasel Enterprise · ফুলবাড়িয়া · SL-Super Group', 'due': '485000', 'limit': '500000', 'percent': 97,
          'link': '/customers/12'},
        {'name': 'Nava Enterprise · ত্রিশাল · SL-Lion Group', 'due': '520000', 'limit': '500000', 'percent': 104,
          'link': '/customers/13'},
        for (var i = 0; i < 8; i++)
          {'name': 'Dealer $i · পয়েন্ট · SL-Super Group', 'due': '95', 'limit': '100', 'percent': 95, 'link': '/customers/${20 + i}'},
      ];
      final doc = kind.build({'count': 10, 'rows': rows, 'link': '/credit-control'}, asOf: asOf);

      expectWidgetRules(doc, customers: {
        'Rasel Enterprise': ('ফুলবাড়িয়া', 'SL-Super Group'),
        'Nava Enterprise': ('ত্রিশাল', 'SL-Lion Group'),
      });
      expect(doc.rows.length, 8, reason: '⛔ মাপের সীমা ছাড়িয়ে সারি');
      expect(doc.more, 'আরও ২টা');
      expect(doc.rows[0].chip!.tone, WidgetTone.warn);
      expect(doc.rows[1].chip!.tone, WidgetTone.bad, reason: '⛔ সীমা পেরোনো গ্রাহক লাল নয়');
      expect(doc.rows[0].second!.text, '৳4,85,000.00 / ৳5,00,000.00');
      expect(doc.rows[0].second!.secret, isTrue);
      expect(doc.rows[0].link, '/customers/12');
      expect(doc.hero!.figure.text, '১০');
    });

    test('কেউ সীমার কাছে নেই — খালির লেখা, বড় অঙ্ক নেই', () {
      final doc = kind.build({'count': 0, 'rows': const []}, asOf: asOf);
      expectWidgetRules(doc);
      expect(doc.rows, isEmpty);
      expect(doc.hero, isNull);
      expect(doc.empty, 'কোনো গ্রাহক সীমার কাছে নেই');
    });
  });

  group('১৩ · চেক', () {
    const kind = ChequesWidget();

    test('আজ জমার চেক আর ফেরত চেক — ফেরত লাল, নাম · পয়েন্ট · শাখা, মোট গোপন-যোগ্য', () {
      final doc = kind.build({
        'total': '740000.0000',
        'count': 3,
        'link': '/accounts/cheques',
        'rows': [
          {'name': 'Nava Enterprise · ত্রিশাল · SL-Lion Group', 'bank': 'DBBL', 'no': '0729', 'date': '2026-10-20',
            'amount': '240000', 'state': 'due', 'link': '/accounts/cheques/7'},
          {'name': 'Bismillah Enterprise · শাহগঞ্জ · SL-Super Group', 'bank': 'IBBL', 'no': '5512', 'date': '2026-10-19',
            'amount': '500000', 'state': 'due', 'link': '/accounts/cheques/8'},
          {'name': 'Rasel Enterprise · ফুলবাড়িয়া · SL-Super Group', 'bank': 'City', 'no': '0077', 'date': '2026-10-18',
            'amount': '85000', 'state': 'bounced', 'link': '/accounts/cheques/9'},
        ],
      }, asOf: asOf);

      expectWidgetRules(doc, customers: {
        'Nava Enterprise': ('ত্রিশাল', 'SL-Lion Group'),
        'Rasel Enterprise': ('ফুলবাড়িয়া', 'SL-Super Group'),
      });
      expect(doc.hero!.figure.text, '৳7,40,000.00');
      expect(doc.rows[0].second!.text, 'DBBL চেক 0729 · তারিখ ২০ অক্টোবর');
      expect(doc.rows[2].title.tone, WidgetTone.bad, reason: '⛔ ফেরত চেক লাল নয়');
      expect(doc.rows[2].title.text, startsWith('ফেরত এসেছে: '));
      expect(doc.rows[2].amount!.tone, WidgetTone.bad);
      expect(doc.rows.map((r) => r.link), ['/accounts/cheques/7', '/accounts/cheques/8', '/accounts/cheques/9']);
      expect(doc.more, isNull);
    });

    test('জমা দেওয়ার চেক নেই — খালির লেখা', () {
      final doc = kind.build({'total': '0', 'count': 0, 'rows': const []}, asOf: asOf);
      expectWidgetRules(doc);
      expect(doc.hero, isNull);
      expect(doc.empty, 'আজ জমা দেওয়ার মতো কোনো চেক নেই');
    });
  });

  group('১৬ · খরচ', () {
    const kind = ExpensesWidget();

    test('খাত ধরে দাগ, সবচেয়ে বড়টা পুরো; "কর্মীর খরচ বাদে"; বেতনের কোনো কথা নেই', () {
      final doc = kind.build({
        'month': '2026-10',
        'total': '532600.0000',
        'link': '/accounts/reports/expenses',
        'rows': [
          {'head': 'গাড়িভাড়া', 'amount': '310000'},
          {'head': 'ভাড়া', 'amount': '130000'},
          {'head': 'বিদ্যুৎ', 'amount': '92600'},
        ],
      }, asOf: asOf);

      expectWidgetRules(doc);
      expect(doc.title, 'অক্টোবর মাসের খরচ');
      expect(doc.hero!.figure.text, '৳5,32,600.00');
      expect(doc.hero!.caption!.text, 'কর্মীর খরচ বাদে');
      expect(doc.bars.first.fraction, 1.0);
      expect(doc.bars[1].fraction, closeTo(130000 / 310000, 1e-9));
      expect(doc.bars.every((b) => b.value!.secret), isTrue);
    });

    test('এখনো খরচ নেই — খালির লেখা, দাগ নেই', () {
      final doc = kind.build({'month': '2026-10', 'total': '0', 'rows': const []}, asOf: asOf);
      expectWidgetRules(doc);
      expect(doc.bars, isEmpty);
      expect(doc.empty, 'এই মাসে এখনো কোনো খরচ নেই');
    });
  });

  group('১৭ · লাভ', () {
    const kind = ProfitWidget();

    test('লাভ সবুজ, হার, বিক্রি আর মালের দাম — সব টাকা গোপন-যোগ্য', () {
      final doc = kind.build({
        'month': '2026-10', 'sales': '13600000', 'cogs': '11774600', 'gross': '1825400', 'margin': '13.4', 'link': '/accounts/reports/profit-loss',
      }, asOf: asOf);

      expectWidgetRules(doc);
      expect(doc.title, 'অক্টোবর মাসের মোট লাভ');
      expect(doc.hero!.figure.text, '৳18,25,400.00');
      expect(doc.hero!.figure.tone, WidgetTone.good);
      expect(doc.hero!.caption!.text, 'মোট লাভের হার 13.4%');
      expect(secretTexts(doc).length, 3);
    });

    test('লোকসান — লাল, "লোকসান" লেখা, বিয়োগ চিহ্ন ছাড়া অঙ্ক', () {
      final doc = kind.build({'month': '2026-10', 'sales': '100', 'cogs': '150', 'gross': '-50', 'margin': '-50.0'}, asOf: asOf);

      expectWidgetRules(doc);
      expect(doc.title, 'অক্টোবর মাসের মোট লোকসান', reason: '⛔ লোকসানকে "লাভ" বলা হলো');
      expect(doc.hero!.figure.text, '৳50.00');
      expect(doc.hero!.figure.tone, WidgetTone.bad);
    });
  });

  group('২০ · ব্যাংক মেলানো', () {
    const kind = BankReconcileWidget();

    test('না-মেলানো সারি আর শেষ মেলানোর দিন', () {
      final doc = kind.build({'open': 14, 'lastOn': '2026-10-15', 'link': '/accounts/reconciliation'}, asOf: asOf);

      expectWidgetRules(doc);
      expect(doc.hero!.figure.text, 'না-মেলানো সারি: ১৪');
      expect(doc.hero!.figure.tone, WidgetTone.bad);
      expect(doc.hero!.caption!.text, 'শেষ মেলানো: ১৫ অক্টোবর');
      expect(doc.link, '/accounts/reconciliation');
    });

    test('সব মেলানো — খালির লেখায় শেষ দিন; কখনো না মেলানো হলে তা-ই বলে', () {
      expect(kind.build({'open': 0, 'lastOn': '2026-10-15'}, asOf: asOf).empty, 'সব মেলানো · শেষ মেলানো: ১৫ অক্টোবর');
      final never = kind.build({'open': 3, 'lastOn': null}, asOf: asOf);
      expect(never.hero!.caption!.text, 'এখনো কখনো মেলানো হয়নি');
    });
  });
}
