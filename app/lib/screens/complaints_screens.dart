import 'dart:async';
import 'dart:convert';
import 'dart:math';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../l10n/lang.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

final complaintsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) {
  ref.watch(langProvider);
  return ref.watch(appApiProvider).complaints();
});

final complaintProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) {
  ref.watch(langProvider);
  return ref.watch(appApiProvider).complaint(id);
});

final complaintOptionsProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) {
  ref.watch(langProvider);
  return ref.watch(appApiProvider).complaintOptions();
});

String _uuid() {
  final r = Random.secure();
  final b = List<int>.generate(16, (_) => r.nextInt(256));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  final h = b.map((x) => x.toRadixString(16).padLeft(2, '0')).join();
  return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
}

IconData _catIcon(String k) => switch (k) {
      'not_arrived' => Icons.inventory_2_outlined,
      'damaged' => Icons.broken_image_outlined,
      'wrong_item' => Icons.swap_horiz_rounded,
      'missing' => Icons.remove_shopping_cart_outlined,
      'late' => Icons.schedule_rounded,
      'cancel' => Icons.cancel_outlined,
      'payment' => Icons.credit_card_rounded,
      'service' => Icons.support_agent_rounded,
      'inquiry' => Icons.help_outline_rounded,
      _ => Icons.more_horiz_rounded,
    };

Color _stateColor(Map<String, dynamic> c) {
  if (c['needs_reply'] == true) return C.accent;
  if (c['open'] == true) return C.primary;
  return c['status'] == 'rejected' ? C.accentText : C.success;
}

PreferredSizeWidget _bar(BuildContext context, String title) => AppBar(
      title: Text(title),
      leading: Padding(
        padding: const EdgeInsets.all(5),
        child: RoundIconButton(icon: Icons.arrow_back_rounded, label: context.tr('رجوع', 'Back')),
      ),
    );

/* ============================================================== list */

class ComplaintsScreen extends ConsumerWidget {
  const ComplaintsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (ref.watch(sessionProvider) == null) return const _NeedLogin();
    final list = ref.watch(complaintsProvider);
    return Scaffold(
      appBar: _bar(context, context.tr('الشكاوى والإلغاء', 'Complaints & cancellation')),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: C.accent,
        foregroundColor: C.ink,
        onPressed: () async {
          await context.push('/complaint-new');
          ref.invalidate(complaintsProvider);
        },
        icon: const Icon(Icons.add_comment_outlined),
        label: Text(context.tr('شكوى جديدة', 'New complaint')),
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(complaintsProvider.future),
        child: list.when(
          loading: () => const LoadingBox(),
          error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(complaintsProvider)),
          data: (items) => ListView(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 110),
            children: [
              const _PolicyNote(),
              const SizedBox(height: 14),
              if (items.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 40),
                  child: Column(children: [
                    const Icon(Icons.sentiment_satisfied_alt_rounded, size: 54, color: C.faint),
                    const SizedBox(height: 10),
                    Text(context.tr('ما عندك شكاوى — نتمنى تظل كذا!', 'No complaints — hope it stays that way!'),
                        style: const TextStyle(color: C.muted)),
                  ]),
                ),
              for (final c in items) _CaseCard(c: c),
            ],
          ),
        ),
      ),
    );
  }
}

class _CaseCard extends StatelessWidget {
  const _CaseCard({required this.c});
  final Map<String, dynamic> c;

  @override
  Widget build(BuildContext context) {
    final color = _stateColor(c);
    final cat = '${c['category'] ?? ''}';
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Material(
        color: C.surface,
        borderRadius: BorderRadius.circular(R.card),
        child: InkWell(
          borderRadius: BorderRadius.circular(R.card),
          onTap: () => context.push('/complaint/${c['id']}'),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Row(children: [
              CircleAvatar(
                radius: 22,
                backgroundColor: c['needs_reply'] == true ? C.peach : C.mint,
                child: Icon(_catIcon(cat.isEmpty ? (c['kind'] == 'inquiry' ? 'inquiry' : 'other') : cat), color: C.primary),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${c['subject']}', maxLines: 2, overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  Text(
                    [c['ref'], c['kind_label'], if ('${c['order_number']}'.isNotEmpty) '#${c['order_number']}'].join(' · '),
                    style: const TextStyle(fontSize: 12, color: C.muted),
                  ),
                ]),
              ),
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(R.chip)),
                child: Text('${c['status_label']}', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: color)),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

class _PolicyNote extends StatelessWidget {
  const _PolicyNote();

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: C.sand, borderRadius: BorderRadius.circular(R.card)),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Icon(Icons.info_outline_rounded, color: C.primary, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              context.tr(
                'شكاوى «ما وصل» و«تالف» تحتاج رقم الطلب وصورة، والتلف يُبلّغ خلال ٢٤ ساعة من الاستلام. الإلغاء متاح والطلب «قيد التنفيذ» فقط.',
                '"Not arrived" and "damaged" complaints need the order and a photo; damage must be reported within 24 hours of delivery. Orders can be cancelled only while processing.',
              ),
              style: const TextStyle(fontSize: 12.5, height: 1.6, color: C.ink),
            ),
          ),
          TextButton(
            onPressed: () => context.push('/policy/complaints'),
            child: Text(context.tr('السياسة', 'Policy')),
          ),
        ]),
      );
}

