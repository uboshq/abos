import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/records/report_record.dart';
import '../../core/widgets/empty_state.dart';
import 'reports_screen.dart';

/// ⭐ একটা রিপোর্ট, চাবি ধরে — মেনুর রিপোর্ট-সারি থেকে ([ReportPaths]; fe, ১৯ অক্টোবর ২০২৬)।
///
/// <p>রিপোর্টের নাম আর ছাঁকনির ঘোষণা আসে সার্ভারের তালিকা থেকে (`GET /reports`), তারপর [ReportViewScreen] — তারিখের ঘর আর বাকি
/// ছাঁকনি সেই ঘোষণা ধরেই। ⓘ তালিকায় চাবিটা না থাকলে (চাবি নেই, বা মডিউল ফোনে বন্ধ) সোজা কথা — ওয়েব-জানালায় পাঠানো নয়,
/// কারণ সেখানেও একই দেয়াল।
class ReportByKeyScreen extends StatefulWidget {
  const ReportByKeyScreen({super.key, required this.reportKey, this.title = '', this.loadList, this.view});

  final String reportKey;

  /// মেনুর নাম — তালিকা আসার আগে মাথায়
  final String title;

  /// Injected in tests.
  final Future<List<ReportSummary>> Function()? loadList;

  /// Injected in tests — the report screen built for the summary found.
  final Widget Function(ReportSummary summary)? view;

  @override
  State<ReportByKeyScreen> createState() => _ReportByKeyScreenState();
}

class _ReportByKeyScreenState extends State<ReportByKeyScreen> {
  ReportSummary? _summary;
  bool _missing = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _error = null;
      _missing = false;
    });
    try {
      final list = await (widget.loadList ?? ReportsApi.list)();
      final found = list.where((r) => r.key == widget.reportKey).firstOrNull;
      if (!mounted) return;
      setState(() {
        _summary = found;
        _missing = found == null;
      });
    } catch (error) {
      if (mounted) {
        setState(() => _error = errorMessageFor(error, fallback: 'রিপোর্ট আনা গেল না।'));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final summary = _summary;
    if (summary != null) {
      return (widget.view ?? (s) => ReportViewScreen(report: s))(summary);
    }

    return Scaffold(
      appBar: AppBar(title: Text(widget.title.isEmpty ? 'রিপোর্ট' : widget.title)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          children: [
            if (_error != null)
              EmptyState(
                key: const ValueKey('report-by-key-error'),
                icon: Icons.cloud_off_outlined,
                title: 'রিপোর্ট আনা গেল না',
                message: _error,
              )
            else if (_missing)
              const EmptyState(
                key: ValueKey('report-by-key-missing'),
                icon: Icons.lock_outline,
                title: 'এই রিপোর্ট আপনার জন্য খোলা নেই',
                message: 'রিপোর্টের চাবি নেই, অথবা এই মডিউল ফোনে বন্ধ। দরকার হলে অফিসে চাবি চেয়ে নিন।',
              )
            else
              const Padding(
                padding: EdgeInsets.all(32),
                child: Center(child: CircularProgressIndicator()),
              ),
          ],
        ),
      ),
    );
  }
}
