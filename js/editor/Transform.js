const ROTATE_HANDLE_OFFSET = 36;
const SCALE_HANDLE_SIZE = 9;

/** World-space position of the rotate handle above a selected object's anchor. */
export function getRotateHandlePosition(obj, assetManager) {
  const anchor = obj.getAnchorOffset(assetManager);
  const localX = 0;
  const localY = -anchor.y - ROTATE_HANDLE_OFFSET;
  const cos = Math.cos(obj.rotation);
  const sin = Math.sin(obj.rotation);
  return { x: obj.x + localX * cos - localY * sin, y: obj.y + localX * sin + localY * cos };
}

/** World-space position of the bottom-right uniform-scale handle. */
export function getScaleHandlePosition(obj, assetManager) {
  const { width, height } = obj.getSize(assetManager);
  const anchor = obj.getAnchorOffset(assetManager);
  const localX = width - anchor.x;
  const localY = height - anchor.y;
  const cos = Math.cos(obj.rotation);
  const sin = Math.sin(obj.rotation);
  return { x: obj.x + localX * cos - localY * sin, y: obj.y + localX * sin + localY * cos };
}

export function drawTransformHandles(ctx, camera, obj, assetManager) {
  const rotateHandle = getRotateHandlePosition(obj, assetManager);
  const scaleHandle = getScaleHandlePosition(obj, assetManager);
  const r = 6 / camera.zoom;
  const s = SCALE_HANDLE_SIZE / camera.zoom;

  ctx.save();
  ctx.strokeStyle = '#ffcf4d';
  ctx.lineWidth = 1.5 / camera.zoom;
  ctx.beginPath();
  ctx.moveTo(obj.x, obj.y - obj.getAnchorOffset(assetManager).y);
  ctx.lineTo(rotateHandle.x, rotateHandle.y);
  ctx.stroke();

  ctx.fillStyle = '#2fbf71';
  ctx.beginPath();
  ctx.arc(rotateHandle.x, rotateHandle.y, r, 0, Math.PI * 2);
  ctx.fill();
  ctx.strokeStyle = '#183a26';
  ctx.stroke();

  ctx.fillStyle = '#4fa3ff';
  ctx.fillRect(scaleHandle.x - s / 2, scaleHandle.y - s / 2, s, s);
  ctx.strokeStyle = '#123055';
  ctx.strokeRect(scaleHandle.x - s / 2, scaleHandle.y - s / 2, s, s);
  ctx.restore();
}

export function hitTestHandle(worldX, worldY, obj, assetManager, camera) {
  const tolerance = 12 / camera.zoom;
  const rotateHandle = getRotateHandlePosition(obj, assetManager);
  if (Math.hypot(worldX - rotateHandle.x, worldY - rotateHandle.y) <= tolerance) return 'rotate';

  const scaleHandle = getScaleHandlePosition(obj, assetManager);
  if (Math.hypot(worldX - scaleHandle.x, worldY - scaleHandle.y) <= tolerance) return 'scale';

  return null;
}

export function angleBetween(cx, cy, px, py) {
  return Math.atan2(py - cy, px - cx) + Math.PI / 2;
}
