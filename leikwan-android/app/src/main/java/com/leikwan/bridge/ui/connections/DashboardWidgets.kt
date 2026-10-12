package com.leikwan.bridge.ui.connections

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp

/** FlClash-style dashboard card: a small icon + label header over free-form content. */
@Composable
internal fun WidgetCard(
    icon: ImageVector,
    title: String,
    modifier: Modifier = Modifier,
    onClick: (() -> Unit)? = null,
    trailing: @Composable (() -> Unit)? = null,
    contentPadding: Boolean = true,
    content: @Composable ColumnScope.() -> Unit,
) {
    val body: @Composable () -> Unit = {
        Column {
            Row(
                modifier = Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(icon, contentDescription = null, modifier = Modifier.size(18.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.width(8.dp))
                Text(
                    title,
                    style = MaterialTheme.typography.titleSmall,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f),
                )
                trailing?.invoke()
            }
            Column(
                modifier = if (contentPadding) Modifier.padding(16.dp) else Modifier.padding(top = 8.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp),
                content = content,
            )
        }
    }
    if (onClick != null) {
        Surface(
            onClick = onClick,
            modifier = modifier,
            shape = MaterialTheme.shapes.medium,
            color = MaterialTheme.colorScheme.surfaceContainer,
            content = body,
        )
    } else {
        Surface(
            modifier = modifier,
            shape = MaterialTheme.shapes.medium,
            color = MaterialTheme.colorScheme.surfaceContainer,
            content = body,
        )
    }
}

/** Smoothed area chart of recent rates; flat when idle. */
@Composable
internal fun SpeedChart(values: List<Long>, color: Color, modifier: Modifier = Modifier) {
    Canvas(modifier) {
        val points = if (values.size >= 2) values else List(2) { values.firstOrNull() ?: 0L }
        // A floor of 8 KB/s keeps idle noise from filling the chart.
        val max = maxOf(points.max().toFloat(), 8f * 1024)
        val step = size.width / (points.size - 1)
        val top = 4.dp.toPx()
        val usable = size.height - top
        val offsets = points.mapIndexed { index, value ->
            Offset(index * step, size.height - (value / max) * usable)
        }
        val line = Path().apply {
            moveTo(offsets.first().x, offsets.first().y)
            for (i in 1 until offsets.size) {
                val previous = offsets[i - 1]
                val current = offsets[i]
                val midX = (previous.x + current.x) / 2
                cubicTo(midX, previous.y, midX, current.y, current.x, current.y)
            }
        }
        val area = Path().apply {
            addPath(line)
            lineTo(size.width, size.height)
            lineTo(0f, size.height)
            close()
        }
        drawPath(area, Brush.verticalGradient(listOf(color.copy(alpha = 0.32f), color.copy(alpha = 0.02f))))
        drawPath(line, color, style = Stroke(width = 2.dp.toPx(), cap = StrokeCap.Round))
    }
}

/** Two-segment ring: upload vs download share. */
@Composable
internal fun TrafficDonut(
    upload: Long,
    download: Long,
    uploadColor: Color,
    downloadColor: Color,
    trackColor: Color,
    modifier: Modifier = Modifier,
) {
    Canvas(modifier) {
        val stroke = 7.dp.toPx()
        val inset = stroke / 2
        val arcSize = Size(size.width - stroke, size.height - stroke)
        val topLeft = Offset(inset, inset)
        val total = (upload + download).toFloat()
        drawArc(trackColor, 0f, 360f, false, topLeft, arcSize, style = Stroke(stroke))
        if (total <= 0f) return@Canvas
        val gap = 6f
        val uploadSweep = (upload / total) * 360f
        val downloadSweep = 360f - uploadSweep
        if (uploadSweep > gap) {
            drawArc(uploadColor, -90f + gap / 2, uploadSweep - gap, false, topLeft, arcSize, style = Stroke(stroke, cap = StrokeCap.Round))
        }
        if (downloadSweep > gap) {
            drawArc(downloadColor, -90f + uploadSweep + gap / 2, downloadSweep - gap, false, topLeft, arcSize, style = Stroke(stroke, cap = StrokeCap.Round))
        }
    }
}
