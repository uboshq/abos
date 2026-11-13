package com.abos.abos_mobile.voice

import android.Manifest
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.speech.RecognitionListener
import android.speech.RecognizerIntent
import android.speech.SpeechRecognizer
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import io.flutter.plugin.common.BinaryMessenger
import io.flutter.plugin.common.MethodChannel
import java.util.Locale

/**
 * ⭐ কোপাইলটের কথা — শোনা আর পড়ে শোনানো, ফোনের নিজের যন্ত্রে (মালিক, ১২ নভেম্বর ২০২৬: "সিরি আর জেমিনাইয়ের মতো … মাইক";
 *   নমুনা 3uJhK92DAMhwyELt63mSmr, ফোনের অংশ)।
 *
 * ⓘ কোনো নতুন প্যাকেজ নয় — Android-এর [SpeechRecognizer] আর [TextToSpeech]। ⛔ কথার অডিও কোথাও জমা হয় না, ABOS-এর সার্ভারেও
 *   যায় না: ফোন শুনে লেখা দেয়, কোপাইলট কেবল সেই লেখাটা পায় — ওয়েবের মাইকের একই নিয়ম।
 *
 * Dart → Kotlin: `available`, `listen {locale}`, `stopListening`, `cancel`, `speak {text, locale}`, `stopSpeaking`.
 * Kotlin → Dart: `partial {text}`, `final {text}`, `level {rms}`, `error {code}`, `spoken {}`.
 * ⓘ মাইকের অনুমতি [MainActivity]-র মাধ্যমে চাওয়া হয় ([needsPermission] → [onPermission])।
 */
class VoiceChannel(private val activity: Activity, messenger: BinaryMessenger) {

    private val channel = MethodChannel(messenger, CHANNEL)
    private var recognizer: SpeechRecognizer? = null
    private var tts: TextToSpeech? = null
    private var ttsReady = false
    private var pendingSpeak: Pair<String, String>? = null
    private var pendingListen: String? = null

    /** মাইকের অনুমতি চাওয়ার দরকার হলে [MainActivity] এটা ডাকে */
    var needsPermission: (() -> Unit)? = null

    init {
        channel.setMethodCallHandler { call, result ->
            when (call.method) {
                "available" -> result.success(SpeechRecognizer.isRecognitionAvailable(activity))
                "listen" -> {
                    val locale = call.argument<String>("locale") ?: "bn-BD"
                    if (activity.checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
                        pendingListen = locale
                        needsPermission?.invoke()
                    } else {
                        startListening(locale)
                    }
                    result.success(true)
                }
                "stopListening" -> { recognizer?.stopListening(); result.success(true) }
                "cancel" -> { recognizer?.cancel(); result.success(true) }
                "speak" -> {
                    speak(call.argument<String>("text").orEmpty(), call.argument<String>("locale") ?: "bn-BD")
                    result.success(true)
                }
                "stopSpeaking" -> { tts?.stop(); result.success(true) }
                else -> result.notImplemented()
            }
        }
    }

    /** অনুমতির উত্তর — হ্যাঁ হলে অপেক্ষার শোনা শুরু, না হলে Dart-কে ভুলের সংকেত */
    fun onPermission(granted: Boolean) {
        val locale = pendingListen ?: return
        pendingListen = null
        if (granted) startListening(locale) else emit("error", mapOf("code" to "permission"))
    }

    private fun startListening(locale: String) {
        if (!SpeechRecognizer.isRecognitionAvailable(activity)) {
            emit("error", mapOf("code" to "unavailable"))
            return
        }
        recognizer?.destroy()
        recognizer = SpeechRecognizer.createSpeechRecognizer(activity).apply {
            setRecognitionListener(object : RecognitionListener {
                override fun onReadyForSpeech(params: Bundle?) {}
                override fun onBeginningOfSpeech() {}
                override fun onRmsChanged(rmsdB: Float) = emit("level", mapOf("rms" to rmsdB.toDouble()))
                override fun onBufferReceived(buffer: ByteArray?) {}
                override fun onEndOfSpeech() {}
                override fun onError(error: Int) = emit("error", mapOf("code" to errorCode(error)))
                override fun onResults(results: Bundle?) = emit("final", mapOf("text" to best(results)))
                override fun onPartialResults(partialResults: Bundle?) = emit("partial", mapOf("text" to best(partialResults)))
                override fun onEvent(eventType: Int, params: Bundle?) {}
            })
        }
        val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
            putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
            putExtra(RecognizerIntent.EXTRA_LANGUAGE, locale)
            putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, true)
            putExtra(RecognizerIntent.EXTRA_MAX_RESULTS, 1)
        }
        recognizer?.startListening(intent)
    }

    private fun speak(text: String, locale: String) {
        if (text.isBlank()) return
        val engine = tts
        if (engine == null || !ttsReady) {
            pendingSpeak = text to locale
            if (engine == null) {
                tts = TextToSpeech(activity) { status ->
                    ttsReady = status == TextToSpeech.SUCCESS
                    tts?.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
                        override fun onStart(utteranceId: String?) {}
                        override fun onDone(utteranceId: String?) = activity.runOnUiThread { emit("spoken", emptyMap<String, Any>()) }
                        @Deprecated("Deprecated in Java")
                        override fun onError(utteranceId: String?) = activity.runOnUiThread { emit("spoken", emptyMap<String, Any>()) }
                    })
                    pendingSpeak?.let { (t, l) -> pendingSpeak = null; if (ttsReady) say(t, l) else emit("error", mapOf("code" to "tts")) }
                }
            }
            return
        }
        say(text, locale)
    }

    private fun say(text: String, locale: String) {
        val engine = tts ?: return
        val wanted = Locale.forLanguageTag(locale)
        // ⓘ বাংলার কণ্ঠ ফোনে না থাকলে ইংরেজিতে নয় — ভুল উচ্চারণের চেয়ে নীরবতা ভালো; Dart লেখাটা দেখায়ই
        val ok = engine.setLanguage(wanted)
        if (ok == TextToSpeech.LANG_MISSING_DATA || ok == TextToSpeech.LANG_NOT_SUPPORTED) {
            emit("error", mapOf("code" to "no_voice"))
            return
        }
        engine.speak(text, TextToSpeech.QUEUE_FLUSH, null, "copilot")
    }

    private fun best(bundle: Bundle?): String =
        bundle?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)?.firstOrNull().orEmpty()

    private fun errorCode(error: Int): String = when (error) {
        SpeechRecognizer.ERROR_NO_MATCH, SpeechRecognizer.ERROR_SPEECH_TIMEOUT -> "no_speech"
        SpeechRecognizer.ERROR_INSUFFICIENT_PERMISSIONS -> "permission"
        SpeechRecognizer.ERROR_NETWORK, SpeechRecognizer.ERROR_NETWORK_TIMEOUT, SpeechRecognizer.ERROR_SERVER -> "network"
        else -> "other"
    }

    private fun emit(method: String, args: Map<String, Any>) = channel.invokeMethod(method, args)

    fun dispose() {
        recognizer?.destroy()
        recognizer = null
        tts?.shutdown()
        tts = null
    }

    companion object {
        const val CHANNEL = "com.abos.abos_mobile/voice"
        const val MIC_PERMISSION_REQUEST = 4711
    }
}
