import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../models/models.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

class PetScreen extends ConsumerWidget {
  const PetScreen({super.key, required this.id});
  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pets = ref.watch(petsProvider).valueOrNull ?? const <Pet>[];
    final pet = pets.where((p) => p.id == id).firstOrNull;
    if (pet == null) {
      return const Scaffold(body: LoadingBox());
    }
    final stock = ref.watch(petStockProvider(id));
    final top = MediaQuery.paddingOf(context).top;

    return Scaffold(
      body: ListView(
        padding: const EdgeInsets.only(bottom: 40),
        children: [
          Container(
            padding: EdgeInsets.fromLTRB(20, top + 8, 20, 26),
            decoration: const BoxDecoration(
              color: C.peach,
              borderRadius: BorderRadius.vertical(bottom: Radius.circular(36)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [
                  const RoundIconButton(icon: Icons.arrow_back_rounded, label: 'رجوع'),
                  Expanded(child: Center(child: Text('حيواناتي', style: display(16)))),
                  RoundIconButton(
                    icon: Icons.edit_outlined,
                    label: 'تعديل الملف',
                    onTap: () => context.push('/pet-edit', extra: pet),
                  ),
                ]),
                const SizedBox(height: 18),
                Row(children: [
                  PetAvatar(pet: pet, size: 92, tint: C.surface),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(pet.name, style: display(28, weight: FontWeight.w700)),
                      Text([pet.kindLabel, if (pet.breed.isNotEmpty) pet.breed].join(' · '),
                          style: const TextStyle(fontSize: 14, color: Color(0xFF3D4846))),
                    ]),
                  ),
                ]),
                const SizedBox(height: 18),
                Row(children: [
                  _Fact('العمر', pet.ageLabel ?? '—'),
                  const SizedBox(width: 8),
                  _Fact('الوزن', pet.weightKg == null ? '—' : '${_num(pet.weightKg!)} كجم'),
                  const SizedBox(width: 8),
                  _Fact('الأكل', foodTypes[pet.foodType] ?? '—'),
                ]),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('مخزون ${pet.name}', style: display(17)),
                const SizedBox(height: 10),
                stock.when(
                  loading: () => const LoadingBox(height: 100),
                  error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(petStockProvider(id))),
                  data: (items) => items.isEmpty
                      ? Container(
                          padding: const EdgeInsets.all(16),
                          decoration: BoxDecoration(color: C.surface, borderRadius: BorderRadius.circular(R.card)),
                          child: Text(
                            'أول ما تطلب أكل أو رمل لـ${pet.name} بنحسب لك كم يكفي ونذكرك قبل ما يخلص.',
                            style: const TextStyle(color: C.muted, height: 1.6),
                          ),
                        )
                      : Column(children: [for (final (i, s) in items.indexed) _StockRow(item: s, index: i)]),
                ),
                const SizedBox(height: 10),
                ToggleRow(
                  icon: Icons.notifications_none_rounded,
                  title: 'تذكير قبل ما يخلص الأكل',
                  subtitle: 'إشعار قبل ٥ أيام تقريباً',
                  value: pet.remind,
                  onChanged: (v) => ref.read(petsProvider.notifier).setRemind(pet, v).catchError((e) {
                    if (context.mounted) showMessage(context, e, error: true);
                  }),
                ),
                const SizedBox(height: 14),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    minimumSize: const Size.fromHeight(54),
                    foregroundColor: C.ink,
                    side: const BorderSide(color: C.faint, width: 1.5),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(R.button)),
                  ),
                  onPressed: () => context.push('/pet-edit'),
                  icon: const Icon(Icons.add_rounded),
                  label: const Text('أضف حيوان ثاني', style: TextStyle(fontWeight: FontWeight.w700)),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  static String _num(double v) => v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toStringAsFixed(1);
}

class _Fact extends StatelessWidget {
  const _Fact(this.label, this.value);
  final String label;
  final String value;
  @override
  Widget build(BuildContext context) => Expanded(
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(color: C.surface, borderRadius: BorderRadius.circular(16)),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: const TextStyle(fontSize: 12, color: C.muted)),
            Text(value, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
          ]),
        ),
      );
}

class _StockRow extends StatelessWidget {
  const _StockRow({required this.item, required this.index});
  final StockItem item;
  final int index;

  @override
  Widget build(BuildContext context) {
    final note = item.daysLeft <= 0
        ? 'المفروض خلص'
        : item.low
            ? 'يخلص بعد ${item.daysLeft} أيام تقريباً'
            : 'يكفي ${item.daysLeft} يوم تقريباً';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: C.surface, borderRadius: BorderRadius.circular(R.card)),
      child: Column(children: [
        Row(children: [
          SizedBox(width: 46, height: 46, child: NetImage(item.image, tint: C.tints[index % C.tints.length], padding: 4)),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(item.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
              Text(note, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: item.low ? C.accentText : C.muted)),
            ]),
          ),
          TextButton(
            style: TextButton.styleFrom(backgroundColor: C.ground, foregroundColor: C.primary, minimumSize: const Size(64, 44)),
            onPressed: () => context.push('/product/${item.productId}'),
            child: const Text('اطلبه', style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ]),
        const SizedBox(height: 10),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: item.percentLeft / 100,
            minHeight: 8,
            color: item.low ? C.accentStrong : C.primary,
            backgroundColor: C.line,
          ),
        ),
      ]),
    );
  }
}

