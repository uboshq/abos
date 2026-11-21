import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/auth/me_features.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_top_bar.dart' show bnDigits;
import '../../core/printing/document_delivery.dart';
import '../../core/printing/documents_api.dart';
import '../../core/printing/printer_settings.dart';
import '../../core/printing/thermal_printer.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../messenger/list/send_paper_sheet.dart';
import 'pdf_view_page.dart';
import 'printer_settings_screen.dart';
import 'thermal_flow.dart';

/// "ছাপুন / পাঠান" for one paper, from anywhere in the app.
///
/// <p>⭐ Two printers (owner, 14 Oct 2026: "mobile app theke thermal & normal
/// print zate dewa zay"):
/// <ul>
/// <li><b>নরমাল প্রিন্টার (A4/A5)</b> — the server's A4/A5 PDF, the same paper
///   the web prints, into Android's own print dialog (Wi-Fi, USB, network,
///   Save as PDF).</li>
/// <li><b>থার্মাল প্রিন্টার (৫৮/৮০ মিমি)</b> — the server's 58/80 mm PDF,
///   turned into a picture on the phone and sent over Bluetooth, because the
///   printer's own fonts have no Bengali.</li>
/// </ul>
///
/// <p>The papers offered are the server's list for this paper, never a list
/// written on the phone. The road and the sheet size last used for this kind
/// of paper are remembered on this phone.
class DocumentActionsSheet extends StatefulWidget {
  const DocumentActionsSheet({
    super.key,
    required this.type,
    required this.id,
    required this.title,
    this.fileStem,
    this.loadPapers,
    this.loadPdf,
    this.delivery = const DocumentDeliveryPorts(),
    this.thermal,
    this.settingsStore,
  });

  /// `SalesInvoice`, `Voucher` … the server's own naming.
  final String type;
  final String id;

  /// What the person is looking at — "ক্রয় বিল · PB-2609-0007".
  final String title;

  /// The file's name without extension (the paper's number).
  final String? fileStem;

  final Future<List<String>> Function()? loadPapers;
  final Future<Uint8List> Function(String paper)? loadPdf;

  /// View, system print, share, save — injectable for tests.
  final DocumentDeliveryPorts delivery;

  /// The Bluetooth road — injectable for tests.
  final ThermalPrinting? thermal;
  final PrinterSettingsStore? settingsStore;

  static Future<void> show(
    BuildContext context, {
    required String type,
    required String id,
    required String title,
    String? fileStem,
  }) =>
      showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        builder: (_) => DocumentActionsSheet(
          type: type,
          id: id,
          title: title,
          fileStem: fileStem,
        ),
      );

  @override
  State<DocumentActionsSheet> createState() => _DocumentActionsSheetState();
}

class _DocumentActionsSheetState extends State<DocumentActionsSheet> {
  List<String>? _papers;
  PrinterSettings _settings = const PrinterSettings();
  PrintRoad _road = PrintRoad.normal;
  String? _sheet;
  String? _error;

  /// The last outcome, said inside the sheet — a SnackBar would sit behind
  /// the sheet itself and be missed.
  String? _note;
  bool _noteIsError = false;
  bool _busy = false;

  late final PrinterSettingsStore _store =
      widget.settingsStore ?? PrinterSettingsStore.instance;
  late final ThermalPrinting _thermal = widget.thermal ?? ThermalPrinting();

  @override
  void initState() {
    super.initState();
    _load();
  }

  String get _fileName => '${widget.fileStem ?? widget.id}.pdf';

  List<String> get _sheets =>
      (_papers ?? const []).where(isSheetPaper).toList();
  List<String> get _rolls =>
      (_papers ?? const []).where(isThermalPaper).toList();

