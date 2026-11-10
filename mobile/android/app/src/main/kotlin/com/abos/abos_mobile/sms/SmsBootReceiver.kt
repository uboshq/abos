package com.abos.abos_mobile.sms

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * ⓘ ফোন বন্ধ-চালু হলে SMS গেটওয়ে নিজে থেকে আবার চলে — অফিসে কেউ অ্যাপ না খুললেও (কেবল আগে চালু থাকলে)।
 * ⓘ বিদ্যুৎ গিয়ে ফোন বন্ধ হলে এটাই SMS-কে ঘণ্টার পর ঘণ্টা আটকে থাকা থেকে বাঁচায়।
 */
class SmsBootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED && intent.action != Intent.ACTION_MY_PACKAGE_REPLACED) return
        val store = SmsGatewayStore(context)
        if (store.enabled && store.key.isNotEmpty() && store.baseUrl.isNotEmpty()) {
            runCatching { SmsGatewayService.start(context) }
        }
    }
}
