import 'package:flutter/material.dart';

import '../../../core/messenger/messenger_api.dart';

/// ⭐ চ্যাটের পাতা (WhatsApp-ধাঁচ, তিন রূপেই একই) — নমুনা 3nuwRUdn v5 "খ"/"গ"। ⓘ ভিত্তি; আসল পাতা চ্যাটের ভাগে।
class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key, required this.thread});

  final ChatThread thread;

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  @override
  Widget build(BuildContext context) => const Scaffold();
}

/// একটা কথোপকথন খোলে — তালিকা, "আমি" পাতা, নোটিফিকেশন সবাই এটাই ডাকে
Future<void> openChat(BuildContext context, ChatThread thread) =>
    Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => ChatScreen(thread: thread)));

/// কেবল নম্বর জানা থাকলে (নোটিফিকেশন চাপলে) — কথোপকথন খুঁজে খোলে
Future<void> openChatById(BuildContext context, String threadId) async {}
