// ── SHARED UTILITIES ──

// Load repository.txt exclusively via save.php so there is one canonical path.
// save.php reads from home/data/repository.txt — always the same file that gets written.
async function loadData() {
  try {
    const res = await fetch('save.php?action=load&t=' + Date.now());
    if (res.ok) {
      const ct = res.headers.get('content-type') || '';
      if (ct.includes('json')) {
        const json = await res.json();
        if (json.ok && json.content && json.content.trim()) return parseDB(json.content);
        if (json.ok && json.content === '') return []; // empty but valid
      }
    }
  } catch (_) { /* save.php unreachable */ }

  // Last-resort: direct fetch of the canonical path only — never root
  // (handles static hosting without PHP)
  try {
    const res = await fetch('home/data/repository.txt?t=' + Date.now());
    if (res.ok) {
      const text = await res.text();
      if (text.trim() && !text.trim().startsWith('<')) return parseDB(text);
    }
  } catch (_) {}

  console.error('[KIX Repository] Could not load repository.txt via save.php or home/data/repository.txt.');
  document.dispatchEvent(new CustomEvent('repoLoadError'));
  return [];
}

function parseDB(text) {
  const items = [];
  const blocks = text.split(/\n\s*\n/).map(b => b.trim()).filter(Boolean);
  blocks.forEach(block => {
    const obj = {};
    block.split('\n').forEach(rawLine => {
      const line = rawLine.replace(/\r/g, '');  // strip Windows CR
      const idx = line.indexOf(':');
      if (idx === -1) return;
      const key = line.slice(0, idx).trim().toLowerCase();
      const val = line.slice(idx + 1).trim();
      if (key === 'photo') {
        obj.photos = obj.photos || [];
        obj.photos.push(val);
      } else {
        obj[key] = val;
      }
    });
    if (obj.title && obj.type) items.push(obj);
  });
  return items;
}

function getYoutubeId(url) {
  if (!url) return null;
  const m = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/))([A-Za-z0-9_-]{11})/);
  return m ? m[1] : null;
}

function formatDate(d) {
  if (!d) return '';
  try { return new Date(d).toLocaleDateString('en-GB', {year:'numeric', month:'short', day:'numeric'}); }
  catch { return d; }
}

function formatYear(d) {
  if (!d) return '';
  try { return new Date(d).getFullYear(); } catch { return ''; }
}

