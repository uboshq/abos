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

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.abos.abos_mobile"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.abos.abos_mobile"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
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
    }

    buildTypes {
        release {
            signingConfig = if (hasReleaseKey) {
                signingConfigs.getByName("release")
            } else {
                logger.warn(
                    "ABOS: android/key.properties নেই — এই release বিল্ড debug চাবি দিয়ে সই হচ্ছে। " +
                    "চালানোর জন্য চলবে, কিন্তু বিতরণ করা যাবে না: debug চাবিতে সই করা APK দিয়ে " +
                    "আপডেট দিলে কোনো ফোন নেবে না। docs/মোবাইল অ্যাপ রিলিজ করার নিয়ম.md দেখুন।"
                )
                signingConfigs.getByName("debug")
            }
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
