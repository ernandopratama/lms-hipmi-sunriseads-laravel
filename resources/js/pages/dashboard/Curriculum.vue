<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { AlertCircle, CheckCircle2, Layers3, Loader2, Users } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";
import { apiError } from "@/lib/api";
import MiniForm from "@/components/dashboard/curriculum/MiniForm.vue";
import CurriculumInput from "@/components/dashboard/curriculum/CurriculumInput.vue";
import CurriculumSelect from "@/components/dashboard/curriculum/CurriculumSelect.vue";
import Builder from "@/components/dashboard/curriculum/Builder.vue";
import type { BuilderItem } from "@/components/dashboard/curriculum/types";

type Program = { id: string; code: string; name: string; _count?: { courses: number } };
type Lesson = { id: string; title: string; isPublished: boolean };
type Module = { id: string; title: string; isPublished: boolean; lessons: Lesson[] };
type Course = { id: string; code: string; name: string; modules?: Module[]; program?: Program | null };
type SchoolClass = { id: string; code: string; name: string };

const programs = ref<Program[]>([]);
const courses = ref<Course[]>([]);
const tree = ref<Course[]>([]);
const classes = ref<SchoolClass[]>([]);
const rubricItems = ref<BuilderItem[]>([
  { name: "Kualitas hasil", weight: 40 }, { name: "Kelengkapan", weight: 35 }, { name: "Orisinalitas", weight: 25 },
]);
const checklistItems = ref<BuilderItem[]>([
  { name: "Profil toko lengkap", weight: 25, required: true }, { name: "Produk memenuhi brief", weight: 40, required: true },
  { name: "Operasional siap", weight: 20, required: true }, { name: "Bukti aman", weight: 15, required: true },
]);
const loading = ref(true);
const saving = ref(false);
const message = ref("");
const error = ref("");

async function load() {
  loading.value = true;
  try {
    const responses = await Promise.all([
      fetch("/api/backend/learning/programs"), fetch("/api/backend/learning/courses"),
      fetch("/api/backend/learning/tree"), fetch("/api/backend/learning/classes"),
    ]);
    if (responses.some((response) => !response.ok)) throw new Error("Struktur pembelajaran belum dapat dimuat");
    const [programData, courseData, treeData, classData] = await Promise.all(responses.map((response) => response.json()));
    programs.value = programData; courses.value = courseData; tree.value = treeData; classes.value = classData;
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Data belum dapat dimuat";
  } finally { loading.value = false; }
}

onMounted(() => { void load(); });

const send = async (url: string, payload: unknown, success: string, reset?: () => void) => {
  saving.value = true; error.value = ""; message.value = "";
  try {
    const response = await csrfFetch(url, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(payload) });
    if (!response.ok) throw new Error(await apiError(response, "Data gagal disimpan"));
    message.value = success; reset?.(); await load();
  } catch (caught) { error.value = caught instanceof Error ? caught.message : "Data gagal disimpan"; }
  finally { saving.value = false; }
};

const formValue = (form: HTMLFormElement, name: string) => String(new FormData(form).get(name) || "").trim();

