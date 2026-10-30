<script setup lang="ts">
import { onMounted, ref } from "vue";
import { Clock, Eye } from "@lucide/vue";
import DashLink from "@/components/dashboard/DashLink.vue";

/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
const submissions = ref<any[]>([]);
const loading = ref(true);

onMounted(async () => {
  try {
    const res = await fetch("/api/backend/submissions?status=SUBMITTED");
    const data = await res.json();
    submissions.value = data.data || [];
  } catch (err) {
    console.error("Gagal ambil antrean review", err);
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="p-8 bg-[#FFFFFF] min-h-screen">
    <h2 class="text-2xl font-bold text-[#1F2937] mb-6">Antrean Penilaian Tugas</h2>

    <div v-if="loading" class="text-[#377C78] font-semibold">Memuat antrean...</div>
    <div v-else class="grid gap-4">
      <div v-if="submissions.length === 0" class="p-8 bg-white border rounded-xl text-center text-[#64748B]">
        Tidak ada tugas yang menunggu penilaian.
      </div>
      <template v-else><div v-for="sub in submissions" :key="sub.id" class="p-6 bg-white rounded-xl border border-[#DDEBE8] shadow-sm flex items-center justify-between">
        <div>
          <h3 class="font-bold text-lg text-[#1F2937]">{{ sub.assignment.title }}</h3>
          <p class="text-sm text-[#64748B] mt-1">Siswa: {{ sub.student.fullName }} ({{ sub.student.studentNumber }})</p>
          <div class="flex gap-4 mt-3 text-xs text-[#286864] font-semibold">
            <span class="flex items-center gap-1"><Clock aria-hidden="true" :size="14" /> Dikumpul: {{ new Date(sub.createdAt).toLocaleDateString("id-ID") }}</span>
          </div>
        </div>
        <DashLink :to="`/dashboard/review/${sub.id}`" class="px-5 py-2.5 bg-[#377C78] text-white rounded-lg font-semibold hover:bg-[#286864] transition-colors flex items-center gap-2">
          <Eye aria-hidden="true" :size="18" /> Review
        </DashLink>
      </div></template>
    </div>
  </div>
</template>
