import 'package:abos_mobile/core/privacy/device_integrity.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ root করা বা ছদ্ম-ফোন — সতর্কতার লেখা আর "প্রতি চালুতে একবার" (অ্যাপের নিরাপত্তা, MASVS-RESILIENCE-1, ১৯ অক্টোবর ২০২৬;
/// [[DeviceIntegrity]]; হোমের পর্দার দাবি home_shell_test-এ)।
void main() {
  tearDown(DeviceIntegrity.reset);

  test('the message names what was found, says the app goes on, and is silent for a clean phone', () {
    expect(DeviceIntegrity.messageFor(const IntegrityReport(rooted: false, emulator: false)), isNull);
    expect(DeviceIntegrity.messageFor(const IntegrityReport(rooted: true, emulator: false)), contains('root করা'));
    expect(DeviceIntegrity.messageFor(const IntegrityReport(rooted: false, emulator: true)), contains('emulator'));
    expect(DeviceIntegrity.messageFor(const IntegrityReport(rooted: true, emulator: true)), contains('অ্যাপ চলবে'));
  });

  test('once a run, and a check that cannot run says nothing rather than breaking', () async {
    DeviceIntegrity.checker = () async => const IntegrityReport(rooted: true, emulator: false);
    expect(await DeviceIntegrity.warnOnce(), isNotNull);
    expect(await DeviceIntegrity.warnOnce(), isNull, reason: '⛔ একই চালুতে দুবার');

    DeviceIntegrity.reset();
    DeviceIntegrity.checker = () async => throw StateError('no channel');
    expect(await DeviceIntegrity.warnOnce(), isNull);
  });
}
