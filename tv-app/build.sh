#!/usr/bin/env bash
# Construit l'APK de l'application télévision sans Android Studio : un JDK en
# conteneur et les outils Android en ligne de commande (aapt2, javac, d8,
# zipalign, apksigner). Résultat : backend/resources/tv/memorylane-tv.apk,
# servi par /tv/application (et /apk).
#
# Prérequis (une fois) — SDK hors dépôt, ~/.cache/android-sdk :
#   cmdline-tools/latest (commandlinetools-linux), puis dans eclipse-temurin:17-jdk :
#   sdkmanager --sdk_root=/sdk "platforms;android-34" "build-tools;34.0.0"
# Clé de signature hors dépôt : ~/.memorylane-tv/ (créée au premier passage).
# ⚠️ La garder : une mise à jour signée avec une autre clé ne s'installe pas
# par-dessus l'application existante.
# Nouvelle version : augmenter versionCode / versionName dans AndroidManifest.xml.
set -euo pipefail
cd "$(dirname "$0")"
SDK=${ANDROID_SDK:-$HOME/.cache/android-sdk}
KEYS=${TV_KEYS:-$HOME/.memorylane-tv}
BT=34.0.0
API=34
OUT=../backend/resources/tv/memorylane-tv.apk
# Variante de test : TV_URL=https://dev.memory-lane.fr/tv/ecran TV_AUTH_FILE=~/.memorylane-dev-password ./build.sh
# (APK laissé dans build/, jamais publié ; identifiants lus du fichier, pas du dépôt).
TV_URL=${TV_URL:-https://memory-lane.fr/tv/ecran}
IMAGE=eclipse-temurin:17-jdk

[ -f "$SDK/platforms/android-$API/android.jar" ] && [ -x "$SDK/build-tools/$BT/aapt2" ] \
  || { echo "SDK Android absent de $SDK (voir l'en-tête de ce script)."; exit 1; }

mkdir -p "$KEYS" && chmod 700 "$KEYS"
if [ ! -f "$KEYS/release.keystore" ]; then
  echo "▶ Nouvelle clé de signature dans $KEYS"
  head -c 32 /dev/urandom | base64 | tr -d '/+=' > "$KEYS/password"
  chmod 600 "$KEYS/password"
  docker run --rm -u "$(id -u):$(id -g)" -v "$KEYS:/keys" "$IMAGE" keytool -genkeypair \
    -keystore /keys/release.keystore -alias memorylane-tv -keyalg RSA -keysize 4096 -validity 36500 \
    -storepass:file /keys/password -keypass:file /keys/password \
    -dname "CN=MemoryLane, O=MemoryLane, C=FR" >/dev/null
fi

rm -rf build && mkdir -p build/gen/fr/memorylane/tv build/classes
AUTH_USER=""; AUTH_PASSWORD=""
if [ -n "${TV_AUTH_FILE:-}" ]; then
  AUTH_USER=$(grep '^identifiant' "$TV_AUTH_FILE" | cut -d: -f2- | xargs)
  AUTH_PASSWORD=$(grep '^mot de passe' "$TV_AUTH_FILE" | cut -d: -f2- | xargs)
fi
cat > build/gen/fr/memorylane/tv/Config.java <<JAVA
package fr.memorylane.tv;
final class Config {
    static final String HOME = "$TV_URL";
    static final String AUTH_USER = "$AUTH_USER";
    static final String AUTH_PASSWORD = "$AUTH_PASSWORD";
}
JAVA
docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp \
  -v "$SDK:/sdk:ro" -v "$PWD:/app" -v "$KEYS:/keys:ro" -w /app "$IMAGE" bash -euc "
  T=/sdk/build-tools/$BT; J=/sdk/platforms/android-$API/android.jar
  \$T/aapt2 compile --dir res -o build/res.zip
  \$T/aapt2 link -o build/unsigned.apk -I \$J --manifest AndroidManifest.xml --java build/gen \
    --min-sdk-version 23 --target-sdk-version $API build/res.zip
  javac -source 8 -target 8 -nowarn -Xlint:-options -encoding UTF-8 -bootclasspath \$J:\$T/core-lambda-stubs.jar -classpath \$J \
    -d build/classes \$(find src build/gen -name '*.java')
  \$T/d8 --release --lib \$J --min-api 23 --output build \$(find build/classes -name '*.class')
  (cd build && \$T/aapt add unsigned.apk classes.dex >/dev/null)
  \$T/zipalign -f 4 build/unsigned.apk build/aligned.apk
  \$T/apksigner sign --ks /keys/release.keystore --ks-pass file:/keys/password --out build/memorylane-tv.apk build/aligned.apk
  \$T/apksigner verify build/memorylane-tv.apk
  \$T/aapt dump badging build/memorylane-tv.apk | grep -E '^(package|sdkVersion|targetSdkVersion|application-label|leanback-launchable)'
"
if [ "$TV_URL" != "https://memory-lane.fr/tv/ecran" ] || [ -n "$AUTH_USER" ]; then
  echo "✅ Variante de test : tv-app/build/memorylane-tv.apk ($TV_URL), non publiée"
  exit 0
fi
mkdir -p "$(dirname "$OUT")"
cp build/memorylane-tv.apk "$OUT"
# Version affichée sur la page « Relier une télévision ».
VNAME=$(grep -o 'android:versionName="[^"]*"' AndroidManifest.xml | cut -d'"' -f2)
VCODE=$(grep -o 'android:versionCode="[^"]*"' AndroidManifest.xml | cut -d'"' -f2)
printf '{"version":"%s","code":%s,"size":%s}\n' "$VNAME" "$VCODE" "$(stat -c %s "$OUT")" > "$(dirname "$OUT")/version.json"
echo "✅ $(du -h "$OUT" | cut -f1) → $OUT"
