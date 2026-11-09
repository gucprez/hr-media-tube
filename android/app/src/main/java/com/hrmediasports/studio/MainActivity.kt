package com.hrmediasports.studio

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import com.hrmediasports.studio.ui.StudioApp
import com.hrmediasports.studio.ui.theme.HrTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            HrTheme { StudioApp() }
        }
    }
}
