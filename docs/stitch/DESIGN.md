# DESIGN.md — Sunrise Academy

Design system for Google Stitch. Product: Sunrise Academy, a web platform where Indonesian school students practice digital business (building an online store, writing affiliate content) and teachers review that work with rubrics. Schools and parents see progress summaries.

All UI copy is in Bahasa Indonesia. This file is in English so the generator reads it precisely.

---

## 1. Concept: "Lembar kerja yang diperiksa"

The whole site is built around one object from the school world: **a worksheet that a teacher has reviewed.** A paper sheet, a checklist, short margin notes, a progress mark. That is what the product actually does: students submit work, teachers check it, it gets better.

- The memorable element is the **review sheet** in the hero: a paper-like card on graph paper, showing a student's store draft with a checklist and a teacher note. Everything else stays quiet.
- Real photos of school activities carry warmth. No illustrations, no 3D blobs, no stock-looking people.
- Tone: calm, competent, school-appropriate. Not a startup, not a toy.

Audience: school principals, teachers, program coordinators (decision makers, age 30–55, mid-range Android phones and school laptops). Secondary: students (SMP/SMA) and parents.

Primary job of the landing page: make a school coordinator understand the program flow in under a minute and send the "ceritakan kebutuhan sekolah" form.

---

## 2. Color

| Token | Hex | Role |
|---|---|---|
| `paper` | `#F5F8F7` | Page background. Cool, slightly green off-white. Never cream. |
| `sheet` | `#FFFFFF` | Surfaces that behave like paper: review sheet, form, dialogs. |
| `ink` | `#17302D` | Primary text and dark sections. Deep teal-ink, not black. |
| `slate-ink` | `#5A6B69` | Secondary text, captions. 5.4:1 on `paper`. |
| `board` | `#2F6F6A` | Brand / primary action. Chalkboard teal. White text on it passes AA. |
| `board-deep` | `#235450` | Hover/pressed state of `board`, links on `paper`. |
| `rule` | `#D5E0DD` | Hairlines, input borders, graph-paper lines. |
| `marigold` | `#E9A23B` | "Sunrise". Used ONLY for progress fill, the review mark, and the active role-tab underline. Never for text on light backgrounds, never as a large fill. |
| `stamp` | `#B3412E` | Errors and "perlu diperbaiki" status only. |
| `pass` | `#2E7D4F` | "Lengkap" / success status only. |

Rules:
- Light theme is the default. One dark section is allowed (the role section), using `ink` as background, `paper` text, `marigold` for the active indicator.
- No gradients as decoration. The only gradient allowed is a photo scrim (`ink` 70% → transparent) under text on images.
- Status colors always come with an icon and a word, never color alone.

---

## 3. Typography

Two families, clearly different jobs.

- **Display: Epilogue** (700, 800). Headlines only. Tight tracking (`-0.03em` at large sizes, `-0.01em` at h3). Headlines are set in one color, one weight. No accent word, no italic word, no colored word.
- **Text & UI: Public Sans** (400, 500, 600). Body, labels, buttons, tables, forms. Plain and legible, built for public-service interfaces.

Scale (modular, ratio ~1.25, base 17px on desktop, 16px on mobile):

| Token | Size / line-height | Use |
|---|---|---|
| `display` | clamp(2.75rem, 5.5vw, 5rem) / 1.02 | Hero h1 only |
| `h2` | clamp(2rem, 3.4vw, 3rem) / 1.1 | Section headings |
| `h3` | 1.375rem / 1.3 | Item titles |
| `body-lg` | 1.1875rem / 1.65 | Hero lead, section intros |
| `body` | 1.0625rem / 1.65 | Paragraphs |
| `small` | 0.875rem / 1.5 | Captions, helper text, table cells |
| `micro` | 0.8125rem / 1.4 | Status chips, timestamps |

Rules:
- Body line length 60–72 characters (`max-width: 38rem`).
- Sentence case everywhere. **No ALL-CAPS labels, no tracked-out eyebrows above headings.** If a section needs context, the h2 carries it.
- Numbers in the review sheet use tabular figures (`font-variant-numeric: tabular-nums`) in Public Sans, not a monospace font.
- Left-aligned text everywhere. Centered text only inside small buttons and chips.

---

## 4. Layout

