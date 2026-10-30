<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { ArrowLeft, ArrowRight, X } from "@lucide/vue";
import ActivityPhotoCard from "./ActivityPhotoCard.vue";
import { vReveal } from "./reveal";

const photos = [
  { file: "turen-foto-bersama", title: "Bersama membangun masa depan.", alt: "Tujuh orang berfoto bersama di depan layar Kelas Inspirasi Digital di SMK Negeri 1 Turen" },
  { file: "turen-interaksi-kelas", title: "Diskusi yang menghidupkan kelas", alt: "Pemateri berinteraksi langsung dengan para murid di aula SMK Negeri 1 Turen" },
  { file: "turen-peserta-menyimak", title: "Menyimak, mencatat, belajar", alt: "Para murid menyimak sesi dengan buku catatan di meja masing-masing" },
  { file: "turen-kebersamaan-peserta", title: "Satu kelas, banyak harapan", alt: "Para murid, guru, dan pemateri berfoto bersama setelah kegiatan di aula" },
  { file: "turen-kelas-inspirasi", title: "Dari hobi digital jadi keterampilan", alt: "Suasana kelas dengan pemateri dan layar bertuliskan Dari Hobi Digital Jadi Skill Masa Depan" },
  { file: "turen-berbagi-pesan", title: "Pesan untuk langkah berikutnya", alt: "Seorang perempuan berseragam cokelat menyampaikan pesan dengan mikrofon di hadapan para murid" },
  { file: "turen-diskusi-sekolah", title: "Percakapan yang membuka peluang", alt: "Dua perempuan berseragam cokelat duduk di meja depan, salah satunya berbicara menggunakan mikrofon" },
  { file: "turen-pemateri-digital", title: "Berbagi pengalaman digital", alt: "Pemateri berkemeja biru menjelaskan materi sambil memegang mikrofon dan mengangkat tangan" },
  { file: "turen-sambutan-sekolah", title: "Sambutan penuh semangat", alt: "Seorang perempuan berhijab kuning memberikan sambutan di depan para murid" },
  { file: "kebersamaan-sekolah", title: "Satu ruang, banyak semangat.", alt: "Foto bersama di aula sekolah dengan para murid sebagai latar" },
  { file: "sesi-digital-marketing", title: "Mengenal dunia digital", alt: "Pemateri menjelaskan digital marketing di depan layar presentasi" },
  { file: "peserta-kegiatan", title: "Ruang untuk belajar bersama", alt: "Para murid menyimak kegiatan dari area tempat duduk di aula" },
  { file: "suasana-aula", title: "Cerita dari aula sekolah", alt: "Suasana aula yang dipenuhi murid saat sesi berlangsung" },
  { file: "sesi-berbagi", title: "Berbagi pengetahuan", alt: "Pemateri berbicara di panggung di hadapan para peserta" },
  { file: "belajar-bersama", title: "Belajar dalam kebersamaan", alt: "Para peserta mengikuti sesi bersama di aula sekolah" },
  { file: "momen-kegiatan", title: "Pertemuan yang berarti", alt: "Enam orang berfoto bersama di depan para murid peserta kegiatan" },
  { file: "senyum-bersama", title: "Ditutup dengan senyum", alt: "Momen ceria saat foto bersama dengan gestur tangan dan senyuman" },
  { file: "foto-bersama", title: "Kenangan dari sekolah", alt: "Foto bersama dengan suasana aula dan seluruh peserta di belakang" },
];

const track = ref<HTMLElement | null>(null);
const atStart = ref(true);
const atEnd = ref(false);
const selected = ref<number | null>(null);
const dialogRef = ref<HTMLDialogElement | null>(null);
const isOpen = computed(() => selected.value !== null);
let previousOverflow = "";

watch(isOpen, async (open) => {
  const dialog = dialogRef.value;
  if (open) {
    await nextTick();
    previousOverflow = document.body.style.overflow;
    dialog?.showModal();
    document.body.style.overflow = "hidden";
  } else {
    if (dialog?.open) dialog.close();
    document.body.style.overflow = previousOverflow;
  }
});

function movePhoto(direction: number) {
  if (selected.value !== null) selected.value = (selected.value + direction + photos.length) % photos.length;
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === "ArrowRight" || event.key === "ArrowLeft") {
    event.preventDefault();
    movePhoto(event.key === "ArrowRight" ? 1 : -1);
  }
}

// Slider like the template's course carousel: native scroll-snap, the arrows move one card at a time.
function updateEdges() {
  const el = track.value;
  if (!el) return;
  atStart.value = el.scrollLeft <= 4;
  atEnd.value = el.scrollLeft + el.clientWidth >= el.scrollWidth - 4;
}

