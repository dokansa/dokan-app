import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../l10n/lang.dart';
import '../services/push_service.dart';
import '../theme.dart';

/// Explains why we want notifications, then shows the system dialog.
/// Call after login and after the first order. Does nothing if already decided.
Future<void> askNotifications(BuildContext context, {bool force = false}) async {
  if (!PushService.available) return;
  final allowed = await PushService.isAllowed();
  if (allowed == true) return;
  if (!force && !await PushService.canAsk()) return;
  if (!context.mounted) return;
  final container = ProviderScope.containerOf(context, listen: false);
  final yes = await showModalBottomSheet<bool>(
    context: context,
    useRootNavigator: true,
    backgroundColor: C.ground,
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
    builder: (ctx) => _Rationale(
      icon: Icons.notifications_active_outlined,
      title: ctx.tr('خلّك متابع طلبك', 'Stay on top of your order'),
      body: ctx.tr(
        'نرسل لك إشعار لما يتجهز طلبك، ولما ينشحن، ولما يوصل. ونذكرك قبل ما يخلص أكل حيوانك. بدون إزعاج.',
        'We\'ll let you know when your order is packed, shipped and delivered — and remind you before your pet\'s food runs out. No spam.',
      ),
      allow: ctx.tr('فعّل الإشعارات', 'Turn on notifications'),
    ),
  );
  if (yes == true) {
    await PushService.requestAndRegister(container);
  } else {
    await PushService.markAsked();
  }
}

bool _autoAsked = false;

/// Once per app start: a signed-in customer who hasn't decided yet is asked for notifications.
Future<void> autoAskNotifications(BuildContext context) async {
  if (_autoAsked) return;
  _autoAsked = true;
  await Future<void>.delayed(const Duration(milliseconds: 1200));
  if (context.mounted) await askNotifications(context);
}

/// Explains why we want the location before the system dialog. Returns true to continue.
Future<bool> explainLocation(BuildContext context) async {
  final yes = await showModalBottomSheet<bool>(
    context: context,
    useRootNavigator: true,
    backgroundColor: C.ground,
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
    builder: (ctx) => _Rationale(
      icon: Icons.my_location_rounded,
      title: ctx.tr('نحدد عنوانك بدقة', 'Pin your address exactly'),
      body: ctx.tr(
        'نستخدم موقعك مرة وحدة عشان نعبّي العنوان ويوصل المندوب لباب بيتك بدون اتصالات. ما نتتبعك.',
        'We use your location once to fill in the address so the driver finds your door without calling. We don\'t track you.',
      ),
      allow: ctx.tr('استخدم موقعي', 'Use my location'),
    ),
  );
  return yes == true;
}

/// Small card on the order screen when notifications are off.
class NotifyPromptCard extends StatefulWidget {
  const NotifyPromptCard({super.key});

  @override
  State<NotifyPromptCard> createState() => _NotifyPromptCardState();
}

class _NotifyPromptCardState extends State<NotifyPromptCard> {
  bool? _allowed;

  @override
  void initState() {
    super.initState();
    PushService.isAllowed().then((v) {
      if (mounted) setState(() => _allowed = v);
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_allowed != false) return const SizedBox.shrink();
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: C.peach, borderRadius: BorderRadius.circular(18)),
      child: Row(
        children: [
          const Icon(Icons.notifications_off_outlined, color: C.accentText),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              context.tr('فعّل الإشعارات عشان يوصلك كل تحديث عن طلبك', 'Turn on notifications to get every update on this order'),
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
          ),
          TextButton(
            onPressed: () async {
              await askNotifications(context, force: true);
              final v = await PushService.isAllowed();
              if (mounted) setState(() => _allowed = v);
            },
            child: Text(context.tr('فعّل', 'Turn on')),
          ),
        ],
      ),
    );
  }
}

class _Rationale extends StatelessWidget {
  const _Rationale({required this.icon, required this.title, required this.body, required this.allow});
  final IconData icon;
  final String title;
  final String body;
  final String allow;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(24, 28, 24, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: CircleAvatar(radius: 34, backgroundColor: C.mint, child: Icon(icon, size: 32, color: C.primary)),
            ),
            const SizedBox(height: 16),
            Text(title, textAlign: TextAlign.center, style: display(20)),
            const SizedBox(height: 8),
            Text(body, textAlign: TextAlign.center, style: const TextStyle(color: C.muted, height: 1.6)),
            const SizedBox(height: 20),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(allow)),
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              style: TextButton.styleFrom(minimumSize: const Size.fromHeight(48), foregroundColor: C.muted),
              child: Text(context.tr('مو الحين', 'Not now')),
            ),
          ],
        ),
      ),
    );
  }
}