  /// The roll to ask the server for: the printer's own width if this paper
  /// has it, otherwise the one it has.
  String? get _roll {
    final rolls = _rolls;
    if (rolls.isEmpty) return null;
    return rolls.contains(_settings.thermalPaper)
        ? _settings.thermalPaper
        : rolls.first;
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final settings = await _store.read();
      final papers = await (widget.loadPapers ??
          () => DocumentsApi.papersFor(type: widget.type, id: widget.id))();
      if (!mounted) return;
      setState(() {
        _settings = settings;
        _papers = papers;
        final sheets = _sheets;
        final remembered = settings.normalPapers[widget.type];
        _sheet = sheets.contains(remembered)
            ? remembered
            : (sheets.contains('a4')
                ? 'a4'
                : (sheets.isEmpty ? null : sheets.first));
        final road = settings.roads[widget.type] ?? PrintRoad.normal;
        _road = (road == PrintRoad.thermal && _rolls.isNotEmpty) ||
                (_sheets.isEmpty && _rolls.isNotEmpty)
            ? PrintRoad.thermal
            : PrintRoad.normal;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() => _error = errorMessageFor(error,
          fallback: context.l10n.printingActionsLoadPapersFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _say(String message, {bool error = false}) {
    if (!mounted) return;
    setState(() {
      _note = message;
      _noteIsError = error;
    });
  }

  Future<Uint8List> _pdf(String paper) async {
    final bytes = await (widget.loadPdf ??
        (String p) =>
            DocumentsApi.pdf(type: widget.type, id: widget.id, paper: p))(paper);
    if (bytes.isEmpty) throw const _EmptyPdf();
    return bytes;
  }

  /// Remember the road (and sheet size) for this kind of paper.
  Future<void> _remember() async {
    final s = await _store.read();
    final next = s.copyWith(
      roads: {...s.roads, widget.type: _road},
      normalPapers: _sheet == null
          ? s.normalPapers
          : {...s.normalPapers, widget.type: _sheet!},
    );
    await _store.write(next);
    _settings = next;
  }

  /// Fetch, hand off, say what happened. Every failure is a Bengali sentence.
  Future<void> _run(
      String? paper, Future<String?> Function(Uint8List pdf) action) async {
    if (paper == null || _busy) return;
    final words = context.l10n;
    setState(() {
      _busy = true;
      _note = null;
    });
    Uint8List pdf;
    try {
      pdf = await _pdf(paper);
    } catch (error) {
      _say(
          errorMessageFor(error,
              fallback: words.printingActionsFetchFailed),
          error: true);
      if (mounted) setState(() => _busy = false);
      return;
    }
    try {
      final note = await action(pdf);
      if (note != null) _say(note);
    } on BluetoothPrintingUnavailable catch (error) {
      _say(error.message, error: true);
    } catch (error) {
      _say(errorMessageFor(error, fallback: words.printingActionsFailed),
          error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String? get _currentPaper => _road == PrintRoad.thermal ? _roll : _sheet;

  Future<void> _view() => _run(_currentPaper, (pdf) async {
        if (!mounted) return null;
        await widget.delivery.view(context, pdf: pdf, title: widget.title);
        return null;
      });

  Future<void> _printNormal() => _run(_sheet, (pdf) async {
        await _remember();
        // False = the person closed the dialog on purpose; not an error.
        await widget.delivery.system(pdf: pdf, documentName: widget.title);
        return null;
      });

  Future<void> _share() => _run(_currentPaper, (pdf) async {
        await widget.delivery
            .share(pdf: pdf, fileName: _fileName, subject: widget.title);
        return null;
      });

  Future<void> _save() {
    final words = context.l10n;
    return _run(_currentPaper, (pdf) async {
      final path = await widget.delivery.save(pdf: pdf, fileName: _fileName);
      return words.printingActionsSaved(path);
    });
  }

  /// এই শিট বন্ধ করে "বার্তায় পাঠান" শিট — নেভিগেটরের নিজের context-এ, কারণ এই শিটের context বন্ধ হলে আর থাকে না
  void _sendInMessages() {
    final nav = Navigator.of(context);
    final paper = paperRefFor(type: widget.type, id: widget.id, title: widget.title, number: widget.fileStem);
    nav.pop();
    showSendPaperSheet(nav.context, paper);
  }

  /// Chooses (and remembers) a printer when none is set yet.
  Future<bool> _ensurePrinter() async {
    if (_settings.hasPrinter) return true;
    setState(() {
      _busy = true;
      _note = null;
    });
    List<BluetoothPrinter> printers;
    try {
      printers = await ThermalFlow.list(_thermal);
    } on BluetoothPrintingUnavailable catch (error) {
      _say(error.message, error: true);
      return false;
    } finally {
      // Not busy while the list is open: the person is the one deciding.
      if (mounted) setState(() => _busy = false);
    }
    if (!mounted) return false;
    final printer = await ThermalFlow.choose(context, printers);
    if (printer == null) return false;
    final next = (await _store.read())
        .copyWith(printerAddress: printer.address, printerName: printer.name);
    await _store.write(next);
    if (mounted) setState(() => _settings = next);
    return true;
  }

  Future<void> _printThermal() async {
    if (_busy) return;
    final words = context.l10n;
    if (!await ThermalFlow.explainOnce(context, _store)) return;
    if (!mounted || !await _ensurePrinter()) return;
    final roll = _roll;
    await _run(roll, (pdf) async {
      await _remember();
      await _thermal.printPdf(
        pdf: pdf,
        address: _settings.printerAddress!,
        paperWidthMm: thermalWidthMm(roll!),
      );
      return words.printingActionsSentTo(_settings.printerLabel);
    });
  }

  Future<void> _setWidth(int mm) async {
    final next = (await _store.read()).copyWith(widthMm: mm);
    await _store.write(next);
    if (mounted) setState(() => _settings = next);
  }

  Future<void> _openSettings() async {
    await Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => PrinterSettingsScreen(store: _store, thermal: _thermal),
    ));
    final s = await _store.read();
    if (mounted) setState(() => _settings = s);
  }

  @override
  Widget build(BuildContext context) {
    final papers = _papers;
    final words = context.l10n;

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(widget.title,
                style: const TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: AppSpacing.md),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) ...[
              Text(_error!, style: TextStyle(color: context.palette.danger)),
              const SizedBox(height: AppSpacing.sm),
              TextButton(onPressed: _load, child: Text(words.printingActionsRetry)),
            ] else if (papers != null) ...[
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(
                  child: _RoadCard(
                    key: const Key('road-normal'),
                    icon: Icons.print_outlined,
                    title: words.printingActionsNormalPrinter,
                    subtitle: _sheets.isEmpty
                        ? words.printingActionsNoSheetLayout
                        : words.printingActionsNormalSubtitle,
                    selected: _road == PrintRoad.normal,
                    enabled: _sheets.isNotEmpty,
                    onTap: () => setState(() {
                      _road = PrintRoad.normal;
                      _note = null;
                    }),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: _RoadCard(
                    key: const Key('road-thermal'),
                    icon: Icons.receipt_long_outlined,
                    title: words.printingActionsThermalPrinter,
                    subtitle: _rolls.isEmpty
                        ? words.printingActionsNoReceiptLayout
                        : words.printingActionsThermalSubtitle(bnDigits(58), bnDigits(80)),
                    selected: _road == PrintRoad.thermal,
                    enabled: _rolls.isNotEmpty,
                    onTap: () => setState(() {
                      _road = PrintRoad.thermal;
                      _note = null;
                    }),
                  ),
                ),
              ]),
              const SizedBox(height: AppSpacing.md),
              if (_road == PrintRoad.normal)
                ..._normalPart()
              else
                ..._thermalPart(),
              if (_note != null) ...[
                const SizedBox(height: AppSpacing.sm),
                Text(
                  _note!,
                  key: const Key('print-note'),
                  style: TextStyle(
                      color:
                          _noteIsError ? context.palette.danger : context.palette.success),
                ),
              ],
              const Divider(),
              ListTile(
                key: const Key('document-view'),
                leading: const Icon(Icons.visibility_outlined),
                title: Text(words.printingActionsView),
                onTap: _busy || _currentPaper == null ? null : _view,
              ),
              ListTile(
                key: const Key('document-share'),
                leading: const Icon(Icons.share_outlined),
                title: Text(words.printingActionsShare),
                subtitle: Text(words.printingActionsShareHint),
                onTap: _busy || _currentPaper == null ? null : _share,
              ),
              ListTile(
                key: const Key('document-save'),
                leading: const Icon(Icons.download_outlined),
                title: Text(words.printingActionsSave),
                onTap: _busy || _currentPaper == null ? null : _save,
              ),
            ],
            // ⭐ বার্তায় পাঠান — কাগজটা মানুষ বা গ্রুপকে বার্তায় (নমুনা 3nuwRUdn v5 "গ" ১); PDF লাগে না, তাই সবসময়।
            // ⓘ রিপোর্ট কাগজ নয় — তার জন্য নেই; বার্তা চালু না থাকলে (`features.messaging`) নেই
            if (widget.type != 'report' && messagingOn(context))
              ListTile(
                key: const Key('document-send-chat'),
                leading: const Icon(Icons.chat_outlined),
                title: Text(words.msgListSendPaper),
                onTap: _sendInMessages,
              ),
          ],
        ),
      ),
    );
  }

  List<Widget> _normalPart() => [
        Wrap(spacing: AppSpacing.sm, children: [
          for (final option in _sheets)
            ChoiceChip(
              label: Text(paperLabel(option)),
              selected: option == _sheet,
              onSelected:
                  _busy ? null : (_) => setState(() => _sheet = option),
            ),
        ]),
        const SizedBox(height: AppSpacing.sm),
        SizedBox(
          width: double.infinity,
          child: FilledButton.icon(
            key: const Key('print-normal'),
            onPressed: _busy || _sheet == null ? null : _printNormal,
            icon: const Icon(Icons.print),
            label: Text(context.l10n.printingActionsPrint),
          ),
        ),
      ];

  List<Widget> _thermalPart() => [
        ListTile(
          key: const Key('thermal-printer'),
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.bluetooth),
          title: Text(_settings.hasPrinter
              ? _settings.printerLabel
              : context.l10n.printingActionsNoPrinter),
          subtitle: Text(_settings.hasPrinter
              ? context.l10n.printingActionsConnectsWhenPrinting
              : context.l10n.printingActionsChooseOnFirstPrint),
          trailing: TextButton(
            key: const Key('thermal-settings'),
            onPressed: _busy ? null : _openSettings,
            child: Text(context.l10n.printingActionsSettings),
          ),
        ),
        Wrap(spacing: AppSpacing.sm, children: [
          for (final option in _rolls)
            ChoiceChip(
              label: Text(paperLabel(option)),
              selected: option == _roll,
              onSelected:
                  _busy ? null : (_) => _setWidth(thermalWidthMm(option)),
            ),
        ]),
        const SizedBox(height: AppSpacing.sm),
        SizedBox(
          width: double.infinity,
          child: FilledButton.icon(
            key: const Key('print-thermal'),
            onPressed: _busy || _roll == null ? null : _printThermal,
            icon: const Icon(Icons.print),
            label: Text(context.l10n.printingActionsPrintThermal),
          ),
        ),
      ];
}

