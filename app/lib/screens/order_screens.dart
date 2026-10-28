import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart' as intl;
import 'package:url_launcher/url_launcher.dart';

import '../models/models.dart';
import '../router.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

String _date(DateTime? d) => d == null ? '' : intl.DateFormat('d MMM، h:mm a', 'ar').format(d.toLocal());

class OrdersScreen extends ConsumerWidget {
  const OrdersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(sessionProvider);
    final orders = ref.watch(ordersProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('طلباتي')),
      body: user == null
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(32),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  const Text('سجّل دخولك عشان تشوف طلباتك', style: TextStyle(color: C.muted)),
                  const SizedBox(height: 16),
                  FilledButton(
                    style: FilledButton.styleFrom(minimumSize: const Size(200, 52)),
                    onPressed: () => context.push('/login'),
                    child: const Text('تسجيل الدخول'),
                  ),
                ]),
              ),
            )
          : RefreshIndicator(
              color: C.primary,
              onRefresh: () => ref.refresh(ordersProvider.future),
              child: orders.when(
                loading: () => const LoadingBox(),
                error: (e, _) => ListView(children: [ErrorBox(e, onRetry: () => ref.invalidate(ordersProvider))]),
                data: (list) => list.isEmpty
                    ? ListView(children: const [
                        SizedBox(height: 120),
                        Center(child: Text('ما عندك طلبات للحين', style: TextStyle(color: C.muted))),
                      ])
                    : ListView.separated(
                        padding: const EdgeInsets.fromLTRB(20, 4, 20, kTabBarClearance),
                        itemCount: list.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (_, i) => _OrderRow(order: list[i]),
                      ),
              ),
            ),
    );
  }
}

class _OrderRow extends StatelessWidget {
  const _OrderRow({required this.order});
  final OrderSummary order;

  @override
  Widget build(BuildContext context) {
    final active = !{'completed', 'cancelled', 'refunded', 'failed'}.contains(order.status);
    return Material(
      color: C.surface,
      borderRadius: BorderRadius.circular(R.card),
      child: InkWell(
        borderRadius: BorderRadius.circular(R.card),
        onTap: () => context.push('/order/${order.id}'),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              SizedBox(width: 60, height: 60, child: NetImage(order.image, tint: active ? C.peach : C.mint)),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('طلب #${order.number}', style: const TextStyle(fontWeight: FontWeight.w700)),
                    Text('${_date(order.date)} · ${order.itemCount} منتج', style: const TextStyle(fontSize: 12, color: C.muted)),
                    const SizedBox(height: 4),
                    Text(order.statusLabel,
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: active ? C.accentText : C.primary)),
                  ],
                ),
              ),
              Text('${order.total} ر.س', style: display(14, weight: FontWeight.w700)),
            ],
          ),
        ),
      ),
    );
  }
}

class OrderScreen extends ConsumerStatefulWidget {
  const OrderScreen({super.key, required this.id, this.justPlaced = false});
  final int id;
  final bool justPlaced;

