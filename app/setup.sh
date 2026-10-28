#!/usr/bin/env bash
# One-time setup: creates the iOS/Android project folders and applies Dokan's settings.
# Run from this folder on a Mac with Flutter installed:  bash setup.sh
set -euo pipefail
cd "$(dirname "$0")"

flutter create --org sa.dokansa --project-name dokan_app --platforms ios,android .
rm -rf test
flutter pub get

# ---------- Android
MANIFEST=android/app/src/main/AndroidManifest.xml
if ! grep -q 'android.permission.INTERNET' "$MANIFEST"; then
  perl -0pi -e 's#<application#<uses-permission android:name="android.permission.INTERNET"/>\n    <uses-permission android:name="android.permission.POST_NOTIFICATIONS"/>\n    <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"/>\n    <uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION"/>\n    <application#' "$MANIFEST"
fi
perl -pi -e 's#android:label="[^"]*"#android:label="دكان"#' "$MANIFEST"
# Firebase needs minSdk 23.
GRADLE=$(ls android/app/build.gradle* | head -1)
perl -pi -e 's/minSdk(Version)?\s*=?\s*flutter\.minSdkVersion/minSdk = 23/' "$GRADLE"

# ---------- iOS
PLIST=ios/Runner/Info.plist
/usr/libexec/PlistBuddy -c "Set :CFBundleDisplayName دكان" "$PLIST" 2>/dev/null || true
/usr/libexec/PlistBuddy -c "Add :CFBundleDevelopmentRegion string ar" "$PLIST" 2>/dev/null || \
  /usr/libexec/PlistBuddy -c "Set :CFBundleDevelopmentRegion ar" "$PLIST" 2>/dev/null || true
/usr/libexec/PlistBuddy -c "Add :CFBundleLocalizations array" "$PLIST" 2>/dev/null || true
/usr/libexec/PlistBuddy -c "Add :CFBundleLocalizations:0 string ar" "$PLIST" 2>/dev/null || true
/usr/libexec/PlistBuddy -c "Add :ITSAppUsesNonExemptEncryption bool false" "$PLIST" 2>/dev/null || true
/usr/libexec/PlistBuddy -c "Add :NSLocationWhenInUseUsageDescription string نستخدم موقعك مرة وحدة لتعبئة عنوان التوصيل / We use your location once to fill in your delivery address" "$PLIST" 2>/dev/null || true
/usr/libexec/PlistBuddy -c "Add :CFBundleLocalizations:1 string en" "$PLIST" 2>/dev/null || true
/usr/libexec/PlistBuddy -c "Add :UIBackgroundModes array" "$PLIST" 2>/dev/null || true
/usr/libexec/PlistBuddy -c "Add :UIBackgroundModes:0 string remote-notification" "$PLIST" 2>/dev/null || true
sed -i.bak "s/platform :ios, '[0-9.]*'/platform :ios, '13.0'/; s/# platform :ios/platform :ios/" ios/Podfile 2>/dev/null || true
rm -f ios/Podfile.bak

# ---------- Icons
dart run flutter_launcher_icons

echo
echo "Done. Next: connect Firebase with  flutterfire configure  (see README.md), then  flutter run"
