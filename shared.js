// ── SHARED UTILITIES ──

// Tries repository.txt at root first, then data/repository.txt
// No demo data fallback — shows a clear error if the file is missing.
async function loadData() {
  const candidates = ['repository.txt', 'data/repository.txt'];
  for (const path of candidates) {
    try {
      const res = await fetch(path + '?t=' + Date.now());
      if (res.ok) {
        const text = await res.text();
        if (text.trim()) return parseDB(text);
      }
    } catch (_) { /* try next */ }
  }
  // File not found — surface a clear message rather than showing fake data
  console.error('[KIX Repository] Could not load repository.txt from root or data/ folder.'); 
  document.dispatchEvent(new CustomEvent('repoLoadError'));
  return [];
}

function parseDB(text) {
  const items = [];
  const blocks = text.split(/\n\s*\n/).map(b => b.trim()).filter(Boolean);
  blocks.forEach(block => {
    const obj = {};
    block.split('\n').forEach(line => {
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