using System.Windows;
using System.Windows.Media;
using Brush = System.Windows.Media.Brush;
using Brushes = System.Windows.Media.Brushes;
using Pen = System.Windows.Media.Pen;
using Point = System.Windows.Point;
using Size = System.Windows.Size;

namespace LeikwanBridge.App.Views;

/// <summary>Glyph (Segoe Fluent Icons code point) shown before a sidebar item's label.</summary>
public static class NavIcon
{
    public static readonly DependencyProperty GlyphProperty = DependencyProperty.RegisterAttached(
        "Glyph", typeof(string), typeof(NavIcon), new FrameworkPropertyMetadata(string.Empty));

    public static string GetGlyph(DependencyObject element) => (string)element.GetValue(GlyphProperty);

    public static void SetGlyph(DependencyObject element, string value) => element.SetValue(GlyphProperty, value);
}

/// <summary>
/// Smoothed area chart of the last minute of download / upload speed (dashboard "Network speed").
/// Plain WPF drawing, no chart library. Values are bytes per second.
/// </summary>
public sealed class SpeedChart : FrameworkElement
{
    public static readonly DependencyProperty PrimaryBrushProperty = DependencyProperty.Register(
        nameof(PrimaryBrush), typeof(Brush), typeof(SpeedChart),
        new FrameworkPropertyMetadata(Brushes.SteelBlue, FrameworkPropertyMetadataOptions.AffectsRender));

    public static readonly DependencyProperty SecondaryBrushProperty = DependencyProperty.Register(
        nameof(SecondaryBrush), typeof(Brush), typeof(SpeedChart),
        new FrameworkPropertyMetadata(Brushes.Teal, FrameworkPropertyMetadataOptions.AffectsRender));

    public static readonly DependencyProperty GridBrushProperty = DependencyProperty.Register(
        nameof(GridBrush), typeof(Brush), typeof(SpeedChart),
        new FrameworkPropertyMetadata(Brushes.LightGray, FrameworkPropertyMetadataOptions.AffectsRender));

    private double[] _primary = [];
    private double[] _secondary = [];

    /// <summary>Download (inbound) series.</summary>
    public Brush PrimaryBrush
    {
        get => (Brush)GetValue(PrimaryBrushProperty);
        set => SetValue(PrimaryBrushProperty, value);
    }

    /// <summary>Upload (outbound) series.</summary>
    public Brush SecondaryBrush
    {
        get => (Brush)GetValue(SecondaryBrushProperty);
        set => SetValue(SecondaryBrushProperty, value);
    }

    public Brush GridBrush
    {
        get => (Brush)GetValue(GridBrushProperty);
        set => SetValue(GridBrushProperty, value);
    }

    public int Capacity { get; set; } = 60;

    public void SetSamples(IReadOnlyList<double> primary, IReadOnlyList<double> secondary)
    {
        _primary = primary.ToArray();
        _secondary = secondary.ToArray();
        InvalidateVisual();
    }

    protected override void OnRender(DrawingContext context)
    {
        var width = ActualWidth;
        var height = ActualHeight;
        if (width <= 2 || height <= 2)
            return;
        var gridPen = new Pen(GridBrush, 1) { DashStyle = new DashStyle([2, 4], 0) };
        for (var line = 1; line <= 3; line++)
        {
            var y = Math.Round(height * line / 4) + 0.5;
            context.DrawLine(gridPen, new Point(0, y), new Point(width, y));
        }
        var max = Math.Max(1024, Math.Max(_primary.DefaultIfEmpty(0).Max(), _secondary.DefaultIfEmpty(0).Max()) * 1.15);
        DrawSeries(context, _secondary, SecondaryBrush, max, width, height, 0.10);
        DrawSeries(context, _primary, PrimaryBrush, max, width, height, 0.22);
    }

