<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { CheckCircle, AlertCircle, ArrowLeft } from "@lucide/vue";
import DashLink from "@/components/dashboard/DashLink.vue";
import { csrfFetch } from "@/lib/csrf";

/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
const id = String(useRoute().params.id);
const submission = ref<any>(null);
const score = ref<number>(100);
const feedback = ref("");
const rubricScores = ref<Record<string, number>>({});
const status = ref<"APPROVED" | "REVISION_REQUIRED" | "REJECTED">("APPROVED");
const aiBusy = ref(false);
const aiAssessment = ref<any>(null);

const loading = ref(true);
const submitting = ref(false);
const error = ref("");
const success = ref(false);

async function fetchSubmission() {
  try {
    const res = await fetch(`/api/backend/submissions/${id}`);
    const data = await res.json();
    submission.value = data;
    const latestAiAssessment = data.aiAssessments?.[0] || null;
    aiAssessment.value = latestAiAssessment;
    if (latestAiAssessment) {
      score.value = latestAiAssessment.totalScore;
      feedback.value = latestAiAssessment.feedback || "";
      rubricScores.value = Object.fromEntries(latestAiAssessment.scores.map((item: any) => [item.criterionId, item.score]));
    } else {
      if (data.score) score.value = data.score;
      if (data.feedback) feedback.value = data.feedback;
      if (data.assignment?.rubric?.criteria?.length) rubricScores.value = Object.fromEntries(data.assignment.rubric.criteria.map((criterion: any) => [criterion.id, criterion.maxScore]));
    }
  } catch (err) {
    console.error("Gagal ambil detail submission", err);
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  fetchSubmission();
});

const createAiRecommendation = async () => {
  aiBusy.value = true; error.value = "";
  try {
    const res = await csrfFetch(`/api/backend/submissions/${id}/ai-assessment`, { method: "POST" });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.message || "Rekomendasi AI belum dapat dibuat");
    aiAssessment.value = data;
    score.value = data.totalScore;
    feedback.value = data.feedback || "";
    rubricScores.value = Object.fromEntries(data.scores.map((item: any) => [item.criterionId, item.score]));
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Rekomendasi AI belum dapat dibuat";
  } finally { aiBusy.value = false; }
};

const handleSubmit = async () => {
  submitting.value = true;
  error.value = "";
  success.value = false;
  try {
    const res = await csrfFetch(`/api/backend/submissions/${id}/review`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        score: Number(score.value), feedback: feedback.value, status: status.value,
        ...(submission.value.assignment.rubric?.criteria?.length ? { scores: submission.value.assignment.rubric.criteria.map((criterion: any) => ({ criterionId: criterion.id, score: Number(rubricScores.value[criterion.id] || 0) })) } : {}),
      }),
    });

    if (!res.ok) throw new Error("Gagal menyimpan penilaian");
    success.value = true;
    setTimeout(() => {
      window.location.replace("/dashboard/review");
    }, 1500);
  } catch (err: any) {
    error.value = err.message;
  } finally {
    submitting.value = false;
  }
};
</script>

