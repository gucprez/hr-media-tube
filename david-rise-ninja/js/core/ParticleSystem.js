// Sistema de partículas genérico y reutilizable, con pooling para evitar presión de GC.
// En esta fase sólo se usa para ambientación (hojas, polvo, luciérnagas); las fases de
// combate/habilidades reutilizarán la misma clase para humo, fuego, chispas, magia, etc.
const DEFAULT_POOL_SIZE = 300;

class Particle {
    constructor() {
        this.active = false;
        this.reset();
    }

    reset() {
        this.x = 0;
        this.y = 0;
        this.vx = 0;
        this.vy = 0;
        this.gravity = 0;
        this.life = 0;
        this.maxLife = 1;
        this.size = 2;
        this.endSize = 2;
        this.rotation = 0;
        this.rotationSpeed = 0;
        this.color = '255,255,255';
        this.alphaStart = 1;
        this.alphaEnd = 0;
        this.kind = 'dot';
        this.glow = false;
    }
}

export class ParticleSystem {
    constructor(poolSize = DEFAULT_POOL_SIZE) {
        this.pool = Array.from({ length: poolSize }, () => new Particle());
        this.cursor = 0;
    }

    spawn(config) {
        let p = this.pool.find((particle) => !particle.active);
        if (!p) {
            p = this.pool[this.cursor];
            this.cursor = (this.cursor + 1) % this.pool.length;
        }
        p.reset();
        p.active = true;
        Object.assign(p, config);
        return p;
    }

    update(dt) {
        for (const p of this.pool) {
            if (!p.active) continue;
            p.life += dt;
            if (p.life >= p.maxLife) {
                p.active = false;
                continue;
            }
            p.vy += p.gravity * dt;
            p.x += p.vx * dt;
            p.y += p.vy * dt;
            p.rotation += p.rotationSpeed * dt;
        }
    }

    render(ctx, camera) {
        const view = camera.getVisibleWorldRect(80);
        for (const p of this.pool) {
            if (!p.active) continue;
            if (p.x < view.left || p.x > view.right || p.y < view.top || p.y > view.bottom) continue;

            const t = p.life / p.maxLife;
            const alpha = Math.max(0, p.alphaStart + (p.alphaEnd - p.alphaStart) * t);
            const size = (p.size + (p.endSize - p.size) * t) * camera.zoom;
            const screen = camera.worldToScreen(p.x, p.y);

            ctx.save();
            ctx.translate(screen.x, screen.y);
            ctx.rotate(p.rotation);
            ctx.globalAlpha = alpha;

            if (p.glow) {
                const g = ctx.createRadialGradient(0, 0, 0, 0, 0, size * 2.4);
                g.addColorStop(0, `rgba(${p.color},0.9)`);
                g.addColorStop(1, `rgba(${p.color},0)`);
                ctx.fillStyle = g;
                ctx.beginPath();
                ctx.arc(0, 0, size * 2.4, 0, Math.PI * 2);
                ctx.fill();
            } else if (p.kind === 'leaf') {
                ctx.fillStyle = `rgb(${p.color})`;
                ctx.beginPath();
                ctx.ellipse(0, 0, size, size * 0.5, 0, 0, Math.PI * 2);
                ctx.fill();
            } else {
                ctx.fillStyle = `rgb(${p.color})`;
                ctx.beginPath();
                ctx.arc(0, 0, size, 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.restore();
        }
    }
}
