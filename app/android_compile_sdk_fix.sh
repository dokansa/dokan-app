#!/usr/bin/env bash
# Some plugins (geocoding_android) still compile against an old Android SDK; force 36 for all.
set -e
cd "$(dirname "$0")/android"
if [ -f build.gradle.kts ]; then
  grep -q "DOKAN_COMPILE_SDK_FIX" build.gradle.kts || cat >> build.gradle.kts <<'KTS'

// DOKAN_COMPILE_SDK_FIX
subprojects {
    val fixSdk: Project.() -> Unit = {
        (extensions.findByName("android") as? com.android.build.gradle.BaseExtension)?.compileSdkVersion(36)
    }
    if (state.executed) fixSdk() else afterEvaluate { fixSdk() }
}
KTS
elif [ -f build.gradle ]; then
  grep -q "DOKAN_COMPILE_SDK_FIX" build.gradle || cat >> build.gradle <<'GRV'

// DOKAN_COMPILE_SDK_FIX
subprojects { p ->
    def fixSdk = { if (p.hasProperty('android')) { p.android.compileSdkVersion 36 } }
    if (p.state.executed) { fixSdk() } else { p.afterEvaluate { fixSdk() } }
}
GRV
fi
# The app module too.
APP=$(ls app/build.gradle* | head -1)
perl -pi -e 's/compileSdk\s*=\s*flutter\.compileSdkVersion/compileSdk = 36/; s/compileSdkVersion\s+flutter\.compileSdkVersion/compileSdkVersion 36/' "$APP"
echo "compileSdk fix applied"
