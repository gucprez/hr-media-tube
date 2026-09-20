const MAX_HISTORY = 200;

/** Simple command-pattern undo/redo stack (Ctrl+Z / Ctrl+Y + toolbar buttons). */
export class History {
  constructor(onChange) {
    this.undoStack = [];
    this.redoStack = [];
    this.onChange = onChange;
  }

  execute(command) {
    command.do();
    this.undoStack.push(command);
    if (this.undoStack.length > MAX_HISTORY) this.undoStack.shift();
    this.redoStack.length = 0;
    this.onChange?.();
  }

  undo() {
    const command = this.undoStack.pop();
    if (!command) return false;
    command.undo();
    this.redoStack.push(command);
    this.onChange?.();
    return true;
  }

  redo() {
    const command = this.redoStack.pop();
    if (!command) return false;
    command.do();
    this.undoStack.push(command);
    this.onChange?.();
    return true;
  }

  canUndo() {
    return this.undoStack.length > 0;
  }

  canRedo() {
    return this.redoStack.length > 0;
  }

  clear() {
    this.undoStack.length = 0;
    this.redoStack.length = 0;
    this.onChange?.();
  }
}

export function createCommand(doFn, undoFn) {
  return { do: doFn, undo: undoFn };
}
