<script setup lang="ts">
import { onMounted, ref, watch } from "vue";
import { AlertCircle, BookOpen, CheckCircle, ChevronLeft, ChevronRight, Clock, Download, Presentation, X } from "@lucide/vue";
import TeacherLearningManager from "@/components/dashboard/TeacherLearningManager.vue";
import LessonComponentProgress from "@/components/dashboard/tugas/LessonComponentProgress.vue";
import QuizDialog from "@/components/dashboard/tugas/QuizDialog.vue";
import VideoPlayerDialog from "@/components/dashboard/tugas/VideoPlayerDialog.vue";
import { csrfFetch } from "@/lib/csrf";
import DashLink from "@/components/dashboard/DashLink.vue";

/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
function materialKind(material: { type: string; originalFileName?: string | null }) {
  return material.originalFileName?.toLowerCase().endsWith(".pptx") ? "PPTX" : material.type;
}

type LearningMaterial = {
  id: string;
  lessonId?: string | null;
  title: string;
  type: string;
  content?: string | null;
  fileUrl?: string | null;
  originalFileName?: string | null;
  course?: { code?: string };
  video?: any;
};

type MaterialViewer = {
  material: LearningMaterial;
  slide: number;
  totalSlides: number;
  furthestSlide: number;
};

function pptxPreviewPath(material: LearningMaterial, slide: number) {
  return `/api/materials/${encodeURIComponent(material.id)}?slide=${slide}`;
}

const assignments = ref<any[]>([]);
const materials = ref<LearningMaterial[]>([]);
const videoMaterial = ref<LearningMaterial | null>(null);
const quizLesson = ref<{ id: string; title: string } | null>(null);
const learningTree = ref<any[]>([]);
const profile = ref<any>(null);
const loading = ref(true);

// Modal states
const selectedAssignment = ref<any>(null);
const videoUrl = ref("");
const platform = ref("TIKTOK");
const productName = ref("");
const productUrl = ref("");
const notes = ref("");

const submitting = ref(false);
const error = ref("");
const success = ref(false);
const viewer = ref<MaterialViewer | null>(null);
const progressSaving = ref(false);
const progressError = ref("");
const materialOpeningId = ref("");

async function fetchAssignments() {
  try {
    const [assignmentResponse, materialResponse, treeResponse] = await Promise.all([
      fetch("/api/backend/assignments"),
      fetch("/api/backend/learning/materials"),
      fetch("/api/backend/learning/tree"),
    ]);
    if (!assignmentResponse.ok || !materialResponse.ok || !treeResponse.ok) throw new Error("Materi dan tugas belum dapat dimuat");
    const [assignmentData, materialData, treeData] = await Promise.all([assignmentResponse.json(), materialResponse.json(), treeResponse.json()]);
    assignments.value = assignmentData.data || [];
    materials.value = Array.isArray(materialData) ? materialData : [];
    learningTree.value = Array.isArray(treeData) ? treeData : [];
  } catch (err) {
    console.error("Gagal ambil tugas", err);
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  fetch("/api/session")
    .then((response) => {
      if (!response.ok) throw new Error("Sesi tidak valid");
      return response.json();
    })
    .then((session) => {
      profile.value = session;
      if (session.roles?.includes("student")) {
        void fetchAssignments();
      } else {
        loading.value = false;
      }
    })
    .catch(() => { loading.value = false; });
});

watch(viewer, (current, _previous, onCleanup) => {
  if (!current) return;
  const previousOverflow = document.body.style.overflow;
  const closeOnEscape = (event: KeyboardEvent) => {
    if (event.key === "Escape") viewer.value = null;
  };
  document.body.style.overflow = "hidden";
  window.addEventListener("keydown", closeOnEscape);
  onCleanup(() => {
    document.body.style.overflow = previousOverflow;
    window.removeEventListener("keydown", closeOnEscape);
  });
});

const lessonProgress = (lessonId?: string | null) => {
  if (!lessonId) return null;
  for (const course of learningTree.value) {
    for (const courseModule of course.modules || []) {
      const lesson = courseModule.lessons?.find((item: any) => item.id === lessonId);
      if (lesson) {
        const progress = lesson.progress?.[0];
        const components = progress?.components ?? lesson.components;
        return progress || components ? { ...(progress || {}), components } : null;
      }
    }
  }
  return null;
};

