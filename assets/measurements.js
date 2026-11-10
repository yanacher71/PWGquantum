(() => {
  'use strict';
  const source = document.getElementById('measurement-data');
  if (!source) return;
  const data = JSON.parse(source.textContent);
  const namespace = 'http://www.w3.org/2000/svg';
  for (const panel of document.querySelectorAll('.measurement-chart')) {
    const svg = panel.querySelector('svg');
    const readout = panel.querySelector('.measurement-readout');
    const tdr = panel.dataset.kind === 'tdr';
    const series = tdr ? [{name: 'TDR', color: '#1262b5', points: data.tdr}] :
      ['s11', 's22'].filter(key => data[key]).map(key => ({name: key.toUpperCase(), color: key === 's11' ? '#1262b5' : '#b45506', points: data[key]}));
    const points = series.flatMap(line => line.points);
    const extent = points.reduce((range, p) => [Math.min(range[0], p[0]), Math.max(range[1], p[0])], [Infinity, -Infinity]);
    let xRange = [...extent];
    let yRange = tdr ? [30, 70] : [-80, 0];
    let dragStart = null;
    let width = Math.max(360, panel.getBoundingClientRect().width);
    let plotWidth = width - 110;
    function add(tag, attributes, text, parent = svg) {
      const element = document.createElementNS(namespace, tag);
      for (const [key, value] of Object.entries(attributes)) element.setAttribute(key, value);
      if (text !== undefined) element.textContent = text;
      parent.appendChild(element);
      return element;
    }
    const xPixel = value => 85 + (value - xRange[0]) / (xRange[1] - xRange[0]) * plotWidth;
    const yPixel = value => 285 - (value - yRange[0]) / (yRange[1] - yRange[0]) * 260;
    function render() {
      width = Math.max(360, panel.getBoundingClientRect().width);
      plotWidth = width - 110;
      svg.setAttribute('viewBox', `0 0 ${width} 340`);
      svg.replaceChildren();
      const clipId = 'clip-' + panel.dataset.kind;
      const defs = add('defs', {});
      const clip = add('clipPath', {id: clipId}, undefined, defs);
      add('rect', {x: 85, y: 25, width: plotWidth, height: 260}, undefined, clip);
      for (let i = 0; i <= 5; i++) {
        const x = 85 + i * plotWidth / 5;
        add('line', {x1: x, x2: x, y1: 25, y2: 285, stroke: '#e2e7ed'});
        add('text', {x, y: 308, 'text-anchor': 'middle', 'font-size': 14, fill: '#334155'}, (xRange[0] + i / 5 * (xRange[1] - xRange[0])).toFixed(2));

      }
      // Keep normal views at 10-unit intervals, with fewer labels for extreme fitted ranges.
      const tickStep = 10 * Math.max(1, Math.ceil((yRange[1] - yRange[0]) / 1000));
      for (let value = Math.ceil(yRange[0] / tickStep) * tickStep; value <= yRange[1]; value += tickStep) {
        const y = yPixel(value);
        add('line', {x1: 85, x2: width - 25, y1: y, y2: y, stroke: '#e2e7ed'});
        add('text', {x: 75, y: y + 5, 'text-anchor': 'end', 'font-size': 14, fill: '#334155'}, value.toFixed(0));
      }
      add('text', {x: (85 + width - 25) / 2, y: 333, 'text-anchor': 'middle', 'font-size': 15, fill: '#334155'}, tdr ? 'Time (ns)' : 'Frequency (GHz)');
      add('text', {x: 18, y: 155, transform: 'rotate(-90 18 155)', 'text-anchor': 'middle', 'font-size': 15, fill: '#334155'}, tdr ? 'Impedance (Ω)' : 'Reflection magnitude (dB)');
      const graph = add('g', {'clip-path': `url(#${clipId})`});
      if (tdr) add('line', {x1: 85, x2: width - 25, y1: yPixel(50), y2: yPixel(50), stroke: '#64748b', 'stroke-dasharray': '6 5'}, undefined, graph);
      let outside = false;
      for (const line of series) {
        let path = ''; let pen = false;
        for (const [x, y] of line.points) {
          if (y === null || !Number.isFinite(y)) { pen = false; continue; }
          if (x >= xRange[0] && x <= xRange[1] && (y < yRange[0] || y > yRange[1])) outside = true;
          path += `${pen ? 'L' : 'M'}${xPixel(x).toFixed(2)},${yPixel(y).toFixed(2)} `;
          pen = true;
          if (!tdr) add('circle', {cx: xPixel(x), cy: yPixel(y), r: 3, fill: line.color}, undefined, graph);
        }
        add('path', {d: path, fill: 'none', stroke: line.color, 'stroke-width': 1.6}, undefined, graph);
      }
      if (outside) readout.textContent = tdr ? 'Some values are outside this view. Use Fit impedance range.' : 'Some values are outside the −80 to 0 dB view. Download the original data for all values.';
      else readout.textContent = '';
    }
    function position(event) {
      const point = svg.createSVGPoint();
      point.x = event.clientX; point.y = event.clientY;
      const local = point.matrixTransform(svg.getScreenCTM().inverse());
      return Math.max(85, Math.min(width - 25, local.x));
    }
    function valueAt(pixel) { return xRange[0] + (pixel - 85) / plotWidth * (xRange[1] - xRange[0]); }
    svg.addEventListener('pointermove', event => {
      const x = valueAt(position(event));
      readout.textContent = series.map(line => {
        const nearest = line.points.reduce((best, p) => Math.abs(p[0] - x) < Math.abs(best[0] - x) ? p : best);
        return `${line.name} · ${nearest[0].toFixed(3)} ${tdr ? 'ns' : 'GHz'} · ${nearest[1] === null ? 'undefined' : nearest[1].toFixed(2)} ${tdr ? 'Ω' : 'dB'}`;
      }).join('   |   ');
    });
    svg.addEventListener('pointerdown', event => { dragStart = position(event); svg.setPointerCapture(event.pointerId); });
    svg.addEventListener('pointerup', event => {
      const end = position(event);
      if (dragStart !== null && Math.abs(end - dragStart) > 12) {
        xRange = [valueAt(Math.min(dragStart, end)), valueAt(Math.max(dragStart, end))];
        render();
      }
      dragStart = null;
    });
    svg.addEventListener('pointercancel', () => { dragStart = null; });
    panel.querySelector('[data-action="reset"]').addEventListener('click', () => {
      xRange = [...extent]; yRange = tdr ? [30, 70] : [-80, 0]; render();
    });
    panel.querySelector('[data-action="fit"]')?.addEventListener('click', () => {
      const values = points.filter(p => p[0] >= xRange[0] && p[0] <= xRange[1] && p[1] !== null && Number.isFinite(p[1])).map(p => p[1]);
      if (!values.length) return;
      const [low, high] = values.reduce((range, value) => [Math.min(range[0], value), Math.max(range[1], value)], [50, 50]);
      const padding = Math.max(1, (high - low) * 0.1);
      yRange = [Math.floor((low - padding) / 10) * 10, Math.ceil((high + padding) / 10) * 10]; render();
    });
    if (typeof ResizeObserver !== 'undefined') {
      new ResizeObserver(() => {
        if (Math.abs(Math.max(360, panel.getBoundingClientRect().width) - width) > 1) render();
      }).observe(panel);
    }
    render();
  }
})();
