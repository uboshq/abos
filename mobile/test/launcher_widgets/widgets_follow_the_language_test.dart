import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/approvals_launcher_widget.dart';
import 'package:abos_mobile/core/launcher_widgets/hub/widget_hub.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/all_kinds.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/credit_limits.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/offers.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/owner_board.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/sales_today.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/target.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_kind.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';

/// ⭐ হোম-স্ক্রিনের উইজেট মানুষের ভাষায় (0.4.43, cb) — Dart-এর লেখা ARB থেকে, Kotlin-এর নিজের কয়েকটা শব্দ
/// `res/values/widget_words.xml` থেকে, দুটোই অ্যাপের বাছাই (`widget_locale`) মেনে; বাছাই না থাকলে বাংলা।
///
/// <p>বাংলায় আগের লেখা হুবহু (পুরনো পরীক্ষাগুলো তা-ই ধরে); এখানে: ইংরেজিতে কোনো উইজেটে একটাও বাংলা অক্ষর থাকে না, আর
/// Kotlin-এর দিক — প্রতিটা শব্দের ইংরেজি জোড়া আছে, আঁকিয়ে `widget_locale` পড়ে, আর Kotlin-এ হাতে-লেখা বাংলা ফেরে না।
void main() {
  tearDown(() => AppLocale.current = AppLocale.locale);

  // ঢাকায় ২১ অক্টোবর সকাল ১০টা
  final asOf = DateTime.utc(2026, 10, 21, 4);

  // সার্ভারের নাম আর লেখা ইংরেজিতে, যাতে যে বাংলা থাকে তা আমাদের নিজের লেখা
  final summaries = <String, Map<String, dynamic>>{
    'sales_today': {'total': '1000', 'invoices': 38, 'collections': '500', 'change_percent': '12.4'},
    'money_in_hand': {'total': '100', 'cash': '50', 'bank': '30', 'mfs': '20', 'transit': '5'},
    'approvals': {
      'count': 3,
      'rows': [
        {'kind_label': 'Purchase bill', 'number': 'PB-1', 'party': {'label': 'Rahman Traders'}, 'amount': '1200.0000'},
      ],
    },
    'big_dues': {
      'rows': [
        {'customer': {'label': 'Karim Store'}, 'balance': '5000.0000', 'overdue_days': 12},
        {'customer': {'label': 'Early Shop'}, 'balance': '-100.0000'},
      ],
      'total': '4900.0000',
    },
    'stock_alerts': {
      'count': 3,
      'rows': [
        {'product': 'Soap', 'share': 0.2, 'available': 2, 'reorder': 10, 'unit': 'pcs'},
      ],
      'warehouse': 'Main',
      'branch': 'Dhaka',
    },
    'challans_today': {
      'total': 5, 'gate_pending': 1, 'moving': 2, 'delivered': 1, 'returned': 1,
      'latest': {'customer': 'Karim', 'no': 'DC-1', 'vehicle': 'DHA-11'},
    },
    'notices': {
      'unread': 1,
      'more': 2,
      'rows': [
        {'title': 'Price change', 'second': 'From Monday', 'unread': true},
      ],
    },
    'quick_actions': {
      'actions': [
        for (final k in ['order', 'collect', 'voucher', 'scan', 'approve', 'notice']) {'key': k, 'link': '/$k'},
      ],
    },
    'target': {'month': '2026-10', 'own': true, 'target': '20000000', 'achieved': '13600000', 'days_left': 10},
    'sync_state': {'pending': 2, 'failed': true},
    'collections_today': {'total': '100', 'cash': '50', 'bank': '50'},
    'credit_limits': {
      'rows': [
        {'name': 'Karim', 'due': '95', 'limit': '100', 'percent': 95},
      ],
      'count': 2,
    },
    'cheques': {
      'rows': [
        {'name': 'Karim', 'bank': 'DBBL', 'no': '123', 'date': '2026-10-22', 'amount': '500'},
        {'name': 'Rahim', 'state': 'bounced', 'bank': 'IBBL', 'no': '9', 'amount': '100'},
      ],
      'count': 4,
      'total': '600',
    },
    'sr_board': {
      'rows': [
        {'name': 'Karim', 'rank': 1, 'branch': 'Dhaka', 'amount': '100'},
      ],
      'branch_label': 'Dhaka',
    },
    'goods_incoming': {
      'count': 3,
      'late': 1,
      'rows': [
        {'supplier': 'Acme', 'supplier_branch': 'Dhaka', 'no': 'PO-1', 'expected': '2026-10-20', 'late': true, 'amount': '100'},
        {'supplier': 'Beta', 'no': 'PO-2', 'expected': '2026-10-25', 'products': 4},
      ],
    },
    'expenses': {
      'month': '2026-10',
      'total': '500',
      'rows': [
        {'head': 'Fuel', 'amount': '300'},
      ],
    },
    'profit': {'month': '2026-10', 'gross': '-100', 'margin': '-5', 'sales': '1000', 'cogs': '1100'},
    'offers': {
      'count': 3,
      'rows': [
        {'title': 'Eid offer', 'product': 'Soap', 'scope': 'All', 'ends_on': '2026-10-23'},
        {'title': 'Winter', 'ends_on': '2026-11-30'},
      ],
    },
    'attendance': {
      'today': {'status': 'present', 'in_time': '09:12', 'late': true},
      'month': {'present': 10, 'absent': 1, 'leave': 2, 'late': 3},
      'leave_left': 12,
    },
    'bank_reconcile': {'open': 3, 'lastOn': '2026-10-15'},
    'principal_commission': {'rows': <Object>[]},
    'owner_board': {
      'company': 'ADI',
      'today': {
        'sales': {'amount': '1000', 'count': 5},
        'collections': {'count': 3, 'amount': '500'},
        'money': {'cash': '10', 'amount': '100'},
        'dues': {'shops': 4, 'amount': '800'},
        'approvals': {'pending': 2},
      },
      'parts': {
        'profit': {'margin': '12.5', 'gross': '300'},
        'expenses': {'total': '50'},
        'target': {'month': '2026-10', 'target': '1000', 'achieved': '500', 'days_left': 10},
        'cheques': {'count': 2},
        'stock_alerts': {'count': 3},
        'challans_today': {'moving': 2},
        'notices': {'unread': 1},
      },
    },
  };

  // ৳ টাকার চিহ্ন (U+09F3) বাংলা ব্লকে, কিন্তু সব ভাষায় একই
  final bengali = RegExp('[ঀ-৲৴-৿]');

  test('every widget has a sample here, so a new one is not left out', () {
    expect(summaries.keys.toSet(), allKinds.map((k) => k.key).toSet());
  });

  test('in English no widget writes a single Bengali letter — title, rows, captions, empty and more', () {
    AppLocale.current = AppLocale.english;
    for (final kind in allKinds) {
      final doc = jsonEncode(kind.build(summaries[kind.key]!, asOf: asOf).toJson());
      expect(bengali.hasMatch(doc), isFalse, reason: '${kind.key}: $doc');
      expect(bengali.hasMatch(kind.title), isFalse, reason: '${kind.key} title: ${kind.title}');
    }
  });

  test('in English the words read as English, digits in English', () {
    AppLocale.current = AppLocale.english;
    String doc(LauncherWidgetKind k) => jsonEncode(k.build(summaries[k.key]!, asOf: asOf).toJson());
    expect(doc(const OffersWidget()), allOf(contains('Ends in 2 days'), contains('Ends 30 November'), contains('Active 3')));
    expect(doc(const CreditLimitsWidget()), allOf(contains('Customers over 90% of their limit'), contains('1 more')));
    expect(doc(const SalesTodayWidget()), allOf(contains('▲ 12% more than yesterday'), contains('Bills 38')));
    expect(doc(const OwnerBoardWidget()), allOf(contains('ADI · All branches'), contains('October target · 10 days left')));
    expect(TargetWidget.short(13600000), '৳ 1.36 crore');
  });

  test('in Bangla the same widgets read exactly as before', () {
    AppLocale.current = AppLocale.locale;
    String doc(LauncherWidgetKind k) => jsonEncode(k.build(summaries[k.key]!, asOf: asOf).toJson());
    expect(doc(const OffersWidget()), allOf(contains('শেষ: ২ দিন পর'), contains('শেষ: ৩০ নভেম্বর'), contains('চালু 3')));
    expect(doc(const CreditLimitsWidget()), allOf(contains('গ্রাহক সীমার ৯০%-এর বেশি'), contains('আরও ১টা')));
    expect(doc(const OwnerBoardWidget()), allOf(contains('ADI · সব শাখা'), contains('অক্টোবর-এর লক্ষ্য · বাকি 10 দিন')));
    expect(TargetWidget.short(13600000), '৳ 1.36 কোটি');
  });

  test('the older approvals widget says its lines in the language', () {
    AppLocale.current = AppLocale.english;
    final en = ApprovalsLauncherWidget.valuesFor(const [], at: DateTime(2026, 10, 21, 10));
    expect(en[ApprovalsLauncherWidget.empty], 'Nothing to approve right now.');
    expect(en[ApprovalsLauncherWidget.asOf], 'As of 10:00 AM');
    AppLocale.current = AppLocale.locale;
    final bn = ApprovalsLauncherWidget.valuesFor(const [], at: DateTime(2026, 10, 21, 10));
    expect(bn[ApprovalsLauncherWidget.empty], 'এখন অনুমোদনের জন্য কিছু নেই।');
    expect(bn[ApprovalsLauncherWidget.asOf], 'সকাল 10:00 পর্যন্ত');
  });

  group('the Kotlin side', () {
    const kotlin = 'android/app/src/main/kotlin/com/abos/abos_mobile';
    const res = 'android/app/src/main/res';
    String read(String path) => File(path).readAsStringSync();

    Map<String, String> strings(String path) => {
          for (final m in RegExp(r'<string name="([a-z0-9_]+)">(.*?)</string>', dotAll: true).allMatches(read(path)))
            m[1]!: m[2]!,
        };

    test('it reads the language Dart writes, and only "en" is English', () {
      final words = read('$kotlin/widgets/WidgetWords.kt');
      expect(words, contains('const val LOCALE = "${WidgetHub.localeKey}"'));
      expect(words, contains('getString(LOCALE, null) == "en"'), reason: 'missing or anything else = Bangla');
      expect(read('$kotlin/widgets/DocWidget.kt'), contains('english = WidgetWords.english(prefs)'));
      expect(read('$kotlin/TodayWidgetProvider.kt'), contains('WidgetWords.english(widgetData)'));
      expect(read('$kotlin/ApprovalsWidgetProvider.kt'), contains('WidgetWords.english(widgetData)'));
    });

    test('every word has its English twin, the English has no Bengali, and there is no values-en folder', () {
      final words = strings('$res/values/widget_words.xml');
      final all = {...strings('$res/values/strings.xml'), ...words};
      final twins = words.keys.where((k) => k.endsWith('_en')).toList();
      expect(twins.length, greaterThan(40));
      for (final en in twins) {
        final bn = en.substring(0, en.length - 3);
        expect(all[bn], isNotNull, reason: '$en has no Bangla word $bn');
        expect(bengali.hasMatch(all[bn]!), isTrue, reason: '$bn should be the Bangla word');
        expect(bengali.hasMatch(words[en]!), isFalse, reason: en);
        expect(words[en]!.trim(), isNotEmpty, reason: en);
      }
      for (final bn in words.keys.where((k) => !k.endsWith('_en'))) {
        expect(words['${bn}_en'], isNotNull, reason: '$bn has no English twin');
      }
      expect(Directory('$res/values-en').existsSync(), isFalse,
          reason: 'Android would pick values-en by the phone\'s language, not the app\'s');
    });

    test('every kit widget has its title in both languages, and the picker name points at the Bangla one', () {
      final words = strings('$res/values/widget_words.xml');
      final names = strings('$res/values/strings.xml');
      final kotlinWords = read('$kotlin/widgets/WidgetWords.kt');
      for (final key in allKinds.map((k) => k.key)) {
        expect(words['widget_${key}_title'], isNotNull, reason: key);
        expect(words['widget_${key}_title_en'], isNotNull, reason: key);
        expect(names['widget_${key}_name'], '@string/widget_${key}_title', reason: key);
        expect(kotlinWords, contains('"$key" to Word(R.string.widget_${key}_title, R.string.widget_${key}_title_en)'), reason: key);
      }
    });

    test('every fixed label of the two older widgets is set from Kotlin in the language', () {
      final kotlinWords = read('$kotlin/widgets/WidgetWords.kt') + read('$kotlin/ApprovalsWidgetProvider.kt');
      for (final layout in ['today_widget.xml', 'approvals_widget.xml']) {
        final ids = RegExp(r'@\+id/(label_[a-z_]+)').allMatches(read('$res/layout/$layout')).map((m) => m[1]!).toList();
        expect(ids, isNotEmpty, reason: layout);
        for (final id in ids) {
          expect(kotlinWords, contains('R.id.$id'), reason: '$layout: $id is never set');
        }
      }
    });

    test('no Kotlin widget source writes Bengali by hand — it comes from widget_words.xml', () {
      final digitTable = RegExp(r'const val DIGITS = "[০-৯]{10}"');
      for (final f in [
        ...Directory('$kotlin/widgets').listSync().whereType<File>(),
        File('$kotlin/TodayWidgetProvider.kt'),
        File('$kotlin/ApprovalsWidgetProvider.kt'),
      ]) {
        final lines = f.readAsLinesSync();
        for (var i = 0; i < lines.length; i++) {
          final line = lines[i];
          final code = line.trimLeft();
          if (code.startsWith('//') || code.startsWith('*') || code.startsWith('/*')) continue;
          if (digitTable.hasMatch(line)) continue;
          final beforeComment = line.split('//').first;
          for (final s in RegExp(r'"([^"]*)"').allMatches(beforeComment)) {
            expect(bengali.hasMatch(s[1]!), isFalse, reason: '${f.path}:${i + 1}: ${line.trim()}');
          }
        }
      }
    });
  });
}
