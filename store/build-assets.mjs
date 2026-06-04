#!/usr/bin/env node
/**
 * Renders the Shopware store assets for asbs/shopware-streamdeck from inline SVG
 * templates via sharp. Brand palette is taken from AS Business Solutions'
 * logo_rainbow.svg (purple -> red -> gold spectrum).
 *
 * Output (committed):
 *   ../src/Resources/config/plugin.png   admin extension-list icon
 *   images/icon.png                      store icon, 112x112, full-bleed, no alpha
 *   images/{de,en}/01-hero.png ...       preview images, 1920x1080, 16:9, no alpha
 */
import sharp from 'sharp';
import { mkdir, writeFile, stat } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const DIR = dirname(fileURLToPath(import.meta.url));
const IMAGES = join(DIR, 'images');
const PLUGIN_ICON = join(DIR, '..', 'src', 'Resources', 'config', 'plugin.png');

const BG = '#0e0e12';
const FG = '#f5f5f7';
const MUTED = '#b6b6c2';
const FONT = 'Liberation Sans, DejaVu Sans, sans-serif';
const W = 1920;
const H = 1080;

const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

// Shared <defs>: brand gradient + a soft corner glow.
function defs() {
  return `<defs>
    <linearGradient id="brand" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#570089"/>
      <stop offset="0.45" stop-color="#ff0043"/>
      <stop offset="1" stop-color="#ffd104"/>
    </linearGradient>
    <linearGradient id="brandH" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#8a17c7"/>
      <stop offset="0.5" stop-color="#ff0049"/>
      <stop offset="1" stop-color="#ffd104"/>
    </linearGradient>
    <radialGradient id="glow" cx="0.85" cy="0.12" r="0.6">
      <stop offset="0" stop-color="#ff0049" stop-opacity="0.30"/>
      <stop offset="0.5" stop-color="#8a17c7" stop-opacity="0.12"/>
      <stop offset="1" stop-color="#0e0e12" stop-opacity="0"/>
    </radialGradient>
  </defs>`;
}

// Brand lockup, top-left.
function lockup(label) {
  return `<g transform="translate(130,110)">
    <rect x="0" y="-2" width="74" height="10" rx="5" fill="url(#brandH)"/>
    <text x="92" y="9" font-family="${FONT}" font-size="29" font-weight="700"
      letter-spacing="5" fill="${FG}">AS BUSINESS SOLUTIONS</text>
    <text x="92" y="44" font-family="${FONT}" font-size="22" letter-spacing="3"
      fill="${MUTED}">${esc(label)}</text>
  </g>`;
}

function footer(text) {
  return `<text x="130" y="998" font-family="${FONT}" font-size="26"
    letter-spacing="1" fill="${MUTED}">${esc(text)}</text>`;
}

// Decorative Stream-Deck key grid (3x2 rounded squares), gradient-outlined.
function keyGrid(x, y, cell = 150, gap = 26, fill = false) {
  let g = `<g transform="translate(${x},${y})">`;
  for (let r = 0; r < 2; r++) {
    for (let c = 0; c < 3; c++) {
      const px = c * (cell + gap);
      const py = r * (cell + gap);
      if (fill) {
        const op = 0.10 + 0.16 * ((r * 3 + c) / 5);
        g += `<rect x="${px}" y="${py}" width="${cell}" height="${cell}" rx="22"
          fill="url(#brand)" fill-opacity="${op.toFixed(2)}"
          stroke="url(#brand)" stroke-opacity="0.5" stroke-width="2"/>`;
      } else {
        g += `<rect x="${px}" y="${py}" width="${cell}" height="${cell}" rx="22"
          fill="none" stroke="url(#brand)" stroke-opacity="0.35" stroke-width="3"/>`;
      }
    }
  }
  return g + '</g>';
}

function headlineBlock(lines, y, size = 104) {
  return lines
    .map(
      (ln, i) =>
        `<text x="130" y="${y + i * (size + 14)}" font-family="${FONT}" font-size="${size}"
        font-weight="700" fill="${FG}">${esc(ln)}</text>`,
    )
    .join('');
}

// A gradient rounded-square bullet (echoes a Stream-Deck key) + text line.
function bulletLine(x, y, text, n = null) {
  const marker = n
    ? `<circle cx="${x + 21}" cy="${y - 13}" r="26" fill="none" stroke="url(#brand)" stroke-width="4"/>
       <text x="${x + 21}" y="${y - 3}" text-anchor="middle" font-family="${FONT}" font-size="30"
         font-weight="700" fill="${FG}">${n}</text>`
    : `<rect x="${x}" y="${y - 30}" width="26" height="26" rx="7" fill="url(#brand)"/>`;
  return `${marker}
    <text x="${x + (n ? 64 : 46)}" y="${y}" font-family="${FONT}" font-size="40"
      fill="${FG}">${esc(text)}</text>`;
}

