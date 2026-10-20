import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/attendance.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_doc.dart';
import 'package:abos_mobile/core/router/deep_links.dart';

import '../support/widget_claims.dart';

/// ⭐ ১৯ · আমার উপস্থিতি — [AttendanceWidget] (a6, ২০ অক্টোবর ২০২৬)
void main() {
  const kind = AttendanceWidget();
  final asOf = DateTime.utc(2026, 10, 20, 10, 0);

  Map<String, dynamic> s(Map<String, dynamic> today, {Object? leaveLeft = '11.0'}) => {
        'today': today,
        'month': {'present': 17, 'absent': 1, 'leave': 1, 'late': 2},
        'leave_left': leaveLeft,
        'link': '/hr/attendance',
      };

  test('came in on time: arrival time, leave left, this month', () {
    final doc = kind.build(s({'status': 'present', 'in_time': '09:12:00', 'late': false}), asOf: asOf);
    expectWidgetRules(doc);
    expect(doc.hero!.figure.text, 'আজ এসেছি: 09:12');
    expect(doc.hero!.figure.tone, WidgetTone.good);
    expect(doc.hero!.caption, isNull);
    expect(doc.rows[0].title.text, 'ছুটি বাকি: 11 দিন');
    expect(doc.rows[1].title.text, 'এ মাসে: উপস্থিত 17 · অনুপস্থিত 1');
    expect(doc.rows[1].second!.text, 'ছুটি 1 · দেরি 2');
    expect(doc.link, '/hr/attendance');
    expect(DeepLinks.targetFor(Uri.parse(doc.link!)), isNotNull);
  });

  test('late is warned', () {
    final doc = kind.build(s({'status': 'present', 'in_time': '10:05', 'late': true}), asOf: asOf);
    expect(doc.hero!.figure.tone, WidgetTone.warn);
    expect(doc.hero!.caption!.text, 'দেরি');
  });

  test('absent, leave, holiday and no mark each say so', () {
    final cases = {
      'absent': 'আজ অনুপস্থিত',
      'leave': 'আজ ছুটি',
      'holiday': 'আজ সরকারি ছুটি',
      null: 'আজ হাজিরা বসেনি',
    };
    for (final MapEntry(key: status, value: words) in cases.entries) {
      final doc = kind.build(s({'status': status, 'in_time': null, 'late': false}), asOf: asOf);
      expect(doc.hero!.figure.text, words, reason: '$status');
      expect(doc.hero!.caption, isNull);
      expectWidgetRules(doc);
    }
  });

  test('no employee record is empty', () {
    final doc = kind.build({'employee': false}, asOf: asOf);
    expect(doc.empty, 'আপনার কর্মীর খাতা নেই');
    expect(doc.hero, isNull);
    expect(doc.rows, isEmpty);
    expectWidgetRules(doc);
  });

  test('half a day of leave stays half; no leave figure, no row', () {
    final half = kind.build(s({'status': 'present', 'in_time': '09:00', 'late': false}, leaveLeft: '1.5'), asOf: asOf);
    expect(half.rows[0].title.text, 'ছুটি বাকি: 1.5 দিন');
    final none = kind.build(s({'status': 'present', 'in_time': '09:00', 'late': false}, leaveLeft: null), asOf: asOf);
    expect(none.rows.single.title.text, startsWith('এ মাসে'));
  });

  test('never a word of pay, and no money at all', () {
    final doc = kind.build(s({'status': 'present', 'in_time': '09:12', 'late': true}), asOf: asOf);
    for (final t in doc.texts) {
      expect(t.text, isNot(contains('৳')));
    }
    expectWidgetRules(doc);
  });
}