  @override
  ConsumerState<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends ConsumerState<OrderScreen> {
  bool _busy = false;

  static const _stages = [
    ('تم استلام الطلب', 'وصلنا طلبك وجهزنا الفاتورة'),
    ('قيد التنفيذ', 'نجهّز طلبك في المستودع'),
    ('تم الشحن', 'طلع مع شركة الشحن ومعه رقم تتبع'),
    ('في الطريق إليك', 'المندوب بيتواصل معك'),
    ('تم التوصيل', 'بالعافية على حيوانك'),
  ];
  static const _heads = ['استلمنا طلبك', 'نجهّز طلبك', 'طلبك انشحن', 'طلبك في الطريق', 'وصل طلبك'];

  Future<void> _reorder(OrderDetail o) async {
    setState(() => _busy = true);
    try {
      final r = await ref.read(appApiProvider).reorder(o.summary.id);
      final cart = ref.read(cartProvider.notifier);
      if (r.items.isNotEmpty) {
        for (final (id, qty) in r.items) {
          await cart.add(id, qty);
        }
      } else {
        await cart.refresh();
      }
      if (mounted) context.go('/cart');
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel(OrderDetail o) async {
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('طلب إلغاء'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          const Text('بنوقف تجهيز الطلب ونراجع طلب الإلغاء.'),
          const SizedBox(height: 12),
          TextField(controller: reason, decoration: const InputDecoration(hintText: 'السبب (اختياري)')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('رجوع')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('أرسل الطلب')),
        ],
      ),
    );
    if (ok != true) return;
    setState(() => _busy = true);
    try {
      await ref.read(appApiProvider).cancelRequest(o.summary.id, reason.text.trim());
      ref.invalidate(orderProvider(widget.id));
      ref.invalidate(ordersProvider);
      if (mounted) showMessage(context, 'وصلنا طلب الإلغاء، بنرد عليك قريب');
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      reason.dispose();
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final order = ref.watch(orderProvider(widget.id));
    return Scaffold(
      appBar: AppBar(
        title: Text('طلب #${order.valueOrNull?.summary.number ?? widget.id}'),
        leading: Padding(
          padding: const EdgeInsets.all(5),
          child: RoundIconButton(
            icon: Icons.arrow_back_rounded,
            label: 'رجوع',
            onTap: () => context.canPop() ? context.pop() : context.go('/orders'),
          ),
        ),
      ),
      body: order.when(
        loading: () => const LoadingBox(),
        error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(orderProvider(widget.id))),
        data: (o) => RefreshIndicator(
          color: C.primary,
          onRefresh: () => ref.refresh(orderProvider(widget.id).future),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 40),
            children: [
              if (widget.justPlaced)
                Container(
                  margin: const EdgeInsets.only(bottom: 12),
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(color: C.mint, borderRadius: BorderRadius.circular(18)),
                  child: const Row(children: [
                    Icon(Icons.check_circle_rounded, color: C.success),
                    SizedBox(width: 10),
                    Expanded(child: Text('تم الطلب، شكراً لك! بنرسل لك إشعار مع كل تحديث.', style: TextStyle(fontWeight: FontWeight.w700))),
                  ]),
                ),
              _StatusCard(order: o, heads: _heads),
              const SizedBox(height: 16),
              if (o.stage > 0) _Timeline(order: o, stages: _stages),
              const SizedBox(height: 16),
              Row(children: [
                if (o.tracking?.url != null && o.tracking!.url!.isNotEmpty) ...[
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: () => launchUrl(Uri.parse(o.tracking!.url!), mode: LaunchMode.externalApplication),
                      icon: const Icon(Icons.local_shipping_outlined, size: 18),
                      label: const Text('تتبع الشحنة', style: TextStyle(fontSize: 14)),
                    ),
                  ),
                  const SizedBox(width: 10),
                ],
                Expanded(
                  child: FilledButton.icon(
                    style: FilledButton.styleFrom(backgroundColor: C.surface, foregroundColor: C.ink),
                    onPressed: () => context.push('/support?order=${o.summary.id}'),
                    icon: const Icon(Icons.chat_bubble_outline_rounded, size: 18),
                    label: const Text('عندي مشكلة', style: TextStyle(fontSize: 14)),
                  ),
                ),
              ]),
              const SizedBox(height: 10),
              Text(
                o.canCancel ? 'تقدر تطلب إلغاء الطلب ما دام قيد التنفيذ.' : 'بعد الشحن ما يمكن إلغاء الطلب — لو فيه مشكلة كلمنا.',
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 12, color: C.muted),
              ),
              const SizedBox(height: 20),
              Text('المنتجات', style: display(16)),
              const SizedBox(height: 8),
              for (final l in o.items)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(children: [
                    SizedBox(width: 52, height: 52, child: NetImage(l.image)),
                    const SizedBox(width: 10),
                    Expanded(child: Text('${l.name} × ${l.quantity}', style: const TextStyle(fontSize: 13))),
                    Text('${l.total} ر.س', style: const TextStyle(fontWeight: FontWeight.w700)),
                  ]),
                ),
              const Divider(color: C.line),
              _line('الشحن', '${o.shippingTotal} ر.س'),
              for (final (name, total) in o.fees) _line(name, '$total ر.س'),
              _line('طريقة الدفع', o.paymentTitle),
              _line('الإجمالي', '${o.summary.total} ر.س', bold: true),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _busy ? null : () => _reorder(o),
                icon: const Icon(Icons.autorenew_rounded),
                label: const Text('اطلبه مرة ثانية'),
              ),
              if (o.canCancel) ...[
                const SizedBox(height: 8),
                TextButton(
                  onPressed: _busy ? null : () => _cancel(o),
                  style: TextButton.styleFrom(foregroundColor: C.accentText, minimumSize: const Size.fromHeight(48)),
                  child: const Text('طلب إلغاء الطلب'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _line(String a, String b, {bool bold = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [
          Expanded(child: Text(a, style: TextStyle(color: bold ? C.ink : C.muted, fontWeight: bold ? FontWeight.w700 : FontWeight.w400))),
          Text(b, style: TextStyle(fontWeight: bold ? FontWeight.w700 : FontWeight.w500)),
        ]),
      );
}

class _StatusCard extends StatelessWidget {
  const _StatusCard({required this.order, required this.heads});
  final OrderDetail order;
  final List<String> heads;

  @override
  Widget build(BuildContext context) {
    final stage = order.stage;
    final head = stage >= 1 && stage <= 5 ? heads[stage - 1] : order.summary.statusLabel;
    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(color: C.ink, borderRadius: BorderRadius.circular(26)),
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('الحالة الآن', style: TextStyle(fontSize: 13, color: Color(0xFFC9D3D0))),
          const SizedBox(height: 6),
          Text(head, style: display(26, weight: FontWeight.w700, color: Colors.white)),
          if (order.tracking?.number != null && order.tracking!.number!.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text('${order.tracking!.carrier ?? 'رقم التتبع'}: ${order.tracking!.number}',
                style: const TextStyle(fontSize: 13, color: Color(0xFFFFC8A1))),
          ],
          const SizedBox(height: 14),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: stage <= 0 ? 0 : stage / 5,
              minHeight: 8,
              color: C.accent,
              backgroundColor: const Color(0xFF2C3D3B),
            ),
          ),
        ],
      ),
    );
  }
}

