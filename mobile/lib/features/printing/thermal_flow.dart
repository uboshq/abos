import 'package:flutter/material.dart';

import '../../core/printing/printer_settings.dart';
import '../../core/printing/thermal_printer.dart';

/// The Bluetooth steps both the print sheet and the printer settings screen
/// take — one copy, so the two never say different things.
class ThermalFlow {
  const ThermalFlow._();

  static const String notPaired =
      'কোনো ব্লুটুথ প্রিন্টার জোড়া দেওয়া নেই। প্রিন্টার চালু করে ফোনের সেটিংস → ব্লুটুথ থেকে '
      'একবার জোড়া দিন (পিন সাধারণত 0000 বা 1234), তারপর আবার চেষ্টা করুন।';

  /// Before Android's own "nearby devices" prompt, once per phone: what it
  /// is for, in Bengali. False when the person backs out.
  static Future<bool> explainOnce(
      BuildContext context, PrinterSettingsStore store) async {
    final settings = await store.read();
    if (settings.permissionExplained) return true;
    if (!context.mounted) return false;
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        key: const Key('bt-explain'),
        title: const Text('ব্লুটুথ প্রিন্টারের অনুমতি'),
        content: const Text(
            'রসিদ প্রিন্টারে ছাপতে ABOS ফোনের ব্লুটুথ দিয়ে জোড়া দেওয়া প্রিন্টারের সাথে যুক্ত হবে। '
            'এরপর ফোন "আশেপাশের ডিভাইস" অনুমতি চাইবে — "অনুমতি দিন" চাপুন। '
            'এতে আপনার অবস্থান নেওয়া হয় না।'),
        actions: [
          TextButton(
              onPressed: () => Navigator.of(context).pop(false),
              child: const Text('এখন না')),
          FilledButton(
              key: const Key('bt-explain-ok'),
              onPressed: () => Navigator.of(context).pop(true),
              child: const Text('ঠিক আছে')),
        ],
      ),
    );
    if (yes != true) return false;
    await store.write(
        (await store.read()).copyWith(permissionExplained: true));
    return true;
  }

  /// Every paired printer; throws [BluetoothPrintingUnavailable] with a
  /// Bengali sentence for every reason there is none.
  static Future<List<BluetoothPrinter>> list(ThermalPrinting thermal) async {
    final printers = await thermal.pairedPrinters();
    if (printers.isEmpty) {
      throw const BluetoothPrintingUnavailable(notPaired);
    }
    return printers;
  }

  /// The person's choice among [printers] (the only one without asking);
  /// null when the list was closed.
  static Future<BluetoothPrinter?> choose(
      BuildContext context, List<BluetoothPrinter> printers) async {
    if (printers.length == 1) return printers.single;
    if (!context.mounted) return null;
    return showDialog<BluetoothPrinter>(
      context: context,
      builder: (context) => SimpleDialog(
        title: const Text('প্রিন্টার বাছুন'),
        children: [
          for (final p in printers)
            SimpleDialogOption(
              key: Key('bt-printer-${p.address}'),
              onPressed: () => Navigator.of(context).pop(p),
              child: ListTile(
                leading: const Icon(Icons.print_outlined),
                title: Text(p.label),
                subtitle: Text(p.address),
              ),
            ),
        ],
      ),
    );
  }
}
