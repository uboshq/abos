import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/printing/document_delivery.dart';
import '../../core/printing/documents_api.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// "প্রিন্ট / শেয়ার" for one document, from anywhere in the app.
///
/// <p>One sheet rather than a print button on each screen: every document in
/// ABOS is printed the same four ways, and a second implementation is a
/// second thing to keep in step.
///
/// <p><b>The paper comes first, and the list is the server's.</b> Not every
/// template has a thermal version, so the choices are fetched rather than
/// hardcoded — a phone offering "রসিদ ৫৮mm" for a ledger would produce a
/// metre of unreadable paper and look like a bug in the printer.
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
  });

  /// `sales.invoice`, `purchase.bill`, `accounts.voucher` … the server's own
  /// naming.
  final String type;
  final String id;

  /// What the person is looking at — "ক্রয় বিল · PB-2609-0007".
  final String title;

  /// The file's name without extension. A document shared as `document.pdf`
  /// tells nobody anything; a shopkeeper sent three of those cannot tell
  /// which bill is which.
  final String? fileStem;

  final Future<List<String>> Function()? loadPapers;
  final Future<Uint8List> Function(String paper)? loadPdf;

  /// The four ways out, injectable so this sheet can be driven in a test —
  /// printing and Bluetooth both need platform channels that have no native
  /// side under `flutter test`.
  final DocumentDeliveryPorts delivery;

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
  String? _paper;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _loadPapers();
  }

  String get _fileName => '${widget.fileStem ?? widget.id}.pdf';

  Future<void> _loadPapers() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final papers = await (widget.loadPapers ??
          () => DocumentsApi.papersFor(type: widget.type, id: widget.id))();
      if (!mounted) return;
      setState(() {
        _papers = papers;
        // A4 unless the template has none — the ordinary case is a sheet, and
        // a receipt roll chosen by default would surprise someone at a desk.
        _paper = papers.contains('a4')
            ? 'a4'
            : (papers.isEmpty ? null : papers.first);
      });
    } catch (error) {
      if (!mounted) return;
      setState(() => _error = errorMessageFor(error,
          fallback: 'কাগজের মাপ আনা গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<Uint8List> _pdf(String paper) =>
      (widget.loadPdf ??
          (String p) =>
              DocumentsApi.pdf(type: widget.type, id: widget.id, paper: p))(
        paper,
      );

  /// Every action shares this: fetch, then hand off, then say what happened.
  /// A failure anywhere is shown as a sentence rather than leaving the sheet
  /// looking like it worked.
  Future<void> _run(Future<String?> Function(Uint8List pdf) action) async {
    final paper = _paper;
    if (paper == null) return;

    setState(() => _busy = true);
    try {
      final pdf = await _pdf(paper);
      final note = await action(pdf);
      if (!mounted) return;
      if (note != null) _say(note);
    } on BluetoothPrintingUnavailable catch (error) {
      if (mounted) _say(error.message);
    } catch (error) {
      if (mounted) {
        _say(errorMessageFor(error, fallback: 'কাজটি সম্পন্ন হয়নি।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _say(String message) => ScaffoldMessenger.of(context)
      .showSnackBar(SnackBar(content: Text(message)));

  Future<void> _printToSystem() => _run((pdf) async {
        // False means the person closed the dialog. Not an error, and saying
        // "প্রিন্ট হয়নি" for a deliberate cancel teaches people to distrust
        // the message that matters.
        await widget.delivery.system(pdf: pdf, documentName: widget.title);
        return null;
      });

  Future<void> _share() => _run((pdf) async {
        await widget.delivery.share(
          pdf: pdf,
          fileName: _fileName,
          subject: widget.title,
        );
        return null;
      });

  Future<void> _save() => _run((pdf) async {
        final path = await widget.delivery.save(pdf: pdf, fileName: _fileName);
        return 'সেভ হয়েছে: $path';
      });

  Future<void> _printToBluetooth() async {
    final paper = _paper;
    if (paper == null) return;

    setState(() => _busy = true);
    List<BluetoothPrinter> printers;
    try {
      printers = await widget.delivery.paired();
    } on BluetoothPrintingUnavailable catch (error) {
      if (mounted) _say(error.message);
      if (mounted) setState(() => _busy = false);
      return;
    } finally {
      if (mounted) setState(() => _busy = false);
    }

    if (!mounted) return;
    if (printers.isEmpty) {
      // Pairing lives in Android's own settings on purpose — see
      // DocumentDelivery.pairedPrinters. Saying where to go is more use than
      // a scan screen that duplicates it.
      _say('কোনো ব্লুটুথ প্রিন্টার জোড়া দেওয়া নেই। ফোনের সেটিংস থেকে '
          'প্রিন্টারটি একবার জোড়া দিন।');
      return;
    }

    final printer = printers.length == 1
        ? printers.single
        : await showDialog<BluetoothPrinter>(
            context: context,
            builder: (context) => SimpleDialog(
              title: const Text('প্রিন্টার বাছুন'),
              children: [
                for (final p in printers)
                  SimpleDialogOption(
                    onPressed: () => Navigator.of(context).pop(p),
                    child: Text(p.name.isEmpty ? p.address : p.name),
                  ),
              ],
            ),
          );

    if (printer == null || !mounted) return;

    await _run((pdf) async {
      await widget.delivery.bluetooth(
        pdf: pdf,
        printer: printer,
        paperWidthMm: thermalWidthMm(paper),
      );
      return 'প্রিন্টারে পাঠানো হয়েছে।';
    });
  }

  @override
  Widget build(BuildContext context) {
    final papers = _papers;
    final paper = _paper;

    return SafeArea(
      child: Padding(
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
              Text(_error!, style: const TextStyle(color: AppColors.danger)),
              const SizedBox(height: AppSpacing.sm),
              TextButton(
                  onPressed: _loadPapers, child: const Text('আবার চেষ্টা')),
            ] else if (papers != null) ...[
              Wrap(
                spacing: AppSpacing.sm,
                children: [
                  for (final option in papers)
                    ChoiceChip(
                      label: Text(paperLabel(option)),
                      selected: option == paper,
                      onSelected: _busy
                          ? null
                          : (_) => setState(() => _paper = option),
                    ),
                ],
              ),
              const SizedBox(height: AppSpacing.md),
              ListTile(
                leading: const Icon(Icons.print_outlined),
                title: const Text('প্রিন্ট করুন'),
                subtitle: const Text('ওয়াইফাই, USB, বা ফোনের প্রিন্ট তালিকা'),
                onTap: _busy || paper == null ? null : _printToSystem,
              ),
              // Only for a receipt roll. Offering a Bluetooth receipt printer
              // for an A4 invoice would hand somebody a metre of paper.
              if (paper != null && isThermalPaper(paper))
                ListTile(
                  leading: const Icon(Icons.bluetooth),
                  title: const Text('ব্লুটুথ রসিদ প্রিন্টার'),
                  subtitle: const Text('দোকানের থার্মাল প্রিন্টার'),
                  onTap: _busy ? null : _printToBluetooth,
                ),
              ListTile(
                leading: const Icon(Icons.share_outlined),
                title: const Text('শেয়ার করুন'),
                subtitle: const Text('হোয়াটসঅ্যাপ, ইমেইল, ড্রাইভ'),
                onTap: _busy || paper == null ? null : _share,
              ),
              ListTile(
                leading: const Icon(Icons.download_outlined),
                title: const Text('ফোনে সেভ করুন'),
                onTap: _busy || paper == null ? null : _save,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// The four ways a document leaves the phone, behind one object so a test can
/// stand in for all of them — every one of them is a platform channel with no
/// native side under `flutter test`.
class DocumentDeliveryPorts {
  const DocumentDeliveryPorts({
    this.systemPrint,
    this.sharePdf,
    this.savePdf,
    this.pairedPrinters,
    this.bluetoothPrint,
  });

  final Future<bool> Function({required Uint8List pdf, required String documentName})?
      systemPrint;
  final Future<void> Function(
      {required Uint8List pdf, required String fileName, String? subject})? sharePdf;
  final Future<String> Function({required Uint8List pdf, required String fileName})?
      savePdf;
  final Future<List<BluetoothPrinter>> Function()? pairedPrinters;
  final Future<void> Function(
      {required Uint8List pdf,
      required BluetoothPrinter printer,
      int paperWidthMm})? bluetoothPrint;

  Future<bool> system({required Uint8List pdf, required String documentName}) =>
      (systemPrint ?? DocumentDelivery.toSystemPrinter)(
          pdf: pdf, documentName: documentName);

  Future<void> share(
          {required Uint8List pdf, required String fileName, String? subject}) =>
      (sharePdf ?? DocumentDelivery.share)(
          pdf: pdf, fileName: fileName, subject: subject);

  Future<String> save({required Uint8List pdf, required String fileName}) =>
      (savePdf ?? DocumentDelivery.save)(pdf: pdf, fileName: fileName);

  Future<List<BluetoothPrinter>> paired() =>
      (pairedPrinters ?? DocumentDelivery.pairedPrinters)();

  Future<void> bluetooth({
    required Uint8List pdf,
    required BluetoothPrinter printer,
    int paperWidthMm = 58,
  }) =>
      (bluetoothPrint ?? DocumentDelivery.toBluetoothPrinter)(
          pdf: pdf, printer: printer, paperWidthMm: paperWidthMm);
}
