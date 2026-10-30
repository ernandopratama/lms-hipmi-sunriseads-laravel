<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { AlertCircle, BookOpen, CheckCircle2, ClipboardPlus, FileText, Loader2, Plus } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";
import { apiError } from "@/lib/api";
import DashLink from "@/components/dashboard/DashLink.vue";
import QuizEditor from "@/components/dashboard/tugas/QuizEditor.vue";

type Course = { id: string; name: string; code: string };
type LearningCourse = Course & { modules: Array<{ id: string; title: string; position: number; lessons: Array<{ id: string; title: string; position: number }> }> };
type Material = {
  id: string;
  title: string;
  type: string;
  content?: string | null;
  fileUrl?: string | null;
  originalFileName?: string | null;
  course: Course;
};
type Assignment = {
  id: string;
  title: string;
  description?: string | null;
  type: string;
  startDate: string;
  endDate: string;
  maxScore: number;
  isActive: boolean;
  course: Course;
  _count?: { submissions: number };
};
type Rubric = { id: string; name: string };
type Checklist = { id: string; name: string; minimumProducts: number };

function materialKind(material: Pick<Material, "type" | "originalFileName">) {
  return material.originalFileName?.toLowerCase().endsWith(".pptx") ? "PPTX" : material.type;
}

function localDateTime(offsetHours = 0) {
  const value = new Date(Date.now() + offsetHours * 60 * 60 * 1000);
  const local = new Date(value.getTime() - value.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 16);
}

const tab = ref<"materials" | "assignments" | "quiz">("materials");
const courses = ref<Course[]>([]);
const learningTree = ref<LearningCourse[]>([]);
const materials = ref<Material[]>([]);
const assignments = ref<Assignment[]>([]);
const rubrics = ref<Rubric[]>([]);
const checklists = ref<Checklist[]>([]);
const loading = ref(true);
const saving = ref(false);
const error = ref("");
const success = ref("");

const materialCourseId = ref("");
const materialTitle = ref("");
const materialType = ref("TEXT");
const materialContent = ref("");
const materialUrl = ref("");
const materialLessonId = ref("");

const assignmentCourseId = ref("");
const assignmentTitle = ref("");
const assignmentDescription = ref("");
const assignmentType = ref("AFFILIATE");
const assignmentStart = ref(localDateTime());
const assignmentEnd = ref(localDateTime(7 * 24));
const assignmentMaxScore = ref(100);
const assignmentPublished = ref(true);
const assignmentRubricId = ref("");
const assignmentChecklistId = ref("");
const assignmentModuleId = ref("");

async function loadData() {
  loading.value = true;
  error.value = "";
  try {
    const [courseResponse, treeResponse, materialResponse, assignmentResponse, rubricResponse, checklistResponse] = await Promise.all([
      fetch("/api/backend/learning/courses"),
      fetch("/api/backend/learning/tree"),
      fetch("/api/backend/learning/materials"),
      fetch("/api/backend/assignments"),
      fetch("/api/backend/rubrics"),
      fetch("/api/backend/student-store/checklist-templates"),
    ]);
    if (!courseResponse.ok) throw new Error(await apiError(courseResponse, "Mata pelajaran belum dapat dimuat"));
    if (!treeResponse.ok || !materialResponse.ok) throw new Error(await apiError(materialResponse, "Materi belum dapat dimuat"));
    if (!assignmentResponse.ok) throw new Error(await apiError(assignmentResponse, "Tugas belum dapat dimuat"));

    if (!rubricResponse.ok || !checklistResponse.ok) throw new Error("Rubrik dan checklist belum dapat dimuat");
    const [courseData, treeData, materialData, assignmentData, rubricData, checklistData] = await Promise.all([
      courseResponse.json(),
      treeResponse.json(),
      materialResponse.json(),
      assignmentResponse.json(),
      rubricResponse.json(),
      checklistResponse.json(),
    ]);
    courses.value = courseData;
    learningTree.value = treeData;
    materials.value = materialData;
    assignments.value = assignmentData.data || [];
    rubrics.value = rubricData || [];
    checklists.value = checklistData || [];
    if (!materialCourseId.value && courseData[0]) materialCourseId.value = courseData[0].id;
    if (!assignmentCourseId.value && courseData[0]) assignmentCourseId.value = courseData[0].id;
    const firstLesson = treeData.flatMap((course: LearningCourse) => course.modules.flatMap((module) => module.lessons))[0];
    const firstModule = treeData.flatMap((course: LearningCourse) => course.modules)[0];
    if (!materialLessonId.value && firstLesson) materialLessonId.value = firstLesson.id;
    if (!assignmentModuleId.value && firstModule) assignmentModuleId.value = firstModule.id;
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Data pembelajaran belum dapat dimuat";
  } finally {
    loading.value = false;
  }
}

