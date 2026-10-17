allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()
rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}
subprojects {
    project.evaluationDependsOn(":app")
}

/*
 * ⭐ file_picker 11 (কাগজ তোলা, ১৭ অক্টোবর ২০২৬) — AGP ৯-এ সে নিজে Kotlin প্লাগিন বসায় না, ধরে নেয় AGP-র ভিতরের Kotlin চালু; অথচ
 * এই অ্যাপে `android.builtInKotlin=false` (টেমপ্লেটের, বাকি প্লাগিন নিজেরাই KGP বসায়)। ফলে তার .kt ফাইল কম্পাইলই হত না, আর
 * GeneratedPluginRegistrant-এ FilePickerPlugin "cannot find symbol"। ⓘ কেবল ঐ প্লাগিনে Kotlin প্লাগিন বসানো, JVM 17 — ভাষার
 * সুইচ গোটা অ্যাপে উল্টানো নয়, যাতে বাকি প্লাগিনের পথ না বদলায়।
 */
subprojects {
    if (name == "file_picker") {
        plugins.withId("com.android.library") {
            pluginManager.apply("org.jetbrains.kotlin.android")
            extensions.configure<org.jetbrains.kotlin.gradle.dsl.KotlinAndroidProjectExtension> {
                compilerOptions.jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
            }
        }
    }
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
