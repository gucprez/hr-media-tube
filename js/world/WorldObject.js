import { rotatePoint, pointInRotatedRect } from '../utils/Geometry.js';

const DEFAULT_SIZE = 96;

const ANCHOR_FRACTIONS = {
  center: { x: 0.5, y: 0.5 },
  'bottom-center': { x: 0.5, y: 1 },
  'top-center': { x: 0.5, y: 0 }
};

/**
 * Generic sprite-based world entity: trees, rocks, bushes, grass, logs, fences,
 * barricades, bridges and base markers are all independent WorldObject records.
 * Nothing is ever merged into a single drawing - every instance keeps its own
 * id/position/rotation/scale so duplicated fences, trees, etc. stay independent.
 */
export class WorldObject {
  constructor({ id, type, asset, x = 0, y = 0, rotation = 0, scaleX = 1, scaleY = 1, layer = 'objects' }) {
    this.id = id;
    this.type = type;
    this.asset = asset;
    this.x = x;
    this.y = y;
    this.rotation = rotation;
    this.scaleX = scaleX;
    this.scaleY = scaleY;
    this.layer = layer;
  }

  getBaseSize(assetManager) {
    const record = assetManager.get(this.asset);
    const meta = record?.meta;
    const width = (record?.loaded ? record.image.naturalWidth : meta?.dimensions?.width) ?? DEFAULT_SIZE;
    const height = (record?.loaded ? record.image.naturalHeight : meta?.dimensions?.height) ?? DEFAULT_SIZE;
    return { width, height };
  }

  getSize(assetManager) {
    const { width, height } = this.getBaseSize(assetManager);
    return { width: width * this.scaleX, height: height * this.scaleY };
  }

  getAnchorFraction(assetManager) {
    const meta = assetManager.get(this.asset)?.meta;
    return ANCHOR_FRACTIONS[meta?.anchor] ?? ANCHOR_FRACTIONS['bottom-center'];
  }

  getAnchorOffset(assetManager) {
    const { width, height } = this.getSize(assetManager);
    const fraction = this.getAnchorFraction(assetManager);
    return { x: width * fraction.x, y: height * fraction.y };
  }

  containsPoint(px, py, assetManager) {
    const { width, height } = this.getSize(assetManager);
    const fraction = this.getAnchorFraction(assetManager);
    return pointInRotatedRect(px, py, {
      x: this.x,
      y: this.y,
      width,
      height,
      rotation: this.rotation,
      anchorX: fraction.x,
      anchorY: fraction.y
    });
  }

  getBoundingCorners(assetManager) {
    const { width, height } = this.getSize(assetManager);
    const anchor = this.getAnchorOffset(assetManager);
    const local = [
      { x: -anchor.x, y: -anchor.y },
      { x: width - anchor.x, y: -anchor.y },
      { x: width - anchor.x, y: height - anchor.y },
      { x: -anchor.x, y: height - anchor.y }
    ];
    return local.map((c) => rotatePoint(this.x + c.x, this.y + c.y, this.x, this.y, this.rotation));
  }

