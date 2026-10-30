<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { CheckCircle, X } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
const props = defineProps<{
  lessonId: string;
  lessonTitle: string;
  onSnapshot: (snapshot: any) => void;
  onClose: () => void;
}>();

const quiz = ref<any>(null);
const attempts = ref<any[]>([]);
const attemptsLeft = ref(0);
const answers = ref<Record<string, string>>({});
const results = ref<any[] | null>(null);
const outcome = ref<{ scorePercent: number; passed: boolean } | null>(null);
const loading = ref(true);
const submitting = ref(false);
const error = ref("");

async function loadQuiz() {
  loading.value = true;
  error.value = "";
  try {
    const response = await fetch(`/api/backend/learning/lessons/${props.lessonId}/quiz`, { credentials: "include", cache: "no-store" });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || "Tanya jawab tidak dapat dimuat.");
    quiz.value = data.quiz;
    attempts.value = data.attempts ?? [];
    attemptsLeft.value = data.attemptsLeft ?? 0;
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Tanya jawab tidak dapat dimuat.";
  } finally {
    loading.value = false;
  }
}

watch(() => props.lessonId, () => { void loadQuiz(); }, { immediate: true });

const closeOnEscape = (event: KeyboardEvent) => {
  if (event.key === "Escape") props.onClose();
};
onMounted(() => window.addEventListener("keydown", closeOnEscape));
onUnmounted(() => window.removeEventListener("keydown", closeOnEscape));

const alreadyPassed = computed(() => attempts.value.some((attempt) => attempt.passed));
const canAnswer = computed(() => !!quiz.value && !alreadyPassed.value && attemptsLeft.value > 0 && !results.value);
const allAnswered = computed(() => !!quiz.value && quiz.value.questions.every((question: any) => answers.value[question.id]));

const submitAnswers = async () => {
  if (!quiz.value || !allAnswered.value || submitting.value) return;
  submitting.value = true;
  error.value = "";
  try {
    const response = await csrfFetch(`/api/backend/learning/quizzes/${quiz.value.id}/attempts`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ answers: quiz.value.questions.map((question: any) => ({ questionId: question.id, optionId: answers.value[question.id] })) }),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || "Jawaban gagal dikirim.");
    results.value = data.results;
    outcome.value = { scorePercent: data.scorePercent, passed: data.passed };
    attempts.value = [...attempts.value, { attemptNo: data.attemptNo, scorePercent: data.scorePercent, passed: data.passed }];
    attemptsLeft.value = data.attemptsLeft;
    props.onSnapshot(data.snapshot);
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Jawaban gagal dikirim.";
  } finally {
    submitting.value = false;
  }
};

const retry = async () => {
  results.value = null;
  outcome.value = null;
  answers.value = {};
  await loadQuiz();
};

const resultFor = (questionId: string) => results.value?.find((result) => result.questionId === questionId);
const hasAnswerKey = (question: any) => question.options.some((option: any) => option.isCorrect !== undefined);
const isMarkedCorrect = (question: any, option: any) => (hasAnswerKey(question) && option.isCorrect) || resultFor(question.id)?.correctOptionId === option.id;
</script>

<template>
  <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/80 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="quiz-title">
    <div class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
      <div class="flex items-start justify-between gap-3 border-b border-slate-200 p-4">
        <div>
          <p class="text-xs font-bold uppercase text-orange-600">Tanya jawab · {{ lessonTitle }}</p>
          <h4 id="quiz-title" class="font-bold text-slate-800">{{ quiz?.title ?? "Tanya jawab" }}</h4>
        </div>
        <button type="button" @click="onClose()" aria-label="Tutup" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100"><X aria-hidden="true" :size="18" /></button>
      </div>
      <div class="space-y-4 overflow-y-auto p-4 text-sm">
        <p v-if="loading" class="text-slate-500">Memuat…</p>
        <p v-if="error" role="alert" class="font-semibold text-red-600">{{ error }}</p>
        <p v-if="!loading && !quiz && !error" class="text-slate-500">Belum ada tanya jawab untuk pelajaran ini.</p>
        <template v-if="quiz">
          <p class="text-xs text-slate-500">{{ quiz.passPercent ? `Nilai minimal ${quiz.passPercent}% untuk selesai.` : "Selesai setelah jawaban dikirim." }} Kesempatan tersisa: {{ attemptsLeft }} dari {{ quiz.maxAttempts }}.{{ attempts.length > 0 ? ` Nilai terbaik: ${Math.max(...attempts.map((attempt) => attempt.scorePercent))}%.` : "" }}</p>
          <p v-if="alreadyPassed && !results" class="flex items-center gap-2 rounded-lg bg-emerald-50 p-3 font-semibold text-emerald-700"><CheckCircle aria-hidden="true" :size="16" /> Tanya jawab sudah selesai.</p>
          <fieldset v-for="question in quiz.questions" :key="question.id" class="rounded-xl border border-slate-200 p-3" :disabled="!canAnswer">
            <legend class="px-1 font-semibold text-slate-800">{{ question.position }}. {{ question.prompt }}</legend>
            <div class="mt-2 space-y-1.5">
              <label v-for="option in question.options" :key="option.id" :class="['flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border px-3 py-2', answers[question.id] === option.id ? 'border-orange-500 bg-orange-50' : 'border-slate-200', isMarkedCorrect(question, option) ? 'ring-2 ring-emerald-400' : '']">
                <input type="radio" :name="question.id" :value="option.id" :checked="answers[question.id] === option.id" @change="answers = { ...answers, [question.id]: option.id }" />
                <span>{{ option.label }}</span>
                <span v-if="isMarkedCorrect(question, option)" class="ml-auto text-xs font-bold text-emerald-700">Jawaban benar</span>
              </label>
            </div>
            <p v-if="resultFor(question.id)" :class="['mt-2 text-xs font-semibold', resultFor(question.id).correct ? 'text-emerald-700' : 'text-red-600']">{{ resultFor(question.id).correct ? "Benar" : "Belum tepat" }}{{ resultFor(question.id).explanation ? ` — ${resultFor(question.id).explanation}` : "" }}</p>
          </fieldset>
        </template>
      </div>
      <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4">
        <span class="text-sm font-bold text-slate-700" aria-live="polite">{{ outcome ? `Nilai ${outcome.scorePercent}% · ${outcome.passed ? "Selesai" : "Belum mencapai nilai minimal"}` : "" }}</span>
        <button v-if="canAnswer" type="button" @click="submitAnswers" :disabled="!allAnswered || submitting" class="min-h-11 rounded-xl bg-orange-600 px-5 font-bold text-white disabled:opacity-50">{{ submitting ? "Mengirim…" : "Kirim jawaban" }}</button>
        <button v-if="results && !outcome?.passed && attemptsLeft > 0" type="button" @click="retry" class="min-h-11 rounded-xl bg-orange-600 px-5 font-bold text-white">Coba lagi</button>
      </div>
    </div>
  </div>
</template>