const videoProgressFor = (material: LearningMaterial, progress: any) => progress?.components?.video?.materials?.find((item: any) => item.materialId === material.id);
const videoPercent = (material: LearningMaterial, progress: any) => Number(videoProgressFor(material, progress)?.percent || 0);
const readingPercent = (material: LearningMaterial) => {
  const progress = lessonProgress(material.lessonId);
  const reading = progress?.components?.reading?.materials?.find((item: any) => item.materialId === material.id);
  return Number(reading ? reading.percent : progress?.progressPercent || 0);
};
const isPreviewable = (material: LearningMaterial) => materialKind(material) === "PPTX" && Boolean(material.lessonId && material.originalFileName);

const applyLocalProgress = (lessonId: string, progressPercent: number, lastPosition: number, state: string) => {
  learningTree.value = learningTree.value.map((course: any) => ({
    ...course,
    modules: course.modules?.map((courseModule: any) => ({
      ...courseModule,
      lessons: courseModule.lessons?.map((lesson: any) => {
        if (lesson.id !== lessonId) return lesson;
        const current = lesson.progress?.[0] || {};
        const nextProgress = Math.max(Number(current.progressPercent || 0), progressPercent);
        const nextPosition = Math.max(Number(current.lastPosition || 0), lastPosition);
        const nextState = current.state === "COMPLETED" || state === "COMPLETED" ? "COMPLETED" : state;
        return { ...lesson, progress: [{ ...current, progressPercent: nextProgress, lastPosition: nextPosition, state: nextState }] };
      }),
    })),
  }));
};

const applyServerProgress = (lessonId: string, snapshot: any) => {
  learningTree.value = learningTree.value.map((course: any) => ({
    ...course,
    modules: course.modules?.map((courseModule: any) => ({
      ...courseModule,
      lessons: courseModule.lessons?.map((lesson: any) => (lesson.id !== lessonId ? lesson : { ...lesson, components: snapshot.components, progress: [snapshot] })),
    })),
  }));
};

// Binds the lesson id at render time, like the source's inline closure: the video dialog reports once more after it closes.
const snapshotHandler = (lessonId: string) => (snapshot: any) => applyServerProgress(lessonId, snapshot);

const saveSlideProgress = async (lessonId: string, materialId: string, position: number, totalSlides: number) => {
  const progressPercent = Math.round(position / totalSlides * 100);
  const state = position >= totalSlides ? "COMPLETED" : "IN_PROGRESS";
  progressSaving.value = true;
  progressError.value = "";
  try {
    const response = await csrfFetch(`/api/backend/learning/lessons/${lessonId}/progress`, {
      method: "PUT",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ state, progressPercent, lastPosition: position, materialId }),
    });
    if (!response.ok) throw new Error("Progres belum dapat disimpan. Coba kembali beberapa saat lagi.");
    const snapshot = await response.json().catch(() => null);
    if (snapshot?.components) applyServerProgress(lessonId, snapshot);
    else applyLocalProgress(lessonId, progressPercent, position, state);
  } catch (reason) {
    progressError.value = reason instanceof Error ? reason.message : "Progres belum dapat disimpan.";
  } finally {
    progressSaving.value = false;
  }
};

const openMaterial = async (material: LearningMaterial) => {
  if (!material.lessonId || materialKind(material) !== "PPTX") return;
  materialOpeningId.value = material.id;
  progressError.value = "";
  try {
    const response = await fetch(`/api/materials/${encodeURIComponent(material.id)}`, { cache: "no-store" });
    const metadata = await response.json().catch(() => null) as { slideCount?: number; message?: string } | null;
    if (!response.ok || !metadata?.slideCount) throw new Error(metadata?.message || "Preview materi belum tersedia.");
    const saved = lessonProgress(material.lessonId);
    const reading = saved?.components?.reading?.materials?.find((item: any) => item.materialId === material.id);
    const savedPercent = Number(reading ? reading.percent : saved?.progressPercent || 0);
    const savedPosition = Math.max(
      Number(reading ? reading.position : saved?.lastPosition || 0),
      Math.ceil(savedPercent / (100 / metadata.slideCount)),
      1,
    );
    const slide = Math.min(savedPosition, metadata.slideCount);
    viewer.value = { material, slide, totalSlides: metadata.slideCount, furthestSlide: slide };
    if (!savedPercent) void saveSlideProgress(material.lessonId, material.id, slide, metadata.slideCount);
  } catch (reason) {
    progressError.value = reason instanceof Error ? reason.message : "Preview materi belum tersedia.";
  } finally {
    materialOpeningId.value = "";
  }
};

