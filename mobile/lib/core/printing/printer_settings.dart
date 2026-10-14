import 'package:hive/hive.dart';

import '../storage/hive_encryption.dart';

/// Which of the two roads to paper a print takes.
enum PrintRoad {
  /// The phone's own print system — Wi-Fi, USB, network, Save as PDF; A4/A5.
  normal,

  /// A Bluetooth receipt printer, 58 or 80 mm.
  thermal,
}

/// This phone's printer choices (owner, 14 Oct 2026). Kept on the phone, not
/// on the server: the printer is a thing on this counter, and the next phone
/// on the next counter has its own.
class PrinterSettings {
  const PrinterSettings({
    this.printerAddress,
    this.printerName,
    this.widthMm = 58,
    this.roads = const {},
    this.normalPapers = const {},
    this.permissionExplained = false,
  });

  /// The Bluetooth printer's MAC — stable across renames, so it is the key.
  final String? printerAddress;
  final String? printerName;

  /// 58 or 80 — the roll in the printer. Decides both the server paper asked
  /// for (`58mm`/`80mm`) and the bitmap width (384/576 dots).
  final int widthMm;

  /// The road last used, per paper type (`SalesInvoice` → thermal …), so the
  /// counter's bill opens on thermal and the office's voucher on normal.
  final Map<String, PrintRoad> roads;

  /// The sheet size last used on the normal road, per paper type (a4/a5).
  final Map<String, String> normalPapers;

  /// The Bengali "why Bluetooth" note has been shown once, before Android's
  /// own permission prompt — so it is not repeated on every print.
  final bool permissionExplained;

  bool get hasPrinter => (printerAddress ?? '').isNotEmpty;

  String get thermalPaper => widthMm >= 80 ? '80mm' : '58mm';

  String get printerLabel =>
      (printerName ?? '').isNotEmpty ? printerName! : (printerAddress ?? '');

  PrinterSettings copyWith({
    String? printerAddress,
    String? printerName,
    int? widthMm,
    Map<String, PrintRoad>? roads,
    Map<String, String>? normalPapers,
    bool? permissionExplained,
    bool clearPrinter = false,
  }) =>
      PrinterSettings(
        printerAddress:
            clearPrinter ? null : (printerAddress ?? this.printerAddress),
        printerName: clearPrinter ? null : (printerName ?? this.printerName),
        widthMm: widthMm ?? this.widthMm,
        roads: roads ?? this.roads,
        normalPapers: normalPapers ?? this.normalPapers,
        permissionExplained: permissionExplained ?? this.permissionExplained,
      );

  Map<String, dynamic> toMap() => {
        'address': printerAddress,
        'name': printerName,
        'width': widthMm,
        'roads': {for (final e in roads.entries) e.key: e.value.name},
        'papers': normalPapers,
        'explained': permissionExplained,
      };

  static PrinterSettings fromMap(Map? m) {
    if (m == null) return const PrinterSettings();
    final roads = <String, PrintRoad>{};
    final rawRoads = m['roads'];
    if (rawRoads is Map) {
      for (final e in rawRoads.entries) {
        final road = PrintRoad.values
            .where((r) => r.name == e.value?.toString())
            .firstOrNull;
        if (road != null) roads[e.key.toString()] = road;
      }
    }
    final papers = <String, String>{};
    final rawPapers = m['papers'];
    if (rawPapers is Map) {
      for (final e in rawPapers.entries) {
        papers[e.key.toString()] = e.value.toString();
      }
    }
    final width = m['width'];
    return PrinterSettings(
      printerAddress: m['address']?.toString(),
      printerName: m['name']?.toString(),
      widthMm: width is int && width >= 80 ? 80 : 58,
      roads: roads,
      normalPapers: papers,
      permissionExplained: m['explained'] == true,
    );
  }
}

abstract class PrinterSettingsStore {
  Future<PrinterSettings> read();
  Future<void> write(PrinterSettings settings);

  /// What the app uses; a test swaps in a [MemoryPrinterSettingsStore].
  static PrinterSettingsStore instance = HivePrinterSettingsStore();
}

/// Encrypted like every other box on this phone. Every failure reads as "no
/// settings yet" — a box that will not open costs the person a remembered
/// printer, never the print.
class HivePrinterSettingsStore implements PrinterSettingsStore {
  static const String _boxName = 'abos_printer';
  static const String _key = 'settings';
  static const Duration _patience = Duration(seconds: 3);
  Box<Map>? _box;

  Future<Box<Map>> _open() async =>
      _box ??= await HiveEncryptionKey.openBox<Map>(_boxName);

  @override
  Future<PrinterSettings> read() async {
    try {
      // A box that will not open in time is "no settings yet" — the sheet
      // must never wait on a remembered choice to show its buttons.
      final box = await _open().timeout(_patience);
      return PrinterSettings.fromMap(box.get(_key));
    } catch (_) {
      return const PrinterSettings();
    }
  }

  @override
  Future<void> write(PrinterSettings settings) async {
    try {
      await (await _open().timeout(_patience))
          .put(_key, settings.toMap())
          .timeout(_patience);
    } catch (_) {
      // Not remembered this time; the print itself already happened.
    }
  }
}

class MemoryPrinterSettingsStore implements PrinterSettingsStore {
  MemoryPrinterSettingsStore([this.settings = const PrinterSettings()]);

  PrinterSettings settings;

  @override
  Future<PrinterSettings> read() async => settings;

  @override
  Future<void> write(PrinterSettings settings) async =>
      this.settings = settings;
}