const materialLessons = computed(() => learningTree.value.find((course) => course.id === materialCourseId.value)?.modules.flatMap((module) => module.lessons.map((lesson) => ({ ...lesson, moduleTitle: module.title }))) || []);
const assignmentModules = computed(() => learningTree.value.find((course) => course.id === assignmentCourseId.value)?.modules || []);

// The source reloads whenever one of these ids changes (they are loadData's useCallback deps).
watch([assignmentCourseId, assignmentModuleId, materialCourseId, materialLessonId], () => { void loadData(); }, { immediate: true });

const createMaterial = async () => {
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    const response = await csrfFetch("/api/backend/learning/materials", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        courseId: materialCourseId.value,
        lessonId: materialLessonId.value,
        title: materialTitle.value,
        type: materialType.value,
        content: materialContent.value || undefined,
        fileUrl: materialUrl.value || undefined,
      }),
    });
    if (!response.ok) throw new Error(await apiError(response, "Materi gagal disimpan"));
    materialTitle.value = "";
    materialContent.value = "";
    materialUrl.value = "";
    success.value = "Materi berhasil dipublikasikan.";
    await loadData();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Materi gagal disimpan";
  } finally {
    saving.value = false;
  }
};

const createAssignment = async () => {
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    const response = await csrfFetch("/api/backend/assignments", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        courseId: assignmentCourseId.value,
        moduleId: assignmentModuleId.value,
        title: assignmentTitle.value,
        description: assignmentDescription.value || undefined,
        type: assignmentType.value,
        startDate: new Date(assignmentStart.value).toISOString(),
        endDate: new Date(assignmentEnd.value).toISOString(),
        maxScore: assignmentMaxScore.value,
        rubricId: assignmentRubricId.value || undefined,
        checklistTemplateId: assignmentType.value === "STORE" ? assignmentChecklistId.value || undefined : undefined,
        isActive: assignmentPublished.value,
      }),
    });
    if (!response.ok) throw new Error(await apiError(response, "Tugas gagal disimpan"));
    assignmentTitle.value = "";
    assignmentDescription.value = "";
    success.value = assignmentPublished.value ? "Tugas berhasil dipublikasikan." : "Tugas berhasil disimpan sebagai draf.";
    await loadData();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Tugas gagal disimpan";
  } finally {
    saving.value = false;
  }
};

function changeMaterialCourse(event: Event) {
  const courseId = (event.target as HTMLSelectElement).value; materialCourseId.value = courseId; const first = learningTree.value.find((course) => course.id === courseId)?.modules.flatMap((module) => module.lessons)[0]; materialLessonId.value = first?.id || "";
}

function changeAssignmentCourse(event: Event) {
  const courseId = (event.target as HTMLSelectElement).value; assignmentCourseId.value = courseId; assignmentModuleId.value = learningTree.value.find((course) => course.id === courseId)?.modules[0]?.id || "";
}
</script>

