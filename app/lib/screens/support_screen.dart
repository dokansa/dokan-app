import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../config.dart';
import '../models/models.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// In-app chat through the store's Chatwoot (same inbox the team already uses).
class SupportScreen extends ConsumerWidget {
  const SupportScreen({super.key, this.orderId});
  final int? orderId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(configProvider);
    final user = ref.watch(sessionProvider);
    return Scaffold(
      appBar: AppBar(
        title: const Text('خدمة عملاء دكان'),
        leading: const Padding(padding: EdgeInsets.all(5), child: RoundIconButton(icon: Icons.arrow_back_rounded, label: 'رجوع')),
        actions: [
          if ((config.valueOrNull?.whatsapp ?? '').isNotEmpty)
            IconButton(
              tooltip: 'واتساب',
              icon: const Icon(Icons.chat_outlined),
              onPressed: () => _openWhatsapp(config.value!.whatsapp, orderId),
            ),
        ],
      ),
      body: config.when(
        loading: () => const LoadingBox(),
        error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(configProvider)),
        data: (c) {
          if (c.chatwootBase.isEmpty || c.chatwootToken.isEmpty) {
            return _WhatsappFallback(number: c.whatsapp, orderId: orderId);
          }
          return _ChatView(config: c, user: user, orderId: orderId);
        },
      ),
    );
  }
}

Future<void> _openWhatsapp(String number, int? orderId) async {
  final n = number.replaceAll(RegExp(r'[^0-9]'), '');
  final text = orderId == null ? 'السلام عليكم' : 'السلام عليكم، عندي استفسار عن طلب رقم $orderId';
  await launchUrl(Uri.parse('https://wa.me/$n?text=${Uri.encodeComponent(text)}'), mode: LaunchMode.externalApplication);
}

class _WhatsappFallback extends StatelessWidget {
  const _WhatsappFallback({required this.number, this.orderId});
  final String number;
  final int? orderId;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const CircleAvatar(radius: 40, backgroundColor: C.mint, child: Icon(Icons.support_agent_rounded, size: 40, color: C.primary)),
            const SizedBox(height: 16),
            Text('حياك، كلمنا على واتساب', style: display(18)),
            const SizedBox(height: 20),
            if (number.isNotEmpty)
              FilledButton(
                style: FilledButton.styleFrom(minimumSize: const Size(220, 52)),
                onPressed: () => _openWhatsapp(number, orderId),
                child: const Text('افتح واتساب'),
              ),
          ]),
        ),
      );
}

class _ChatView extends StatefulWidget {
  const _ChatView({required this.config, required this.user, this.orderId});
  final StoreConfig config;
  final AppUser? user;
  final int? orderId;

  @override
  State<_ChatView> createState() => _ChatViewState();
}

class _ChatViewState extends State<_ChatView> {
  late final WebViewController _web;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    final base = widget.config.chatwootBase.replaceAll(RegExp(r'/+$'), '');
    final u = widget.user;
    final userJson = u == null
        ? 'null'
        : jsonEncode({
            'id': 'wp-${u.id}',
            'name': u.name.isEmpty ? u.phone : u.name,
            if (u.email.isNotEmpty) 'email': u.email,
            if (u.phone.isNotEmpty) 'phone_number': u.phone.startsWith('+') ? u.phone : '+${u.phone}',
          });
    final attrs = jsonEncode({'source': 'app', if (widget.orderId != null) 'order_id': widget.orderId});
    final html = '''
<!doctype html><html lang="ar" dir="rtl"><head>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<style>html,body{margin:0;height:100%;background:#F5F4EF}</style></head><body>
<script>
window.chatwootSettings = { hideMessageBubble: true, locale: 'ar', type: 'standard', launcherTitle: '' };
window.addEventListener('chatwoot:ready', function () {
  var u = $userJson;
  if (u) { window.\$chatwoot.setUser(u.id, u); }
  window.\$chatwoot.setCustomAttributes($attrs);
  window.\$chatwoot.toggle('open');
  if (window.Ready) Ready.postMessage('ok');
});
(function(d,t){var BASE_URL=${jsonEncode(base)};var g=d.createElement(t),s=d.getElementsByTagName(t)[0];
g.src=BASE_URL+"/packs/js/sdk.js";g.defer=true;g.async=true;s.parentNode.insertBefore(g,s);
g.onload=function(){window.chatwootSDK.run({websiteToken:${jsonEncode(widget.config.chatwootToken)},baseUrl:BASE_URL})}})(document,"script");
</script></body></html>''';
    _web = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(C.ground)
      ..addJavaScriptChannel('Ready', onMessageReceived: (_) {
        if (mounted) setState(() => _loading = false);
      })
      ..setNavigationDelegate(NavigationDelegate(
        onNavigationRequest: (r) {
          final uri = Uri.parse(r.url);
          if (r.isMainFrame && uri.host.isNotEmpty && !r.url.startsWith(base) && !r.url.startsWith(AppConfig.storeUrl)) {
            launchUrl(uri, mode: LaunchMode.externalApplication);
            return NavigationDecision.prevent;
          }
          return NavigationDecision.navigate;
        },
      ))
      ..loadHtmlString(html, baseUrl: AppConfig.storeUrl);
    Future.delayed(const Duration(seconds: 8), () {
      if (mounted) setState(() => _loading = false);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Stack(children: [
      WebViewWidget(controller: _web),
      if (_loading) const LoadingBox(height: double.infinity),
    ]);
  }
}