class _EmptyPdf implements Exception {
  const _EmptyPdf();
}

class _RoadCard extends StatelessWidget {
  const _RoadCard({
    super.key,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.selected,
    required this.enabled,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final bool selected;
  final bool enabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = selected ? AppColors.primary : context.palette.onSurfaceMuted;
    return Opacity(
      opacity: enabled ? 1 : 0.45,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: enabled ? onTap : null,
        child: Container(
          padding: const EdgeInsets.all(AppSpacing.sm),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: color, width: selected ? 2 : 1),
          ),
          child: Column(children: [
            Icon(icon, color: color),
            const SizedBox(height: 4),
            Text(title,
                textAlign: TextAlign.center,
                style: TextStyle(fontWeight: FontWeight.w700, color: color)),
            Text(subtitle,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 12)),
          ]),
        ),
      ),
    );
  }
}

/// The ways a document leaves the phone other than Bluetooth, behind one
/// object so a test can stand in for all of them.
class DocumentDeliveryPorts {
  const DocumentDeliveryPorts({
    this.systemPrint,
    this.sharePdf,
    this.savePdf,
    this.viewPdf,
  });

  /// পর্দায় দেখা — পরীক্ষায় নকল; না দিলে [PdfViewPage]
  final Future<void> Function(BuildContext context,
      {required Uint8List pdf, required String title})? viewPdf;

  Future<void> view(BuildContext context,
          {required Uint8List pdf, required String title}) =>
      (viewPdf ?? PdfViewPage.open)(context, pdf: pdf, title: title);

  final Future<bool> Function(
      {required Uint8List pdf, required String documentName})? systemPrint;
  final Future<void> Function(
      {required Uint8List pdf,
      required String fileName,
      String? subject})? sharePdf;
  final Future<String> Function(
      {required Uint8List pdf, required String fileName})? savePdf;

  Future<bool> system({required Uint8List pdf, required String documentName}) =>
      (systemPrint ?? DocumentDelivery.toSystemPrinter)(
          pdf: pdf, documentName: documentName);

  Future<void> share(
          {required Uint8List pdf, required String fileName, String? subject}) =>
      (sharePdf ?? DocumentDelivery.share)(
          pdf: pdf, fileName: fileName, subject: subject);

  Future<String> save({required Uint8List pdf, required String fileName}) =>
      (savePdf ?? DocumentDelivery.save)(pdf: pdf, fileName: fileName);
}
