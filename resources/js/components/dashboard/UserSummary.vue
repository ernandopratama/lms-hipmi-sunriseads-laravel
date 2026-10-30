<script setup lang="ts">
import { computed } from "vue";
import type { SessionProfile } from "./types";

const props = defineProps<{ profile: SessionProfile; roleLabel: string }>();
const initial = computed(() => (props.profile.firstName || props.profile.email || "P").trim().charAt(0).toUpperCase());
const displayName = computed(() => [props.profile.firstName, props.profile.lastName].filter(Boolean).join(" ") || props.profile.email?.split("@")[0] || "Pengguna");
</script>

<template>
  <div class="flex min-w-0 items-center gap-3">
    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-orange-600 text-sm font-extrabold text-white">{{ initial }}</span>
    <span class="min-w-0">
      <span class="block truncate text-sm font-extrabold text-slate-900">{{ displayName }}</span>
      <span class="block truncate text-[11px] font-medium text-slate-500">{{ roleLabel }}</span>
    </span>
  </div>
</template>