    private void DrawSeries(DrawingContext context, double[] values, Brush brush, double max, double width, double height, double fillOpacity)
    {
        if (values.Length < 2)
            return;
        var capacity = Math.Max(Capacity, values.Length);
        var step = width / (capacity - 1);
        var offset = (capacity - values.Length) * step;
        var points = values.Select((value, index) => new Point(offset + index * step, height - 2 - (height - 6) * Math.Clamp(value / max, 0, 1))).ToList();

        var line = new StreamGeometry();
        using (var stream = line.Open())
        {
            stream.BeginFigure(points[0], false, false);
            AddSmooth(stream, points);
        }
        line.Freeze();

        var area = new StreamGeometry();
        using (var stream = area.Open())
        {
            stream.BeginFigure(new Point(points[0].X, height), true, true);
            stream.LineTo(points[0], false, false);
            AddSmooth(stream, points);
            stream.LineTo(new Point(points[^1].X, height), false, false);
        }
        area.Freeze();

        var fill = brush.CloneCurrentValue();
        fill.Opacity = fillOpacity;
        fill.Freeze();
        context.DrawGeometry(fill, null, area);
        context.DrawGeometry(null, new Pen(brush, 2) { LineJoin = PenLineJoin.Round }, line);
    }

    private static void AddSmooth(StreamGeometryContext stream, IReadOnlyList<Point> points)
    {
        for (var index = 1; index < points.Count; index++)
        {
            var previous = points[index - 1];
            var current = points[index];
            var midX = (previous.X + current.X) / 2;
            stream.BezierTo(new Point(midX, previous.Y), new Point(midX, current.Y), current, true, true);
        }
    }
}

/// <summary>Donut showing the inbound share of session traffic (dashboard "Traffic usage").</summary>
public sealed class RingGauge : FrameworkElement
{
    public static readonly DependencyProperty ValueProperty = DependencyProperty.Register(
        nameof(Value), typeof(double), typeof(RingGauge),
        new FrameworkPropertyMetadata(0d, FrameworkPropertyMetadataOptions.AffectsRender));

    public static readonly DependencyProperty FillBrushProperty = DependencyProperty.Register(
        nameof(FillBrush), typeof(Brush), typeof(RingGauge),
        new FrameworkPropertyMetadata(Brushes.SteelBlue, FrameworkPropertyMetadataOptions.AffectsRender));

    public static readonly DependencyProperty TrackBrushProperty = DependencyProperty.Register(
        nameof(TrackBrush), typeof(Brush), typeof(RingGauge),
        new FrameworkPropertyMetadata(Brushes.LightGray, FrameworkPropertyMetadataOptions.AffectsRender));

    public double Value
    {
        get => (double)GetValue(ValueProperty);
        set => SetValue(ValueProperty, value);
    }

    public Brush FillBrush
    {
        get => (Brush)GetValue(FillBrushProperty);
        set => SetValue(FillBrushProperty, value);
    }

    public Brush TrackBrush
    {
        get => (Brush)GetValue(TrackBrushProperty);
        set => SetValue(TrackBrushProperty, value);
    }

    public double Thickness { get; set; } = 9;

    protected override void OnRender(DrawingContext context)
    {
        var size = Math.Min(ActualWidth, ActualHeight);
        if (size <= Thickness * 2)
            return;
        var radius = (size - Thickness) / 2;
        var center = new Point(ActualWidth / 2, ActualHeight / 2);
        context.DrawEllipse(null, new Pen(TrackBrush, Thickness), center, radius, radius);
        var value = Math.Clamp(Value, 0, 1);
        if (value <= 0)
            return;
        if (value >= 0.999)
        {
            context.DrawEllipse(null, new Pen(FillBrush, Thickness), center, radius, radius);
            return;
        }
        var angle = value * 2 * Math.PI;
        var start = new Point(center.X, center.Y - radius);
        var end = new Point(center.X + radius * Math.Sin(angle), center.Y - radius * Math.Cos(angle));
        var arc = new StreamGeometry();
        using (var stream = arc.Open())
        {
            stream.BeginFigure(start, false, false);
            stream.ArcTo(end, new Size(radius, radius), 0, value > 0.5, SweepDirection.Clockwise, true, false);
        }
        arc.Freeze();
        context.DrawGeometry(null, new Pen(FillBrush, Thickness) { StartLineCap = PenLineCap.Round, EndLineCap = PenLineCap.Round }, arc);
    }
}
