(() => {
  const canvas = document.querySelector('.signal-canvas');
  if (!(canvas instanceof HTMLCanvasElement)) return;

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  const context = canvas.getContext('2d');
  if (!context) return;

  const colors = ['#ef4938', '#21c58b', '#3188d8', '#f5f5f1'];
  let visible = true;
  let frame = 0;
  let width = 1;
  let height = 1;

  const resize = () => {
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    const rect = canvas.getBoundingClientRect();
    width = Math.max(1, rect.width);
    height = Math.max(1, rect.height);
    canvas.width = Math.max(1, Math.floor(width * ratio));
    canvas.height = Math.max(1, Math.floor(height * ratio));
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
  };

  const stop = () => {
    if (frame) cancelAnimationFrame(frame);
    frame = 0;
  };

  const draw = (time = 0) => {
    frame = 0;
    context.clearRect(0, 0, width, height);
    const phase = reduced.matches ? 0.32 : ((time / 4800) % 1);

    colors.forEach((color, index) => {
      const startY = height * (0.28 + index * 0.13);
      const targetY = height * 0.55;
      context.strokeStyle = color;
      context.lineWidth = index === 3 ? 1.5 : 2;
      context.globalAlpha = index === 3 ? 0.55 : 0.9;
      context.beginPath();
      context.moveTo(width * 0.43, startY);
      context.bezierCurveTo(width * 0.62, startY, width * 0.7, targetY, width * 0.96, targetY);
      context.stroke();

      const progress = (phase + index * 0.19) % 1;
      const x = width * (0.43 + progress * 0.53);
      const y = startY + (targetY - startY) * Math.min(1, progress * 1.35);
      context.fillStyle = color;
      context.globalAlpha = 1;
      context.beginPath();
      context.arc(x, y, 3.5, 0, Math.PI * 2);
      context.fill();
    });
    context.globalAlpha = 1;

    if (!reduced.matches && visible && document.visibilityState === 'visible') {
      frame = requestAnimationFrame(draw);
    }
  };

  const observer = new IntersectionObserver(([entry]) => {
    visible = entry.isIntersecting;
    if (!visible) stop();
    if (visible && !frame) draw();
  });
  observer.observe(canvas);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') stop();
    if (document.visibilityState === 'visible' && visible && !frame) draw();
  });
  reduced.addEventListener('change', () => {
    stop();
    draw();
  });
  window.addEventListener('resize', () => {
    resize();
    draw();
  }, {passive: true});

  resize();
  draw();
})();
