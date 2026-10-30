# Prompt Google Stitch — Sunrise Academy

Cara pakai:
1. Buka stitch.withgoogle.com, pilih mode **Web**, gunakan model Pro/Experimental bila tersedia.
2. Unggah atau tempel isi `DESIGN.md` lebih dulu (sebagai design system / konteks proyek).
3. Jalankan **Prompt 0** sekali, lalu satu prompt per layar (Stitch paling rapi kalau satu layar per prompt).
4. Pakai **Prompt koreksi** di bagian bawah kalau hasilnya mulai terlihat generik.
5. Lampirkan foto kegiatan asli dari `public/images/activities/` sebagai referensi gambar.
6. Lampirkan **screenshot full-page situs sekarang** bersama Prompt 0 dan Prompt 1. Screenshot = sumber konten dan urutan section, bukan acuan gaya.

Prompt ditulis dalam bahasa Inggris karena Stitch lebih patuh; semua teks UI tetap bahasa Indonesia.

---

## Prompt 0 — Konteks utama

```
You are redesigning the website of Sunrise Academy, an Indonesian web platform where SMP/SMA students practice digital business (building an online store, writing affiliate content) and teachers review their work with rubrics. Schools and parents see progress summaries. All UI text must be in Bahasa Indonesia.

Follow the attached DESIGN.md strictly: colors, Epilogue + Public Sans typography, radius hierarchy, motion rules and the "do not generate" list.

Visual concept: "a worksheet that a teacher has reviewed". The signature element is a paper-like review sheet on a graph-paper background showing a student's store draft, a checklist, an 8-of-10 progress bar in marigold and a short teacher note. Everything else stays quiet: cool off-white page (#F5F8F7), deep teal-ink text (#17302D), chalkboard teal buttons (#2F6F6A), hairline separators instead of cards, real school activity photos.

Never use: cream backgrounds, gradients as decoration, glassmorphism, identical card grids, ALL-CAPS eyebrow labels, one highlighted word in a headline, arrow characters in button text, fake testimonials or logos, 3D illustrations.

The attached full-page screenshot is the CURRENT website. Use it only as the source of content, section order and real photos/people. Do not copy its styling. Problems to fix from the screenshot:
- Small ALL-CAPS teal labels above every heading: remove them all.
- Teal-colored words inside headlines ("digital,", "Cerita yang terasa nyata."): set headlines in one color.
- Large empty gaps between sections and very small grey body text: tighten vertical rhythm, raise body text to 17px with stronger contrast.
- Team members numbered 01–05 although they are not a sequence: remove the numbers.
- Members without photos shown as dark initial squares: use a neutral paper-colored initial avatar instead.
- Two separate numbered step sections ("Pendekatan kami" 3 steps, "Alur program" 4 steps) look the same: give them different treatments as described in the screen prompts.
- The public statistics section is empty ("Belum ada data yang dipublikasikan") but takes a full section: make it a compact strip.
```

---

## Prompt 1 — Landing page (desktop)

