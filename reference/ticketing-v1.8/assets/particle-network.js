/*
 * شبکهٔ ذرات تعاملی — پس‌زمینهٔ صفحات ورود و داشبورد.
 * رنگ با data-color روی تگ script تنظیم می‌شود (مثلاً data-color="0,0,0" برای مشکی).
 * پشت محتوا می‌نشیند و pointer-events ندارد، پس کلیک‌ها را مسدود نمی‌کند.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var color = (script && script.dataset && script.dataset.color) || '0,0,0';
    if (!/^\d{1,3},\d{1,3},\d{1,3}$/.test(color)) {
        color = '0,0,0';
    }

    var canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    canvas.className = 'particle-network-canvas';
    canvas.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;z-index:-1;pointer-events:none;display:block';
    document.body.insertBefore(canvas, document.body.firstChild);

    var ctx = canvas.getContext ? canvas.getContext('2d') : null;
    if (!ctx) {
        return;
    }
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var CONFIG = {
        density: 4500,      // هرچه عدد بزرگ‌تر، ذرات کمتر
        maxParticles: 320,
        speed: 0.25,
        linkDistance: 130,
        mouseRadius: 180
    };

    var particles = [];
    var mouse = { x: -9999, y: -9999, active: false };
    var w = 0;
    var h = 0;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);

    function makeParticle() {
        var angle = Math.random() * Math.PI * 2;
        var speed = (0.3 + Math.random() * 0.7) * CONFIG.speed;
        return {
            x: Math.random() * w,
            y: Math.random() * h,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed,
            r: 0.8 + Math.random() * 1.6
        };
    }

    function resize() {
        w = window.innerWidth;
        h = window.innerHeight;
        canvas.width = Math.floor(w * dpr);
        canvas.height = Math.floor(h * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        var count = Math.min(CONFIG.maxParticles, Math.floor((w * h) / CONFIG.density));
        particles = [];
        for (var i = 0; i < count; i++) {
            particles.push(makeParticle());
        }
    }

    window.addEventListener('resize', resize);
    window.addEventListener('mousemove', function (e) {
        mouse.x = e.clientX;
        mouse.y = e.clientY;
        mouse.active = true;
    });
    document.addEventListener('mouseleave', function () { mouse.active = false; });

    function draw() {
        ctx.clearRect(0, 0, w, h);
        var i;
        var p;
        for (i = 0; i < particles.length; i++) {
            p = particles[i];
            if (!reduce) {
                p.x += p.vx;
                p.y += p.vy;
            }
            if (p.x < -10) { p.x = w + 10; } else if (p.x > w + 10) { p.x = -10; }
            if (p.y < -10) { p.y = h + 10; } else if (p.y > h + 10) { p.y = -10; }
        }

        for (var a = 0; a < particles.length; a++) {
            var pa = particles[a];
            if (mouse.active) {
                var dmx = pa.x - mouse.x;
                var dmy = pa.y - mouse.y;
                var dm = Math.sqrt(dmx * dmx + dmy * dmy);
                if (dm < CONFIG.mouseRadius) {
                    ctx.strokeStyle = 'rgba(' + color + ',' + ((1 - dm / CONFIG.mouseRadius) * 0.9).toFixed(3) + ')';
                    ctx.lineWidth = 0.8;
                    ctx.beginPath();
                    ctx.moveTo(pa.x, pa.y);
                    ctx.lineTo(mouse.x, mouse.y);
                    ctx.stroke();
                }
            }
            for (var b = a + 1; b < particles.length; b++) {
                var pb = particles[b];
                var dx = pa.x - pb.x;
                var dy = pa.y - pb.y;
                var d = Math.sqrt(dx * dx + dy * dy);
                if (d >= CONFIG.linkDistance) {
                    continue;
                }
                var boost = 0;
                if (mouse.active) {
                    var mx = (pa.x + pb.x) / 2 - mouse.x;
                    var my = (pa.y + pb.y) / 2 - mouse.y;
                    boost = Math.max(0, 1 - Math.sqrt(mx * mx + my * my) / CONFIG.mouseRadius);
                }
                var alpha = (1 - d / CONFIG.linkDistance) * (0.15 + boost * 0.75);
                if (alpha <= 0.01) {
                    continue;
                }
                ctx.strokeStyle = 'rgba(' + color + ',' + alpha.toFixed(3) + ')';
                ctx.lineWidth = 0.7;
                ctx.beginPath();
                ctx.moveTo(pa.x, pa.y);
                ctx.lineTo(pb.x, pb.y);
                ctx.stroke();
            }
        }

        ctx.fillStyle = 'rgba(' + color + ',0.75)';
        for (i = 0; i < particles.length; i++) {
            p = particles[i];
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
            ctx.fill();
        }

        if (!reduce) {
            requestAnimationFrame(draw);
        }
    }

    resize();
    draw();
})();