<template>
  <div class="min-h-screen bg-[#FFFFFF] p-4 sm:p-8">
    <div class="mx-auto max-w-6xl">
      <div class="mb-6">
        <p class="text-sm font-bold uppercase tracking-[0.16em] text-orange-600">Ruang Guru</p>
        <h2 class="mt-2 text-2xl font-extrabold text-[#1F2937]">Kelola Materi &amp; Tugas</h2>
        <p class="mt-2 text-sm text-[#64748B]">Konten hanya diterbitkan untuk mata pelajaran di sekolah Anda.</p>
        <DashLink to="/dashboard/kurikulum" class="mt-3 inline-flex text-sm font-bold text-orange-600 hover:underline">Atur program, modul, lesson, rubrik &amp; checklist →</DashLink>
      </div>

      <div class="mb-6 flex gap-2 rounded-xl border border-[#DDEBE8] bg-white p-2" role="tablist" aria-label="Jenis konten pembelajaran">
        <button type="button" role="tab" :aria-selected="tab === 'materials'" @click="tab = 'materials'" :class="['min-h-11 flex-1 rounded-lg px-4 text-sm font-bold', tab === 'materials' ? 'bg-orange-100 text-orange-700' : 'text-slate-600']">Materi</button>
        <button type="button" role="tab" :aria-selected="tab === 'assignments'" @click="tab = 'assignments'" :class="['min-h-11 flex-1 rounded-lg px-4 text-sm font-bold', tab === 'assignments' ? 'bg-orange-100 text-orange-700' : 'text-slate-600']">Tugas</button>
        <button type="button" role="tab" :aria-selected="tab === 'quiz'" @click="tab = 'quiz'" :class="['min-h-11 flex-1 rounded-lg px-4 text-sm font-bold', tab === 'quiz' ? 'bg-orange-100 text-orange-700' : 'text-slate-600']">Tanya Jawab</button>
      </div>

      <div v-if="error" role="alert" class="mb-5 flex gap-2 rounded-xl bg-red-50 p-4 text-sm text-red-700"><AlertCircle aria-hidden="true" :size="18" />{{ error }}</div>
      <div v-if="success" role="status" class="mb-5 flex gap-2 rounded-xl bg-green-50 p-4 text-sm text-green-700"><CheckCircle2 aria-hidden="true" :size="18" />{{ success }}</div>

      <div v-if="courses.length === 0 && !loading" class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-800">Belum ada mata pelajaran aktif. Hubungi administrator untuk menyiapkan course sekolah.</div>
      <QuizEditor v-else-if="tab === 'quiz'" :courses="courses" :tree="learningTree" />
      <div v-else-if="tab === 'materials'" class="grid gap-6 lg:grid-cols-[minmax(0,380px)_1fr]">
        <form @submit.prevent="createMaterial" class="h-fit space-y-4 rounded-2xl border border-[#DDEBE8] bg-white p-5 shadow-sm">
          <h3 class="flex items-center gap-2 text-lg font-bold text-[#1F2937]"><Plus aria-hidden="true" :size="20" class="text-orange-600" /> Tambah Materi</h3>
          <div><label for="material-course" class="mb-1 block text-xs font-bold text-[#1F2937]">Mata pelajaran</label><select id="material-course" required v-model="materialCourseId" @change="changeMaterialCourse" class="form-control"><option v-for="course in courses" :key="course.id" :value="course.id">{{ course.code }} · {{ course.name }}</option></select></div>
          <div><label for="material-lesson" class="mb-1 block text-xs font-bold text-[#1F2937]">Lesson</label><select id="material-lesson" required v-model="materialLessonId" class="form-control"><option value="">Pilih lesson</option><option v-for="lesson in materialLessons" :key="lesson.id" :value="lesson.id">{{ lesson.moduleTitle }} · {{ lesson.title }}</option></select></div>
          <div><label for="material-title" class="mb-1 block text-xs font-bold text-[#1F2937]">Judul materi</label><input id="material-title" required minlength="3" maxlength="160" v-model="materialTitle" class="form-control" placeholder="Contoh: Dasar membuat konten promosi" /></div>
          <div><label for="material-type" class="mb-1 block text-xs font-bold text-[#1F2937]">Jenis materi</label><select id="material-type" v-model="materialType" class="form-control"><option value="TEXT">Teks</option><option value="VIDEO">Video</option><option value="LINK">Tautan</option><option value="DOC">Dokumen</option></select></div>
          <div><label for="material-content" class="mb-1 block text-xs font-bold text-[#1F2937]">Isi atau deskripsi</label><textarea id="material-content" v-model="materialContent" class="form-control min-h-28 resize-y" placeholder="Tuliskan ringkasan dan petunjuk belajar..." /></div>
          <div><label for="material-url" class="mb-1 block text-xs font-bold text-[#1F2937]">Tautan sumber (opsional)</label><input id="material-url" type="url" v-model="materialUrl" class="form-control" placeholder="https://..." /></div>
          <button :disabled="saving || !materialCourseId || !materialLessonId" class="bg-sunrise flex min-h-12 w-full items-center justify-center gap-2 rounded-xl font-bold text-white disabled:opacity-50"><Loader2 v-if="saving" aria-hidden="true" class="animate-spin" :size="18" /><FileText v-else aria-hidden="true" :size="18" /> Publikasikan Materi</button>
        </form>
        <div v-if="loading" class="rounded-xl border border-[#DDEBE8] bg-white p-8 text-sm font-semibold text-orange-600">Memuat data...</div>
        <div v-else-if="materials.length === 0" class="rounded-xl border border-[#DDEBE8] bg-white p-8 text-center text-sm text-[#64748B]">Belum ada materi yang Anda buat.</div>
        <div v-else class="grid content-start gap-4">
          <article v-for="material in materials" :key="material.id" class="rounded-xl border border-[#DDEBE8] bg-white p-5"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-wide text-orange-600">{{ material.course.code }} · {{ materialKind(material) }}</p><h3 class="mt-1 font-bold text-[#1F2937]">{{ material.title }}</h3></div><BookOpen aria-hidden="true" class="text-orange-500" :size="20" /></div><p v-if="material.content" class="mt-3 line-clamp-3 text-sm leading-6 text-[#64748B]">{{ material.content }}</p><a v-if="material.fileUrl" :href="material.fileUrl" target="_blank" rel="noreferrer" class="mt-3 inline-flex text-sm font-bold text-orange-600 hover:underline">Buka sumber →</a></article>
        </div>
      </div>
      <div v-else class="grid gap-6 lg:grid-cols-[minmax(0,420px)_1fr]">
        <form @submit.prevent="createAssignment" class="h-fit space-y-4 rounded-2xl border border-[#DDEBE8] bg-white p-5 shadow-sm">
          <h3 class="flex items-center gap-2 text-lg font-bold text-[#1F2937]"><ClipboardPlus aria-hidden="true" :size="20" class="text-orange-600" /> Buat Tugas</h3>
          <div><label for="assignment-course" class="mb-1 block text-xs font-bold text-[#1F2937]">Mata pelajaran</label><select id="assignment-course" required v-model="assignmentCourseId" @change="changeAssignmentCourse" class="form-control"><option v-for="course in courses" :key="course.id" :value="course.id">{{ course.code }} · {{ course.name }}</option></select></div>
          <div><label for="assignment-module" class="mb-1 block text-xs font-bold text-[#1F2937]">Modul tugas</label><select id="assignment-module" required v-model="assignmentModuleId" class="form-control"><option value="">Pilih modul</option><option v-for="module in assignmentModules" :key="module.id" :value="module.id">Modul {{ module.position }} · {{ module.title }}</option></select></div>
          <div><label for="assignment-title" class="mb-1 block text-xs font-bold text-[#1F2937]">Judul tugas</label><input id="assignment-title" required minlength="3" maxlength="160" v-model="assignmentTitle" class="form-control" placeholder="Contoh: Video promosi produk lokal" /></div>
          <div><label for="assignment-description" class="mb-1 block text-xs font-bold text-[#1F2937]">Instruksi</label><textarea id="assignment-description" v-model="assignmentDescription" class="form-control min-h-24 resize-y" placeholder="Jelaskan hasil yang perlu dikumpulkan..." /></div>
          <div class="grid gap-4 sm:grid-cols-2"><div><label for="assignment-type" class="mb-1 block text-xs font-bold text-[#1F2937]">Jenis tugas</label><select id="assignment-type" v-model="assignmentType" class="form-control"><option value="AFFILIATE">Affiliate</option><option value="STORE">Student Store</option><option value="WRITTEN">Tertulis</option><option value="PROJECT">Proyek</option><option value="QUIZ">Kuis</option></select></div><div><label for="assignment-score" class="mb-1 block text-xs font-bold text-[#1F2937]">Nilai maksimal</label><input id="assignment-score" type="number" :min="1" :max="1000" :value="assignmentMaxScore" @input="assignmentMaxScore = Number(($event.target as HTMLInputElement).value)" class="form-control" /></div></div>
          <div><label for="assignment-rubric" class="mb-1 block text-xs font-bold text-[#1F2937]">Rubrik penilaian (opsional)</label><select id="assignment-rubric" v-model="assignmentRubricId" class="form-control"><option value="">Tanpa rubrik</option><option v-for="rubric in rubrics" :key="rubric.id" :value="rubric.id">{{ rubric.name }}</option></select></div>
          <div v-if="assignmentType === 'STORE'"><label for="assignment-checklist" class="mb-1 block text-xs font-bold text-[#1F2937]">Checklist toko</label><select id="assignment-checklist" required v-model="assignmentChecklistId" class="form-control"><option value="">Pilih checklist</option><option v-for="checklist in checklists" :key="checklist.id" :value="checklist.id">{{ checklist.name }} · min. {{ checklist.minimumProducts }} produk</option></select></div>
          <div class="grid gap-4 sm:grid-cols-2"><div><label for="assignment-start" class="mb-1 block text-xs font-bold text-[#1F2937]">Mulai</label><input id="assignment-start" type="datetime-local" required v-model="assignmentStart" class="form-control" /></div><div><label for="assignment-end" class="mb-1 block text-xs font-bold text-[#1F2937]">Deadline</label><input id="assignment-end" type="datetime-local" required v-model="assignmentEnd" class="form-control" /></div></div>
          <label class="flex items-center gap-3 rounded-xl bg-[#FFFFFF] p-3 text-sm text-slate-700"><input type="checkbox" v-model="assignmentPublished" class="size-4" /> Publikasikan langsung kepada siswa</label>
          <button :disabled="saving || !assignmentCourseId || !assignmentModuleId" class="bg-sunrise flex min-h-12 w-full items-center justify-center gap-2 rounded-xl font-bold text-white disabled:opacity-50"><Loader2 v-if="saving" aria-hidden="true" class="animate-spin" :size="18" /><ClipboardPlus v-else aria-hidden="true" :size="18" /> {{ assignmentPublished ? "Publikasikan Tugas" : "Simpan Draf" }}</button>
        </form>
        <div v-if="loading" class="rounded-xl border border-[#DDEBE8] bg-white p-8 text-sm font-semibold text-orange-600">Memuat data...</div>
        <div v-else-if="assignments.length === 0" class="rounded-xl border border-[#DDEBE8] bg-white p-8 text-center text-sm text-[#64748B]">Belum ada tugas yang Anda buat.</div>
        <div v-else class="grid content-start gap-4">
          <article v-for="assignment in assignments" :key="assignment.id" class="rounded-xl border border-[#DDEBE8] bg-white p-5"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-wide text-orange-600">{{ assignment.course.code }} · {{ assignment.type }}</p><h3 class="mt-1 font-bold text-[#1F2937]">{{ assignment.title }}</h3></div><span :class="['rounded-full px-3 py-1 text-xs font-bold', assignment.isActive ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-600']">{{ assignment.isActive ? "Terbit" : "Draf" }}</span></div><p v-if="assignment.description" class="mt-3 text-sm leading-6 text-[#64748B]">{{ assignment.description }}</p><div class="mt-4 flex flex-wrap gap-3 text-xs font-semibold text-slate-500"><span>Deadline {{ new Date(assignment.endDate).toLocaleString("id-ID") }}</span><span>Nilai {{ assignment.maxScore }}</span><span>{{ assignment._count?.submissions || 0 }} pengumpulan</span></div></article>
        </div>
      </div>
    </div>
  </div>
</template>
