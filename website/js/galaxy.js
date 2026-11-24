/**
 * Image-based Space Background
 * Uses a CC0/Public Domain seamless space texture from Screaming Brain Studios.
 * It is intentionally static/lightweight to avoid GPU/RAM spikes.
 */
export function initGalaxy() {
    const canvas = document.getElementById('galaxy');
    if (!canvas) return;

    const ctx = canvas.getContext('2d', { alpha: true });
    if (!ctx) return;

    const image = new Image();
    image.src = 'assets/space-background.webp';

    let width = 0;
    let height = 0;
    let dpr = 1;
    let offsetX = 0;
    let offsetY = 0;
    let raf = 0;

    function resize() {
        dpr = Math.min(window.devicePixelRatio || 1, 1.25);
        width = window.innerWidth;
        height = window.innerHeight;
        canvas.width = Math.floor(width * dpr);
        canvas.height = Math.floor(height * dpr);
        canvas.style.width = `${width}px`;
        canvas.style.height = `${height}px`;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        draw();
    }

    function draw() {
        if (!image.complete || !image.naturalWidth) return;

        ctx.clearRect(0, 0, width, height);

        const scale = Math.max(1.65, Math.min(2.6, Math.max(width, height) / image.naturalWidth));
        const tile = image.naturalWidth * scale;
        const ox = ((offsetX % tile) + tile) % tile;
        const oy = ((offsetY % tile) + tile) % tile;

        ctx.globalAlpha = 0.82;
        for (let x = -tile + ox; x < width + tile; x += tile) {
            for (let y = -tile + oy; y < height + tile; y += tile) {
                ctx.drawImage(image, x, y, tile, tile);
            }
        }
        ctx.globalAlpha = 1;

        // Dark vignette keeps Persian text readable while preserving real star texture.
        const vignette = ctx.createRadialGradient(width * 0.5, height * 0.47, 0, width * 0.5, height * 0.47, Math.max(width, height) * 0.78);
        vignette.addColorStop(0, 'rgba(0, 0, 0, 0.08)');
        vignette.addColorStop(0.55, 'rgba(5, 10, 24, 0.35)');
        vignette.addColorStop(1, 'rgba(5, 8, 18, 0.78)');
        ctx.fillStyle = vignette;
        ctx.fillRect(0, 0, width, height);
    }

    function requestDraw() {
        if (raf) return;
        raf = requestAnimationFrame(() => {
            raf = 0;
            draw();
        });
    }

    window.addEventListener('resize', resize, { passive: true });
    window.addEventListener('mousemove', (event) => {
        offsetX = (event.clientX / window.innerWidth - 0.5) * -24;
        offsetY = (event.clientY / window.innerHeight - 0.5) * -18;
        requestDraw();
    }, { passive: true });

    image.onload = () => {
        resize();
        // Static image texture + redraw only on resize/mousemove; no artificial star animation.
        draw();
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden && raf) {
            cancelAnimationFrame(raf);
            raf = 0;
        } else if (!document.hidden && image.complete) {
            draw();
        }
    });
}
