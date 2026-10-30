<script setup lang="ts">
import { Building2, Loader2 } from "@lucide/vue";
import type { SchoolOption } from "./types";

defineProps<{ schools: SchoolOption[]; switching: boolean; error: string }>();
defineEmits<{ select: [schoolId: string] }>();
</script>

<template>
  <main class="grid min-h-[calc(100vh-3.5rem)] place-items-center p-4 sm:p-8">
    <section class="w-full max-w-3xl rounded-[28px] border border-orange-100 bg-white p-6 shadow-sm sm:p-9">
      <span class="grid size-12 place-items-center rounded-2xl bg-orange-100 text-orange-700"><Building2 :size="24" aria-hidden="true" /></span>
      <p class="mt-6 text-sm font-extrabold uppercase tracking-[0.16em] text-orange-600">Konteks sekolah</p>
      <h1 class="mt-2 text-3xl font-extrabold tracking-[-0.03em] text-slate-900">Pilih sekolah untuk melanjutkan</h1>
      <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
        Akun Anda terhubung ke beberapa sekolah. Data dashboard, siswa, guru,
        kelas, dan laporan akan mengikuti sekolah yang dipilih.
      </p>

      <p v-if="error" role="alert" class="mt-5 rounded-xl bg-red-50 p-4 text-sm font-semibold text-red-700">{{ error }}</p>

      <div class="mt-7 grid gap-3 sm:grid-cols-2">
        <button
          v-for="school in schools"
          :key="school.id"
          type="button"
          :disabled="switching"
          @click="$emit('select', school.id)"
          class="group flex min-h-28 items-start gap-4 rounded-2xl border border-slate-200 p-4 text-left transition hover:border-orange-300 hover:bg-orange-50 disabled:opacity-60"
        >
          <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-orange-100 text-orange-700 group-hover:bg-orange-200">
            <Loader2 v-if="switching" aria-hidden="true" class="animate-spin" :size="19" />
            <Building2 v-else aria-hidden="true" :size="19" />
          </span>
          <span>
            <span class="block font-extrabold text-slate-900">{{ school.name }}</span>
            <span class="mt-1 block text-xs font-semibold text-slate-500">{{ school.code }} · {{ school.level }} · {{ school.status }}</span>
            <span v-if="[school.city, school.province].filter(Boolean).length" class="mt-2 block text-xs text-slate-500">{{ [school.city, school.province].filter(Boolean).join(", ") }}</span>
          </span>
        </button>
      </div>
    </section>
  </main>
</template>