const createProgram = (event: Event) => {
  const form = event.currentTarget as HTMLFormElement;
  void send("/api/backend/learning/programs", { code: formValue(form, "code"), name: formValue(form, "name"), description: formValue(form, "description") || undefined }, "Program berhasil dibuat.", () => form.reset());
};
const createCourse = (event: Event) => {
  const form = event.currentTarget as HTMLFormElement;
  void send("/api/backend/learning/courses", { programId: formValue(form, "programId") || undefined, code: formValue(form, "code"), name: formValue(form, "name"), description: formValue(form, "description") || undefined }, "Course berhasil dibuat.", () => form.reset());
};
const createModule = (event: Event) => {
  const form = event.currentTarget as HTMLFormElement; const courseId = formValue(form, "courseId");
  void send(`/api/backend/learning/courses/${courseId}/modules`, { title: formValue(form, "title"), description: formValue(form, "description") || undefined, isPublished: true }, "Modul berhasil diterbitkan.", () => form.reset());
};
const createLesson = (event: Event) => {
  const form = event.currentTarget as HTMLFormElement; const moduleId = formValue(form, "moduleId");
  void send(`/api/backend/learning/modules/${moduleId}/lessons`, { title: formValue(form, "title"), summary: formValue(form, "summary") || undefined, estimatedMinutes: Number(formValue(form, "minutes")) || undefined, isPublished: true }, "Lesson berhasil diterbitkan.", () => form.reset());
};
const enroll = (event: Event) => {
  const form = event.currentTarget as HTMLFormElement;
  void send("/api/backend/learning/enrollments/class", { courseId: formValue(form, "courseId"), classId: formValue(form, "classId") }, "Siswa aktif di kelas berhasil dimasukkan ke course.");
};
const createRubric = (event: Event) => {
  const form = event.currentTarget as HTMLFormElement;
  void send("/api/backend/rubrics", { name: formValue(form, "name"), description: formValue(form, "description") || undefined, criteria: rubricItems.value.map((item, index) => ({ name: item.name, weight: item.weight, maxScore: 100, position: index + 1 })) }, "Rubrik berhasil dibuat.", () => form.reset());
};
const createMgmpRubrics = () => {
  void send("/api/backend/rubrics/templates/mgmp", {}, "Tiga rubrik MGMP siap digunakan: KIK, Bahasa Inggris, dan Informatika.");
};
const createChecklist = (event: Event) => {
  const form = event.currentTarget as HTMLFormElement;
  void send("/api/backend/student-store/checklist-templates", { name: formValue(form, "name"), platform: formValue(form, "platform") || undefined, minimumProducts: Number(formValue(form, "minimumProducts")), items: checklistItems.value.map((item, index) => ({ code: `ITEM_${index + 1}`, label: item.name, evidenceType: "SCREENSHOT", weight: item.weight, isRequired: Boolean(item.required), position: index + 1 })) }, "Checklist toko berhasil dibuat.", () => form.reset());
};

const allModules = computed(() => tree.value.flatMap((course) => (course.modules || []).map((module) => ({ ...module, courseName: course.name }))));
</script>

