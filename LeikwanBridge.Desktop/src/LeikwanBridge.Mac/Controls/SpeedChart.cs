using Avalonia;
using Avalonia.Controls;
using Avalonia.Media;

namespace LeikwanBridge.Mac.Controls;

/// <summary>
/// Smoothed area chart of recent throughput samples (bytes per second), in the
/// style of FlClash's network-speed card. Download is the filled series; upload is
/// drawn as a thin secondary line. The vertical scale follows the window maximum,
/// so an idle connection draws a flat baseline rather than noise.
/// </summary>
public sealed class SpeedChart : Control
{
    public static readonly StyledProperty<IReadOnlyList<double>?> DownloadSamplesProperty =
        AvaloniaProperty.Register<SpeedChart, IReadOnlyList<double>?>(nameof(DownloadSamples));

    public static readonly StyledProperty<IReadOnlyList<double>?> UploadSamplesProperty =
        AvaloniaProperty.Register<SpeedChart, IReadOnlyList<double>?>(nameof(UploadSamples));

    public static readonly StyledProperty<IBrush?> StrokeProperty =
        AvaloniaProperty.Register<SpeedChart, IBrush?>(nameof(Stroke));

    public static readonly StyledProperty<IBrush?> FillProperty =
        AvaloniaProperty.Register<SpeedChart, IBrush?>(nameof(Fill));

    public static readonly StyledProperty<IBrush?> SecondaryStrokeProperty =
        AvaloniaProperty.Register<SpeedChart, IBrush?>(nameof(SecondaryStroke));

    static SpeedChart()
    {
        AffectsRender<SpeedChart>(
            DownloadSamplesProperty,
            UploadSamplesProperty,
            StrokeProperty,
            FillProperty,
            SecondaryStrokeProperty);
    }

    public IReadOnlyList<double>? DownloadSamples
    {
        get => GetValue(DownloadSamplesProperty);
        set => SetValue(DownloadSamplesProperty, value);
    }

    public IReadOnlyList<double>? UploadSamples
    {
        get => GetValue(UploadSamplesProperty);
        set => SetValue(UploadSamplesProperty, value);
    }

    public IBrush? Stroke
    {
        get => GetValue(StrokeProperty);
        set => SetValue(StrokeProperty, value);
    }

    public IBrush? Fill
    {
        get => GetValue(FillProperty);
        set => SetValue(FillProperty, value);
    }

    public IBrush? SecondaryStroke
    {
        get => GetValue(SecondaryStrokeProperty);
        set => SetValue(SecondaryStrokeProperty, value);
    }

    public override void Render(DrawingContext context)
    {
        base.Render(context);
        var bounds = Bounds;
        if (bounds.Width < 4 || bounds.Height < 4)
            return;
        var download = DownloadSamples ?? [];
        var upload = UploadSamples ?? [];
        var peak = Math.Max(
            download.Count == 0 ? 0 : download.Max(),
            upload.Count == 0 ? 0 : upload.Max());
        // Keep a little headroom and never divide by zero.
        var scale = peak <= 0 ? 1 : peak * 1.15;

        var downPoints = Project(download, bounds.Width, bounds.Height, scale);
        if (downPoints.Count >= 2)
        {
            var area = BuildSmoothPath(downPoints, close: true, bounds.Height);
            if (Fill is { } fill)
                context.DrawGeometry(fill, null, area);
            if (Stroke is { } stroke)
                context.DrawGeometry(null, new Pen(stroke, 2.2, lineCap: PenLineCap.Round, lineJoin: PenLineJoin.Round),
                    BuildSmoothPath(downPoints, close: false, bounds.Height));
        }

        var upPoints = Project(upload, bounds.Width, bounds.Height, scale);
        if (upPoints.Count >= 2 && SecondaryStroke is { } secondary)
            context.DrawGeometry(null, new Pen(secondary, 1.6, lineCap: PenLineCap.Round, lineJoin: PenLineJoin.Round),
                BuildSmoothPath(upPoints, close: false, bounds.Height));
    }

    internal static List<Point> Project(IReadOnlyList<double> samples, double width, double height, double scale)
    {
        var points = new List<Point>(samples.Count);
        if (samples.Count < 2)
            return points;
        var top = 4.0;
        var usable = Math.Max(1, height - top - 2);
        var step = width / (samples.Count - 1);
        for (var i = 0; i < samples.Count; i++)
        {
            var value = Math.Clamp(samples[i] / scale, 0, 1);
            points.Add(new Point(i * step, top + usable * (1 - value)));
        }
        return points;
    }

    private static StreamGeometry BuildSmoothPath(IReadOnlyList<Point> points, bool close, double height)
    {
        var geometry = new StreamGeometry();
        using var ctx = geometry.Open();
        if (close)
        {
            ctx.BeginFigure(new Point(points[0].X, height), isFilled: true);
            ctx.LineTo(points[0]);
        }
        else
        {
            ctx.BeginFigure(points[0], isFilled: false);
        }
        // Monotone-ish Catmull-Rom to cubic Bezier: smooth without overshooting much.
        for (var i = 0; i < points.Count - 1; i++)
        {
            var p0 = i == 0 ? points[i] : points[i - 1];
            var p1 = points[i];
            var p2 = points[i + 1];
            var p3 = i + 2 < points.Count ? points[i + 2] : p2;
            var c1 = new Point(p1.X + (p2.X - p0.X) / 6, p1.Y + (p2.Y - p0.Y) / 6);
            var c2 = new Point(p2.X - (p3.X - p1.X) / 6, p2.Y - (p3.Y - p1.Y) / 6);
            c1 = c1.WithY(Math.Min(c1.Y, height));
            c2 = c2.WithY(Math.Min(c2.Y, height));
            ctx.CubicBezierTo(c1, c2, p2);
        }
        if (close)
        {
            ctx.LineTo(new Point(points[^1].X, height));
            ctx.EndFigure(true);
        }
        else
        {
            ctx.EndFigure(false);
        }
        return geometry;
    }
}