- Max content width 1200px, side padding 20px (mobile) / 32px (tablet) / 48px (desktop).
- 12-column grid, 24px gutter. Sections separated by space (96–128px desktop, 64px mobile), not by boxes.
- Asymmetric splits (5/7, 7/5) instead of 50/50 everywhere.
- Lists of features are **rows separated by hairlines**, not grids of identical cards.
- Must work at 360px wide and at a cramped 1130×548 laptop viewport (users run 170% text zoom). Hero must not require scrolling to see the main button at 1130×548.

### Radius hierarchy (different objects, different radius)

| Object | Radius |
|---|---|
| Paper objects (review sheet, form, dialog) | 6px |
| Photos | 16px |
| Buttons, inputs | 8px |
| Status chips, tab indicator | 999px |

### Elevation

- Default: none. Separate with `rule` hairlines and space.
- Paper objects only: `0 1px 0 #D5E0DD, 0 18px 32px -20px rgba(23,48,45,.35)` — a sheet resting on a desk.
- Graph-paper pattern (24px squares, `rule` at 60% opacity) appears in exactly one place: behind the hero review sheet.

---

## 5. Components

**Button / primary** — `board` fill, white Public Sans 600, 48px tall, 8px radius, 20px horizontal padding. Label names the action ("Kirim kebutuhan sekolah", "Masuk"). No arrow characters in the label.
**Button / secondary** — transparent, 1px `ink` 20% border, `ink` text.
**Text link** — `board-deep`, underline offset 3px, underline thickens on hover.

**Review sheet (signature)** — `sheet` surface, 6px radius, paper shadow. Contents top to bottom:
1. Header row: store icon + "Toko produk lokal" + status chip "Draf".
2. Progress: "8 dari 10 bagian lengkap" + thin bar (6px), `marigold` fill.
3. Checklist rows (hairline separated): check icon in `pass` or empty circle; label; status word on the right ("Lengkap" / "Belum").
4. Teacher note: left border 3px `marigold`, text "Catatan guru: foto produk sudah konsisten. Tambahkan kebijakan pengiriman sebelum mengirim versi final." with teacher name and time in `micro`.

**Status chip** — pill, `micro` size, tinted background (status color at 12%), status color text at full strength, leading icon.

**Role switcher** — four tabs: Murid, Guru, Orang tua, Instansi. Underline indicator in `marigold`. Panel shows: one sentence of what that role sees + a small cropped screenshot-like preview of that role's dashboard.

**Feature row** — icon (20px, outline, `board`) | title (h3) | one-sentence description. Hairline below. No card background.

**Process steps** — only for real sequences. Two variants, never styled the same on one page:
- *Learning loop* (Pahami dasarnya, Kerjakan proyeknya, Perbaiki dengan arahan): drawn as a cycle, step 3 curves back to step 2 with the label "revisi".
- *Rollout timeline* (Tentukan ruang lingkup, Siapkan pembelajaran, Jalankan proyek, Baca perkembangannya): 4 points on one horizontal line, numbers 1–4 in Epilogue on the line.

**Team** — no numbering. Founders with photos in a larger first row; members without photos get a `paper` initial avatar with `ink` initials, never a dark square.

**Photo gallery** — horizontal scroll-snap strip, photos 4:5, caption below each photo (what is happening + school name). No autoplay, visible scroll buttons for keyboard users.

**Lead form** — on a `sheet` surface. Fields: Nama, Instansi / sekolah, Peran, WhatsApp, Kebutuhan program (textarea). Labels above inputs, helper text below, error text in `stamp` with icon.

**FAQ** — accordion, question in Public Sans 600, plus/minus icon, hairline separators.

**Stats** — written as sentences with numbers inline ("Dipakai oleh 12 sekolah di 3 kota, dengan 1.400 tugas praktik direview guru."), not a row of giant numbers.

**Dashboard (logged-in)** — same tokens. Left sidebar on `sheet` with `ink` text, active item = `board` 10% background + `board-deep` text. Content on `paper`. Tables with hairline rows, sticky header, no zebra stripes. The student dashboard shows "Tugas berikutnya" as a review-sheet-style object.

Icons: Lucide, outline, 1.75 stroke, 20px default.

---

## 6. Motion

Principle: motion answers a user action or tells one story once. No scroll-triggered fade-ups on every section, no hover lifts on every card, no floating/parallax decoration, no looping animation.

