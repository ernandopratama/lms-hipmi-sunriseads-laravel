<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { AlertCircle, CheckCircle2, CircleQuestionMark, Loader2, Plus, Trash } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
type Course = { id: string; name: string; code: string };
type LearningCourse = Course & { modules: Array<{ id: string; title: string; lessons: Array<{ id: string; title: string }> }> };
type QuestionDraft = { prompt: string; explanation: string; options: string[]; correct: number };

const props = defineProps<{ courses: Course[]; tree: LearningCourse[] }>();

const MAX_QUESTIONS = 50;
const blankQuestion = (): QuestionDraft => ({ prompt: "", explanation: "", options: ["", ""], correct: 0 });

// Laravel validation errors first, then the generic message, then the fallback.
async function quizError(response: Response, fallback: string) {
  const payload = await response.json().catch(() => null);
  return (payload?.errors ? (Object.values(payload.errors)[0] as any)?.[0] : null) || payload?.message || fallback;
}

const courseId = ref(props.courses[0]?.id ?? "");
const lessons = computed(() => props.tree.find((course) => course.id === courseId.value)?.modules.flatMap((module) => module.lessons.map((lesson) => ({ ...lesson, moduleTitle: module.title }))) ?? []);
const lessonId = ref("");
const title = ref("");
const passPercent = ref("");
const maxAttempts = ref(3);
const isRequired = ref(true);
const isActive = ref(true);
const questions = ref<QuestionDraft[]>([blankQuestion()]);
const exists = ref(false);
const attemptCount = ref(0);
const loading = ref(false);
const saving = ref(false);
const error = ref("");
const success = ref("");
const locked = computed(() => attemptCount.value > 0);

const reset = () => {
  title.value = "";
  passPercent.value = "";
  maxAttempts.value = 3;
  isRequired.value = true;
  isActive.value = true;
  questions.value = [blankQuestion()];
  exists.value = false;
  attemptCount.value = 0;
};

async function loadQuiz(id: string) {
  if (!id) return reset();
  loading.value = true;
  error.value = "";
  try {
    const response = await fetch(`/api/backend/learning/lessons/${id}/quiz`, { credentials: "include", cache: "no-store" });
    if (!response.ok) throw new Error(await quizError(response, "Tanya jawab belum dapat dimuat"));
    const data = await response.json();
    attemptCount.value = data.attemptCount ?? 0;
    if (!data.quiz) return reset();
    exists.value = true;
    title.value = data.quiz.title;
    passPercent.value = data.quiz.passPercent ? String(data.quiz.passPercent) : "";
    maxAttempts.value = data.quiz.maxAttempts;
    isRequired.value = data.quiz.isRequired;
    isActive.value = data.quiz.isActive;
    questions.value = data.quiz.questions.map((question: any) => ({
      prompt: question.prompt,
      explanation: question.explanation ?? "",
      options: question.options.map((option: any) => option.label),
      correct: Math.max(0, question.options.findIndex((option: any) => option.isCorrect)),
    }));
  } catch (caught) {
    reset();
    error.value = caught instanceof Error ? caught.message : "Tanya jawab belum dapat dimuat";
  } finally {
    loading.value = false;
  }
}

watch(lessonId, (id) => { void loadQuiz(id); }, { immediate: true });

const updateQuestion = (index: number, patch: Partial<QuestionDraft>) => {
  questions.value = questions.value.map((question, current) => (current === index ? { ...question, ...patch } : question));
};

function changeCourse(event: Event) {
  courseId.value = (event.target as HTMLSelectElement).value;
  lessonId.value = "";
}

function changeMaxAttempts(event: Event) {
  const target = event.target as HTMLInputElement;
  maxAttempts.value = Math.max(1, Math.min(10, Number(target.value) || 1));
  // React re-applies the controlled value even when the clamped state did not change.
  target.value = String(maxAttempts.value);
}

