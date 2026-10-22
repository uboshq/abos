import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/locale/l10n.dart';
import '../../core/orders/paper_scan_api.dart';
import '../../core/permissions/permission_rationale.dart';
import 'receive_screen.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// কাগজের QR স্ক্যান — গেটম্যান "মাল বেরোল", ডেলিভারিম্যান "ডেলিভারি নিশ্চিত" (0.4.3)।
///
/// <p>ফোন নিজে কিছু ঠিক করে না: কোন বোতাম দেখাবে, বাকির সীমা, গাড়ি বসানো আছে কিনা —
/// সবই সার্ভার বলে (`actions`), আর সার্ভারই ফেরায়। এখানে কেবল দেখানো আর জিজ্ঞেস করা।
///
/// <p>⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস, কোনো টেবিল নয় — ছোট পর্দার ডান পাশ কাটা পড়ে।
class PaperScanScreen extends StatefulWidget {
  const PaperScanScreen({super.key, this.api = const ServerPaperScanApi(), this.isGranted});

  final PaperScanApi api;

  /// পরীক্ষার পথ — ক্যামেরার অনুমতি আগে থেকে আছে কিনা ([PermissionCheck.granted])।
  final Future<bool?> Function(AppPermission)? isGranted;

  @override
  State<PaperScanScreen> createState() => _PaperScanScreenState();
}

class _PaperScanScreenState extends State<PaperScanScreen> {
  final MobileScannerController _camera = MobileScannerController(
    formats: const [BarcodeFormat.qrCode],
    detectionSpeed: DetectionSpeed.noDuplicates,
  );

  String? _token;
  ScannedPaper? _paper;
  String? _error;
  bool _busy = false;