```
Design the Sunrise Academy landing page, desktop 1440px, following DESIGN.md.

Header: logo "Sunrise Academy" left; links Program, Alur, Galeri, Tim, FAQ; secondary button "Masuk" and primary button "Ceritakan kebutuhan sekolah" right. Sticky, paper background, hairline bottom border when scrolled.

Hero (7/5 split, left-aligned):
- Left: h1 in Epilogue 800 "Belajar bisnis digital yang benar-benar dipraktikkan murid." Lead paragraph: "Sunrise Academy membantu guru menjalankan pembelajaran berbasis proyek: materi, praktik toko, review dengan rubrik, sampai laporan perkembangan murid." Primary button "Ceritakan kebutuhan sekolah", text link "Lihat alur program". Below, one quiet line: "[ganti dengan fakta nyata, mis. jumlah sekolah mitra]".
- Right: a school activity photo (16px radius) with the review sheet overlapping its lower-left corner, sitting on a small graph-paper patch, rotated -1deg. Sheet content: "Toko produk lokal" + chip "Draf"; "8 dari 10 bagian lengkap" with marigold bar; checklist rows Profil toko (Lengkap), Foto produk (Lengkap), Deskripsi produk (Lengkap), Kebijakan pengiriman (Belum), Bukti transaksi (Belum); teacher note with 3px marigold left border.
The main button must be visible without scrolling on a 1130x548 viewport.

Keep the section order of the attached screenshot:

1. Gallery "Belajar bersama, cerita yang terasa nyata.": horizontal scroll-snap strip of 4:5 real activity photos, caption below each photo (activity + school), arrow buttons beside the heading, text link "Lihat semua foto".

2. "Keterampilan tumbuh dari pekerjaan yang selesai, bukan materi yang lewat." Learning loop of 3 steps drawn as a CYCLE (step 3 loops back to step 2 with a curved line labeled "revisi"): Pahami dasarnya, Kerjakan proyeknya, Perbaiki dengan arahan, one sentence each.

3. "Cukup lengkap untuk program sekolah. Tetap sederhana untuk dipakai setiap hari." 5/7 split, heading left, right side 6 hairline rows (icon | title | one sentence): Ruang belajar, Pekerjaan murid, Review guru, Pandangan instansi, Laporan yang siap dipakai, Akses sesuai peran. No numbers.

4. "Teknologi mengikuti cara sekolah bekerja." Program rollout as a horizontal TIMELINE with 4 points on one line (different from section 2): Tentukan ruang lingkup, Siapkan pembelajaran, Jalankan proyek, Baca perkembangannya.

5. Ink background (#17302D): "Setiap orang melihat bagian yang memang menjadi tanggung jawabnya." Role switcher tabs Murid / Guru / Orang tua / Instansi with marigold underline; panel shows the role's sentence from the screenshot and a cropped dashboard preview for that role.

6. "Membangun toko digital, langkah demi langkah." Left: text + the 5 store-building steps as a short list. Right: the larger version of the review sheet (Toko produk lokal, 8 dari 10, checklist, teacher note). This is the full-size reuse of the hero sheet.

7. "Aman bukan berarti rumit bagi pengguna." Lock icon, short text, 4–6 protections as a two-column checklist with hairlines.

8. CTA band on light teal (#E4F1EE): "Ceritakan program yang ingin sekolah Anda jalankan." primary button "Hubungi tim kami" and text link "Sudah punya akun? Masuk". Compact, not a full-height section.

9. "Orang-orang yang menggerakkan Sunrise Academy." 5 people from the screenshot (Surya Indra Permana, Hendi Suryo Leksono, Nabila Putri Restaningsih, Lutfhan Bayu Lukmana, Ernando Januar Pratama) with name, title and role. Two founders with photos larger in the first row; three others smaller in a second row with neutral initial avatars. No numbers, no social icon row.

10. "Angka yang boleh dilihat publik, tanpa mengorbankan privasi." Compact strip: one sentence of explanation + empty state "Belum ada data yang dipublikasikan." When data exists, numbers appear inline in a sentence, never as giant number tiles.

11. Lead form "Tidak perlu menyiapkan proposal panjang." on a white sheet (6px radius, paper shadow): Nama, Instansi / sekolah, Peran (select), WhatsApp, Kebutuhan program. Button "Kirim kebutuhan sekolah". Beside the form: what happens after sending.

12. FAQ accordion, hairline separators. Footer: ink background, 3 columns of links, address, small privacy links.
```

## Prompt 2 — Landing page (mobile)

```
Create the mobile version (390px) of the same landing page. Keep the hierarchy: h1, lead, primary button first; the photo and review sheet come after the buttons, the sheet overlapping the photo bottom edge, full width minus 20px. Role switcher becomes horizontally scrollable tabs. Feature rows stack icon + title on one line, description below. Header collapses into logo + "Masuk" + menu button; the menu opens as a full-height sheet from the right with large 48px links.
```

## Prompt 3 — Halaman masuk

