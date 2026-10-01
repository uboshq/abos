import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/orders/paper_scan_api.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// কাগজের QR স্ক্যান — গেটম্যান "মাল বেরোল", ডেলিভারিম্যান "ডেলিভারি নিশ্চিত" (0.4.3)।
///
/// <p>ফোন নিজে কিছু ঠিক করে না: কোন বোতাম দেখাবে, বাকির সীমা, গাড়ি বসানো আছে কিনা —
/// সবই সার্ভার বলে (`actions`), আর সার্ভারই ফেরায়। এখানে কেবল দেখানো আর জিজ্ঞেস করা।
///
/// <p>⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস, কোনো টেবিল নয় — ছোট পর্দার ডান পাশ কাটা পড়ে।
class PaperScanScreen extends StatefulWidget {
  const PaperScanScreen({super.key, this.api = const ServerPaperScanApi()});

  final PaperScanApi api;

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

  @override
  void dispose() {
    _camera.dispose();
    super.dispose();
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_busy || _token != null) return;
    final raw = capture.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
    if (raw == null) return;

    final token = PaperToken.from(raw);
    if (token == null) {
      setState(() => _error = PaperToken.isOldPaper(raw)
          ? 'এটা পুরনো ছাপা কাগজ। ফোনে খোলে না — ওয়েবে খুলুন, বা কাগজটা আবার ছাপুন।'
          : 'এটা ABOS-এর কাগজের QR নয়।');
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
                ? 'কাগজটা পাওয়া যায়নি — বাতিল হয়েছে, বা আপনার শাখার নয়।'
                : null,
          ));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _fallbackFor(Object e) {
    return switch (_statusOf(e)) {
      403 => 'এই কাগজ দেখার বা বদলানোর অনুমতি আপনার নেই।',
      409 => 'মাল আগেই বেরিয়ে গেছে।',
      422 => 'হয়নি — গাড়ির তথ্য বসানো নেই, বা দোকানের বাকির সীমা পার। অফিসে বলুন।',
      _ => 'হয়নি। আবার চেষ্টা করুন।',
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
        title: const Text('মাল বেরোল?'),
        content: const Text('গেট পাস হবে আর বিল কাটা হবে। এটা ফেরানো যায় না।'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('না')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('হ্যাঁ, বেরোল')),
        ],
      ),
    );
    if (ok == true) await _run(() => widget.api.gateOut(_token!));
  }

  Future<void> _deliver() async {
    final name = TextEditingController();
    final phone = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('ডেলিভারি নিশ্চিত'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(controller: name, decoration: const InputDecoration(labelText: 'যিনি মাল নিলেন')),
            TextField(
              controller: phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'তাঁর ফোন (ইচ্ছা হলে)'),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('বাদ')),
          FilledButton(
            onPressed: () => Navigator.pop(context, name.text.trim().isNotEmpty),
            child: const Text('নিশ্চিত'),
          ),
        ],
      ),
    );
    if (ok == true) {
      await _run(() => widget.api.deliver(_token!, receiver: name.text.trim(), phone: phone.text.trim()));
    }
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
      appBar: AppBar(title: const Text('কাগজ স্ক্যান')),
      body: _token == null ? _scanner() : _sheet(context),
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
              _error ?? 'চালান বা গেট পাসের QR ক্যামেরার সামনে ধরুন।',
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white, fontSize: 15),
            ),
          ),
        ),
      ],
    );
  }

  Widget _sheet(BuildContext context) {
    final paper = _paper;
    const bold = TextStyle(fontWeight: FontWeight.w700);
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.md),
      children: [
        if (_busy) const LinearProgressIndicator(),
        if (_error != null)
          Card(
            color: AppColors.dangerSurface,
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Text(_error!, style: const TextStyle(color: AppColors.danger)),
            ),
          ),
        if (paper != null) ...[
          Text(paper.documentNo, style: Theme.of(context).textTheme.titleLarge),
          if (paper.saleNo != null) Text('বিক্রয় নং ${paper.saleNo}'),
          if (paper.date != null) Text('তারিখ ${paper.date}'),
          Text(paper.customer, style: bold),
          const SizedBox(height: AppSpacing.md),
          Text('অবস্থা: ${_stageLabel(paper.stage)}', style: bold),
          if (paper.outAt != null)
            Text('বেরিয়েছে ${DateFormat('dd/MM/yyyy HH:mm').format(paper.outAt!)}'
                '${paper.outBy == null ? '' : ' · ${paper.outBy}'}'),
          const Divider(),
          for (final line in paper.lines) ...[
            Text(line.product, style: bold),
            Text('পরিমাণ ${Money.plain(line.qty)}'),
            if (line.freeQty > 0) Text('ফ্রি ${Money.plain(line.freeQty)}'),
            if (line.lot != null) Text('লট ${line.lot}'),
            const SizedBox(height: AppSpacing.sm),
          ],
          const Divider(),
          Text(paper.transportNamed ? 'গাড়ি বসানো আছে' : '⚠ গাড়ি বসানো নেই',
              style: TextStyle(color: paper.transportNamed ? AppColors.success : AppColors.warning)),
          if (paper.vehicle != null) Text('গাড়ি ${paper.vehicle}'),
          if (paper.driver != null) Text('চালক ${paper.driver}'),
          if (paper.billTotal != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text('বিলের মোট ${Money.taka(paper.billTotal)}', style: bold),
          ],
          const SizedBox(height: AppSpacing.lg),
          if (paper.canGateOut)
            FilledButton.icon(
              onPressed: _busy ? null : _gateOut,
              icon: const Icon(Icons.local_shipping_outlined),
              label: const Text('মাল বেরোল'),
            ),
          if (paper.canDeliver)
            FilledButton.icon(
              onPressed: _busy ? null : _deliver,
              icon: const Icon(Icons.check_circle_outline),
              label: const Text('ডেলিভারি নিশ্চিত'),
            ),
        ],
        const SizedBox(height: AppSpacing.md),
        OutlinedButton.icon(
          onPressed: _busy ? null : _again,
          icon: const Icon(Icons.qr_code_scanner),
          label: const Text('আরেকটা স্ক্যান'),
        ),
      ],
    );
  }

  static String _stageLabel(String stage) => switch (stage) {
        'allocated' => 'বরাদ্দ হয়েছে',
        'picking' => 'মাল তোলা হচ্ছে',
        'packed' => 'প্যাক হয়েছে',
        'dispatched' => 'গেট পেরিয়েছে',
        'delivered' => 'পৌঁছেছে',
        _ => stage,
      };
}
