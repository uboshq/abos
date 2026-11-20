import java.util.Properties

/*
 * সই করার চাবি — android/key.properties থেকে, যেটা git-এ নেই।
 *
 * ⛔ Android-এ একটা অ্যাপ যে কীস্টোর দিয়ে প্রথম সই হয়, চিরকাল সেটাই লাগে।
 * কীস্টোর বা পাসওয়ার্ড হারালে ABOS Mobile আর কোনোদিন আপডেট করা যাবে না —
 * নতুন নামে নতুন অ্যাপ ছাড়তে হবে, আর প্রতিটা ফোনে পুরনোটা মুছে নতুনটা
 * বসাতে হবে। ফেরানোর কোনো পথ নেই।
 *
 * ⚠️ ফাইলটা না থাকলে বিল্ড **থামে না** — debug চাবি দিয়ে সই হয়, ঠিক যেমন
 * আগে হত। এটা ইচ্ছাকৃত: যে ডেভেলপার কেবল কোড দেখছেন তাঁর কীস্টোর লাগে না,
 * আর কীস্টোর ছাড়া রিপোটা অকেজো হয়ে গেলে নতুন কারো পক্ষে শুরু করাই কঠিন।
 *
 * ⛔ কিন্তু তাতে একটা ফাঁদ আছে, আর সেটাই নিচের সতর্কবার্তাটার কারণ:
 * key.properties ছাড়া বানানো APK **বিতরণযোগ্য নয়** — ওটা debug চাবিতে সই,
 * আর ওটা দিয়ে আপডেট দিলে কোনো ফোন নেবে না। নীরবে ভুল হওয়ার বদলে বিল্ড
 * তখন জোরে বলে দেয়।
 */
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
val hasReleaseKey = keystorePropertiesFile.exists()
if (hasReleaseKey) {
    keystorePropertiesFile.inputStream().use { keystoreProperties.load(it) }
}

/*
 * ⭐ Google Play-র আপলোড-চাবি — android/key-play.properties থেকে (git-এ নেই; ২০ নভেম্বর ২০২৬)।
 *
 * Play App Signing: ফোনে যে চাবিতে সই থাকে সেটা Google-এর কাছে (Google-managed app key); আমরা কেবল আপলোডের চাবি
 * (univer-abos-upload.jks) দিয়ে .aab সই করে পাঠাই। ⓘ এটা side-এর abos-release.jks নয়, আলাদা — আপলোড-চাবি হারালে
 * Play Console থেকে বদলানো যায়, অ্যাপ হারায় না। ফাইলের চার লাইন key.properties-এর মতোই:
 * storeFile / storePassword / keyAlias / keyPassword।
 * ⛔ ফাইল না থাকলে play-এর release বিল্ড থামে (নিচে) — debug চাবিতে সই করা .aab Play নেয় না।
 */