  draw(ctx, assetManager) {
    const record = assetManager.get(this.asset);
    const { width, height } = this.getSize(assetManager);
    const anchor = this.getAnchorOffset(assetManager);

    ctx.save();
    ctx.translate(this.x, this.y);
    ctx.rotate(this.rotation);

    ctx.save();
    ctx.globalAlpha = 0.32;
    ctx.fillStyle = '#000000';
    ctx.beginPath();
    ctx.ellipse(0, anchor.y - height * 0.04, width * 0.32, height * 0.09, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();

    if (record?.loaded) {
      ctx.drawImage(record.image, -anchor.x, -anchor.y, width, height);
    } else {
      drawPlaceholder(ctx, this.type, width, height, anchor);
    }
    ctx.restore();
  }

  clone(newId) {
    return new WorldObject({
      id: newId,
      type: this.type,
      asset: this.asset,
      x: this.x + 40,
      y: this.y + 40,
      rotation: this.rotation,
      scaleX: this.scaleX,
      scaleY: this.scaleY,
      layer: this.layer
    });
  }

  toJSON() {
    return {
      id: this.id,
      type: this.type,
      asset: this.asset,
      x: this.x,
      y: this.y,
      rotation: this.rotation,
      scaleX: this.scaleX,
      scaleY: this.scaleY,
      layer: this.layer
    };
  }
}

const PLACEHOLDER_STYLES = {
  tree: { color: '#3c5a3a', accent: '#2c4229', shape: 'canopy' },
  rock: { color: '#6b6f70', accent: '#54585a', shape: 'blob' },
  bush: { color: '#4a6b3f', accent: '#3a552f', shape: 'blob' },
  grass: { color: '#5f8a3f', accent: '#4a6b30', shape: 'tuft' },
  log: { color: '#5a4632', accent: '#453423', shape: 'log' },
  fence: { color: '#8a6f4d', accent: '#5c4a33', shape: 'post' },
  barricade: { color: '#4b4038', accent: '#332b25', shape: 'box' },
  bridge: { color: '#7a5c3a', accent: '#5c4429', shape: 'box' },
  playerBase: { color: '#2f6fb0', accent: '#1e4c7d', shape: 'flag' },
  enemyBase: { color: '#a13333', accent: '#742323', shape: 'flag' }
};

/**
 * Neutral procedural stand-in used until real art is dropped into /assets/.
 * Kept simple/silhouette-based on purpose so it never masquerades as final art.
 */
function drawPlaceholder(ctx, type, width, height, anchor) {
  const style = PLACEHOLDER_STYLES[type] ?? { color: '#777777', accent: '#555555', shape: 'box' };
  const left = -anchor.x;
  const top = -anchor.y;

  ctx.fillStyle = style.color;
  ctx.strokeStyle = 'rgba(0,0,0,0.45)';
  ctx.lineWidth = 2;

  switch (style.shape) {
    case 'canopy': {
      ctx.fillStyle = style.accent;
      ctx.fillRect(left + width * 0.46, top + height * 0.55, width * 0.08, height * 0.45);
      ctx.fillStyle = style.color;
      ctx.beginPath();
      ctx.ellipse(left + width / 2, top + height * 0.35, width * 0.42, height * 0.36, 0, 0, Math.PI * 2);
      ctx.fill();
      ctx.stroke();
      break;
    }
    case 'blob': {
      ctx.beginPath();
      ctx.ellipse(left + width / 2, top + height * 0.62, width * 0.42, height * 0.34, 0, 0, Math.PI * 2);
      ctx.fill();
      ctx.stroke();
      break;
    }
    case 'tuft': {
      ctx.strokeStyle = style.color;
      ctx.lineWidth = Math.max(2, width * 0.06);
      for (let i = 0; i < 5; i++) {
        const bx = left + (width / 5) * i + width / 10;
        ctx.beginPath();
        ctx.moveTo(bx, top + height);
        ctx.lineTo(bx - width * 0.06, top + height * 0.15);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(bx, top + height);
        ctx.lineTo(bx + width * 0.06, top + height * 0.15);
        ctx.stroke();
      }
      break;
    }
    case 'log': {
      const r = Math.min(height * 0.15, 10);
      roundRectPath(ctx, left, top + height * 0.4, width, height * 0.3, r);
      ctx.fill();
      ctx.stroke();
      ctx.fillStyle = style.accent;
      ctx.beginPath();
      ctx.ellipse(left + width * 0.06, top + height * 0.55, height * 0.14, height * 0.14, 0, 0, Math.PI * 2);
      ctx.fill();
      break;
    }
    case 'post': {
      ctx.fillRect(left + width * 0.42, top, width * 0.16, height);
      ctx.strokeRect(left + width * 0.42, top, width * 0.16, height);
      ctx.fillStyle = style.accent;
      ctx.fillRect(left, top + height * 0.3, width, height * 0.12);
      break;
    }
    case 'flag': {
      ctx.fillRect(left + width * 0.46, top, width * 0.08, height);
      ctx.strokeRect(left + width * 0.46, top, width * 0.08, height);
      ctx.fillStyle = style.accent;
      ctx.beginPath();
      ctx.moveTo(left + width * 0.54, top);
      ctx.lineTo(left + width * 0.9, top + height * 0.14);
      ctx.lineTo(left + width * 0.54, top + height * 0.28);
      ctx.closePath();
      ctx.fill();
      break;
    }
    default: {
      roundRectPath(ctx, left, top, width, height, 6);
      ctx.fill();
      ctx.stroke();
    }
  }
}

function roundRectPath(ctx, x, y, width, height, radius) {
  ctx.beginPath();
  ctx.moveTo(x + radius, y);
  ctx.arcTo(x + width, y, x + width, y + height, radius);
  ctx.arcTo(x + width, y + height, x, y + height, radius);
  ctx.arcTo(x, y + height, x, y, radius);
  ctx.arcTo(x, y, x + width, y, radius);
  ctx.closePath();
}