Tokens:

| Token | Value |
|---|---|
| `dur-press` | 80ms |
| `dur-fast` | 140ms |
| `dur-base` | 220ms |
| `dur-slow` | 420ms |
| `ease-out` | cubic-bezier(.2, .7, .2, 1) |
| `ease-in-out` | cubic-bezier(.65, 0, .35, 1) |

### The one orchestrated moment — hero "sheet gets checked" (plays once per session, ≤1.4s total)

1. 0ms — headline and lead are already visible (no text animation; text must never wait).
2. 0–420ms — hero photo reveals with `clip-path: inset(8% 0 0 0 round 16px)` → `inset(0 round 16px)`, `ease-out`.
3. 200–520ms — review sheet moves in: `translateY(16px) rotate(-1.5deg)` → `translateY(0) rotate(-1deg)`, opacity 0 → 1.
4. 520–1000ms — checklist icons tick in sequence, 80ms stagger: check icon draws with `stroke-dashoffset`, row text shifts from `slate-ink` to `ink`.
5. 1000–1400ms — progress bar fills from 0 to 80% in `marigold`; counter goes "0 dari 10" → "8 dari 10".
6. Then everything is still.

### Interaction motion

- **Button press**: `scale(.98)` for `dur-press`. Hover changes color only (`board` → `board-deep`), no lift, no shadow.
- **Role switcher**: underline slides to the new tab (transform translateX + width, `dur-base`, `ease-in-out`). Panel content crossfades with 8px horizontal shift in the direction of the selected tab (`dur-base`).
- **FAQ accordion**: `grid-template-rows: 0fr → 1fr`, `dur-base`; icon rotates plus → minus 90°.
- **Form submit**: button shows spinner inside (width stays fixed); on success the label becomes "Terkirim" with a check that draws in 300ms, and a sentence below explains what happens next ("Tim kami menghubungi lewat WhatsApp dalam 1 hari kerja."). On error, the field shakes 4px twice (`dur-fast`) and shows the error text.
- **Gallery**: native scroll-snap; arrow buttons scroll one photo with smooth scroll.
- **Dashboard**: sidebar collapse `dur-base`; toast slides up 8px + fade `dur-base`, auto-hides after 4s, pausable on hover.

### Reduced motion

`@media (prefers-reduced-motion: reduce)`: hero shows its final state instantly; all transforms removed; only opacity transitions ≤140ms remain.

---

## 7. Accessibility floor

- Text contrast AA minimum (4.5:1 body, 3:1 large text). `marigold` never carries text on light backgrounds.
- Visible focus: 3px `board` outline at 40% opacity + 2px offset, on every interactive element.
- Tap targets ≥44px. Skip link "Lewati ke konten utama".
- Every photo has a real alt describing the activity.

---

## 8. Do not generate (anti-slop list)

- Cream/beige background, terracotta accent, serif "editorial" headlines.
- Near-black background with a single neon accent.
- Grids of identical rounded cards with the same soft grey shadow.
- Gradient blobs, glassmorphism, glowing orbs, mesh gradients, noise textures.
- ALL-CAPS tracked eyebrow labels above headings.
- One highlighted/colored/italic word inside a headline.
- "→" or "↗" characters appended to buttons or links.
- Meta strings joined with middle dots ("A · B · C").
- Decorative 01 / 02 / 03 numbering on things that are not a sequence.
- Hero made of a giant number + small label + gradient.
- Fake testimonials, fake logos, "Trusted by 10,000+" style claims.
- Generic startup copy: "Revolusi", "Solusi terbaik", "Tingkatkan potensi", "All-in-one", "Seamless".
- 3D illustrations, emoji as icons, stock photos of people smiling at laptops.
- Monospace fonts for small labels.

---

## 9. Voice

- Plain Indonesian, sentence case, active voice, "Anda" for school staff, "kamu" inside the student dashboard.
- Describe what happens, do not sell: "Guru memberi catatan berbasis rubrik" instead of "Feedback powerful untuk guru".
- A button and its result use the same verb: "Kirim kebutuhan sekolah" → "Terkirim".
- Empty states tell the next action: "Belum ada tugas. Guru akan menambahkan tugas pertama di sini."
- Errors say what happened and how to fix it, no apology: "Nomor WhatsApp perlu diawali 08 atau +62."