function frame(inner) {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">
    ${defs()}
    <rect width="${W}" height="${H}" fill="${BG}"/>
    <rect width="${W}" height="${H}" fill="url(#glow)"/>
    ${inner}
  </svg>`;
}

function renderPanel(p) {
  const parts = [lockup(p.label), footer(p.footer)];

  if (p.type === 'hero') {
    parts.push(headlineBlock(p.headline, 470, 112));
    parts.push(
      `<text x="130" y="${470 + p.headline.length * 126 + 18}" font-family="${FONT}"
        font-size="44" fill="${MUTED}">${esc(p.sub)}</text>`,
    );
    parts.push(keyGrid(1230, 250, 150, 26, true));
  } else if (p.type === 'features') {
    parts.push(headlineBlock(p.headline, 360, 96));
    parts.push(
      `<text x="130" y="${360 + p.headline.length * 110 + 6}" font-family="${FONT}"
        font-size="38" fill="${MUTED}">${esc(p.sub)}</text>`,
    );
    const top = 360 + p.headline.length * 110 + 96;
    p.items.forEach((it, i) => {
      const col = i % 2;
      const row = Math.floor(i / 2);
      parts.push(bulletLine(130 + col * 880, top + row * 92, it));
    });
  } else if (p.type === 'free') {
    const hl = 320;
    parts.push(headlineBlock(p.headline, hl, 96));
    const subY = hl + p.headline.length * 110 + 6;
    parts.push(
      `<text x="130" y="${subY}" font-family="${FONT}"
        font-size="38" fill="${MUTED}">${esc(p.sub[0])}</text>`,
    );
    if (p.sub[1]) {
      parts.push(
        `<text x="130" y="${subY + 48}" font-family="${FONT}"
          font-size="38" fill="${MUTED}">${esc(p.sub[1])}</text>`,
      );
    }
    const top = subY + 120;
    p.items.forEach((it, i) => {
      parts.push(bulletLine(130, top + i * 80, it));
    });
  } else if (p.type === 'funnel') {
    parts.push(headlineBlock(p.headline, 380, 96));
    parts.push(
      `<text x="130" y="${380 + p.headline.length * 110 + 10}" font-family="${FONT}"
        font-size="40" fill="${MUTED}">${esc(p.sub[0])}</text>`,
    );
    if (p.sub[1]) {
      parts.push(
        `<text x="130" y="${380 + p.headline.length * 110 + 62}" font-family="${FONT}"
          font-size="40" fill="${MUTED}">${esc(p.sub[1])}</text>`,
      );
    }
    parts.push(keyGrid(1300, 250, 140, 24, true));
  } else if (p.type === 'setup') {
    parts.push(headlineBlock(p.headline, 380, 104));
    const top = 380 + p.headline.length * 118 + 70;
    p.items.forEach((it, i) => {
      parts.push(bulletLine(130, top + i * 110, it, i + 1));
    });
  }
  return frame(parts.join('\n'));
}

// Store icon 112x112: full-bleed, no text. A single rounded "key" holding an
// upward revenue line, in the brand gradient on the dark device background.
function iconSvg() {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">
    ${defs()}
    <rect width="512" height="512" fill="${BG}"/>
    <rect width="512" height="512" fill="url(#glow)"/>
    <rect x="96" y="96" width="320" height="320" rx="64" fill="url(#brand)" fill-opacity="0.16"
      stroke="url(#brand)" stroke-width="10"/>
    <polyline points="150,330 226,256 286,300 372,182" fill="none" stroke="url(#brandH)"
      stroke-width="22" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="372" cy="182" r="20" fill="#ffd104"/>
  </svg>`;
}