<template>
  <div v-if="loading" class="p-8 bg-[#FFFFFF] min-h-screen">
    <div class="text-[#377C78] font-semibold">Memuat detail tugas...</div>
  </div>
  <div v-else-if="!submission" class="p-8 text-red-600">Submission tidak ditemukan</div>
  <div v-else class="p-8 bg-[#FFFFFF] min-h-screen max-w-4xl">
    <DashLink to="/dashboard/review" class="flex items-center gap-2 text-sm text-[#64748B] hover:text-[#1F2937] mb-6"><ArrowLeft aria-hidden="true" :size="16" /> Kembali ke Antrean</DashLink>

    <div class="grid gap-6 md:grid-cols-3">
      <!-- Detail Pengumpulan -->
      <div class="md:col-span-2 space-y-6">
        <div class="bg-white rounded-xl border border-[#DDEBE8] p-6 shadow-sm">
          <h2 class="text-xl font-bold text-[#1F2937] mb-2">{{ submission.assignment.title }}</h2>
          <p class="text-sm text-[#64748B] mb-4">Siswa: {{ submission.student.fullName }}</p>

          <div class="border-t pt-4 space-y-3">
            <h4 class="font-semibold text-sm text-[#1F2937]">Detail Video Affiliate:</h4>
            <div v-for="v in submission.affiliateVideos" :key="v.id" class="p-4 bg-[#FFFFFF] rounded-lg border border-[#DDEBE8] text-sm space-y-2">
              <p>Platform: <span class="font-bold">{{ v.platform }}</span></p>
              <p>URL Video: <a :href="v.videoUrl" target="_blank" rel="noopener noreferrer" class="text-[#377C78] hover:underline break-all">{{ v.videoUrl }}</a></p>
              <p>Produk: <span class="font-semibold">{{ v.productName || "-" }}</span></p>
              <p v-if="v.productUrl">Link Produk: <a :href="v.productUrl" target="_blank" rel="noopener noreferrer" class="text-[#377C78] hover:underline break-all">{{ v.productUrl }}</a></p>
              <p v-if="v.campaignName">Campaign: <span class="font-semibold">{{ v.campaignName }}</span></p>
              <p v-if="v.hook">Hook: <span class="font-semibold">{{ v.hook }}</span></p>
              <p v-if="v.caption">Caption: <span class="font-semibold">{{ v.caption }}</span></p>
              <p v-if="v.evidenceUrl">Bukti publikasi: <a :href="v.evidenceUrl" target="_blank" rel="noopener noreferrer" class="text-[#377C78] hover:underline break-all">Buka bukti</a></p>
              <p v-if="v.insightsEvidenceUrl">Bukti insight: <a :href="v.insightsEvidenceUrl" target="_blank" rel="noopener noreferrer" class="text-[#377C78] hover:underline break-all">Buka insight</a></p>
              <p>Versi konten: <span class="font-semibold">{{ v.currentVersion }}</span> · Status bukti: <span class="font-semibold">{{ v.verification }}</span></p>
              <div v-if="v.metricSnapshots?.length > 0" class="mt-3 rounded-lg bg-white p-3"><p class="font-semibold">Snapshot metrik terbaru</p><p v-for="metric in v.metricSnapshots" :key="metric.id" class="mt-1 text-xs">{{ new Date(metric.snapshotDate).toLocaleDateString("id-ID") }}: {{ metric.views }} views · {{ metric.clicks }} klik · {{ metric.orders }} pesanan · {{ metric.verification }}</p></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Form Penilaian -->
      <div class="bg-white rounded-xl border border-[#DDEBE8] p-6 shadow-sm h-fit">
        <h3 class="font-bold text-lg text-[#1F2937] mb-4">Penilaian Guru</h3>

        <div v-if="success" class="p-3 bg-green-50 rounded-lg flex items-center gap-2 text-sm text-green-600 mb-4">
          <CheckCircle aria-hidden="true" :size="18" /> <span>Penilaian berhasil disimpan!</span>
        </div>
        <div v-if="error" class="p-3 bg-red-50 rounded-lg flex items-center gap-2 text-sm text-red-600 mb-4">
          <AlertCircle aria-hidden="true" :size="18" /> <span>{{ error }}</span>
        </div>

        <section class="mb-5 rounded-lg border border-violet-200 bg-violet-50 p-3 text-sm text-violet-950">
          <div class="flex items-center justify-between gap-3"><div><p class="font-bold">Rekomendasi AI</p><p class="mt-1 text-xs text-violet-800">AI menilai isi teks sesuai rubrik. Guru tetap menentukan nilai resmi.</p></div><button type="button" @click="createAiRecommendation" :disabled="aiBusy" class="shrink-0 rounded-lg bg-violet-700 px-3 py-2 text-xs font-bold text-white disabled:opacity-50">{{ aiBusy ? "Menilai..." : "Buat rekomendasi AI" }}</button></div>
          <div v-if="aiAssessment" class="mt-3 border-t border-violet-200 pt-3"><p class="font-semibold">Rekomendasi: {{ aiAssessment.totalScore }}/{{ submission.assignment.maxScore }}</p><p v-if="aiAssessment.insufficientEvidence" class="mt-1 text-xs font-semibold text-amber-800">Bukti teks belum cukup — periksa file atau tautan secara manual.</p><ul class="mt-2 space-y-1 text-xs"><li v-for="item in aiAssessment.scores" :key="item.id">• {{ item.criterion.name }}: {{ item.score }}/{{ item.criterion.maxScore }} — {{ item.reason }}</li></ul><p class="mt-2 text-xs text-violet-900">{{ aiAssessment.feedback }}</p></div>
        </section>

        <form @submit.prevent="handleSubmit" class="space-y-4">
          <div>
            <label for="review-status" class="block text-xs font-semibold text-[#1F2937] mb-1">Status Kelulusan</label>
            <select
              id="review-status"
              class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none"
              v-model="status"
            >
              <option value="APPROVED">Setujui (Approved)</option>
              <option value="REVISION_REQUIRED">Butuh Revisi</option>
              <option value="REJECTED">Tolak (Rejected)</option>
            </select>
          </div>

          <div v-if="submission.assignment.rubric?.criteria?.length" class="space-y-3">
            <p class="text-xs font-semibold text-[#1F2937]">Rubrik: {{ submission.assignment.rubric.name }}</p>
            <label v-for="criterion in submission.assignment.rubric.criteria" :key="criterion.id" class="block text-xs font-semibold text-[#1F2937]">{{ criterion.name }} <span class="font-normal text-slate-500">({{ criterion.weight }}%)</span><input type="number" :min="0" :max="criterion.maxScore" required :value="rubricScores[criterion.id] ?? 0" @input="rubricScores = { ...rubricScores, [criterion.id]: Number(($event.target as HTMLInputElement).value) }" class="mt-1 w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none" /></label>
          </div>
          <div v-else>
            <label for="review-score" class="block text-xs font-semibold text-[#1F2937] mb-1">Nilai Tugas (Max {{ submission.assignment.maxScore }})</label>
            <input
              id="review-score"
              type="number"
              :max="submission.assignment.maxScore"
              :min="0"
              required
              class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none"
              :value="score"
              @input="score = Number(($event.target as HTMLInputElement).value)"
            />
          </div>

          <div>
            <label for="review-feedback" class="block text-xs font-semibold text-[#1F2937] mb-1">Feedback Guru</label>
            <textarea
              id="review-feedback"
              required
              placeholder="Tulis umpan balik..."
              class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none h-24 resize-none"
              v-model="feedback"
            />
          </div>

          <button
            type="submit"
            :disabled="submitting || success"
            class="w-full py-2.5 bg-gradient-to-r from-[#FBBF24] to-[#377C78] text-white rounded-lg text-sm font-semibold hover:opacity-90 disabled:opacity-50"
          >{{ submitting ? "Menyimpan..." : "Simpan Nilai" }}</button>
        </form>
      </div>
    </div>
  </div>
</template>
