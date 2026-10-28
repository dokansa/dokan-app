import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/client.dart';
import '../l10n/lang.dart';
import '../models/models.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// "مساعد دكان": the customer describes what they need in their own words and gets
/// a short answer with matching products from the store.
class AssistantScreen extends ConsumerStatefulWidget {
  const AssistantScreen({super.key, this.initial = ''});
  final String initial;

  @override
  ConsumerState<AssistantScreen> createState() => _AssistantScreenState();
}

class _AssistantScreenState extends ConsumerState<AssistantScreen> {
  final _ctrl = TextEditingController();
  final _scroll = ScrollController();
  final _messages = <ChatMessage>[];
  bool _thinking = false;

  @override
  void initState() {
    super.initState();
    if (widget.initial.trim().isNotEmpty) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _send(widget.initial));
    }
  }

  @override
  void dispose() {
    _ctrl.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _toBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.animateTo(_scroll.position.maxScrollExtent,
            duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
      }
    });
  }

  Future<void> _send(String text) async {
    final t = text.trim();
    if (t.isEmpty || _thinking) return;
    final clipped = t.length > 600 ? t.substring(0, 600) : t;
    setState(() {
      _messages.add(ChatMessage(fromUser: true, text: clipped));
      _thinking = true;
      _ctrl.clear();
    });
    _toBottom();
    try {
      final pet = ref.read(selectedPetProvider);
      final reply = await ref.read(appApiProvider).assistant(_messages, petId: pet?.id);
      if (!mounted) return;
      setState(() => _messages.add(reply.text.isEmpty && reply.productIds.isEmpty
          ? ChatMessage(fromUser: false, text: AppLang.t('ما فهمت عليك تماماً، توضح لي أكثر؟', 'I didn\'t quite get that — could you tell me a bit more?'))
          : reply));
    } on ApiError catch (e) {
      if (!mounted) return;
      setState(() => _messages.add(ChatMessage(fromUser: false, text: e.message)));
    } catch (_) {
      if (!mounted) return;
      setState(() => _messages.add(ChatMessage(
          fromUser: false, text: AppLang.t('صار خطأ، حاول مرة ثانية', 'Something went wrong, please try again'))));
    } finally {
      if (mounted) setState(() => _thinking = false);
      _toBottom();
    }
  }

  List<String> _starters(BuildContext context, Pet? pet) {
    final name = pet?.name;
    final kind = pet?.kind ?? 'cat';
    if (kind == 'dog') {
      return [
        context.tr('${name ?? 'كلبي'} يحك جلده كثير، وش يناسبه؟', '${name ?? 'My dog'} scratches a lot — what helps?'),
        context.tr('أبي أكل جاف ممتاز لكلب بالغ', 'Best dry food for an adult dog'),
        context.tr('ألعاب تشغل الكلب وقت طويل', 'Toys that keep a dog busy'),
      ];
    }
    return [
      context.tr('${name ?? 'قطتي'} صغيرة، وش أعطيها أكل؟', 'Food for my kitten?'),
      context.tr('أبي رمل ما يطلع ريحة ولا غبار', 'Litter with no smell and no dust'),
      context.tr('${name ?? 'قطتي'} معقمة وتسمن، وش الأكل المناسب؟', '${name ?? 'My cat'} is neutered and gaining weight — which food?'),
      context.tr('هدية حلوة لقطة بحدود ١٠٠ ريال', 'A nice gift for a cat around 100 SAR'),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final pet = ref.watch(selectedPetProvider);
    final pets = ref.watch(petsProvider).valueOrNull ?? const <Pet>[];
    return Scaffold(
      appBar: AppBar(
        title: Row(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.auto_awesome_rounded, color: C.accentText, size: 20),
          const SizedBox(width: 6),
          Text(context.tr('مساعد دكان', 'Dokan assistant')),
        ]),
        leading: Padding(
          padding: const EdgeInsets.all(5),
          child: RoundIconButton(icon: Icons.arrow_back_rounded, label: context.tr('رجوع', 'Back')),
        ),
        actions: [
          if (_messages.isNotEmpty)
            IconButton(
              tooltip: context.tr('محادثة جديدة', 'New chat'),
              icon: const Icon(Icons.refresh_rounded),
              onPressed: _thinking ? null : () => setState(_messages.clear),
            ),
        ],
      ),
      body: Column(
        children: [
          if (pets.isNotEmpty)
            SizedBox(
              height: 48,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 16),
                children: [
                  Center(
                    child: Text(context.tr('نتكلم عن: ', 'Talking about: '),
                        style: const TextStyle(color: C.muted, fontSize: 13)),
                  ),
                  for (final p in pets)
                    Padding(
                      padding: const EdgeInsetsDirectional.only(start: 6),
                      child: ChoiceChip(
                        label: Text(p.name),
                        selected: p.id == pet?.id,
                        onSelected: (_) => ref.read(selectedPetIdProvider.notifier).state = p.id,
                        showCheckmark: false,
                        selectedColor: C.ink,
                        backgroundColor: C.surface,
                        labelStyle: TextStyle(color: p.id == pet?.id ? Colors.white : C.ink, fontWeight: FontWeight.w600),
                        side: BorderSide.none,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(R.chip)),
                      ),
                    ),
                ],
              ),
            ),
          Expanded(
            child: ListView(
              controller: _scroll,
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
              children: [
                _Bubble(
                  message: ChatMessage(
                    fromUser: false,
                    text: context.tr(
                      'هلا! أنا مساعد دكان 🐾 قول لي عن حيوانك ووش تحتاج — العمر، المشكلة، الميزانية — وأطلع لك الأنسب من المتجر.',
                      "Hi! I'm the Dokan assistant 🐾 Tell me about your pet and what you need — age, problem, budget — and I'll find the best fit in the store.",
                    ),
                    chips: _messages.isEmpty ? _starters(context, pet) : const [],
                  ),
                  onChip: _send,
                ),
                for (var i = 0; i < _messages.length; i++)
                  _Bubble(
                    message: _messages[i],
                    // Only the latest answer keeps its follow-up suggestions.
                    showChips: i == _messages.length - 1,
                    onChip: _send,
                  ),
                if (_thinking) const _Typing(),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
            child: Text(
              context.tr('يقترح المساعد من منتجات دكان. للأعراض والأمراض راجع الطبيب البيطري.',
                  'Suggestions come from Dokan products. For symptoms or illness, please see a vet.'),
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 11, color: C.muted),
            ),
          ),
          SafeArea(
            top: false,
            minimum: const EdgeInsets.fromLTRB(16, 4, 16, 12),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _ctrl,
                    minLines: 1,
                    maxLines: 4,
                    textInputAction: TextInputAction.send,
                    onSubmitted: _send,
                    decoration: InputDecoration(hintText: context.tr('اكتب وش تحتاج…', 'Type what you need…')),
                  ),
                ),
                const SizedBox(width: 8),
                Tooltip(
                  message: context.tr('إرسال', 'Send'),
                  child: Material(
                    color: C.primary,
                    borderRadius: BorderRadius.circular(16),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(16),
                      onTap: _thinking ? null : () => _send(_ctrl.text),
                      child: SizedBox(
                        width: 50,
                        height: 50,
                        child: const Icon(Icons.send_rounded, color: Colors.white),
                      ),
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

class _Bubble extends ConsumerWidget {
  const _Bubble({required this.message, required this.onChip, this.showChips = true});
  final ChatMessage message;
  final ValueChanged<String> onChip;
  final bool showChips;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mine = message.fromUser;
    final bubble = Container(
      constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.8),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: mine ? C.primary : C.surface,
        borderRadius: BorderRadiusDirectional.only(
          topStart: const Radius.circular(20),
          topEnd: const Radius.circular(20),
          bottomStart: Radius.circular(mine ? 20 : 6),
          bottomEnd: Radius.circular(mine ? 6 : 20),
        ),
      ),
      child: Text(message.text, style: TextStyle(color: mine ? Colors.white : C.ink, fontSize: 14, height: 1.6)),
    );
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: mine ? CrossAxisAlignment.end : CrossAxisAlignment.start,
        children: [
          bubble,
          if (message.productIds.isNotEmpty) ...[
            const SizedBox(height: 8),
            _Products(ids: message.productIds),
          ],
          if (showChips && message.chips.isNotEmpty) ...[
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final c in message.chips)
                  ActionChip(
                    label: Text(c),
                    onPressed: () => onChip(c),
                    backgroundColor: C.mint,
                    labelStyle: const TextStyle(color: C.primaryDeep, fontWeight: FontWeight.w600, fontSize: 13),
                    side: BorderSide.none,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(R.chip)),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _Products extends ConsumerWidget {
  const _Products({required this.ids});
  final List<int> ids;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final products = ref.watch(productsByIdsProvider(idsKey(ids)));
    return products.when(
      loading: () => const LoadingBox(height: 120),
      error: (e, _) => const SizedBox.shrink(),
      data: (list) => SizedBox(
        height: 262,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          itemCount: list.length,
          separatorBuilder: (_, __) => const SizedBox(width: 10),
          itemBuilder: (_, i) => ProductCard(list[i], index: i),
        ),
      ),
    );
  }
}

class _Typing extends StatelessWidget {
  const _Typing();

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: AlignmentDirectional.centerStart,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        decoration: BoxDecoration(color: C.surface, borderRadius: BorderRadius.circular(20)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2, color: C.primary)),
          const SizedBox(width: 10),
          Text(context.tr('أدور لك…', 'Looking…'), style: const TextStyle(color: C.muted)),
        ]),
      ),
    );
  }
}