class _NeedLogin extends StatelessWidget {
  const _NeedLogin();

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: _bar(context, context.tr('الشكاوى والإلغاء', 'Complaints & cancellation')),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              const Icon(Icons.lock_outline_rounded, size: 48, color: C.faint),
              const SizedBox(height: 12),
              Text(context.tr('سجّل دخولك عشان ترفع شكوى وتتابعها', 'Sign in to send and follow a complaint'),
                  textAlign: TextAlign.center),
              const SizedBox(height: 16),
              FilledButton(
                onPressed: () => context.push('/login'),
                child: Text(context.tr('دخول برقم الجوال', 'Sign in with mobile number')),
              ),
            ]),
          ),
        ),
      );
}

/* ============================================================== new */

class ComplaintFormScreen extends ConsumerStatefulWidget {
  const ComplaintFormScreen({super.key, this.orderId, this.category});
  final int? orderId;
  final String? category;

  @override
  ConsumerState<ComplaintFormScreen> createState() => _ComplaintFormState();
}

class _ComplaintFormState extends ConsumerState<ComplaintFormScreen> {
  final _text = TextEditingController();
  final _token = _uuid();
  Timer? _debounce;
  int? _order;
  String? _cat; // chosen by the customer
  bool _catPicked = false;
  Map<String, dynamic>? _ai;
  bool _thinking = false;
  bool _sending = false;
  final List<Uint8List> _photos = [];
  int _seq = 0;

  @override
  void initState() {
    super.initState();
    _order = widget.orderId;
    if (widget.category != null) {
      _cat = widget.category;
      _catPicked = true;
    }
    WidgetsBinding.instance.addPostFrameCallback((_) => _analyze());
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _text.dispose();
    super.dispose();
  }