const PANELS = {
  de: [
    { key: '01-hero', type: 'hero', label: 'Stream Deck Companion für Shopware 6',
      headline: ['Dein Shopware-Shop.', 'Live auf dem Stream Deck.'],
      sub: 'Echtzeit-Kennzahlen auf Knopfdruck — direkt auf deiner Elgato-Hardware.',
      footer: 'Shopware 6  ·  Kostenlos  ·  Open Source (MIT)  ·  as-bs.com' },
    { key: '02-features', type: 'features', label: 'Diese Daten landen auf deinen Tasten',
      headline: ['Alles Wichtige', 'auf einen Blick'],
      sub: 'Datenpunkte, die das Companion an dein Stream Deck liefert:',
      items: ['Tagesumsatz & Vortagesvergleich', 'Letzte Bestellung in Echtzeit',
        'Live-Shop-Status & Systemzustand', 'Umsatzverlauf der letzten 60 Tage',
        'Durchschnittlicher Bestellwert', 'Bestellungen & Top-Produkt des Tages'],
      footer: 'Shopware 6  ·  Kostenlos  ·  Open Source (MIT)  ·  as-bs.com' },
    { key: '03-free', type: 'free', label: 'Kostenlos und quelloffen',
      headline: ['Kostenlos.', 'Open Source. Für immer.'],
      sub: ['MIT-lizenziert, kein externer Lizenzserver.',
        'Die Endpoints schützt ein shop-gebundener API-Key — verwaltet im Admin.'],
      items: ['100 % kostenlos im Shopware Store', 'Quelloffen unter MIT-Lizenz',
        'Shop-gebundener API-Key — kein Tracking', 'Bereit für Shopware 6.6 & 6.7'],
      footer: 'Shopware 6  ·  Kostenlos  ·  Open Source (MIT)  ·  as-bs.com' },
    { key: '04-streamdeck', type: 'funnel', label: 'Das volle Erlebnis',
      headline: ['Volle Power mit dem', 'Stream Deck Plugin'],
      sub: ['Das Companion ist gratis. Hol dir das Stream Deck Plugin im',
        'Elgato Marketplace und steuere deinen Shop per Tastendruck.'],
      footer: 'Stream Deck Plugin: Elgato Marketplace  ·  Companion: kostenlos' },
    { key: '05-setup', type: 'setup', label: 'In wenigen Schritten startklar',
      headline: ['In 2 Minuten', 'startklar'],
      items: ['Plugin installieren & aktivieren',
        'API-Key in der Plugin-Konfiguration generieren',
        'Key im Stream Deck Plugin eintragen — fertig'],
      footer: 'Shopware 6  ·  Kostenlos  ·  Open Source (MIT)  ·  as-bs.com' },
  ],
  en: [
    { key: '01-hero', type: 'hero', label: 'Stream Deck companion for Shopware 6',
      headline: ['Your Shopware store.', 'Live on the Stream Deck.'],
      sub: 'Real-time KPIs at the press of a key — right on your Elgato hardware.',
      footer: 'Shopware 6  ·  Free  ·  Open Source (MIT)  ·  as-bs.com' },
    { key: '02-features', type: 'features', label: 'The data that lands on your keys',
      headline: ['Everything that matters,', 'at a glance'],
      sub: 'Data points the companion delivers to your Stream Deck:',
      items: ['Daily revenue & day-over-day', 'Latest order in real time',
        'Live shop & system status', 'Revenue trend, last 60 days',
        'Average order value', 'Orders & top product of the day'],
      footer: 'Shopware 6  ·  Free  ·  Open Source (MIT)  ·  as-bs.com' },
    { key: '03-free', type: 'free', label: 'Free and open source',
      headline: ['Free.', 'Open source. Forever.'],
      sub: ['MIT-licensed, with no external license server.',
        'Endpoints are protected by a shop-bound API key — managed in the admin.'],
      items: ['100% free in the Shopware Store', 'Open source under the MIT license',
        'Shop-bound API key — no tracking', 'Ready for Shopware 6.6 & 6.7'],
      footer: 'Shopware 6  ·  Free  ·  Open Source (MIT)  ·  as-bs.com' },
    { key: '04-streamdeck', type: 'funnel', label: 'The full experience',
      headline: ['Full power with the', 'Stream Deck plugin'],
      sub: ['The companion is free. Get the Stream Deck plugin on the',
        'Elgato Marketplace and control your shop key by key.'],
      footer: 'Stream Deck plugin: Elgato Marketplace  ·  Companion: free' },
    { key: '05-setup', type: 'setup', label: 'Up and running in no time',
      headline: ['Ready in', 'two minutes'],
      items: ['Install & activate the plugin',
        'Generate an API key in the plugin config',
        'Paste the key into the Stream Deck plugin — done'],
      footer: 'Shopware 6  ·  Free  ·  Open Source (MIT)  ·  as-bs.com' },
  ],
};

async function renderPng(svg, outFile, { width, height, density = 144, flatten = true } = {}) {
  await mkdir(dirname(outFile), { recursive: true });
  let img = sharp(Buffer.from(svg), { density });
  if (width && height) img = img.resize(width, height, { fit: 'fill' });
  if (flatten) img = img.flatten({ background: BG });
  await img.png({ compressionLevel: 9 }).toFile(outFile);
  const { size } = await stat(outFile);
  const meta = await sharp(outFile).metadata();
  return { outFile, kb: Math.round(size / 1024), w: meta.width, h: meta.height, alpha: meta.hasAlpha };
}

async function main() {
  const results = [];

  // Store icon 256x256 (shopware-cli store.icon spec), supersampled, no alpha.
  results.push(
    await renderPng(iconSvg(), join(IMAGES, 'icon.png'), { width: 256, height: 256, density: 96 }),
  );

  // Admin extension-list icon (transparent ok); reuse the icon mark at 192px.
  results.push(
    await renderPng(iconSvg(), PLUGIN_ICON, { width: 192, height: 192, density: 96, flatten: false }),
  );

  // Preview images, both languages.
  for (const lang of ['de', 'en']) {
    for (const p of PANELS[lang]) {
      results.push(
        await renderPng(renderPanel(p), join(IMAGES, lang, `${p.key}.png`), {
          width: W, height: H, density: 96,
        }),
      );
    }
  }

  let ok = true;
  for (const r of results) {
    const rel = r.outFile.replace(join(DIR, '..') + '/', '');
    const tooBig = r.kb > 1024;
    if (tooBig) ok = false;
    console.log(
      `${tooBig ? 'XX' : 'ok'}  ${rel.padEnd(46)} ${String(r.w) + 'x' + r.h}  ${r.kb}KB  alpha=${r.alpha}`,
    );
  }
  console.log(ok ? '\nAll assets within store limits.' : '\nWARNING: some assets exceed 1MB.');
  if (!ok) process.exit(1);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
