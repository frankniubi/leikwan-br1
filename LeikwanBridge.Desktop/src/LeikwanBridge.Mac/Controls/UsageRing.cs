using Avalonia;
using Avalonia.Controls;
using Avalonia.Media;

namespace LeikwanBridge.Mac.Controls;

/// <summary>
/// Two-segment ring showing the upload / download split of the session traffic,
/// like FlClash's traffic-usage card. With no traffic yet it draws the empty track.
/// </summary>
public sealed class UsageRing : Control
{
    public static readonly StyledProperty<double> UploadProperty =
        AvaloniaProperty.Register<UsageRing, double>(nameof(Upload));

    public static readonly StyledProperty<double> DownloadProperty =
        AvaloniaProperty.Register<UsageRing, double>(nameof(Download));

    public static readonly StyledProperty<IBrush?> UploadBrushProperty =
        AvaloniaProperty.Register<UsageRing, IBrush?>(nameof(UploadBrush));

    public static readonly StyledProperty<IBrush?> DownloadBrushProperty =
        AvaloniaProperty.Register<UsageRing, IBrush?>(nameof(DownloadBrush));

    public static readonly StyledProperty<IBrush?> TrackBrushProperty =
        AvaloniaProperty.Register<UsageRing, IBrush?>(nameof(TrackBrush));

    static UsageRing()
    {
        AffectsRender<UsageRing>(UploadProperty, DownloadProperty, UploadBrushProperty, DownloadBrushProperty, TrackBrushProperty);
    }

    public double Upload { get => GetValue(UploadProperty); set => SetValue(UploadProperty, value); }
    public double Download { get => GetValue(DownloadProperty); set => SetValue(DownloadProperty, value); }
    public IBrush? UploadBrush { get => GetValue(UploadBrushProperty); set => SetValue(UploadBrushProperty, value); }
    public IBrush? DownloadBrush { get => GetValue(DownloadBrushProperty); set => SetValue(DownloadBrushProperty, value); }
    public IBrush? TrackBrush { get => GetValue(TrackBrushProperty); set => SetValue(TrackBrushProperty, value); }

    public override void Render(DrawingContext context)
    {
        base.Render(context);
        var size = Math.Min(Bounds.Width, Bounds.Height);
        if (size < 8)
            return;
        const double thickness = 9;
        var radius = (size - thickness) / 2;
        var center = new Point(Bounds.Width / 2, Bounds.Height / 2);
        if (TrackBrush is { } track)
            context.DrawEllipse(null, new Pen(track, thickness), center, radius, radius);

        var total = Math.Max(0, Upload) + Math.Max(0, Download);
        if (total <= 0)
            return;
        const double gap = 6; // degrees between the two segments
        var uploadSweep = 360 * Math.Max(0, Upload) / total;
        var downloadSweep = 360 - uploadSweep;
        var start = -90.0;
        DrawArc(context, DownloadBrush, center, radius, thickness, start, downloadSweep, gap);
        DrawArc(context, UploadBrush, center, radius, thickness, start + downloadSweep, uploadSweep, gap);
    }

    private static void DrawArc(DrawingContext context, IBrush? brush, Point center, double radius,
        double thickness, double startDegrees, double sweepDegrees, double gap)
    {
        if (brush is null || sweepDegrees <= 0.5)
            return;
        var visible = sweepDegrees >= 359.5 ? 359.9 : Math.Max(0.5, sweepDegrees - gap);
        var from = startDegrees + (sweepDegrees >= 359.5 ? 0 : gap / 2);
        var to = from + visible;
        var geometry = new StreamGeometry();
        using (var ctx = geometry.Open())
        {
            ctx.BeginFigure(PointOn(center, radius, from), isFilled: false);
            ctx.ArcTo(PointOn(center, radius, to), new Size(radius, radius), 0, visible > 180, SweepDirection.Clockwise);
            ctx.EndFigure(false);
        }
        context.DrawGeometry(null, new Pen(brush, thickness, lineCap: PenLineCap.Round), geometry);
    }

    private static Point PointOn(Point center, double radius, double degrees)
    {
        var radians = degrees * Math.PI / 180;
        return new Point(center.X + radius * Math.Cos(radians), center.Y + radius * Math.Sin(radians));
    }
}