function slide(direction: number) {
  const el = track.value;
  const card = el?.firstElementChild as HTMLElement | null;
  if (!el || !card) return;
  el.scrollBy({ left: direction * (card.offsetWidth + 24), behavior: "smooth" });
}

onMounted(updateEdges);

function onBackdropClick(event: MouseEvent) {
  if (event.target === event.currentTarget) selected.value = null;
}

</script>


<template>
  <section id="galeri" aria-labelledby="gallery-heading" class="courses-area sub-bg section-pad">
    <div class="courses__line"><img src="/images/landing/courses-line.png" alt="" /></div>
    <div class="courses__shape"><img class="slide-up-down" src="/images/landing/courses-shape.png" alt="" /></div>
    <div class="container">
      <div v-reveal class="section-header mx-auto mb-[60px] max-w-[760px] text-center">
        <h5>Galeri kegiatan</h5>
        <h2 id="gallery-heading">Belajar <span>bersama. <img src="/images/landing/header-shape.png" alt="" /></span> Cerita yang terasa nyata.</h2>
        <p>Cuplikan pertemuan, sesi berbagi, dan kebersamaan di sekolah. Kenali suasana kegiatan kami lebih dekat.</p>
      </div>

      <div class="courses__wrp">
        <div ref="track" class="gallery-track" tabindex="0" aria-label="Foto kegiatan, geser untuk melihat lainnya" @scroll.passive="updateEdges">
          <ActivityPhotoCard v-for="(photo, index) in photos" :key="photo.file" :photo="photo" :index="index" :featured="index === 0" @open="selected = $event" />
        </div>
        <button type="button" aria-label="Foto sebelumnya" :disabled="atStart" class="arry-prev courses__arry-prev" @click="slide(-1)"><ArrowLeft aria-hidden="true" :size="22" :stroke-width="1.5" /></button>
        <button type="button" aria-label="Foto berikutnya" :disabled="atEnd" class="arry-next courses__arry-next active" @click="slide(1)"><ArrowRight aria-hidden="true" :size="22" :stroke-width="1.5" /></button>
      </div>
      <p class="mt-8 text-center text-sm">{{ photos.length }} foto kegiatan · Klik foto untuk melihat lebih dekat</p>
    </div>

    <dialog
      ref="dialogRef"
      aria-labelledby="activity-photo-title"
      @cancel="selected = null"
      @close="selected = null"
      @click="onBackdropClick"
      @keydown="onKeydown"
      class="fixed inset-0 m-auto max-h-[95dvh] w-[calc(100%-2rem)] max-w-6xl overflow-y-auto rounded-2xl border border-white/20 bg-[#181818] p-0 text-white shadow-2xl backdrop:bg-black/85 backdrop:backdrop-blur-sm"
    >
      <template v-if="selected !== null">
        <div class="flex items-center justify-between gap-4 px-4 py-3 sm:px-6">
          <p class="text-xs font-bold uppercase tracking-widest text-[#2eb97e]">Galeri kegiatan</p>
          <button type="button" aria-label="Tutup foto" @click="selected = null" class="grid size-11 place-items-center rounded-full hover:bg-white/10"><X aria-hidden="true" :size="22" /></button>
        </div>
        <div class="relative aspect-video max-h-[62dvh] bg-black/20">
          <img :src="`/images/activities/${photos[selected].file}.webp`" :alt="photos[selected].alt" sizes="(max-width: 1200px) 95vw, 1152px" loading="lazy" class="absolute inset-0 size-full object-contain" />
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4 p-4 sm:p-6">
          <div aria-live="polite" aria-atomic="true">
            <p class="text-xs text-[#2eb97e]">{{ selected + 1 }} / {{ photos.length }}</p>
            <h3 id="activity-photo-title" class="mt-1 text-base font-bold !text-white sm:text-xl">{{ photos[selected].title }}</h3>
          </div>
          <div class="flex gap-2">
            <button type="button" aria-label="Foto sebelumnya" @click="movePhoto(-1)" class="grid size-12 place-items-center rounded-full border border-white/25 hover:border-[#2eb97e] hover:bg-[#2eb97e]"><ArrowLeft aria-hidden="true" :size="20" /></button>
            <button type="button" aria-label="Foto berikutnya" @click="movePhoto(1)" class="grid size-12 place-items-center rounded-full border border-white/25 hover:border-[#2eb97e] hover:bg-[#2eb97e]"><ArrowRight aria-hidden="true" :size="20" /></button>
          </div>
        </div>
      </template>
    </dialog>
  </section>
</template>