val playKeyProperties = Properties()
val playKeyPropertiesFile = rootProject.file("key-play.properties")
val hasPlayKey = playKeyPropertiesFile.exists()
if (hasPlayKey) {
    playKeyPropertiesFile.inputStream().use { playKeyProperties.load(it) }
}

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
    id("com.google.gms.google-services")
    // ⭐ Glance widgets are Compose code (widget kit, 20 Oct 2026)
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "com.abos.abos_mobile"
    /*
     * ⭐ compileSdk আর targetSdk স্পষ্ট লেখা, Flutter-এর ডিফল্টে ছেড়ে নয় (অ্যাপ রিলিজ নিরীক্ষা, ec, ২০ অক্টোবর ২০২৬)।
     * Flutter 3.47.5-এর নিজের মান ৩৬ (FlutterExtension.kt), আর ওর সাথে পরীক্ষা করা সর্বশেষ স্থিতিশীল স্তরও ৩৬ (Android 16)।
     * ⚠️ ডিফল্টে ছেড়ে রাখলে Flutter আপগ্রেডের দিন targetSdk নীরবে বদলাত — আর targetSdk বদলানো মানে ফোনে আচরণ বদল
     * (অনুমতি, পটভূমির কাজ, পর্দার কিনারা), যা কোনো টেস্ট ধরে না। তাই বদলাতে হলে এখানে, জেনেশুনে, রিলিজ-চেকলিস্ট মেনে।
     * ⓘ ৩৭ (Android 17) SDK-তে এসেছে, কিন্তু এই Flutter-এর ডিফল্ট নয় আর এই PC-তে বসানোও নেই — পরের Flutter-এর সাথে।
     * test/android_build_config_test.dart এই দুই সংখ্যা ধরে রাখে।
     */
    compileSdk = 36
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // ⓘ আসল applicationId ফ্লেভারে (নিচে) — side আজকেরটাই, play নতুন
        applicationId = "com.abos.abos_mobile"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = 36
        // ⛔ ২০,০০০ যোগ করা হচ্ছে, আর এটা ইচ্ছাকৃত — ইতিহাস মোছার জন্য।
        //
        // Flutter split-per-ABI বিল্ডে নিজে থেকেই `1000 * ABI_VERSION` যোগ করে
        // (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)।
        // তাই ০.১.০ আর ০.২.০-র যে APK-গুলো মাঠে গেছে সেগুলোর versionCode
        // ১০০২ / ২০০২ ধরনের, অথচ pubspec-এ লেখা ছিল কেবল `+2`।
        //
        // ১৭ সেপ্টেম্বর ২০২৬-এ বিতরণ লিংকে চলে যাওয়ার সময় ধরা পড়ল: universal
        // APK-র versionCode হয় ৩, আর Android সেটাকে ৪০০৩-এর উপরে বসাতে অস্বীকার
        // করে — `INSTALL_FAILED_VERSION_DOWNGRADE`, যা ফোনে কেবল
        // "App not installed" হয়ে দেখা দেয়, কোনো কারণ ছাড়াই। অর্থাৎ নতুন
        // বিল্ডটা ঠিক তাদের ফোনেই বসত না যাদের কাছে পুরনোটা আছে।
        //
        // ⭐ তাই এখন থেকে **একটাই ধরনের APK** — universal, `flutter build apk
        // --release`। `--split-per-abi` আর ব্যবহার করবেন না: ওটা ২২০০৩ ধরনের
        // সংখ্যা বানাবে, আর তারপর পরের universal বিল্ডটা আর বসবে না। দুই ধরন
        // একসাথে বিলি করলে আপডেট চিরকালের জন্য ভাঙে।
        //
        // ২০,০০০ সংখ্যাটা কেবল "যা কিছু আগে গেছে তার উপরে" — সর্বোচ্চ ছিল ৪০০৩।
        versionCode = 20000 + flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (hasReleaseKey) {
            create("release") {
                keyAlias = keystoreProperties["keyAlias"] as String
                keyPassword = keystoreProperties["keyPassword"] as String
                storeFile = rootProject.file(keystoreProperties["storeFile"] as String)
                storePassword = keystoreProperties["storePassword"] as String
            }
        }
        if (hasPlayKey) {
            create("playUpload") {
                keyAlias = playKeyProperties["keyAlias"] as String
                keyPassword = playKeyProperties["keyPassword"] as String
                storeFile = rootProject.file(playKeyProperties["storeFile"] as String)
                storePassword = playKeyProperties["storePassword"] as String
            }
        }
    }

    /*
     * ⭐ দুই বিলি-পথ, দুই ফ্লেভার (মালিকের সিদ্ধান্ত, ২০ নভেম্বর ২০২৬) — lib/core/config/app_channel.dart-এর সাথে জোড়া।
     *
     * side (ডিফল্ট) — আজকের APK: com.abos.abos_mobile, abos-release.jks (key.properties), অ্যাপের ভেতরের আপডেট আর অফিসের
     *   SMS ফোন সহ। ⛔ এর applicationId বা চাবি বদলালে মাঠের কোনো ফোন আর আপডেট নেবে না।
     * play — Google Play-র নতুন অ্যাপ univer.abos (UNIVER BANGLADESH-এর ডেভেলপার অ্যাকাউন্ট), আপলোড-চাবি key-play.properties।
     *   নিজে-আপডেট আর SMS ফোন নেই — ম্যানিফেস্ট src/play/AndroidManifest.xml, Dart-এ --dart-define=ABOS_CHANNEL=play।
     * ⓘ namespace (Kotlin প্যাকেজ, MethodChannel-এর নাম) দুটোতেই com.abos.abos_mobile — applicationId থেকে আলাদা জিনিস।
     * ⓘ versionCode দুটোতেই 20000 + flutter.versionCode (উপরে)।
     * বানানো: tool/release.sh (side APK) আর tool/release-play.sh (play .aab)। test/android_build_config_test.dart ধরে রাখে।
     */
    flavorDimensions += "channel"
    productFlavors {
        create("side") {
            dimension = "channel"
            isDefault = true
            applicationId = "com.abos.abos_mobile"
            signingConfig = signingConfigs.getByName(if (hasReleaseKey) "release" else "debug")
        }
        create("play") {
            dimension = "channel"
            applicationId = "univer.abos"
            signingConfig = signingConfigs.getByName(if (hasPlayKey) "playUpload" else "debug")
        }
    }

    buildTypes {
        release {
            // ⛔ release-এর চাবি এখন ফ্লেভারে (উপরে), buildType-এ নয় — buildType-এর signingConfig ফ্লেভারেরটাকে ঢেকে দেয়।
            // ⓘ চাবি না থাকলে ফ্লেভার debug চাবি নেয় কেবল কনফিগারেশন চলুক বলে; প্যাকেজ বানানো নিচের পাহারায় থামে।
            if (!hasReleaseKey) {
                logger.warn(
                    "ABOS: android/key.properties নেই — side-এর release বিল্ড debug চাবি দিয়ে সই হচ্ছে। " +
                    "চালানোর জন্য চলবে, কিন্তু বিতরণ করা যাবে না: debug চাবিতে সই করা APK দিয়ে " +
                    "আপডেট দিলে কোনো ফোন নেবে না। docs/মোবাইল অ্যাপ রিলিজ করার নিয়ম.md দেখুন।"
                )
            }
        }
    }

    /*
     * ⭐ x86_64 বাদ — APK ৮৬.৫ MB থেকে প্রায় ৫৬ MB (অ্যাপ রিলিজ নিরীক্ষা, ec, ২০ অক্টোবর ২০২৬)।
     * universal APK-তে তিনটা ABI ছিল, আর x86_64-এর অংশই ~৩০ MB (libflutter ১৩, libapp ১১.৩, ML Kit-এর barhopper ৫.৯)।
     * x86_64 কেবল এমুলেটর আর কিছু Chromebook-এ লাগে; দোকানের আর মাঠের সব ফোন ARM (arm64-v8a বা armeabi-v7a)।
     *
     * ⛔ abiFilters দিয়ে নয়: Flutter-এর প্লাগইন afterEvaluate-এ ওটা মুছে নিজের তিনটা বসায় (FlutterPlugin.kt,
     * configureAbiWithoutSplits)। packaging-এর বাদ-তালিকা প্যাকেজ বানানোর সময় খাটে, তাই কেউ মোছে না।
     * ⛔ --split-per-abi নয়: versionCode-এ ১০০০×ABI যোগ হয়, আর তারপর universal আর বসে না (উপরের versionCode মন্তব্য)।
     * ⓘ একটাই APK, একটাই versionCode, একটাই SHA-256 — সার্ভার আর আপডেট বোতাম যেমন ছিল তেমন।
     * test/android_build_config_test.dart এটা ধরে রাখে।
     */
    /*
     * ⭐ হোম-স্ক্রিনের উইজেট-কিট (২০ অক্টোবর ২০২৬) — একটাই Glance আঁকিয়ে (widgets/DocWidget.kt) সব WidgetDoc আঁকে।
     */
    buildFeatures {
        compose = true
    }

    packaging {
        jniLibs {
            excludes += setOf("lib/x86_64/**")
        }
    }
}