class PetEditScreen extends ConsumerStatefulWidget {
  const PetEditScreen({super.key, this.pet});
  final Pet? pet;

  @override
  ConsumerState<PetEditScreen> createState() => _PetEditScreenState();
}

class _PetEditScreenState extends ConsumerState<PetEditScreen> {
  final _form = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.pet?.name ?? '');
  late final _breed = TextEditingController(text: widget.pet?.breed ?? '');
  late final _weight = TextEditingController(
      text: widget.pet?.weightKg == null ? '' : widget.pet!.weightKg!.toString());
  late String _kind = widget.pet?.kind ?? 'cat';
  late String? _food = widget.pet?.foodType ?? 'dry';
  late DateTime? _birth = DateTime.tryParse(widget.pet?.birthDate ?? '');
  bool _saving = false;

  @override
  void dispose() {
    _name.dispose();
    _breed.dispose();
    _weight.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _saving = true);
    final pet = Pet(
      id: widget.pet?.id ?? '',
      name: _name.text.trim(),
      kind: _kind,
      breed: _breed.text.trim(),
      birthDate: _birth == null
          ? null
          : '${_birth!.year.toString().padLeft(4, '0')}-${_birth!.month.toString().padLeft(2, '0')}-${_birth!.day.toString().padLeft(2, '0')}',
      weightKg: double.tryParse(_weight.text.trim().replaceAll('٫', '.')),
      foodType: _food,
      remind: widget.pet?.remind ?? true,
    );
    try {
      final saved = await ref.read(petsProvider.notifier).save(pet, id: widget.pet?.id);
      ref.read(selectedPetIdProvider.notifier).state = saved.id;
      ref.invalidate(forPetProvider);
      if (mounted) context.pop();
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _delete() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('تحذف ملف ${widget.pet!.name}؟'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('لا')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('احذف')),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ref.read(petsProvider.notifier).delete(widget.pet!.id);
      ref.read(selectedPetIdProvider.notifier).state = null;
      if (mounted) context.go('/');
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.pet == null ? 'حيوان جديد' : 'تعديل ${widget.pet!.name}'),
        leading: const Padding(padding: EdgeInsets.all(5), child: RoundIconButton(icon: Icons.arrow_back_rounded, label: 'رجوع')),
        actions: [
          if (widget.pet != null)
            IconButton(tooltip: 'حذف', onPressed: _delete, icon: const Icon(Icons.delete_outline_rounded)),
        ],
      ),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
          children: [
            const Text('نوعه', style: TextStyle(fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final e in petKinds.entries)
                ChoiceChip(
                  label: Text(e.value),
                  selected: _kind == e.key,
                  onSelected: (_) => setState(() => _kind = e.key),
                  showCheckmark: false,
                  selectedColor: C.ink,
                  backgroundColor: C.surface,
                  side: BorderSide.none,
                  labelStyle: TextStyle(color: _kind == e.key ? Colors.white : C.ink, fontWeight: FontWeight.w700),
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                ),
            ]),
            const SizedBox(height: 16),
            TextFormField(
              controller: _name,
              validator: (v) => v == null || v.trim().isEmpty ? 'اكتب اسمه' : null,
              decoration: const InputDecoration(labelText: 'اسمه'),
            ),
            const SizedBox(height: 10),
            TextFormField(controller: _breed, decoration: const InputDecoration(labelText: 'السلالة (اختياري)')),
            const SizedBox(height: 10),
            Row(children: [
              Expanded(
                child: TextFormField(
                  controller: _weight,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(labelText: 'الوزن بالكيلو (اختياري)'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Material(
                  color: C.surface,
                  borderRadius: BorderRadius.circular(R.button),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(R.button),
                    onTap: () async {
                      final now = DateTime.now();
                      final d = await showDatePicker(
                        context: context,
                        initialDate: _birth ?? DateTime(now.year - 1, now.month),
                        firstDate: DateTime(now.year - 30),
                        lastDate: now,
                        helpText: 'تاريخ الميلاد التقريبي',
                      );
                      if (d != null) setState(() => _birth = d);
                    },
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
                      child: Text(
                        _birth == null ? 'تاريخ الميلاد' : '${_birth!.year}/${_birth!.month}/${_birth!.day}',
                        style: TextStyle(color: _birth == null ? C.muted : C.ink, fontSize: 15),
                      ),
                    ),
                  ),
                ),
              ),
            ]),
            const SizedBox(height: 16),
            const Text('نوع الأكل', style: TextStyle(fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            SegmentedButton<String>(
              segments: [for (final e in foodTypes.entries) ButtonSegment(value: e.key, label: Text(e.value))],
              selected: {if (_food != null) _food!},
              emptySelectionAllowed: true,
              showSelectedIcon: false,
              onSelectionChanged: (s) => setState(() => _food = s.isEmpty ? null : s.first),
            ),
            const SizedBox(height: 28),
            FilledButton(
              onPressed: _saving ? null : _save,
              child: _saving
                  ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                  : const Text('احفظ'),
            ),
          ],
        ),
      ),
    );
  }
}
