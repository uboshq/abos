import 'package:flutter/material.dart';

import '../../core/widgets/empty_state.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// ⭐ যে পর্দা এখনো তৈরি হয়নি (ওয়েবের "planned") — ফোনে স্পষ্ট "শীঘ্রই আসছে", ওয়েব-জানালা নয় (fe, ১৭ অক্টোবর ২০২৬)।
class ComingSoonScreen extends StatelessWidget {
  const ComingSoonScreen({super.key, required this.title});

  final String title;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: title.isEmpty ? 'শীঘ্রই আসছে' : title),
        body: const EmptyState(
          icon: Icons.construction_outlined,
          title: 'শীঘ্রই আসছে',
          message: 'এই পর্দাটা এখনো তৈরি হচ্ছে। তৈরি হলে এখানেই খুলবে।',
        ),
      );
}
