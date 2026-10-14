import 'package:abos_mobile/core/printing/thermal_printer.dart';

/// A Bluetooth printer that is not there — every answer scripted, every
/// call written down. Under `flutter test` there is no native side at all.
class FakeBluetoothPort implements BluetoothPort {
  FakeBluetoothPort({
    this.permission = true,
    this.enabled = true,
    this.printers = const [BluetoothPrinter(name: 'POS-58', address: 'AA:BB')],
    this.connects = true,
    this.writes = true,
  });

  bool permission;
  bool enabled;
  List<BluetoothPrinter> printers;
  bool connects;
  bool writes;

  final List<String> calls = [];
  final List<List<int>> sent = [];
  String? connectedTo;

  @override
  Future<bool> ensurePermission() async {
    calls.add('permission');
    return permission;
  }

  @override
  Future<bool> isEnabled() async {
    calls.add('enabled');
    return enabled;
  }

  @override
  Future<List<BluetoothPrinter>> paired() async {
    calls.add('paired');
    return printers;
  }

  @override
  Future<bool> connect(String address) async {
    calls.add('connect');
    connectedTo = address;
    return connects;
  }

  @override
  Future<bool> write(List<int> bytes) async {
    calls.add('write');
    sent.add(bytes);
    return writes;
  }

  @override
  Future<void> disconnect() async {
    calls.add('disconnect');
  }
}