const changeViewerSlide = (nextSlide: number) => {
  const current = viewer.value;
  if (!current || !current.material.lessonId) return;
  const slide = Math.min(Math.max(nextSlide, 1), current.totalSlides);
  const furthestSlide = Math.max(current.furthestSlide, slide);
  viewer.value = { ...current, slide, furthestSlide };
  if (furthestSlide > current.furthestSlide) {
    void saveSlideProgress(current.material.lessonId, current.material.id, furthestSlide, current.totalSlides);
  }
};

const handleOpenModal = (assignment: any) => {
  selectedAssignment.value = assignment;
  error.value = "";
  success.value = false;
  videoUrl.value = "";
  platform.value = "TIKTOK";
  productName.value = "";
  productUrl.value = "";
  notes.value = "";
};

const handleCloseModal = () => {
  selectedAssignment.value = null;
};

const handleSubmit = async () => {
  submitting.value = true;
  error.value = "";
  success.value = false;

  try {
    // 1. Buat Draft / Update Submission
    const subRes = await csrfFetch("/api/backend/submissions", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        assignmentId: selectedAssignment.value.id,
        content: selectedAssignment.value.type === "AFFILIATE" ? undefined : notes.value,
        notes: notes.value,
      }),
    });

    const subData = await subRes.json();
    if (!subRes.ok) throw new Error(subData.message || "Gagal membuat draf pengumpulan");

    if (selectedAssignment.value.type === "AFFILIATE") {
      const videoRes = await csrfFetch(`/api/backend/submissions/${subData.id}/videos`, {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ platform: platform.value, videoUrl: videoUrl.value, publishedAt: new Date().toISOString(), productName: productName.value, productUrl: productUrl.value }),
      });
      const videoData = await videoRes.json();
      if (!videoRes.ok) throw new Error(videoData.message || "Tautan video duplikat atau format salah");
    }

    // 3. Final Submit Tugas
    const finalRes = await csrfFetch(`/api/backend/submissions/${subData.id}/submit`, {
      method: "POST",
    });

    if (!finalRes.ok) throw new Error("Gagal melakukan submit final");

    success.value = true;
    setTimeout(() => {
      handleCloseModal();
      fetchAssignments(); // Refresh list tugas
    }, 1500);
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : "Pengumpulan belum dapat diproses";
  } finally {
    submitting.value = false;
  }
};
</script>