  void _onText(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 900), _analyze);
  }

  Future<void> _analyze() async {
    final text = _text.text.trim();
    if (text.isEmpty && _cat == null) return;
    final seq = ++_seq;
    setState(() => _thinking = true);
    try {
      final r = await ref.read(appApiProvider).analyzeComplaint(
            text: text,
            orderId: _order,
            category: _catPicked ? _cat : null,
          );
      if (!mounted || seq != _seq) return;
      setState(() {
        _ai = r;
        if (!_catPicked) _cat = r['category']?.toString();
      });
    } catch (_) {
      // Understanding is a helper; the form still works without it.
    } finally {
      if (mounted && seq == _seq) setState(() => _thinking = false);
    }
  }

  Map<String, dynamic> get _policy => Map<String, dynamic>.from((_ai?['policy'] as Map?) ?? const {});

  Map<String, dynamic>? _catDef(List cats) {
    for (final c in cats) {
      if ((c as Map)['key'] == _cat) return Map<String, dynamic>.from(c);
    }
    return null;
  }

  Future<void> _addPhoto(ImageSource src) async {
    try {
      final f = await ImagePicker().pickImage(source: src, maxWidth: 1600, maxHeight: 1600, imageQuality: 75);
      if (f == null) return;
      final bytes = await f.readAsBytes();
      if (mounted) setState(() => _photos.add(bytes));
    } catch (e) {
      if (mounted) showMessage(context, context.tr('تعذّر فتح الصور', 'Could not open photos'), error: true);
    }
  }

  void _photoSheet() {
    showModalBottomSheet(
      context: context,
      useRootNavigator: true,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(
            leading: const Icon(Icons.photo_camera_outlined, color: C.primary),
            title: Text(ctx.tr('صوّر الحين', 'Take a photo')),
            onTap: () {
              Navigator.pop(ctx);
              _addPhoto(ImageSource.camera);
            },
          ),
          ListTile(
            leading: const Icon(Icons.photo_library_outlined, color: C.primary),
            title: Text(ctx.tr('من الصور', 'From gallery')),
            onTap: () {
              Navigator.pop(ctx);
              _addPhoto(ImageSource.gallery);
            },
          ),
          const SizedBox(height: 8),
        ]),
      ),
    );
  }

  Future<void> _submit(Map<String, dynamic> def) async {
    final isCancel = _cat == 'cancel';
    final details = _text.text.trim();
    String? err;
    if (_cat == null) {
      err = context.tr('اختر نوع المشكلة', 'Pick what the problem is');
    } else if (def['need_order'] == true && _order == null) {
      err = context.tr('حسب السياسة لازم تختار الطلب', 'Under the policy you need to pick the order');
    } else if (def['need_photo'] == true && _photos.isEmpty) {
      err = context.tr('حسب السياسة لازم ترفق صورة', 'Under the policy you need to attach a photo');
    } else if (!isCancel && details.length < 10) {
      err = context.tr('اكتب تفاصيل أكثر شوي (١٠ أحرف على الأقل)', 'Add a bit more detail (at least 10 characters)');
    } else if (_policy['blocked'] != null) {
      err = '${_policy['blocked']}';
    }
    if (err != null) {
      showMessage(context, err, error: true);
      return;
    }
    if (isCancel) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(ctx.tr('إلغاء الطلب؟', 'Cancel the order?')),
          content: Text(ctx.tr('بنوقف الطلب الحين لين يتم إلغاؤه.', 'We will put the order on hold now until it is cancelled.')),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(ctx.tr('رجوع', 'Back'))),
            TextButton(
              onPressed: () => Navigator.pop(ctx, true),
              style: TextButton.styleFrom(foregroundColor: C.accentText),
              child: Text(ctx.tr('ألغِ الطلب', 'Cancel order')),
            ),
          ],
        ),
      );
      if (ok != true) return;
    }
    setState(() => _sending = true);
    try {
      final r = await ref.read(appApiProvider).submitComplaint({
        'category': _cat,
        if (_order != null) 'order_id': _order,
        'details': details,
        'token': _token,
        'photos': [for (final p in _photos) base64Encode(p)],
      });
      if (!mounted) return;
      if (r['cancelled'] == true) {
        ref.invalidate(ordersProvider);
        if (_order != null) ref.invalidate(orderProvider(_order!));
      }
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.check_circle_rounded, color: C.success, size: 44),
          title: Text('${r['ref'] ?? ''}'.isEmpty ? ctx.tr('تم', 'Done') : '${r['ref']}'),
          content: Text('${r['message'] ?? ''}', textAlign: TextAlign.center),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(ctx.tr('تمام', 'OK')))],
        ),
      );
      if (!mounted) return;
      final id = int.tryParse('${r['id'] ?? ''}') ?? 0;
      if (id > 0) {
        context.pushReplacement('/complaint/$id');
      } else {
        context.pop();
      }
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (ref.watch(sessionProvider) == null) return const _NeedLogin();
    final opts = ref.watch(complaintOptionsProvider);
    return Scaffold(
      appBar: _bar(context, context.tr('شكوى أو إلغاء', 'Complaint or cancellation')),
      body: opts.when(
        loading: () => const LoadingBox(),
        error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(complaintOptionsProvider)),
        data: (o) {
          final cats = (o['categories'] as List?) ?? const [];
          final orders = (o['orders'] as List?) ?? const [];
          final def = _catDef(cats) ?? const <String, dynamic>{};
          final isCancel = _cat == 'cancel';
          final maxPhotos = int.tryParse('${o['max_photos'] ?? 4}') ?? 4;
          final warnings = ((_policy['warnings'] as List?) ?? const []).map((e) => '$e').toList();
          final blocked = _policy['blocked']?.toString();
          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
            children: [
              Text(context.tr('وش صار؟', 'What happened?'), style: display(18)),
              const SizedBox(height: 4),
              Text(
                context.tr('اكتب بكلامك العادي وبنفهم المشكلة ونرتّبها حسب السياسة.',
                    "Write it in your own words — we'll understand it and sort it by the policy."),
                style: const TextStyle(color: C.muted, fontSize: 13),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _text,
                onChanged: _onText,
                minLines: 4,
                maxLines: 8,
                maxLength: 2000,
                decoration: InputDecoration(
                  hintText: context.tr('مثال: وصلني كيس الأكل مفتوح والحبوب متفرقة', 'e.g. The food bag arrived open and spilled'),
                  filled: true,
                  fillColor: C.surface,
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(R.card), borderSide: BorderSide.none),
                ),
              ),
              if (_thinking || _ai != null) _AiCard(ai: _ai, thinking: _thinking, catPicked: _catPicked),
              const SizedBox(height: 16),
              Text(context.tr('نوع المشكلة', 'Type of problem'), style: display(15)),
              const SizedBox(height: 8),
              Wrap(spacing: 8, runSpacing: 8, children: [
                for (final c in cats)
                  ChoiceChip(
                    avatar: Icon(_catIcon('${(c as Map)['key']}'), size: 18,
                        color: _cat == c['key'] ? Colors.white : C.primary),
                    label: Text('${c['label']}'),
                    selected: _cat == c['key'],
                    showCheckmark: false,
                    selectedColor: C.primary,
                    labelStyle: TextStyle(color: _cat == c['key'] ? Colors.white : C.ink, fontWeight: FontWeight.w600),
                    onSelected: (_) {
                      setState(() {
                        _cat = '${c['key']}';
                        _catPicked = true;
                      });
                      _analyze();
                    },
                  ),
              ]),
              const SizedBox(height: 18),
              Row(children: [
                Text(context.tr('الطلب', 'Order'), style: display(15)),
                const SizedBox(width: 8),
                if (def['need_order'] == true) _Badge(context.tr('مطلوب', 'Required')),
              ]),
              const SizedBox(height: 8),
              if (orders.isEmpty)
                Text(context.tr('ما فيه طلبات على حسابك', 'No orders on your account'), style: const TextStyle(color: C.muted))
              else
                SizedBox(
                  height: 92,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    itemCount: orders.length + (def['need_order'] == true ? 0 : 1),
                    separatorBuilder: (_, __) => const SizedBox(width: 8),
                    itemBuilder: (_, i) {
                      if (def['need_order'] != true && i == orders.length) {
                        return _OrderChip(
                          selected: _order == null,
                          title: context.tr('بدون طلب', 'No order'),
                          sub: context.tr('مشكلة عامة', 'General issue'),
                          onTap: () {
                            setState(() => _order = null);
                            _analyze();
                          },
                        );
                      }
                      final od = Map<String, dynamic>.from(orders[i] as Map);
                      final id = int.tryParse('${od['id']}');
                      return _OrderChip(
                        selected: _order == id,
                        title: '#${od['number']}',
                        sub: '${od['status_label']} · ${od['total']} ${currencyLabel(context.isAr)}',
                        onTap: () {
                          setState(() => _order = id);
                          _analyze();
                        },
                      );
                    },
                  ),
                ),
              if (!isCancel) ...[
                const SizedBox(height: 18),
                Row(children: [
                  Text(context.tr('صور', 'Photos'), style: display(15)),
                  const SizedBox(width: 8),
                  if (def['need_photo'] == true) _Badge(context.tr('مطلوبة حسب السياسة', 'Required by policy')),
                ]),
                const SizedBox(height: 8),
                SizedBox(
                  height: 84,
                  child: ListView(scrollDirection: Axis.horizontal, children: [
                    for (var i = 0; i < _photos.length; i++)
                      Padding(
                        padding: const EdgeInsetsDirectional.only(end: 8),
                        child: Stack(children: [
                          ClipRRect(
                            borderRadius: BorderRadius.circular(14),
                            child: Image.memory(_photos[i], width: 84, height: 84, fit: BoxFit.cover),
                          ),
                          PositionedDirectional(
                            top: 2,
                            end: 2,
                            child: InkWell(
                              onTap: () => setState(() => _photos.removeAt(i)),
                              child: const CircleAvatar(radius: 12, backgroundColor: Colors.black54,
                                  child: Icon(Icons.close_rounded, size: 14, color: Colors.white)),
                            ),
                          ),
                        ]),
                      ),
                    if (_photos.length < maxPhotos)
                      InkWell(
                        borderRadius: BorderRadius.circular(14),
                        onTap: _photoSheet,
                        child: Container(
                          width: 84,
                          decoration: BoxDecoration(
                            color: C.surface,
                            borderRadius: BorderRadius.circular(14),
                            border: Border.all(color: def['need_photo'] == true && _photos.isEmpty ? C.accent : C.line, width: 1.5),
                          ),
                          child: const Icon(Icons.add_a_photo_outlined, color: C.primary),
                        ),
                      ),
                  ]),
                ),
              ],
              if (warnings.isNotEmpty || blocked != null) ...[
                const SizedBox(height: 16),
                if (blocked != null) _Notice(text: blocked, strong: true),
                for (final w in warnings) _Notice(text: w),
              ],
              const SizedBox(height: 20),
              FilledButton.icon(
                style: isCancel ? FilledButton.styleFrom(backgroundColor: C.accentText) : null,
                onPressed: _sending || blocked != null ? null : () => _submit(def),
                icon: _sending
                    ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : Icon(isCancel ? Icons.cancel_outlined : Icons.send_rounded),
                label: Text(isCancel
                    ? context.tr('أوقف الطلب واطلب الإلغاء', 'Hold the order & request cancellation')
                    : context.tr('أرسل الشكوى', 'Send complaint')),
              ),
              const SizedBox(height: 10),
              Text(
                context.tr('بنرد عليك هنا وعلى الواتساب. ما نطلب منك أي بيانات بنكية.',
                    "We'll reply here and on WhatsApp. We never ask for bank details."),
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 11.5, color: C.muted),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _AiCard extends StatelessWidget {
  const _AiCard({required this.ai, required this.thinking, required this.catPicked});
  final Map<String, dynamic>? ai;
  final bool thinking;
  final bool catPicked;

  @override
  Widget build(BuildContext context) {
    final reply = '${ai?['reply'] ?? ''}';
    final label = '${ai?['category_label'] ?? ''}';
    return AnimatedSize(
      duration: const Duration(milliseconds: 200),
      child: Container(
        margin: const EdgeInsets.only(top: 6),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: C.mint, borderRadius: BorderRadius.circular(R.card)),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Icon(Icons.auto_awesome_rounded, color: C.primary, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: thinking && ai == null
                ? Text(context.tr('نفهم مشكلتك…', 'Understanding your issue…'), style: const TextStyle(color: C.primary))
                : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    if (label.isNotEmpty && !catPicked)
                      Text(context.tr('فهمت إنها: $label', 'Looks like: $label'),
                          style: const TextStyle(fontWeight: FontWeight.w700, color: C.primaryDeep)),
                    if (reply.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(reply, style: const TextStyle(fontSize: 13, height: 1.6, color: C.ink)),
                    ],
                  ]),
          ),
          if (thinking) const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2)),
        ]),
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.text, this.strong = false});
  final String text;
  final bool strong;

  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: strong ? const Color(0xFFFBE3D6) : C.peach,
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(strong ? Icons.block_rounded : Icons.warning_amber_rounded, size: 18, color: C.accentText),
          const SizedBox(width: 8),
          Expanded(child: Text(text, style: const TextStyle(fontSize: 12.5, height: 1.6))),
        ]),
      );
}

