import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/navigation/top_bar/abos_top_bar.dart' show bnDigits;
import '../../core/printing/printer_settings.dart';
import '../../core/printing/thermal_printer.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import 'thermal_flow.dart';

/// "আমি → প্রিন্টার" — this phone's receipt printer (owner, 14 Oct 2026).
///
/// <p>Pick one of the Bluetooth printers already paired with the phone, the
/// roll width, and print a Bengali test slip. Pairing itself stays in
/// Android's Bluetooth settings: it is done once per printer, and a second
/// pairing flow in the app would be a second place for it to go wrong.
class PrinterSettingsScreen extends StatefulWidget {
  const PrinterSettingsScreen({super.key, this.store, this.thermal});

  final PrinterSettingsStore? store;
  final ThermalPrinting? thermal;

  @override
  State<PrinterSettingsScreen> createState() => _PrinterSettingsScreenState();
}

class _PrinterSettingsScreenState extends State<PrinterSettingsScreen> {
  late final PrinterSettingsStore _store =
      widget.store ?? PrinterSettingsStore.instance;
  late final ThermalPrinting _thermal = widget.thermal ?? ThermalPrinting();

  PrinterSettings? _settings;
  bool _busy = false;
  String? _note;
  bool _noteIsError = false;

  @override
  void initState() {
    super.initState();
    _store.read().then((s) {
      if (mounted) setState(() => _settings = s);
    });
  }

  void _say(String message, {bool error = false}) {
    if (mounted) {
      setState(() {
        _note = message;
        _noteIsError = error;
      });
    }
  }

  Future<void> _save(PrinterSettings next) async {
    await _store.write(next);
    if (mounted) setState(() => _settings = next);
  }

  Future<void> _choose() async {
    final words = context.l10n;
    if (!await ThermalFlow.explainOnce(context, _store)) return;
    if (!mounted) return;
    setState(() {
      _busy = true;
      _note = null;
    });
    List<BluetoothPrinter> printers;
    try {
      printers = await ThermalFlow.list(_thermal);
    } on BluetoothPrintingUnavailable catch (e) {
      _say(e.message, error: true);
      return;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
    if (!mounted) return;
    final printer = await ThermalFlow.choose(context, printers);
    if (printer == null) return;
    await _save((await _store.read())
        .copyWith(printerAddress: printer.address, printerName: printer.name));
    _say(words.printingSettingsSaved(printer.label));
  }

  Future<void> _test() async {
    final s = _settings;
    if (s == null || !s.hasPrinter) return;
    final words = context.l10n;
    if (!await ThermalFlow.explainOnce(context, _store)) return;
    setState(() {
      _busy = true;
      _note = null;
    });
    try {
      await _thermal.testPrint(
          address: s.printerAddress!, paperWidthMm: s.widthMm);
      _say(words.printingSettingsTestSent);
    } on BluetoothPrintingUnavailable catch (e) {
      _say(e.message, error: true);
    } catch (_) {
      _say(words.printingSettingsTestFailed, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _settings;
    final words = context.l10n;
    return Scaffold(
      appBar: AbosScreenBar(title: words.printingSettingsTitle),
      body: s == null
          ? const LinearProgressIndicator()
          : ListView(
              padding: const EdgeInsets.all(AppSpacing.md),
              children: [
                if (_busy) const LinearProgressIndicator(),
                Text(words.printingSettingsThermalHeading,
                    style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: AppSpacing.sm),
                Card(
                  child: ListTile(
                    key: const Key('printer-current'),
                    leading: const Icon(Icons.bluetooth),
                    title: Text(s.hasPrinter
                        ? s.printerLabel
                        : words.printingSettingsNoPrinter),
                    subtitle: Text(s.hasPrinter
                        ? s.printerAddress!
                        : words.printingSettingsPairFirst),
                    trailing: TextButton(
                      key: const Key('printer-choose'),
                      onPressed: _busy ? null : _choose,
                      child: Text(s.hasPrinter ? words.printingSettingsChange : words.printingSettingsChoose),
                    ),
                  ),
                ),
                if (s.hasPrinter)
                  TextButton.icon(
                    key: const Key('printer-forget'),
                    onPressed: _busy
                        ? null
                        : () => _save(s.copyWith(clearPrinter: true)),
                    icon: const Icon(Icons.link_off),
                    label: Text(words.printingSettingsForget),
                  ),
                const SizedBox(height: AppSpacing.md),
                Text(words.printingSettingsRollWidth,
                    style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: AppSpacing.sm),
                SegmentedButton<int>(
                  key: const Key('printer-width'),
                  segments: [
                    ButtonSegment(value: 58, label: Text(words.printingSettingsWidthMm(bnDigits(58)))),
                    ButtonSegment(value: 80, label: Text(words.printingSettingsWidthMm(bnDigits(80)))),
                  ],
                  selected: {s.widthMm},
                  onSelectionChanged: _busy
                      ? null
                      : (v) => _save(s.copyWith(widthMm: v.first)),
                ),
                const SizedBox(height: AppSpacing.md),
                FilledButton.icon(
                  key: const Key('printer-test'),
                  onPressed: _busy || !s.hasPrinter ? null : _test,
                  icon: const Icon(Icons.print),
                  label: Text(words.printingSettingsTestButton),
                ),
                if (_note != null) ...[
                  const SizedBox(height: AppSpacing.sm),
                  Text(_note!,
                      key: const Key('printer-note'),
                      style: TextStyle(
                          color: _noteIsError
                              ? context.palette.danger
                              : context.palette.success)),
                ],
                const SizedBox(height: AppSpacing.lg),
                Text(words.printingSettingsWhereHeading,
                    style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: AppSpacing.sm),
                Text(words.printingSettingsWhereBody,
                    style: TextStyle(color: context.palette.onSurfaceMuted)),
                if (s.roads.isNotEmpty)
                  TextButton.icon(
                    key: const Key('printer-reset-roads'),
                    onPressed: _busy
                        ? null
                        : () => _save(s.copyWith(roads: const {}, normalPapers: const {})),
                    icon: const Icon(Icons.restart_alt),
                    label: Text(words.printingSettingsResetRoads),
                  ),
              ],
            ),
    );
  }
}
