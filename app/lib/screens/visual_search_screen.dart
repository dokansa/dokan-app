import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_mlkit_barcode_scanning/google_mlkit_barcode_scanning.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:image_picker/image_picker.dart';

import '../l10n/lang.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Search by photo: the phone reads the barcode and the words on the package (on the device,
/// no keys needed), then the store finds the product. With Claude enabled on the store, the
/// photo itself is also understood.
class VisualSearchScreen extends ConsumerStatefulWidget {
  const VisualSearchScreen({super.key, this.camera = true});
  final bool camera;

  @override
  ConsumerState<VisualSearchScreen> createState() => _VisualSearchState();
}

class _VisualSearchState extends ConsumerState<VisualSearchScreen> {
  File? _photo;
  String _status = '';
  String _query = '';
  List<int> _ids = const [];
  bool _busy = false;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _pick(widget.camera ? ImageSource.camera : ImageSource.gallery));
  }

  Future<void> _pick(ImageSource src) async {
    try {
      final f = await ImagePicker().pickImage(source: src, maxWidth: 1600, maxHeight: 1600, imageQuality: 80);
      if (f == null) {
        if (!_done && _photo == null && mounted) Navigator.of(context).maybePop();
        return;
      }
      setState(() {
        _photo = File(f.path);
        _ids = const [];
        _query = '';
        _done = false;
      });
      await _run(f.path);
    } catch (_) {
      if (mounted) showMessage(context, context.tr('تعذّر فتح الكاميرا أو الصور', 'Could not open the camera or photos'), error: true);
    }
  }

  /// Brand / product words from the package text: drop numbers, weights and very common words.
  String _wordsFrom(RecognizedText t) {
    const noise = {
      'net', 'wt', 'weight', 'ingredients', 'kg', 'g', 'gm', 'ml', 'lb', 'lbs', 'oz', 'the', 'and', 'for', 'with',
      'made', 'in', 'of', 'best', 'before', 'exp', 'lot', 'www', 'com', 'food', 'new', 'premium', 'complete',
    };
    // Bigger text first: brand and product name are printed largest.
    final lines = [...t.blocks.expand((b) => b.lines)]
      ..sort((a, b) => b.boundingBox.height.compareTo(a.boundingBox.height));
    final words = <String>[];
    for (final l in lines) {
      for (final raw in l.text.split(RegExp(r'[\s,;:/|()\[\]]+'))) {
        final w = raw.replaceAll(RegExp(r"[^A-Za-z؀-ۿ'&-]"), '');
        if (w.length < 3 || noise.contains(w.toLowerCase())) continue;
        if (words.any((x) => x.toLowerCase() == w.toLowerCase())) continue;
        words.add(w);
        if (words.length >= 6) break;
      }
      if (words.length >= 6) break;
    }
    return words.join(' ');
  }

  Future<void> _run(String path) async {
    setState(() {
      _busy = true;
      _status = context.tr('نقرأ الباركود…', 'Reading the barcode…');
    });
    final api = ref.read(appApiProvider);
    final input = InputImage.fromFilePath(path);
    try {
      // 1) Barcode → exact product.
      final scanner = BarcodeScanner(formats: [
        BarcodeFormat.ean13, BarcodeFormat.ean8, BarcodeFormat.upca, BarcodeFormat.upce, BarcodeFormat.code128,
      ]);
      final codes = await scanner.processImage(input);
      await scanner.close();
      for (final c in codes) {
        final v = c.rawValue ?? '';
        if (v.length < 6) continue;
        final ids = await api.searchBarcode(v);
        if (ids.isNotEmpty) {
          _finish(ids, context.tr('من الباركود', 'From the barcode'));
          return;
        }
      }

      // 2) Words on the package (+ the photo itself when the store has AI).
      if (!mounted) return;
      setState(() => _status = context.tr('نقرأ اسم المنتج…', 'Reading the product name…'));
      final rec = TextRecognizer(script: TextRecognitionScript.latin);
      final text = _wordsFrom(await rec.processImage(input));
      await rec.close();

      if (!mounted) return;
      setState(() => _status = context.tr('ندوّر عليه في دكان…', 'Looking for it in Dokan…'));
      final bytes = await File(path).readAsBytes();
      final r = await api.searchImage(base64Encode(bytes), text);
      _finish(r.$2, r.$1);
    } catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _done = true;
          _status = '';
        });
        showMessage(context, e, error: true);
      }
    }
  }

  void _finish(List<int> ids, String query) {
    if (!mounted) return;
    setState(() {
      _ids = ids;
      _query = query;
      _busy = false;
      _done = true;
      _status = '';
    });
  }

  @override
  Widget build(BuildContext context) {
    final products = _ids.isEmpty ? null : ref.watch(productsByIdsProvider(_ids.join(',')));
    return Scaffold(
      appBar: AppBar(
        title: Text(context.tr('البحث بالصورة', 'Search by photo')),
        leading: Padding(
          padding: const EdgeInsets.all(5),
          child: RoundIconButton(icon: Icons.arrow_back_rounded, label: context.tr('رجوع', 'Back')),
        ),
      ),
      body: CustomScrollView(slivers: [
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
            child: Row(children: [
              if (_photo != null)
                ClipRRect(
                  borderRadius: BorderRadius.circular(16),
                  child: Image.file(_photo!, width: 84, height: 84, fit: BoxFit.cover),
                ),
              const SizedBox(width: 14),
              Expanded(
                child: _busy
                    ? Row(children: [
                        const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
                        const SizedBox(width: 10),
                        Expanded(child: Text(_status, style: const TextStyle(color: C.primary))),
                      ])
                    : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        if (_done)
                          Text(
                            _ids.isEmpty
                                ? context.tr('ما لقينا المنتج — جرّب صورة أوضح للاسم أو الباركود', "We couldn't find it — try a clearer photo of the name or barcode")
                                : context.tr('لقينا ${_ids.length} منتج', 'Found ${_ids.length} products'),
                            style: const TextStyle(fontWeight: FontWeight.w700),
                          ),
                        if (_query.isNotEmpty)
                          Text(_query, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: C.muted, fontSize: 12.5)),
                      ]),
              ),
            ]),
          ),
        ),
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 14),
            child: Row(children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _busy ? null : () => _pick(ImageSource.camera),
                  icon: const Icon(Icons.photo_camera_outlined),
                  label: Text(context.tr('صوّر', 'Camera')),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _busy ? null : () => _pick(ImageSource.gallery),
                  icon: const Icon(Icons.photo_library_outlined),
                  label: Text(context.tr('من الصور', 'Gallery')),
                ),
              ),
            ]),
          ),
        ),
        if (products != null)
          products.when(
            loading: () => const SliverToBoxAdapter(child: LoadingBox()),
            error: (e, _) => SliverToBoxAdapter(child: ErrorBox(e)),
            data: (list) => SliverPadding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 30),
              sliver: SliverGrid(
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 2,
                  mainAxisSpacing: 12,
                  crossAxisSpacing: 12,
                  mainAxisExtent: 262,
                ),
                delegate: SliverChildBuilderDelegate(
                  (_, i) => ProductCard(list[i], index: i, width: null),
                  childCount: list.length,
                ),
              ),
            ),
          ),
        if (_done && _ids.isEmpty)
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.all(30),
              child: Column(children: [
                const Icon(Icons.image_search_rounded, size: 54, color: C.faint),
                const SizedBox(height: 10),
                Text(
                  context.tr('صوّر واجهة الكيس أو العلبة بحيث يبان اسم الماركة، أو صوّر الباركود.',
                      'Photograph the front of the bag or can so the brand shows, or the barcode.'),
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: C.muted),
                ),
              ]),
            ),
          ),
      ]),
    );
  }
}
