<script setup lang="ts">
import { ChevronRight } from "@lucide/vue";
import DashLink from "./DashLink.vue";
import { isActiveRoute } from "./route-copy";
import type { NavGroup } from "./types";

defineProps<{ groups: NavGroup[]; pathname: string }>();
defineEmits<{ navigate: [] }>();

const groupId = (label: string) => `nav-${label.toLowerCase().replaceAll(" ", "-")}`;
</script>

<template>
  <nav aria-label="Navigasi utama" class="space-y-6">
    <section v-for="group in groups" :key="group.label" :aria-labelledby="groupId(group.label)">
      <h2 :id="groupId(group.label)" class="mb-2 px-3 text-[10px] font-extrabold uppercase tracking-[0.16em] text-slate-400">{{ group.label }}</h2>
      <div class="space-y-1">
        <DashLink
          v-for="item in group.items"
          :key="item.href"
          :to="item.href"
          :aria-current="isActiveRoute(pathname, item.href) ? 'page' : undefined"
          @click="$emit('navigate')"
          :class="['group flex min-h-12 items-center gap-3 rounded-full px-3 py-2.5 transition', isActiveRoute(pathname, item.href) ? 'bg-orange-50 text-orange-800 ring-1 ring-inset ring-orange-200' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']"
        >
          <span :class="['grid size-9 shrink-0 place-items-center rounded-full transition', isActiveRoute(pathname, item.href) ? 'bg-orange-600 text-white' : 'bg-slate-100 text-slate-500 group-hover:bg-white']">
            <component :is="item.icon" class="size-[18px]" aria-hidden="true" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-bold leading-5">{{ item.label }}</span>
            <span :class="['block truncate text-[11px] leading-4', isActiveRoute(pathname, item.href) ? 'text-orange-700' : 'text-slate-400']">{{ item.description }}</span>
          </span>
          <ChevronRight v-if="isActiveRoute(pathname, item.href)" class="size-4 shrink-0 text-orange-600" aria-hidden="true" />
        </DashLink>
      </div>
    </section>
  </nav>
</template>
