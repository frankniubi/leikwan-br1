package com.leikwan.bridge.ui.routing

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/** A compact feature entry, shared by the routing hub's cards. */
@Composable
internal fun RoutingFeatureCard(
    title: String,
    detail: String,
    icon: ImageVector,
    colors: List<Color>,
    onClick: () -> Unit,
    trailing: (@Composable () -> Unit)? = null,
    action: (@Composable () -> Unit)? = null,
) {
    Surface(
        onClick = onClick,
        shape = RoundedCornerShape(22.dp),
        color = Color.Transparent,
        contentColor = Color.White,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .background(Brush.linearGradient(colors))
                .padding(20.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(icon, contentDescription = null, modifier = Modifier.size(26.dp))
                Spacer(Modifier.width(10.dp))
                Text(
                    title,
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.weight(1f),
                )
                trailing?.invoke()
            }
            Spacer(Modifier.height(10.dp))
            Text(detail, style = MaterialTheme.typography.bodyMedium, lineHeight = 22.sp)
            if (action != null) {
                Spacer(Modifier.height(16.dp))
                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                    action()
                }
            }
        }
    }
}

@Composable
internal fun RoutingChip(text: String) {
    Surface(
        shape = RoundedCornerShape(10.dp),
        color = Color.Black.copy(alpha = 0.20f),
        contentColor = Color.White,
    ) {
        Text(
            text,
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 10.dp),
            style = MaterialTheme.typography.labelLarge,
        )
    }
}

internal val RoutingBlue = listOf(Color(0xFF3949AB), Color(0xFF5E6AD2))
internal val RoutingPurple = listOf(Color(0xFF6A1B9A), Color(0xFF9C4DCC))
internal val RoutingTeal = listOf(Color(0xFF00796B), Color(0xFF26A69A))
