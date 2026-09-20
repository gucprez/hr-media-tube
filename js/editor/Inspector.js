import { radToDeg, degToRad } from '../utils/MathUtils.js';

const LAYERS = ['terrain', 'water', 'paths', 'objects', 'decoration', 'buildSlots', 'bases', 'interactive'];
const BUILD_CATEGORIES = ['defense', 'military', 'economy'];

/**
 * DOM-driven right-side panel. Renders fields for the current selection and
 * pushes numeric edits back through onChange (wrapped in a History command by
 * the Editor so every field edit is undoable).
 */
export class Inspector {
  constructor(panelEl, { onChange, onDelete, onDuplicate }) {
    this.panel = panelEl;
    this.onChange = onChange;
    this.onDelete = onDelete;
    this.onDuplicate = onDuplicate;
  }

  clear() {
    this.panel.innerHTML = '<p class="inspector-empty">No object selected.</p>';
  }

  show(entry) {
    if (!entry) return this.clear();
    this.panel.innerHTML = '';
    if (entry.kind === 'object') this._renderObject(entry.ref);
    else if (entry.kind === 'buildSlot') this._renderBuildSlot(entry.ref);
    else if (entry.kind === 'pathPoint') this._renderPathPoint(entry);
    else this.clear();
  }

  _section(title) {
    const section = document.createElement('div');
    section.className = 'inspector-section';
    const heading = document.createElement('h3');
    heading.textContent = title;
    section.appendChild(heading);
    this.panel.appendChild(section);
    return section;
  }

  _field(section, label, value, { type = 'number', step = 1, onInput, options = null } = {}) {
    const row = document.createElement('label');
    row.className = 'field-row';
    const span = document.createElement('span');
    span.textContent = label;
    row.appendChild(span);

    let input;
    if (options) {
      input = document.createElement('select');
      for (const opt of options) {
        const optionEl = document.createElement('option');
        optionEl.value = opt;
        optionEl.textContent = opt;
        if (opt === value) optionEl.selected = true;
        input.appendChild(optionEl);
      }
      input.addEventListener('change', () => onInput(input.value));
    } else if (type === 'checkbox') {
      input = document.createElement('input');
      input.type = 'checkbox';
      input.checked = !!value;
      input.addEventListener('change', () => onInput(input.checked));
    } else {
      input = document.createElement('input');
      input.type = type;
      input.step = step;
      input.value = value;
      input.addEventListener('change', () => {
        const num = type === 'number' ? parseFloat(input.value) : input.value;
        if (type === 'number' && Number.isNaN(num)) return;
        onInput(num);
      });
    }
    row.appendChild(input);
    section.appendChild(row);
    return input;
  }

  _readonlyField(section, label, value) {
    const row = document.createElement('div');
    row.className = 'field-row readonly';
    const span = document.createElement('span');
    span.textContent = label;
    const val = document.createElement('span');
    val.className = 'field-value';
    val.textContent = value;
    row.appendChild(span);
    row.appendChild(val);
    section.appendChild(row);
  }

  _actions() {
    const row = document.createElement('div');
    row.className = 'inspector-actions';

    const dup = document.createElement('button');
    dup.className = 'btn';
    dup.textContent = 'Duplicate';
    dup.addEventListener('click', () => this.onDuplicate?.());

    const del = document.createElement('button');
    del.className = 'btn btn-danger';
    del.textContent = 'Delete';
    del.addEventListener('click', () => this.onDelete?.());

    row.appendChild(dup);
    row.appendChild(del);
    this.panel.appendChild(row);
  }

  _renderObject(obj) {
    const info = this._section('Object');
    this._readonlyField(info, 'ID', obj.id);
    this._readonlyField(info, 'Type', obj.type);
    this._readonlyField(info, 'Asset', obj.asset);

    const transform = this._section('Transform');
    this._field(transform, 'X', Math.round(obj.x), { onInput: (v) => this.onChange('x', v) });
    this._field(transform, 'Y', Math.round(obj.y), { onInput: (v) => this.onChange('y', v) });
    this._field(transform, 'Rotation (deg)', Math.round(radToDeg(obj.rotation) * 10) / 10, {
      onInput: (v) => this.onChange('rotation', degToRad(v))
    });
    this._field(transform, 'Scale X', obj.scaleX, { step: 0.05, onInput: (v) => this.onChange('scaleX', v) });
    this._field(transform, 'Scale Y', obj.scaleY, { step: 0.05, onInput: (v) => this.onChange('scaleY', v) });

    const layer = this._section('Layer');
    this._field(layer, 'Layer', obj.layer, { options: LAYERS, onInput: (v) => this.onChange('layer', v) });

    this._actions();
  }

  _renderBuildSlot(slot) {
    const info = this._section('Build Slot');
    this._readonlyField(info, 'ID', slot.id);

    const transform = this._section('Transform');
    this._field(transform, 'X', Math.round(slot.x), { onInput: (v) => this.onChange('x', v) });
    this._field(transform, 'Y', Math.round(slot.y), { onInput: (v) => this.onChange('y', v) });
    this._field(transform, 'Radius', Math.round(slot.radius), { onInput: (v) => this.onChange('radius', Math.max(10, v)) });

    const categories = this._section('Allowed Categories');
    for (const cat of BUILD_CATEGORIES) {
      this._field(categories, cat, slot.allowedCategories.includes(cat), {
        type: 'checkbox',
        onInput: (checked) => {
          const next = new Set(slot.allowedCategories);
          if (checked) next.add(cat);
          else next.delete(cat);
          this.onChange('allowedCategories', [...next]);
        }
      });
    }

    this._actions();
  }

  _renderPathPoint(entry) {
    const info = this._section('Path Point');
    this._readonlyField(info, 'Path', entry.pathId);
    this._readonlyField(info, 'Index', entry.index);

    const transform = this._section('Position');
    this._field(transform, 'X', Math.round(entry.point.x), { onInput: (v) => this.onChange('x', v) });
    this._field(transform, 'Y', Math.round(entry.point.y), { onInput: (v) => this.onChange('y', v) });

    const row = document.createElement('div');
    row.className = 'inspector-actions';
    const del = document.createElement('button');
    del.className = 'btn btn-danger';
    del.textContent = 'Delete Point';
    del.addEventListener('click', () => this.onDelete?.());
    row.appendChild(del);
    this.panel.appendChild(row);
  }
}