<template>
  <main class="min-h-screen bg-[#FFFFFF] p-4 sm:p-8">
    <div class="mx-auto max-w-6xl">
      <p class="text-sm font-bold uppercase tracking-[0.16em] text-orange-600">Fase 4 · LMS Inti</p>
      <h2 class="mt-2 text-2xl font-extrabold text-slate-800">Struktur Pembelajaran</h2>
      <p class="mt-2 text-sm text-slate-500">Susun program sampai lesson, daftarkan kelas, lalu siapkan rubrik dan checklist yang dapat dipakai saat membuat tugas.</p>
      <div v-if="error" role="alert" class="mt-5 flex gap-2 rounded-xl bg-red-50 p-4 text-sm text-red-700"><AlertCircle aria-hidden="true" :size="18" />{{ error }}</div>
      <div v-if="message" role="status" class="mt-5 flex gap-2 rounded-xl bg-green-50 p-4 text-sm text-green-700"><CheckCircle2 aria-hidden="true" :size="18" />{{ message }}</div>
      <p v-if="loading" class="mt-8 font-semibold text-orange-600">Memuat struktur...</p>
      <template v-else>
        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <MiniForm title="1. Program" @submit="createProgram"><CurriculumInput name="code" label="Kode" placeholder="WIRAUSAHA" /><CurriculumInput name="name" label="Nama program" /><CurriculumInput name="description" label="Deskripsi" /></MiniForm>
          <MiniForm title="2. Course" @submit="createCourse"><CurriculumSelect name="programId" label="Program" optional :options="programs.map((item) => ({ value: item.id, label: item.name }))" /><CurriculumInput name="code" label="Kode" /><CurriculumInput name="name" label="Nama course" /><CurriculumInput name="description" label="Deskripsi" /></MiniForm>
          <MiniForm title="3. Modul" @submit="createModule"><CurriculumSelect name="courseId" label="Course" :options="courses.map((item) => ({ value: item.id, label: `${item.code} · ${item.name}` }))" /><CurriculumInput name="title" label="Judul modul" /><CurriculumInput name="description" label="Ringkasan" /></MiniForm>
          <MiniForm title="4. Lesson" @submit="createLesson"><CurriculumSelect name="moduleId" label="Modul" :options="allModules.map((item) => ({ value: item.id, label: `${item.courseName} · ${item.title}` }))" /><CurriculumInput name="title" label="Judul lesson" /><CurriculumInput name="summary" label="Ringkasan" /><CurriculumInput name="minutes" label="Durasi (menit)" type="number" /></MiniForm>
        </section>

        <section class="mt-6 grid gap-6 lg:grid-cols-[1fr_1.4fr]">
          <form @submit.prevent="enroll" class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm">
            <h3 class="flex items-center gap-2 font-bold text-slate-800"><Users aria-hidden="true" :size="19" class="text-orange-600" />Enrollment kelas</h3>
            <div class="mt-4 grid gap-3"><CurriculumSelect name="courseId" label="Course" :options="courses.map((item) => ({ value: item.id, label: item.name }))" /><CurriculumSelect name="classId" label="Kelas" :options="classes.map((item) => ({ value: item.id, label: `${item.code} · ${item.name}` }))" /></div>
            <button :disabled="saving" class="mt-4 flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-orange-600 px-4 text-sm font-bold text-white disabled:opacity-50"><Loader2 v-if="saving" aria-hidden="true" :size="17" class="animate-spin" />Daftarkan seluruh siswa aktif</button>
          </form>
          <div class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm">
            <h3 class="flex items-center gap-2 font-bold text-slate-800"><Layers3 aria-hidden="true" :size="19" class="text-orange-600" />Struktur saat ini</h3>
            <div class="mt-4 space-y-3"><p v-if="tree.length === 0" class="text-sm text-slate-500">Belum ada course.</p><template v-else><article v-for="course in tree" :key="course.id" class="rounded-xl bg-orange-50/60 p-4"><p class="text-xs font-bold text-orange-700">{{ course.program?.name || "Tanpa program" }} · {{ course.code }}</p><h4 class="font-bold text-slate-800">{{ course.name }}</h4><div class="mt-2 space-y-1 text-sm text-slate-600"><p v-for="module in course.modules" :key="module.id">• {{ module.title }} <span class="text-slate-400">({{ module.lessons.length }} lesson)</span></p></div></article></template></div>
          </div>
        </section>

        <section class="mt-6 grid gap-6 lg:grid-cols-2">
          <div class="space-y-4"><section class="rounded-2xl border border-violet-200 bg-violet-50 p-5"><h3 class="font-bold text-violet-950">Rubrik AI MGMP</h3><p class="mt-2 text-sm leading-6 text-violet-900">Buat tiga rubrik terpisah untuk KIK, Bahasa Inggris, dan Informatika. Pakai masing-masing pada tugas MGMP terkait agar nilai dan rekomendasi AI tidak tercampur.</p><button type="button" @click="createMgmpRubrics" :disabled="saving" class="mt-4 min-h-11 w-full rounded-xl bg-violet-700 px-4 text-sm font-bold text-white disabled:opacity-50">{{ saving ? "Menyimpan..." : "Buat 3 rubrik MGMP" }}</button></section><Builder title="Rubrik penilaian" name-label="Nama rubrik" v-model:items="rubricItems" @submit="createRubric" /></div>
          <Builder title="Checklist Student Store" name-label="Nama checklist" v-model:items="checklistItems" @submit="createChecklist" checklist />
        </section>
      </template>
    </div>
  </main>
</template>
