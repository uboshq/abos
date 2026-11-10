import 'package:flutter/material.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/sms_gateway/sms_gateway_channel.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';

/// ⭐ "SMS গেটওয়ে" — অফিসের ফোনটাকে কোম্পানির SMS পাঠানোর ফোন বানানো (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২)।
///
/// <p>প্রোফাইল থেকে খোলে, কেবল যাঁর হাতে `notification.sms_gateway` (আজ মালিক)। কন্ট্রোল প্যানেল → বার্তা → SMS-এর ফোন থেকে
/// পাওয়া চাবি বসিয়ে "চালু করুন" — তারপর ফোনটা অ্যাপ বন্ধ থাকলেও মিনিটে একবার সারি দেখে, নিজের সিমে পাঠায়, খবর দেয়।
/// ⓘ SMS পাঠানোর অনুমতি এখানেই চাওয়া হয় — অন্য কোনো ফোনে কখনো নয়।
class SmsGatewayScreen extends StatefulWidget {
  const SmsGatewayScreen({super.key, this.channel = const SmsGatewayChannel()});

  final SmsGatewayChannel channel;

  @override
  State<SmsGatewayScreen> createState() => _SmsGatewayScreenState();
}

class _SmsGatewayScreenState extends State<SmsGatewayScreen> {
  final _key = TextEditingController();
  SmsGatewayStatus _status = SmsGatewayStatus.off;
  bool _busy = false;
  String? _problem;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  @override
  void dispose() {
    _key.dispose();
    super.dispose();
  }

  Future<void> _refresh() async {
    try {
      final s = await widget.channel.status();
      if (mounted) setState(() => _status = s);
    } on Exception {
      // ⓘ পুরনো Android বা পরীক্ষা — অবস্থা "বন্ধ"-ই থাকে
    }
  }

  Future<void> _start() async {
    final l10n = context.l10n;
    if (_key.text.trim().isEmpty && !_status.hasKey) {
      setState(() => _problem = l10n.smsGatewayMissing);
      return;
    }
    setState(() {
      _busy = true;
      _problem = null;
    });
    try {
      if (!await widget.channel.requestPermission()) {
        setState(() => _problem = l10n.smsGatewayNeedPermission);
        return;
      }
      await widget.channel.start(key: _key.text);
      _key.clear();
    } on Exception {
      setState(() => _problem = l10n.smsGatewayMissing);
    } finally {
      if (mounted) setState(() => _busy = false);
      await _refresh();
    }
  }

  Future<void> _stop() async {
    setState(() => _busy = true);
    try {
      await widget.channel.stop();
    } finally {
      if (mounted) setState(() => _busy = false);
      await _refresh();
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final s = _status;
    final last = s.lastRun;

    return Scaffold(
      appBar: AbosScreenBar(title: l10n.smsGatewayTitle, module: l10n.profileModuleMe),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            ListTile(
              key: const ValueKey('sms-gateway-state'),
              leading: Icon(s.enabled ? Symbols.sms : Symbols.sms_failed, color: s.enabled ? p.success : p.onSurfaceMuted),
              title: Text(s.enabled ? l10n.smsGatewayOn : l10n.smsGatewayOff),
              subtitle: Text(l10n.smsGatewaySubtitle),
            ),
            if (s.keyRefused || _problem != null)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
                child: Text(
                  _problem ?? l10n.smsGatewayKeyRefused,
                  key: const ValueKey('sms-gateway-problem'),
                  style: TextStyle(color: p.danger, fontWeight: FontWeight.w600),
                ),
              ),
            const Divider(height: AppSpacing.lg),
            ListTile(title: Text(l10n.smsGatewaySentToday), trailing: Text('${s.sentToday}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700))),
            ListTile(
              title: Text(l10n.smsGatewayLastRun),
              trailing: Text(last == null ? '—' : '${last.hour.toString().padLeft(2, '0')}:${last.minute.toString().padLeft(2, '0')}'),
            ),
            if (s.waitingReports > 0)
              ListTile(title: Text(l10n.smsGatewayWaitingReports), trailing: Text('${s.waitingReports}')),
            if (s.lastError.isNotEmpty && !s.keyRefused)
              ListTile(title: Text(l10n.smsGatewayError), subtitle: Text(s.lastError)),
            const Divider(height: AppSpacing.lg),
            if (!s.enabled) ...[
              Text(l10n.smsGatewayAddress, style: TextStyle(color: p.onSurfaceMuted, fontSize: 13)),
              Text(SmsGatewayChannel.serverRoot(), style: const TextStyle(fontWeight: FontWeight.w600)),
              const SizedBox(height: AppSpacing.md),
              TextField(
                key: const ValueKey('sms-gateway-key'),
                controller: _key,
                autocorrect: false,
                enableSuggestions: false,
                decoration: InputDecoration(labelText: l10n.smsGatewayKey, border: const OutlineInputBorder()),
              ),
              const SizedBox(height: AppSpacing.md),
              FilledButton(key: const ValueKey('sms-gateway-start'), onPressed: _busy ? null : _start, child: Text(l10n.smsGatewayStart)),
            ] else
              OutlinedButton(key: const ValueKey('sms-gateway-stop'), onPressed: _busy ? null : _stop, child: Text(l10n.smsGatewayStop)),
            const SizedBox(height: AppSpacing.lg),
            Text(l10n.smsGatewayNote, style: TextStyle(color: p.onSurfaceMuted, fontSize: 13)),
          ],
        ),
      ),
    );
  }
}
