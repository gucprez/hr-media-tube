export function rotatePoint(px, py, cx, cy, angle) {
  const cos = Math.cos(angle);
  const sin = Math.sin(angle);
  const dx = px - cx;
  const dy = py - cy;
  return { x: cx + dx * cos - dy * sin, y: cy + dx * sin + dy * cos };
}

/**
 * Tests a world point against a rectangle that is centered at (x, y), rotated by
 * `rotation` radians, whose local origin (0,0) sits at anchorX/anchorY fractions
 * of its width/height (0..1).
 */
export function pointInRotatedRect(px, py, rect) {
  const { x, y, width, height, rotation = 0, anchorX = 0.5, anchorY = 1 } = rect;
  const localOriginX = -width * anchorX;
  const localOriginY = -height * anchorY;
  const cos = Math.cos(-rotation);
  const sin = Math.sin(-rotation);
  const dx = px - x;
  const dy = py - y;
  const rx = dx * cos - dy * sin;
  const ry = dx * sin + dy * cos;
  return rx >= localOriginX && rx <= localOriginX + width && ry >= localOriginY && ry <= localOriginY + height;
}

export function distanceToSegment(px, py, x1, y1, x2, y2) {
  const dx = x2 - x1;
  const dy = y2 - y1;
  const lengthSq = dx * dx + dy * dy;
  let t = lengthSq === 0 ? 0 : ((px - x1) * dx + (py - y1) * dy) / lengthSq;
  t = Math.max(0, Math.min(1, t));
  const cx = x1 + t * dx;
  const cy = y1 + t * dy;
  return Math.hypot(px - cx, py - cy);
}

export function pointInCircle(px, py, cx, cy, radius) {
  return Math.hypot(px - cx, py - cy) <= radius;
}
