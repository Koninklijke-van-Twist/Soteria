package nl.kvt.soteria

import android.app.Application

class SoteriaApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Prefs.init(this)
    }
}