```
Design the login page following DESIGN.md. Split layout: left 5 columns a school photo with a short caption at the bottom on an ink scrim ("Kelas praktik toko digital, SMA mitra Sunrise Academy"); right 7 columns a white sheet with h1 "Masuk ke Sunrise Academy", fields Email atau NIS, Kata sandi (show/hide icon button), link "Lupa kata sandi?", primary button "Masuk", and a line for first-time users: "Belum punya akun? Aktivasi akun dari undangan sekolah." Show the error state below the password: "Email atau kata sandi tidak cocok. Periksa lagi atau atur ulang kata sandi." No social login buttons.
```

## Prompt 4 — Dashboard murid

```
Design the student dashboard (desktop) following DESIGN.md, using "kamu" voice. Left sidebar on white: logo, nav Beranda, Materi, Tugas, Toko saya, Affiliate, Sertifikat, Bantuan; active item has teal 10% background. Top bar: school name, notification bell, avatar.

Main area on paper background:
- Greeting line "Halo, Rina. Dua tugas menunggu minggu ini." (no big banner).
- "Tugas berikutnya" shown as the review-sheet object: task title "Toko produk lokal", deadline "Jumat, 7 Nov", progress 8 dari 10, the latest teacher note, button "Lanjutkan tugas".
- Table "Semua tugas" with hairline rows: Tugas, Modul, Tenggat, Status chip (Draf / Dikirim / Perlu revisi / Selesai), Nilai. Sticky header, no zebra stripes.
- Right column: "Progres modul" as a simple list with thin bars, and "Catatan terbaru dari guru" as 3 short notes with time.
Empty state example for the affiliate tab: "Belum ada konten affiliate. Mulai dari tugas Modul 3 untuk membuat yang pertama."
```

## Prompt 5 — Dashboard guru (antrean review)

```
Design the teacher review queue following DESIGN.md. Filters on top as a single row: Kelas (select), Tugas (select), Status (chips), search field. Table: Murid, Kelas, Tugas, Dikirim (relative time), Versi, Status, action button "Review". Selecting a row opens a right-side panel (width 480px) with the student's submission summary, rubric criteria with 1–4 score buttons, a comment textarea, and two buttons "Minta revisi" (secondary) and "Simpan nilai" (primary). Show the toast after saving: "Nilai tersimpan. Murid sudah bisa melihatnya."
```

## Prompt 6 — Prototipe animasi (export HTML)

```
Export the landing page hero as HTML/CSS and implement the motion exactly as DESIGN.md section 6:
- Hero "sheet gets checked" sequence, max 1.4s, plays once per session (sessionStorage flag): photo clip-path reveal, sheet slides in with -1deg rotation, checklist icons draw with stroke-dashoffset at 80ms stagger, marigold bar fills to 80% while the counter counts 0 → 8.
- Headline and lead are visible immediately, never animated.
- Role switcher: sliding marigold underline + 8px directional crossfade, 220ms, cubic-bezier(.65,0,.35,1).
- FAQ: grid-template-rows 0fr → 1fr, 220ms.
- Buttons: scale(.98) on press, color change on hover only.
- Respect prefers-reduced-motion: show final state, opacity-only transitions.
Do not add scroll-triggered fade-up animations to sections.
```

---

## Prompt koreksi (pakai bila hasil terasa generik)

```
This looks templated. Fix it:
- Remove every ALL-CAPS label above headings; let the h2 carry the meaning.
- Replace card grids with hairline-separated rows.
- Remove all decorative gradients and glows; background stays #F5F8F7.
- Headlines in one color and one weight, no highlighted word.
- Remove arrow characters from buttons and links.
- Make the review sheet the single strongest element; tone everything else down.
```

```
Typography is too generic. Use Epilogue 800 only for h1/h2 with -0.03em tracking, Public Sans for everything else. Body text max width 38rem, line-height 1.65. Left-align all text.
```

```
Marigold #E9A23B is overused. Keep it only on the progress bar, the teacher-note left border and the active role-tab underline. Everything else uses teal #2F6F6A or ink #17302D.
```
