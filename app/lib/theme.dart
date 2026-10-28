import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Colors taken from the approved design canvas.
class C {
  static const ground = Color(0xFFF5F4EF);
  static const surface = Color(0xFFFFFFFF);
  static const ink = Color(0xFF13201F);
  static const muted = Color(0xFF5B6664);
  static const faint = Color(0xFF8A9693);
  static const line = Color(0xFFEDEBE4);
  static const primary = Color(0xFF0E4A43);
  static const primaryDeep = Color(0xFF08302B);
  static const primarySoft = Color(0xFF165B52);
  static const accent = Color(0xFFFF8A3D);
  static const accentStrong = Color(0xFFF2711C);
  static const accentText = Color(0xFFB8470A);
  static const mint = Color(0xFFDCEBE6);
  static const peach = Color(0xFFFFE6D3);
  static const peachDeep = Color(0xFFFFD4B3);
  static const lilac = Color(0xFFE6E9F7);
  static const sky = Color(0xFFD9EEF5);
  static const sand = Color(0xFFF3E9D6);
  static const success = Color(0xFF1F9D6B);

  static const tints = [peach, mint, lilac, sky, sand];
}

class R {
  static const card = 20.0;
  static const button = 16.0;
  static const chip = 999.0;
}

TextStyle display(double size, {FontWeight weight = FontWeight.w600, Color color = C.ink}) =>
    GoogleFonts.readexPro(fontSize: size, fontWeight: weight, color: color, height: 1.3);

ThemeData buildTheme() {
  final base = ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(
      seedColor: C.primary,
      primary: C.primary,
      secondary: C.accent,
      surface: C.surface,
    ),
    scaffoldBackgroundColor: C.ground,
  );
  final text = GoogleFonts.ibmPlexSansArabicTextTheme(base.textTheme).apply(
    bodyColor: C.ink,
    displayColor: C.ink,
  );
  return base.copyWith(
    textTheme: text,
    appBarTheme: AppBarTheme(
      backgroundColor: C.ground,
      foregroundColor: C.ink,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: true,
      titleTextStyle: display(16),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: C.primary,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(54),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(R.button)),
        textStyle: GoogleFonts.ibmPlexSansArabic(fontSize: 16, fontWeight: FontWeight.w700),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: C.surface,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(R.button),
        borderSide: BorderSide.none,
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(R.button),
        borderSide: const BorderSide(color: C.primary, width: 1.5),
      ),
      hintStyle: const TextStyle(color: C.muted),
    ),
    snackBarTheme: const SnackBarThemeData(behavior: SnackBarBehavior.floating),
  );
}