class _Badge extends StatelessWidget {
  const _Badge(this.text);
  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(color: C.peach, borderRadius: BorderRadius.circular(R.chip)),
        child: Text(text, style: const TextStyle(fontSize: 11, color: C.accentText, fontWeight: FontWeight.w700)),
      );
}

class _OrderChip extends StatelessWidget {
  const _OrderChip({required this.selected, required this.title, required this.sub, required this.onTap});
  final bool selected;
  final String title;
  final String sub;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Container(
          width: 150,
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: selected ? C.primary : C.surface,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: selected ? C.primary : C.line),
          ),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [
            Text(title, style: TextStyle(fontWeight: FontWeight.w700, color: selected ? Colors.white : C.ink)),
            const SizedBox(height: 4),
            Text(sub, maxLines: 2, overflow: TextOverflow.ellipsis,
                style: TextStyle(fontSize: 11.5, color: selected ? const Color(0xFFCFE3DD) : C.muted)),
          ]),
        ),
      );
}

/* ============================================================== detail */

class ComplaintScreen extends ConsumerStatefulWidget {
  const ComplaintScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<ComplaintScreen> createState() => _ComplaintScreenState();
}

class _ComplaintScreenState extends ConsumerState<ComplaintScreen> {
  final _reply = TextEditingController();
  bool _sending = false;