  /// ⭐ ক্যামেরা খোলে কেবল বাংলা ব্যাখ্যার পরে — MobileScanner আঁকা হলেই Android-এর প্রশ্ন আসে, তাই তার আগে
  /// ([PermissionRationale], ২০ অক্টোবর ২০২৬)। "এখন না" বললে পর্দা বন্ধ।
  bool _cameraAllowed = false;
  bool _cameraDeclined = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _askCamera());
  }

  Future<void> _askCamera() async {
    if (!mounted) return;
    final yes = await PermissionRationale.explainBeforeAsking(context, AppPermission.camera,
        isGranted: widget.isGranted);
    if (!mounted) return;
    setState(() {
      _cameraAllowed = yes;
      _cameraDeclined = !yes;
    });
  }

  @override
  void dispose() {
    _camera.dispose();
    super.dispose();
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_busy || _token != null) return;
    final raw =
        capture.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
    if (raw == null) return;

    final token = PaperToken.from(raw);
    if (token == null) {
      setState(() => _error = PaperToken.isOldPaper(raw)
          ? context.l10n.scanPaperOldPaper
          : context.l10n.scanPaperNotOurs);
      return;
    }
    await _camera.stop();
    setState(() => _token = token);
    await _run(() => widget.api.open(token));
  }

  Future<void> _run(Future<ScannedPaper> Function() call) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final paper = await call();
      if (mounted) setState(() => _paper = paper);
    } catch (e) {
      if (!mounted) return;
      setState(() => _error = errorMessageFor(
            e,
            fallback: _fallbackFor(e),
            whenAbsent: _paper == null
                ? context.l10n.scanPaperNotFound
                : null,
          ));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _fallbackFor(Object e) {
    final words = context.l10n;
    return switch (_statusOf(e)) {
      403 => words.scanPaperForbidden,
      409 => words.scanPaperAlreadyOut,
      422 => words.scanPaperBlocked,
      _ => words.scanPaperFailed,
    };
  }

  int _statusOf(Object e) {
    try {
      return ((e as dynamic).response?.statusCode as int?) ?? 0;
    } catch (_) {
      return 0;
    }
  }

  Future<void> _gateOut() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(context.l10n.scanPaperGateOutTitle),
        content: Text(context.l10n.scanPaperGateOutBody),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: Text(context.l10n.scanPaperNo)),
          FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: Text(context.l10n.scanPaperGateOutYes)),
        ],
      ),
    );
    if (ok == true) await _run(() => widget.api.gateOut(_token!));
  }

  /// ⭐ "বুঝিয়ে দিন" — নাম, ফোন আর প্রতিটা সারির নেওয়া ও ভাঙা (ধাপ ৭, ৬ অক্টোবর ২০২৬; [[ReceiveScreen]])
  Future<void> _deliver() async {
    final paper = _paper;
    if (paper == null) return;
    final result =
        await Navigator.of(context).push<ReceiveResult>(MaterialPageRoute(
      builder: (_) => ReceiveScreen(
          documentNo: paper.documentNo,
          customer: paper.customer,
          lines: paper.lines),
    ));
    if (result == null) return;
    await _run(() => widget.api.deliver(_token!,
        receiver: result.receiver,
        phone: result.phone,
        lines: paper.lines,
        taken: result.taken,
        damaged: result.damaged));
  }

  Future<void> _again() async {
    setState(() {
      _token = null;
      _paper = null;
      _error = null;
    });
    await _camera.start();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.scanPaperTitle),
      body: _token != null
          ? _sheet(context)
          : _cameraAllowed
              ? _scanner()
              : _cameraDeclined
                  ? _noCamera()
                  : const SizedBox.shrink(),
    );
  }

  Widget _noCamera() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(context.l10n.scanPaperNoCamera, textAlign: TextAlign.center),
          const SizedBox(height: AppSpacing.md),
          FilledButton(
              key: const Key('scan-ask-camera'),
              onPressed: _askCamera,
              child: Text(context.l10n.scanPaperAskCamera)),
        ]),
      ),
    );
  }

  Widget _scanner() {
    return Stack(
      children: [
        MobileScanner(controller: _camera, onDetect: _onDetect),
        Align(
          alignment: Alignment.bottomCenter,
          child: Container(
            width: double.infinity,
            color: Colors.black54,
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Text(
              _error ?? context.l10n.scanPaperHint,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white, fontSize: 15), // dark-ok
            ),
          ),
        ),
      ],
    );
  }

  Widget _sheet(BuildContext context) {
    final paper = _paper;
    final words = context.l10n;
    const bold = TextStyle(fontWeight: FontWeight.w700);
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.md),
      children: [
        if (_busy) const LinearProgressIndicator(),
        if (_error != null)
          Card(
            color: context.palette.dangerSurface,
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Text(_error!,
                  style: TextStyle(color: context.palette.danger)),
            ),
          ),
        if (paper != null) ...[
          Text(paper.documentNo, style: Theme.of(context).textTheme.titleLarge),
          if (paper.saleNo != null) Text(words.scanPaperSaleNo('${paper.saleNo}')),
          if (paper.date != null) Text(words.scanPaperDate('${paper.date}')),
          Text(paper.customer, style: bold),
          const SizedBox(height: AppSpacing.md),
          Text(words.scanPaperStatus(_stageLabel(words, paper.stage)), style: bold),
          if (paper.outAt != null)
            Text(
                '${words.scanPaperOutAt(DateFormat('dd/MM/yyyy HH:mm').format(paper.outAt!))}'
                '${paper.outBy == null ? '' : ' · ${paper.outBy}'}'),
          const Divider(),
          for (final line in paper.lines) ...[
            Text(line.product, style: bold),
            Text(words.scanPaperQty(Money.plain(line.qty))),
            if (line.freeQty > 0) Text(words.scanPaperFreeQty(Money.plain(line.freeQty))),
            if (line.lot != null) Text(words.scanPaperLot('${line.lot}')),
            const SizedBox(height: AppSpacing.sm),
          ],
          const Divider(),
          Text(paper.transportNamed ? words.scanPaperVehicleSet : words.scanPaperVehicleMissing,
              style: TextStyle(
                  color: paper.transportNamed
                      ? context.palette.success
                      : context.palette.warning)),
          if (paper.vehicle != null) Text(words.scanPaperVehicle('${paper.vehicle}')),
          if (paper.driver != null) Text(words.scanPaperDriver('${paper.driver}')),
          if (paper.billTotal != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(words.scanPaperBillTotal(Money.taka(paper.billTotal)), style: bold),
          ],
          const SizedBox(height: AppSpacing.lg),
          if (paper.canGateOut)
            FilledButton.icon(
              onPressed: _busy ? null : _gateOut,
              icon: const Icon(Icons.local_shipping_outlined),
              label: Text(words.scanPaperGateOut),
            ),
          if (paper.canDeliver)
            FilledButton.icon(
              onPressed: _busy ? null : _deliver,
              icon: const Icon(Icons.check_circle_outline),
              label: Text(words.scanPaperConfirmDelivery),
            ),
        ],
        const SizedBox(height: AppSpacing.md),
        OutlinedButton.icon(
          onPressed: _busy ? null : _again,
          icon: const Icon(Icons.qr_code_scanner),
          label: Text(words.scanPaperScanAgain),
        ),
      ],
    );
  }

  static String _stageLabel(AppL10n words, String stage) => switch (stage) {
        'allocated' => words.scanPaperStageAllocated,
        'picking' => words.scanPaperStagePicking,
        'packed' => words.scanPaperStagePacked,
        'dispatched' => words.scanPaperStageDispatched,
        'delivered' => words.scanPaperStageDelivered,
        _ => stage,
      };
}
