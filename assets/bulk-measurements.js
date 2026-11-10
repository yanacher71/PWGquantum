(() => {
  'use strict';
  const form = document.getElementById('bulk-form');
  const start = document.getElementById('start');
  const pause = document.getElementById('pause');
  const resume = document.getElementById('resume');
  const summary = document.getElementById('summary');
  const table = document.getElementById('results');
  let job = null;
  let rows = [];
  let cells = [];
  let cursor = 0;
  let paused = false;
  async function request(values) {
    const body = new FormData();
    body.set('csrf', form.elements.csrf.value);
    for (const [key, value] of Object.entries(values)) body.set(key, value);
    const response = await fetch('/admin/bulk-measurements.php', {method: 'POST', body, credentials: 'same-origin'});
    const result = await response.json();
    if (!response.ok || result.error) {
      const error = new Error(result.error || 'Request failed.');
      error.restart = response.status === 409 || response.status === 403;
      throw error;
    }
    return result;
  }
  function progress(prefix) {
    const count = status => rows.filter(row => row.status === status).length;
    summary.textContent = `${prefix} ${count('imported')} imported · ${count('skipped')} skipped · ${count('failed')} failed · ${count('queued')} pending`;
  }
  function displayRows() {
    table.replaceChildren();
    cells = rows.map(row => {
      const tr = document.createElement('tr');
      const values = [row.serial, row.status, row.message];
      const columns = values.map(value => {
        const cell = document.createElement('td');
        cell.textContent = value;
        tr.appendChild(cell);
        return cell;
      });
      table.appendChild(tr);
      return columns;
    });
  }
  async function run() {
    resume.hidden = true;
    pause.disabled = false;
    paused = false;
    try {
      while (cursor < rows.length && !paused) {
        if (rows[cursor].status === 'queued') {
          progress(`Importing ${rows[cursor].serial}.`);
          const result = await request({action: 'import', job, index: cursor});
          rows[cursor] = result.row;
          cells[cursor][1].textContent = result.row.status;
          cells[cursor][2].textContent = result.row.message;
        }
        cursor++;
      }
      progress(cursor === rows.length ? 'Complete.' : 'Paused.');
    } catch (error) {
      // Retrying this same index is safe if the server completed before the connection failed.
      if (error.restart) job = null;
      progress(`${error.restart ? 'Start a new import after reloading or signing in.' : 'Connection interrupted. Resume to retry this cable.'} ${error.message}`);
      paused = true;
    }
    pause.disabled = true;
    resume.hidden = cursor >= rows.length || job === null;
    start.disabled = cursor < rows.length && job !== null;
    form.elements.parent.disabled = start.disabled;
    form.elements.calibration.disabled = start.disabled;
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    start.disabled = true;
    resume.hidden = true;
    summary.textContent = 'Reading cable folders and shared calibration…';
    try {
      const result = await request({action: 'scan', parent: form.elements.parent.value, calibration: form.elements.calibration.value});
      job = result.job;
      rows = result.rows;
      cursor = 0;
      displayRows();
      form.elements.parent.disabled = true;
      form.elements.calibration.disabled = true;
      await run();
    } catch (error) {
      summary.textContent = error.message;
      start.disabled = false;
    }
  });
  pause.addEventListener('click', () => { paused = true; pause.disabled = true; summary.textContent = 'Pausing after the current cable finishes…'; });
  resume.addEventListener('click', run);
})();
