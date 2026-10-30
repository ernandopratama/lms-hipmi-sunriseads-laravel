<script setup lang="ts">
import { computed } from "vue";
import { BookOpen } from "@lucide/vue";
import CurriculumInput from "./CurriculumInput.vue";
import CurriculumSelect from "./CurriculumSelect.vue";
import type { BuilderItem } from "./types";

withDefaults(defineProps<{ title: string; nameLabel: string; checklist?: boolean }>(), { checklist: false });
const items = defineModel<BuilderItem[]>("items", { required: true });
const emit = defineEmits<{ submit: [event: Event] }>();

const total = computed(() => items.value.reduce((sum, item) => sum + item.weight, 0));
const setItem = (index: number, patch: Partial<BuilderItem>) => { items.value = items.value.map((value, itemIndex) => itemIndex === index ? { ...value, ...patch } : value); };
const platformOptions = ["SHOPEE", "TIKTOK", "TOKOPEDIA", "OTHER"].map((value) => ({ value, label: value }));
</script>

<template>
  <form @submit.prevent="emit('submit', $event)" class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm"><h3 class="flex items-center gap-2 font-bold text-slate-800"><BookOpen aria-hidden="true" :size="19" class="text-orange-600" />{{ title }}</h3><div class="mt-4 grid gap-3 sm:grid-cols-2"><CurriculumInput name="name" :label="nameLabel" /><CurriculumInput name="description" label="Deskripsi" /><template v-if="checklist"><CurriculumSelect name="platform" label="Platform" optional :options="platformOptions" /><CurriculumInput name="minimumProducts" label="Minimum produk" type="number" /></template></div><div class="mt-4 space-y-2"><div v-for="(item, index) in items" :key="index" class="grid grid-cols-[1fr_80px_auto] gap-2"><input :aria-label="`${title} item ${index + 1}`" :value="item.name" @input="setItem(index, { name: ($event.target as HTMLInputElement).value })" class="form-control" required /><input :aria-label="`Bobot item ${index + 1}`" type="number" :min="1" :max="100" :value="item.weight" @input="setItem(index, { weight: Number(($event.target as HTMLInputElement).value) })" class="form-control" /><label v-if="checklist" class="flex items-center gap-1 text-xs"><input type="checkbox" :checked="item.required" @change="setItem(index, { required: ($event.target as HTMLInputElement).checked })" />Wajib</label><button v-else type="button" aria-label="Hapus kriteria" @click="items = items.filter((_, itemIndex) => itemIndex !== index)" class="px-2 text-red-600">×</button></div></div><div class="mt-3 flex items-center justify-between"><button type="button" @click="items = [...items, { name: 'Kriteria baru', weight: 0, required: true }]" class="text-sm font-bold text-orange-600">+ Tambah item</button><span :class="['text-sm font-bold', total === 100 ? 'text-green-700' : 'text-red-600']">Total {{ total }}%</span></div><button :disabled="total !== 100" class="mt-4 min-h-11 w-full rounded-xl bg-orange-600 font-bold text-white disabled:bg-slate-300">Simpan {{ title }}</button></form>
</template>
