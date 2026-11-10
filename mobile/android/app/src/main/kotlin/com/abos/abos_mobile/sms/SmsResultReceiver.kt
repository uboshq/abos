package com.abos.abos_mobile.sms

import android.app.Activity
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.telephony.SmsManager

/**
 * ⭐ সিম থেকে বেরোল কি না, আর অপারেটর পৌঁছানোর খবর দিল কি না — প্রতিটা ভাগের ফল এখানে আসে।
 *
 * ⓘ সব ভাগ বেরোলে তবেই "sent"; কোনো ভাগ না বেরোলে "failed", কারণসহ। শেষ ভাগের পৌঁছানো = পুরো লেখার "delivered"।
 * ⓘ খবর প্রথমে ফোনে জমে ([[SmsGatewayStore.addEvent]]), সেবার পরের পাকে সার্ভারে যায়।
 */
class SmsResultReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val id = intent.getStringExtra("id") ?: return
        val part = intent.getIntExtra("part", -1)
        val store = SmsGatewayStore(context)

        when (intent.action) {
            SENT -> {
                val ok = resultCode == Activity.RESULT_OK
                when (store.partSent(id, ok)) {
                    "sent" -> {
                        store.countSent()
                        store.addEvent(id, "sent")
                    }
                    "failed" -> store.addEvent(id, "failed", reason(resultCode))
                }
            }
            DELIVERED -> if (part >= 0 && resultCode == Activity.RESULT_OK) store.addEvent(id, "delivered")
        }
    }

    @Suppress("DEPRECATION")
    private fun reason(code: Int): String = when (code) {
        SmsManager.RESULT_ERROR_NO_SERVICE -> "no network service"
        SmsManager.RESULT_ERROR_RADIO_OFF -> "radio off (airplane mode)"
        SmsManager.RESULT_ERROR_NULL_PDU -> "null pdu"
        SmsManager.RESULT_ERROR_GENERIC_FAILURE -> "generic failure (balance or operator)"
        else -> "error $code"
    }

    companion object {
        const val SENT = "com.abos.abos_mobile.SMS_SENT"
        const val DELIVERED = "com.abos.abos_mobile.SMS_DELIVERED"
    }
}