const saveQuiz = async () => {
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    const response = await csrfFetch(`/api/backend/learning/lessons/${lessonId.value}/quiz`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        title: title.value,
        passPercent: passPercent.value ? Number(passPercent.value) : null,
        maxAttempts: maxAttempts.value,
        isRequired: isRequired.value,
        isActive: isActive.value,
        questions: questions.value.map((question) => ({
          prompt: question.prompt,
          explanation: question.explanation || undefined,
          options: question.options.map((label, index) => ({ label, isCorrect: index === question.correct })),
        })),
      }),
    });
    if (!response.ok) throw new Error(await quizError(response, "Tanya jawab gagal disimpan"));
    exists.value = true;
    success.value = "Tanya jawab berhasil disimpan.";
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Tanya jawab gagal disimpan";
  } finally {
    saving.value = false;
  }
};

const deleteQuiz = async () => {
  if (!window.confirm("Hapus tanya jawab untuk lesson ini?")) return;
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    const response = await csrfFetch(`/api/backend/learning/lessons/${lessonId.value}/quiz`, { method: "DELETE" });
    if (!response.ok) throw new Error(await quizError(response, "Tanya jawab gagal dihapus"));
    reset();
    success.value = "Tanya jawab dihapus.";
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Tanya jawab gagal dihapus";
  } finally {
    saving.value = false;
  }
};

const invalid = computed(() => !lessonId.value || !title.value.trim() || questions.value.some((question) => !question.prompt.trim() || question.options.some((option) => !option.trim())));
</script>