class _Timeline extends StatelessWidget {
  const _Timeline({required this.order, required this.stages});
  final OrderDetail order;
  final List<(String, String)> stages;

  @override
  Widget build(BuildContext context) {
    final cur = order.stage;
    DateTime? dateFor(int s) {
      for (final t in order.timeline) {
        if (t.stage == s) return t.date;
      }
      return null;
    }

    return Container(
      padding: const EdgeInsets.fromLTRB(18, 18, 18, 6),
      decoration: BoxDecoration(color: C.surface, borderRadius: BorderRadius.circular(22)),
      child: Column(
        children: [
          for (var i = 0; i < stages.length; i++)
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  SizedBox(
                    width: 24,
                    child: Column(children: [
                      Container(
                        width: 24,
                        height: 24,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: i + 1 <= cur ? (i + 1 == cur ? C.accentStrong : C.primary) : C.surface,
                          border: i + 1 <= cur ? null : Border.all(color: const Color(0xFFC9CCC6), width: 2),
                        ),
                        child: i + 1 <= cur ? const Icon(Icons.check_rounded, size: 14, color: Colors.white) : null,
                      ),
                      if (i < stages.length - 1)
                        Expanded(
                          child: Container(
                            width: 2,
                            margin: const EdgeInsets.symmetric(vertical: 4),
                            color: i + 1 < cur ? C.primary : const Color(0xFFE2E1DA),
                          ),
                        ),
                    ]),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(bottom: 18),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(stages[i].$1,
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: i + 1 == cur ? FontWeight.w700 : FontWeight.w600,
                                color: i + 1 <= cur ? C.ink : C.muted,
                              )),
                          Text(dateFor(i + 1) != null ? _date(dateFor(i + 1)) : stages[i].$2,
                              style: const TextStyle(fontSize: 12, color: C.muted)),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
