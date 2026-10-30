<script setup lang="ts">
import { onMounted, ref } from "vue";
import { csrfFetch } from "@/lib/csrf";

const choice = ref<string | null>(null);

function trackPageView() {
  csrfFetch("/api/backend/public/analytics/events", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ eventName: "PAGE_VIEW", consentGranted: true, properties: { path: window.location.pathname } }) }).catch(() => undefined);
}

onMounted(() => {
  choice.value = localStorage.getItem("sunrise-analytics-consent");
  if (choice.value === "granted") trackPageView();
});

function decide(value: "granted" | "denied") {
  localStorage.setItem("sunrise-analytics-consent", value);
  choice.value = value;
  if (value === "granted") trackPageView();
}
</script>

<template>
  <aside v-if="!choice" class="fixed bottom-4 left-4 right-4 z-50 mx-auto max-w-2xl rounded-xl border border-[#e5e5e5] bg-white p-5 shadow-2xl" aria-label="Pilihan analitik"><p class="font-spartan text-base font-bold text-[#181818]">Bantu kami memperbaiki pengalaman</p><p class="mt-1 text-xs leading-5 text-slate-600">Analitik opsional hanya mencatat interaksi umum tanpa kata sandi atau nomor identitas. Anda dapat menolak dan tetap memakai situs.</p><div class="mt-4 flex gap-3"><button @click="decide('granted')" class="btn-one !px-5 !py-3 text-sm">Izinkan analitik</button><button @click="decide('denied')" class="btn-outline !px-5 !py-3 text-sm">Tolak</button></div></aside>
</template>