<template>
  <form @submit.prevent="saveQuiz" class="space-y-5 rounded-2xl border border-[#DDEBE8] bg-white p-5 shadow-sm">
    <h3 class="flex items-center gap-2 text-lg font-bold text-[#1F2937]"><CircleQuestionMark aria-hidden="true" :size="20" class="text-orange-600" /> Tanya Jawab per Lesson</h3>
    <div class="grid gap-4 sm:grid-cols-2">
      <label class="block text-xs font-bold text-[#1F2937]">Mata pelajaran<select :value="courseId" @change="changeCourse" class="form-control mt-1"><option v-for="course in courses" :key="course.id" :value="course.id">{{ course.code }} · {{ course.name }}</option></select></label>
      <label class="block text-xs font-bold text-[#1F2937]">Lesson<select v-model="lessonId" class="form-control mt-1"><option value="">Pilih lesson</option><option v-for="lesson in lessons" :key="lesson.id" :value="lesson.id">{{ lesson.moduleTitle }} · {{ lesson.title }}</option></select></label>
    </div>
    <div v-if="error" role="alert" class="flex gap-2 rounded-xl bg-red-50 p-3 text-sm text-red-700"><AlertCircle aria-hidden="true" :size="18" />{{ error }}</div>
    <div v-if="success" role="status" class="flex gap-2 rounded-xl bg-green-50 p-3 text-sm text-green-700"><CheckCircle2 aria-hidden="true" :size="18" />{{ success }}</div>
    <p v-if="loading" class="text-sm font-semibold text-orange-600">Memuat…</p>
    <p v-if="locked" role="status" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{{ attemptCount }} percobaan siswa sudah tercatat, jadi soal tidak dapat diubah atau dihapus.</p>
    <fieldset v-if="lessonId && !loading" :disabled="locked || saving" class="space-y-5">
      <div class="grid gap-4 sm:grid-cols-3">
        <label class="block text-xs font-bold text-[#1F2937] sm:col-span-3">Judul<input required minlength="3" maxlength="160" v-model="title" class="form-control mt-1" placeholder="Contoh: Tanya jawab dasar merek" /></label>
        <label class="block text-xs font-bold text-[#1F2937]">Nilai minimal (%)<input type="number" :min="1" :max="100" :value="passPercent" @input="passPercent = ($event.target as HTMLInputElement).value" class="form-control mt-1" placeholder="Kosong = cukup mengirim" /></label>
        <label class="block text-xs font-bold text-[#1F2937]">Maks. percobaan<input type="number" :min="1" :max="10" :value="maxAttempts" @input="changeMaxAttempts" class="form-control mt-1" /></label>
        <div class="space-y-2 text-sm text-slate-700">
          <label class="flex items-center gap-2"><input type="checkbox" v-model="isRequired" class="size-4" /> Wajib untuk selesai</label>
          <label class="flex items-center gap-2"><input type="checkbox" v-model="isActive" class="size-4" /> Aktif untuk siswa</label>
        </div>
      </div>
      <section v-for="(question, index) in questions" :key="index" class="space-y-3 rounded-xl border border-slate-200 p-4" :aria-label="`Pertanyaan ${index + 1}`">
        <div class="flex items-center justify-between gap-2">
          <p class="text-sm font-bold text-slate-800">Pertanyaan {{ index + 1 }}</p>
          <button v-if="questions.length > 1" type="button" @click="questions = questions.filter((_, current) => current !== index)" :aria-label="`Hapus pertanyaan ${index + 1}`" class="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-600"><Trash aria-hidden="true" :size="16" /></button>
        </div>
        <textarea required minlength="3" maxlength="1000" :value="question.prompt" @input="updateQuestion(index, { prompt: ($event.target as HTMLTextAreaElement).value })" class="form-control min-h-20 resize-y" placeholder="Tulis pertanyaan…" :aria-label="`Teks pertanyaan ${index + 1}`" />
        <div class="space-y-2" role="radiogroup" :aria-label="`Jawaban benar pertanyaan ${index + 1}`">
          <div v-for="(option, optionIndex) in question.options" :key="optionIndex" class="flex items-center gap-2">
            <input type="radio" :name="`correct-${index}`" :checked="question.correct === optionIndex" @change="updateQuestion(index, { correct: optionIndex })" :aria-label="`Jawaban benar: pilihan ${optionIndex + 1}`" class="size-4 shrink-0" />
            <input required maxlength="500" :value="option" @input="updateQuestion(index, { options: question.options.map((value, current) => (current === optionIndex ? ($event.target as HTMLInputElement).value : value)) })" class="form-control" :placeholder="`Pilihan ${optionIndex + 1}`" :aria-label="`Pilihan ${optionIndex + 1} pertanyaan ${index + 1}`" />
            <button v-if="question.options.length > 2" type="button" @click="updateQuestion(index, { options: question.options.filter((_, current) => current !== optionIndex), correct: question.correct === optionIndex ? 0 : question.correct > optionIndex ? question.correct - 1 : question.correct })" :aria-label="`Hapus pilihan ${optionIndex + 1}`" class="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-600"><Trash aria-hidden="true" :size="16" /></button>
          </div>
          <button v-if="question.options.length < 6" type="button" @click="updateQuestion(index, { options: [...question.options, ''] })" class="text-xs font-bold text-orange-600 hover:underline">+ Tambah pilihan</button>
        </div>
        <input maxlength="1000" :value="question.explanation" @input="updateQuestion(index, { explanation: ($event.target as HTMLInputElement).value })" class="form-control" placeholder="Penjelasan jawaban (opsional, tampil setelah siswa selesai)" :aria-label="`Penjelasan pertanyaan ${index + 1}`" />
      </section>
      <button v-if="questions.length < MAX_QUESTIONS" type="button" @click="questions = [...questions, blankQuestion()]" class="flex min-h-11 items-center gap-2 rounded-xl border border-orange-300 px-4 text-sm font-bold text-orange-700 hover:bg-orange-50"><Plus aria-hidden="true" :size="16" /> Tambah pertanyaan</button>
      <div class="flex flex-wrap gap-3">
        <button :disabled="saving || invalid" class="bg-sunrise flex min-h-12 items-center justify-center gap-2 rounded-xl px-6 font-bold text-white disabled:opacity-50"><Loader2 v-if="saving" aria-hidden="true" class="animate-spin" :size="18" /> {{ exists ? "Perbarui Tanya Jawab" : "Simpan Tanya Jawab" }}</button>
        <button v-if="exists" type="button" @click="deleteQuiz" class="min-h-12 rounded-xl border border-red-300 px-5 text-sm font-bold text-red-600 hover:bg-red-50">Hapus</button>
      </div>
    </fieldset>
  </form>
</template>