  @override
  void dispose() {
    _reply.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final t = _reply.text.trim();
    if (t.length < 2) return;
    setState(() => _sending = true);
    try {
      await ref.read(appApiProvider).replyComplaint(widget.id, t);
      _reply.clear();
      ref.invalidate(complaintProvider(widget.id));
      ref.invalidate(complaintsProvider);
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = ref.watch(complaintProvider(widget.id));
    return Scaffold(
      appBar: _bar(context, c.valueOrNull?['ref']?.toString() ?? context.tr('الشكوى', 'Complaint')),
      body: c.when(
        loading: () => const LoadingBox(),
        error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(complaintProvider(widget.id))),
        data: (d) {
          final msgs = (d['messages'] as List?) ?? const [];
          final photos = ((d['photos'] as List?) ?? const []).map((e) => '$e').toList();
          final color = _stateColor(d);
          return Column(children: [
            Expanded(
              child: RefreshIndicator(
                onRefresh: () => ref.refresh(complaintProvider(widget.id).future),
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 20),
                  children: [
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(color: C.surface, borderRadius: BorderRadius.circular(R.card)),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Expanded(child: Text('${d['subject']}', style: display(16))),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                            decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(R.chip)),
                            child: Text('${d['status_label']}', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: color)),
                          ),
                        ]),
                        const SizedBox(height: 6),
                        Text(
                          [d['kind_label'], if ('${d['category_label'] ?? ''}'.isNotEmpty) d['category_label'],
                            if ('${d['order_number']}'.isNotEmpty) '#${d['order_number']}'].join(' · '),
                          style: const TextStyle(fontSize: 12, color: C.muted),
                        ),
                        if ('${d['details'] ?? ''}'.isNotEmpty) ...[
                          const SizedBox(height: 12),
                          Text('${d['details']}', style: const TextStyle(height: 1.7)),
                        ],
                        if (photos.isNotEmpty) ...[
                          const SizedBox(height: 12),
                          SizedBox(
                            height: 76,
                            child: ListView.separated(
                              scrollDirection: Axis.horizontal,
                              itemCount: photos.length,
                              separatorBuilder: (_, __) => const SizedBox(width: 8),
                              itemBuilder: (_, i) => SizedBox(width: 76, child: NetImage(photos[i], fit: BoxFit.cover, padding: 0)),
                            ),
                          ),
                        ],
                      ]),
                    ),
                    if (d['needs_reply'] == true) ...[
                      const SizedBox(height: 10),
                      _Notice(text: context.tr('فريقنا بانتظار ردك على هذه الشكوى.', 'Our team is waiting for your reply on this case.')),
                    ],
                    const SizedBox(height: 14),
                    if (msgs.isEmpty)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 20),
                        child: Text(
                          context.tr('وصلتنا شكواك وبنرد عليك هنا وعلى الواتساب.', "We got it — we'll reply here and on WhatsApp."),
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: C.muted),
                        ),
                      ),
                    for (final m in msgs) _Bubble(m: Map<String, dynamic>.from(m as Map)),
                  ],
                ),
              ),
            ),
            if (d['open'] == true)
              SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 6, 12, 10),
                  child: Row(children: [
                    Expanded(
                      child: TextField(
                        controller: _reply,
                        minLines: 1,
                        maxLines: 4,
                        decoration: InputDecoration(
                          hintText: context.tr('اكتب ردك…', 'Write a reply…'),
                          filled: true,
                          fillColor: C.surface,
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(24), borderSide: BorderSide.none),
                          contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    IconButton.filled(
                      onPressed: _sending ? null : _send,
                      icon: _sending
                          ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                          : Icon(context.isAr ? Icons.send_rounded : Icons.send_rounded, textDirection: TextDirection.ltr),
                    ),
                  ]),
                ),
              ),
          ]);
        },
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.m});
  final Map<String, dynamic> m;

  @override
  Widget build(BuildContext context) {
    final mine = m['from'] == 'me';
    final date = DateTime.tryParse('${m['date']}')?.toLocal();
    return Align(
      alignment: mine ? AlignmentDirectional.centerStart : AlignmentDirectional.centerEnd,
      child: Container(
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * .78),
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.fromLTRB(14, 10, 14, 8),
        decoration: BoxDecoration(
          color: mine ? C.primary : C.surface,
          borderRadius: BorderRadius.circular(18),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (!mine)
            Text(context.tr('خدمة العملاء', 'Customer care'),
                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: C.accentText)),
          Text('${m['text']}', style: TextStyle(color: mine ? Colors.white : C.ink, height: 1.6)),
          if (date != null)
            Text(
              '${date.day}/${date.month} ${date.hour.toString().padLeft(2, '0')}:${date.minute.toString().padLeft(2, '0')}',
              style: TextStyle(fontSize: 10, color: mine ? const Color(0xFFCFE3DD) : C.faint),
            ),
        ]),
      ),
    );
  }
}
