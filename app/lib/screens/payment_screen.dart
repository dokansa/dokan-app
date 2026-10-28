import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../config.dart';
import '../l10n/lang.dart';
import '../theme.dart';
import '../widgets/common.dart';

class PaymentArgs {
  PaymentArgs({required this.url, required this.orderId});
  final String url;
  final int orderId;
}

/// Opens the gateway's own page (MyFatoorah / Tabby / Tamara) set up by the store's
/// existing WooCommerce plugins. Pops `true` when the store's thank-you page is reached.
class PaymentScreen extends StatefulWidget {
  const PaymentScreen({super.key, required this.args});
  final PaymentArgs args;

  @override
  State<PaymentScreen> createState() => _PaymentScreenState();
}

class _PaymentScreenState extends State<PaymentScreen> {
  late final WebViewController _web;
  int _progress = 0;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    _web = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(C.ground)
      ..setNavigationDelegate(NavigationDelegate(
        onProgress: (p) {
          if (mounted) setState(() => _progress = p);
        },
        onNavigationRequest: (req) => _check(req.url),
        onUrlChange: (c) {
          if (c.url != null) _check(c.url!);
        },
      ))
      ..loadRequest(Uri.parse(widget.args.url));
  }

  NavigationDecision _check(String url) {
    final uri = Uri.tryParse(url);
    if (uri == null) return NavigationDecision.navigate;

    // Bank / wallet apps (e.g. tel:, intent:, app links) open outside the WebView.
    if (uri.scheme != 'http' && uri.scheme != 'https') {
      launchUrl(uri, mode: LaunchMode.externalApplication);
      return NavigationDecision.prevent;
    }

    final store = Uri.parse(AppConfig.storeUrl).host;
    if (uri.host == store || uri.host == 'www.$store') {
      if (uri.path.contains('/order-received/')) {
        _finish(true);
        return NavigationDecision.prevent;
      }
      final path = uri.path;
      final backToStore = path.contains('/cart') ||
          (path.contains('/checkout') && !path.contains('/order-pay/')) ||
          uri.queryParameters.containsKey('cancel_order');
      if (backToStore) {
        _finish(false);
        return NavigationDecision.prevent;
      }
    }
    return NavigationDecision.navigate;
  }

  void _finish(bool ok) {
    if (_done || !mounted) return;
    _done = true;
    Navigator.of(context).pop(ok);
  }

  Future<void> _confirmClose() async {
    final leave = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.tr('تلغي الدفع؟', 'Cancel payment?')),
        content: Text(ctx.tr(
          'طلبك محفوظ، وتقدر تكمل الدفع بعدين من طلباتي.',
          'Your order is saved. You can finish paying later from My orders.',
        )),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(ctx.tr('أكمل الدفع', 'Continue paying'))),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: Text(ctx.tr('اطلع', 'Leave'))),
        ],
      ),
    );
    if (leave == true) _finish(false);
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _confirmClose();
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.tr('الدفع · طلب #${widget.args.orderId}', 'Payment · Order #${widget.args.orderId}')),
          leading: Padding(
            padding: const EdgeInsets.all(5),
            child: RoundIconButton(icon: Icons.close_rounded, label: context.tr('إغلاق', 'Close'), onTap: _confirmClose),
          ),
          bottom: _progress < 100
              ? PreferredSize(
                  preferredSize: const Size.fromHeight(3),
                  child: LinearProgressIndicator(value: _progress / 100, color: C.primary, backgroundColor: C.line, minHeight: 3),
                )
              : null,
        ),
        body: Column(
          children: [
            Container(
              width: double.infinity,
              color: C.mint,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Row(
                children: [
                  const Icon(Icons.lock_outline_rounded, size: 16, color: C.primary),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      context.tr('صفحة دفع آمنة من مزوّد الدفع', "Secure payment page from the payment provider"),
                      style: const TextStyle(fontSize: 12, color: C.primaryDeep),
                    ),
                  ),
                ],
              ),
            ),
            Expanded(child: WebViewWidget(controller: _web)),
          ],
        ),
      ),
    );
  }
}