/*
 * ⛔ release-এর চাবি না থাকলে release প্যাকেজ বানানো থামে (পূর্ণ নিরীক্ষা §৯ ⚠️১৪, ১৮ অক্টোবর ২০২৬)। ⓘ কেবল প্যাকেজের কাজে —
 * কনফিগারেশনে ছুড়লে debug বিল্ড আর পরীক্ষাও ভাঙত। আগে কেবল সতর্কবার্তা দিয়ে debug চাবিতে সই হত, আর এমন APK কোনো ফোনে
 * আপডেট হয়ে বসত না।
 */
if (!hasReleaseKey) {
    tasks.matching { it.name == "packageSideRelease" || it.name == "bundleSideRelease" || it.name == "assembleSideRelease" }.configureEach {
        doFirst {
            throw GradleException(
                "ABOS: android/key.properties নেই — release বিল্ড থামল। debug চাবিতে সই করা APK কোনো ফোনে আপডেট হয় না। " +
                "docs/মোবাইল অ্যাপ রিলিজ করার নিয়ম.md দেখুন।"
            )
        }
    }
}

/*
 * ⛔ play-এর আপলোড-চাবি (key-play.properties) না থাকলে play-এর release প্যাকেজ থামে — debug চাবিতে সই করা .aab
 * Play Console নেয় না, আর ভুল চাবিতে প্রথম আপলোড হলে সেটাই আপলোড-চাবি হয়ে বসে।
 */
if (!hasPlayKey) {
    tasks.matching { it.name == "packagePlayRelease" || it.name == "bundlePlayRelease" || it.name == "assemblePlayRelease" }.configureEach {
        doFirst {
            throw GradleException(
                "ABOS: android/key-play.properties নেই — Google Play-র release বিল্ড থামল। আপলোড-চাবি " +
                "(univer-abos-upload.jks) বানিয়ে key-play.properties লিখুন। docs/মোবাইল অ্যাপ রিলিজ করার নিয়ম.md দেখুন।"
            )
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}

dependencies {
    // ⭐ Glance 1.1 — home_widget 0.7 brings 1.0.0; this lifts the whole app to the 1.1 line (widget kit, 20 Oct 2026)
    implementation("androidx.glance:glance-appwidget:1.1.1")
    // ⓘ মানুষের আলো/অন্ধকার বাছাই উইজেটে চাপাতে — Material 3-এর স্থির রং-পরিবার (২০ অক্টোবর ২০২৬)
    implementation("androidx.glance:glance-material3:1.1.1")
    // ⓘ glance-material3 Material 3-এর রং-পরিবার (lightColorScheme, dynamic…) নিজে আনে না — compileOnly
    implementation("androidx.compose.material3:material3:1.3.1")
}
