/**
 * Spreadsheet-style arrow-key movement for the line-item grids.
 *
 * One definition, used by the enquiry, offer, invoice and purchase order
 * screens, so the keyboard behaves identically wherever lines are keyed in.
 *
 * Up / Down / Enter   move between rows, staying in the same column.
 * Left / Right        move between columns, but only once the caret has
 *                     reached the edge of the text — so you can still fix a
 *                     character in the middle of a value the normal way.
 *
 * Two shapes are supported without any change to the markup of a table:
 *   - a <tr> per row (invoices, offers, purchase orders)
 *   - any element marked data-grid-row (the enquiry form, which uses divs)
 *
 * A textarea is left alone entirely: there Up/Down move the caret through a
 * long spec and Enter starts a new line, which is what the description needs.
 *
 * A <select> takes part in Left/Right and Enter so a dropdown column is not
 * silently skipped on the way across a row — but keeps its native Up/Down,
 * because on a dropdown those change the value, and stealing them would leave
 * no keyboard way to pick an option.
 */

const FIELDS = 'input:not([type="file"]):not([type="hidden"]):not([type="checkbox"]), textarea, select';

const rowOf = (el) => el.closest("[data-grid-row]") || el.closest("tr");

/** The navigable fields of a row, in the order they appear on screen. */
const fieldsIn = (row) => [...row.querySelectorAll(FIELDS)].filter((f) => !f.disabled && f.offsetParent !== null);

/** Land ready to overtype, the way a spreadsheet does. */
function land(field) {
  field.focus();
  try {
    field.select();
  } catch {
    /* number inputs in some browsers */
  }
}

/**
 * Where the caret sits. A number input refuses to report it, so treat it as
 * being at both edges — those values are short and are retyped, not edited.
 */
function atEdge(el) {
  // Neither has a caret to walk through, so Left/Right always step columns.
  if (el.tagName === "SELECT" || el.type === "number") return { start: true, end: true };
  try {
    const { selectionStart: s, selectionEnd: e, value } = el;
    if (s === null) return { start: true, end: true };
    return { start: s === 0 && e === 0, end: s === value.length && e === value.length };
  } catch {
    return { start: true, end: true };
  }
}

/** Step to the next/previous row and focus the field in the same column. */
function moveRow(el, row, delta) {
  const index = fieldsIn(row).indexOf(el);
  if (index < 0) return false;

  let candidate = delta < 0 ? row.previousElementSibling : row.nextElementSibling;

  // Skip anything between rows that holds no fields — a spacer, a group header.
  while (candidate) {
    const fields = fieldsIn(candidate);
    if (fields.length) {
      const target = fields[Math.min(index, fields.length - 1)];
      if (target) {
        land(target);
        return true;
      }
    }
    candidate = delta < 0 ? candidate.previousElementSibling : candidate.nextElementSibling;
  }

  return false;
}

/** Step sideways within the same row. */
function moveColumn(el, row, delta) {
  const fields = fieldsIn(row);
  const target = fields[fields.indexOf(el) + delta];
  if (!target) return false;

  land(target);
  return true;
}

/**
 * Attach to the element wrapping the rows: onKeyDown={gridKeyDown}
 */
export function gridKeyDown(e) {
  const el = e.target;

  if (!(el instanceof HTMLElement)) return;
  if (el.tagName === "TEXTAREA") return;
  if (!el.matches(FIELDS)) return;

  const row = rowOf(el);
  if (!row) return;

  switch (e.key) {
    case "ArrowUp":
    case "ArrowDown": {
      // On a dropdown these are how you choose an option — leave them alone.
      if (el.tagName === "SELECT") return;
      // Stop the number spinner even on the first and last row, so Up/Down can
      // never quietly edit a figure — in this grid the key always means "move".
      e.preventDefault();
      moveRow(el, row, e.key === "ArrowUp" ? -1 : 1);
      return;
    }

    case "Enter": {
      if (moveRow(el, row, 1)) e.preventDefault();
      return;
    }

    case "ArrowLeft":
    case "ArrowRight": {
      const edge = atEdge(el);
      const forward = e.key === "ArrowRight";
      if (forward ? !edge.end : !edge.start) return; // still text to walk through
      if (moveColumn(el, row, forward ? 1 : -1)) e.preventDefault();
      return;
    }

    default:
  }
}

export default gridKeyDown;