<template>
  <div v-if="loading" class="mx-auto min-h-screen max-w-[1500px] p-4 font-semibold text-orange-700 sm:p-6 lg:p-8">Memuat materi dan tugas…</div>

  <TeacherLearningManager v-else-if="profile?.roles?.includes('teacher')" />

  <div v-else-if="!profile?.roles?.includes('student')" class="mx-auto min-h-screen max-w-[1500px] p-4 sm:p-6 lg:p-8"><div role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">Halaman ini tersedia untuk guru dan murid.</div></div>

  <main v-else class="mx-auto min-h-screen max-w-[1500px] p-4 sm:p-6 lg:p-8">
    <div class="mb-7 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-orange-700">Ruang belajar</p>
      <h2 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-950 sm:text-3xl">Materi &amp; Tugas</h2>
      <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Ikuti jalur belajar secara berurutan, pahami materinya, lalu kumpulkan tugas sebelum tenggat.</p>
    </div>

    <section class="mb-8" aria-labelledby="learning-path-heading">
      <h3 id="learning-path-heading" class="mb-4 text-lg font-bold text-[#1F2937]">Jalur Belajar &amp; Progres</h3>
      <div class="grid gap-4">
        <article v-for="course in learningTree" :key="course.id" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
          <p class="text-xs font-bold uppercase text-orange-600">{{ course.program?.name || "Program belajar" }} · {{ course.code }}</p>
          <h4 class="mt-1 font-bold text-slate-800">{{ course.name }}</h4>
          <div class="mt-3 space-y-3">
            <div v-for="courseModule in course.modules" :key="courseModule.id" :class="['rounded-xl p-3 sm:p-4', courseModule.locked ? 'border border-slate-200 bg-slate-50' : 'bg-orange-50/60']">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-bold text-slate-700">Modul {{ courseModule.position }} · {{ courseModule.title }}</p>
                <span v-if="courseModule.locked" class="rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-700">Terkunci · {{ courseModule.minimumPlan }}</span>
              </div>
              <div v-if="courseModule.locked" class="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-600">
                <span>{{ courseModule.lockReason === "PLAN_MODULE_LIMIT" ? "Batas modul paket aktif telah tercapai." : `Modul ini memerlukan paket ${courseModule.minimumPlan}.` }}</span>
                <a href="/paket" class="rounded-lg bg-orange-600 px-3 py-2 font-bold text-white">Lihat paket</a>
              </div>
              <template v-else><div v-for="lesson in courseModule.lessons" :key="lesson.id" class="mt-2 rounded-lg bg-white/80 p-3 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span class="font-medium text-slate-700">{{ lesson.progress?.[0]?.state === "COMPLETED" ? "✓" : "○" }} {{ lesson.title }} <span class="text-xs text-slate-400">· {{ lesson.estimatedMinutes || "—" }} menit</span></span>
                  <span class="text-xs font-bold text-orange-700">{{ Number(lesson.progress?.[0]?.progressPercent || 0) }}% dipelajari</span>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-orange-100" :aria-label="`Progres ${lesson.title} ${Number(lesson.progress?.[0]?.progressPercent || 0)}%`">
                  <div class="h-full rounded-full bg-orange-500 transition-[width]" :style="{ width: `${Number(lesson.progress?.[0]?.progressPercent || 0)}%` }" />
                </div>
                <LessonComponentProgress :components="lesson.progress?.[0]?.components ?? lesson.components" :lesson-title="lesson.title" />
                <button v-if="(lesson.progress?.[0]?.components ?? lesson.components)?.quiz" type="button" @click="quizLesson = { id: lesson.id, title: lesson.title }" class="mt-2 min-h-11 rounded-lg border border-orange-300 px-3 text-xs font-bold text-orange-700 hover:bg-orange-50">{{ (lesson.progress?.[0]?.components ?? lesson.components).quiz.state === "COMPLETED" ? "Lihat tanya jawab" : "Kerjakan tanya jawab" }}</button>
              </div></template>
            </div>
          </div>
        </article>
      </div>
    </section>

    <section class="mb-8" aria-labelledby="student-materials-heading">
      <h3 id="student-materials-heading" class="mb-4 flex items-center gap-2 text-lg font-bold text-[#1F2937]"><BookOpen aria-hidden="true" :size="20" class="text-orange-600" /> Materi Belajar</h3>
      <div v-if="materials.length === 0" class="rounded-xl border bg-white p-6 text-sm text-[#64748B]">Belum ada materi yang dipublikasikan.</div>
      <div v-else class="grid gap-4 md:grid-cols-2">
        <article v-for="material in materials" :key="material.id" class="flex flex-col rounded-2xl border border-[#DDEBE8] bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-xs font-bold uppercase tracking-wide text-orange-600">{{ material.course?.code }} · {{ materialKind(material) }}</p>
              <h4 class="mt-1 font-bold text-[#1F2937]">{{ material.title }}</h4>
            </div>
            <Presentation v-if="isPreviewable(material)" class="shrink-0 text-orange-500" :size="22" aria-hidden="true" />
          </div>
          <p v-if="material.content" class="mt-2 line-clamp-3 text-sm leading-6 text-[#64748B]">{{ material.content }}</p>
          <div v-if="isPreviewable(material)" class="mt-4">
            <div class="flex items-center justify-between text-xs"><span class="font-medium text-slate-500">Progres membaca</span><span class="font-bold text-orange-700">{{ readingPercent(material) }}%</span></div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-orange-100"><div class="h-full rounded-full bg-orange-500 transition-[width]" :style="{ width: `${readingPercent(material)}%` }" /></div>
          </div>
          <div v-if="material.video && material.lessonId" class="mt-4">
            <div class="flex items-center justify-between text-xs"><span class="font-medium text-slate-500">Progres menonton</span><span class="font-bold text-orange-700">{{ videoPercent(material, lessonProgress(material.lessonId)) }}%</span></div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-orange-100" role="progressbar" :aria-valuemin="0" :aria-valuemax="100" :aria-valuenow="videoPercent(material, lessonProgress(material.lessonId))" :aria-label="`Progres menonton ${material.title}`"><div class="h-full rounded-full bg-orange-500 transition-[width]" :style="{ width: `${videoPercent(material, lessonProgress(material.lessonId))}%` }" /></div>
          </div>
          <div class="mt-auto flex flex-wrap items-center gap-2 pt-4">
            <button v-if="material.video && material.lessonId" type="button" @click="videoMaterial = material" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-orange-600 px-4 text-sm font-bold text-white transition hover:bg-orange-700"><Presentation aria-hidden="true" :size="17" /> {{ videoPercent(material, lessonProgress(material.lessonId)) > 0 ? "Lanjutkan video" : "Tonton video" }}</button>
            <button v-else-if="isPreviewable(material)" type="button" @click="void openMaterial(material)" :disabled="materialOpeningId === material.id" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-orange-600 px-4 text-sm font-bold text-white transition hover:bg-orange-700 disabled:opacity-60"><BookOpen aria-hidden="true" :size="17" /> {{ materialOpeningId === material.id ? "Menyiapkan…" : readingPercent(material) > 0 ? "Lanjutkan materi" : "Lihat materi" }}</button>
            <a v-else-if="material.fileUrl" :href="material.fileUrl" target="_blank" rel="noreferrer" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl bg-orange-600 px-4 text-sm font-bold text-white">Buka materi</a>
            <a v-if="materialKind(material) === 'PPTX'" :href="`/api/materials/${encodeURIComponent(material.id)}?download=1`" download class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-orange-200 px-3 text-sm font-bold text-orange-700 hover:bg-orange-50"><Download aria-hidden="true" :size="16" /> Unduh</a>
          </div>
        </article>
      </div>
    </section>

    <h3 class="mb-4 text-lg font-bold text-[#1F2937]">Daftar Tugas Praktik</h3>

    <div class="grid gap-4">
      <div v-if="assignments.length === 0" class="rounded-xl border bg-white p-8 text-center text-[#64748B]">Belum ada tugas aktif yang tersedia.</div>
      <template v-else><article v-for="a in assignments" :key="a.id" class="flex flex-col items-stretch justify-between gap-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:p-6">
        <div class="min-w-0">
          <h3 class="font-bold text-lg text-[#1F2937]">{{ a.title }}</h3>
          <p class="text-sm text-[#64748B] mt-1">{{ a.description }}</p>
          <div class="flex gap-4 mt-3 text-xs text-[#286864] font-semibold">
            <span class="flex items-center gap-1"><Clock aria-hidden="true" :size="14" /> Deadline: {{ new Date(a.endDate).toLocaleDateString("id-ID") }}</span>
          </div>
        </div>
        <DashLink v-if="a.type === 'STORE'" :to="`/dashboard/toko?assignmentId=${a.id}`" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-r from-[#FBBF24] to-[#377C78] px-5 py-2.5 font-semibold text-white transition-opacity hover:opacity-90">Buka Student Store</DashLink>
        <DashLink v-else-if="a.type === 'AFFILIATE'" :to="`/dashboard/affiliate?assignmentId=${a.id}`" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-r from-[#FBBF24] to-[#377C78] px-5 py-2.5 font-semibold text-white transition-opacity hover:opacity-90">Buka Affiliate</DashLink>
        <button v-else @click="handleOpenModal(a)" class="min-h-11 shrink-0 rounded-xl bg-gradient-to-r from-[#FBBF24] to-[#377C78] px-5 py-2.5 font-semibold text-white transition-opacity hover:opacity-90">Kumpulkan</button>
      </article></template>
    </div>

    <QuizDialog v-if="quizLesson" :lesson-id="quizLesson.id" :lesson-title="quizLesson.title" :on-snapshot="snapshotHandler(quizLesson.id)" :on-close="() => { quizLesson = null; }" />

    <VideoPlayerDialog
      v-if="videoMaterial?.video && videoMaterial.lessonId"
      :material="{ id: videoMaterial.id, title: videoMaterial.title, video: videoMaterial.video }"
      :resume-seconds="Number(videoProgressFor(videoMaterial, lessonProgress(videoMaterial.lessonId))?.position || 0)"
      :initial="videoProgressFor(videoMaterial, lessonProgress(videoMaterial.lessonId))"
      :on-snapshot="snapshotHandler(videoMaterial.lessonId)"
      :on-close="() => { videoMaterial = null; }"
    />

    <div v-if="viewer" class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/80 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="material-viewer-title">
      <div class="flex max-h-[96vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-6">
          <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-wide text-orange-600">Materi langsung · Slide {{ viewer.slide }} dari {{ viewer.totalSlides }}</p>
            <h3 id="material-viewer-title" class="truncate text-base font-extrabold text-slate-900 sm:text-lg">{{ viewer.material.title }}</h3>
          </div>
          <button type="button" @click="viewer = null" aria-label="Tutup materi" class="grid size-10 shrink-0 place-items-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-slate-900"><X aria-hidden="true" :size="21" /></button>
        </header>

        <div class="min-h-0 flex-1 overflow-auto bg-slate-100 p-2 sm:p-4">
          <div class="mx-auto max-w-5xl overflow-hidden rounded-xl bg-white shadow-lg">
            <img
              :key="`${viewer.material.id}-${viewer.slide}`"
              :src="pptxPreviewPath(viewer.material, viewer.slide)"
              :alt="`Slide ${viewer.slide} dari ${viewer.material.title}`"
              width="1280"
              height="720"
              loading="lazy"
              class="h-auto w-full"
            />
          </div>
        </div>

        <footer class="border-t border-slate-200 bg-white px-4 py-3 sm:px-6">
          <div class="mb-3 flex items-center justify-between gap-3 text-xs">
            <span class="font-medium text-slate-500">{{ progressSaving ? "Menyimpan progres…" : `Progres tersimpan ${Math.round(viewer.furthestSlide / viewer.totalSlides * 100)}%` }}</span>
            <span v-if="progressError" role="alert" class="text-right font-semibold text-red-600">{{ progressError }}</span>
          </div>
          <div class="mb-3 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-orange-500 transition-[width]" :style="{ width: `${viewer.furthestSlide / viewer.totalSlides * 100}%` }" /></div>
          <div class="flex items-center justify-between gap-3">
            <button type="button" @click="changeViewerSlide(viewer.slide - 1)" :disabled="viewer.slide === 1" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"><ChevronLeft aria-hidden="true" :size="18" /> Sebelumnya</button>
            <div class="hidden items-center gap-1.5 sm:flex" aria-label="Posisi slide">
              <button v-for="slide in Array.from({ length: viewer.totalSlides }, (_, index) => index + 1)" type="button" :key="slide" @click="changeViewerSlide(slide)" :disabled="slide > viewer.furthestSlide + 1" :aria-current="slide === viewer.slide ? 'step' : undefined" :aria-label="`Buka slide ${slide}`" :class="['size-2.5 rounded-full disabled:cursor-not-allowed', slide === viewer.slide ? 'bg-orange-600' : slide <= viewer.furthestSlide ? 'bg-orange-300' : 'bg-slate-200']" />
            </div>
            <button v-if="viewer.slide < viewer.totalSlides" type="button" @click="changeViewerSlide(viewer.slide + 1)" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-orange-600 px-4 text-sm font-bold text-white hover:bg-orange-700">Berikutnya <ChevronRight aria-hidden="true" :size="18" /></button>
            <button v-else type="button" @click="viewer = null" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-bold text-white hover:bg-emerald-700"><CheckCircle aria-hidden="true" :size="18" /> Selesai</button>
          </div>
        </footer>
      </div>
    </div>

    <!-- Modal Form Pengumpulan -->
    <div v-if="selectedAssignment" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm px-4">
      <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl border border-[#DDEBE8] relative">
        <button
          type="button"
          aria-label="Tutup formulir pengumpulan"
          @click="handleCloseModal"
          class="absolute right-4 top-4 p-1 rounded-full hover:bg-gray-100 text-[#64748B]"
        >
          <X aria-hidden="true" :size="20" />
        </button>

        <h3 class="text-xl font-bold text-[#1F2937] mb-2">Kumpulkan Tugas</h3>
        <p class="text-sm text-[#64748B] mb-6">{{ selectedAssignment.title }}</p>

        <form @submit.prevent="handleSubmit" class="space-y-4">
          <div v-if="error" class="p-3 bg-red-50 rounded-lg flex items-center gap-2 text-sm text-red-600">
            <AlertCircle aria-hidden="true" :size="18" />
            <span>{{ error }}</span>
          </div>

          <div v-if="success" class="p-3 bg-green-50 rounded-lg flex items-center gap-2 text-sm text-green-600">
            <CheckCircle aria-hidden="true" :size="18" />
            <span>Tugas berhasil dikumpulkan!</span>
          </div>

          <template v-if="selectedAssignment.type === 'AFFILIATE'"><div class="grid grid-cols-2 gap-4">
            <div>
              <label for="submission-platform" class="block text-xs font-semibold text-[#1F2937] mb-1">Platform Video</label>
              <select
                id="submission-platform"
                class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none"
                v-model="platform"
              >
                <option value="TIKTOK">TikTok</option>
                <option value="SHOPEE">Shopee Video</option>
                <option value="INSTAGRAM">Instagram Reels</option>
                <option value="YOUTUBE">YouTube Shorts</option>
              </select>
            </div>

            <div>
              <label for="submission-video-url" class="block text-xs font-semibold text-[#1F2937] mb-1">Tautan Video</label>
              <input
                id="submission-video-url"
                type="url"
                required
                placeholder="https://tiktok.com/..."
                class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none"
                v-model="videoUrl"
              />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label for="submission-product-name" class="block text-xs font-semibold text-[#1F2937] mb-1">Nama Produk Terkait</label>
              <input
                id="submission-product-name"
                type="text"
                placeholder="Contoh: Kemeja Flanel Uniqlo"
                class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none"
                v-model="productName"
              />
            </div>

            <div>
              <label for="submission-product-url" class="block text-xs font-semibold text-[#1F2937] mb-1">Tautan Keranjang/Produk</label>
              <input
                id="submission-product-url"
                type="url"
                placeholder="https://shopee.co.id/..."
                class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none"
                v-model="productUrl"
              />
            </div>
          </div></template>

          <div>
            <label for="submission-notes" class="block text-xs font-semibold text-[#1F2937] mb-1">{{ selectedAssignment.type === "AFFILIATE" ? "Catatan Tambahan (Opsional)" : "Jawaban / hasil tugas" }}</label>
            <textarea
              id="submission-notes"
              :required="selectedAssignment.type !== 'AFFILIATE'"
              :placeholder="selectedAssignment.type === 'AFFILIATE' ? 'Tulis catatan jika ada...' : 'Tuliskan jawaban, hasil, atau tautan pendukung...'"
              class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none h-20 resize-none"
              v-model="notes"
            />
          </div>

          <div class="flex gap-3 justify-end pt-4 border-t border-gray-100">
            <button
              type="button"
              @click="handleCloseModal"
              class="px-4 py-2 border rounded-lg text-sm font-semibold hover:bg-gray-50 text-[#64748B]"
            >Batal</button>
            <button
              type="submit"
              :disabled="submitting || success"
              class="px-5 py-2 bg-gradient-to-r from-[#FBBF24] to-[#377C78] text-white rounded-lg text-sm font-semibold hover:opacity-90 disabled:opacity-50"
            >{{ submitting ? "Mengirim..." : "Kumpulkan" }}</button>
          </div>
        </form>
      </div>
    </div>
  </main>
</template>
