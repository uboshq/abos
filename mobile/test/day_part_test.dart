import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/widgets/day_part.dart';

/// Every boundary, because the bug this guards against was found on a real
/// phone: a greeting that never looked at the clock.
void main() {
  DateTime at(int hour, [int minute = 0]) => DateTime(2026, 9, 27, hour, minute);

  test('the small hours are night, not morning', () {
    expect(banglaGreeting(at(0)), 'শুভ রাত্রি');
    expect(banglaGreeting(at(1)), 'শুভ রাত্রি');
    expect(banglaGreeting(at(5, 59)), 'শুভ রাত্রি');
  });

  test('each band starts where a depot says it does', () {
    expect(banglaGreeting(at(6)), 'শুভ সকাল');
    expect(banglaGreeting(at(11, 59)), 'শুভ সকাল');
    expect(banglaGreeting(at(12)), 'শুভ দুপুর');
    expect(banglaGreeting(at(14, 59)), 'শুভ দুপুর');
    expect(banglaGreeting(at(15)), 'শুভ বিকাল');
    expect(banglaGreeting(at(17, 59)), 'শুভ বিকাল');
    expect(banglaGreeting(at(18)), 'শুভ সন্ধ্যা');
    expect(banglaGreeting(at(19, 59)), 'শুভ সন্ধ্যা');
    expect(banglaGreeting(at(20)), 'শুভ রাত্রি');
    expect(banglaGreeting(at(23, 59)), 'শুভ রাত্রি');
  });
}
