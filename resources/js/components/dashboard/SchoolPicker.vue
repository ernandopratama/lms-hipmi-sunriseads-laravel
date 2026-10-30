<script setup lang="ts">
import { computed } from "vue";
import { Building2, Loader2 } from "@lucide/vue";
import type { SchoolOption } from "./types";

const props = withDefaults(defineProps<{ schools: SchoolOption[]; selectedSchoolId: string | null; switching: boolean; compact?: boolean }>(), { compact: false });
defineEmits<{ select: [schoolId: string] }>();

const selected = computed(() => props.schools.find((school) => school.id === props.selectedSchoolId));
</script>

<template>
  <div v-if="schools.length <= 1 && selected" class="flex min-w-0 items-center gap-2 text-left">
    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-orange-100 text-orange-700"><Building2 :size="18" aria-hidden="true" /></span>
    <span class="min-w-0">
      <span v-if="!compact" class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Sekolah aktif</span>
      <span class="block truncate text-sm font-bold text-slate-800">{{ selected.name }}</span>
    </span>
  </div>
  <label v-else class="flex min-w-0 items-center gap-2 text-left">
    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-orange-100 text-orange-700">
      <Loader2 v-if="switching" class="animate-spin" :size="18" aria-hidden="true" />
      <Building2 v-else :size="18" aria-hidden="true" />
    </span>
    <span class="min-w-0 flex-1">
      <span v-if="!compact" class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Sekolah aktif</span>
      <select
        aria-label="Pilih sekolah aktif"
        :value="selectedSchoolId || ''"
        :disabled="switching || schools.length === 0"
        @change="$emit('select', ($event.target as HTMLSelectElement).value)"
        class="min-h-9 w-full max-w-64 rounded-lg border border-orange-200 bg-white px-2 text-sm font-bold text-slate-800 outline-none focus:border-orange-500 disabled:opacity-60"
      >
        <option value="" disabled>Pilih sekolah</option>
        <option v-for="school in schools" :key="school.id" :value="school.id">{{ school.name }} ({{ school.code }})</option>
      </select>
    </span>
  </label>
</template>
