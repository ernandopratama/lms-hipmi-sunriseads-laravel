<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { Bell, Download, Moon, Sun, Wifi, WifiOff } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";
import DashLink from "@/components/dashboard/DashLink.vue";

type InstallPromptEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: "accepted" | "dismissed" }> };

const online = ref(true);
const unread = ref(0);
const dark = ref(false);
const installPrompt = ref<InstallPromptEvent | null>(null);

function updateOnline() {
  online.value = navigator.onLine;
}

function handleInstall(event: Event) {
  event.preventDefault();
  installPrompt.value = event as InstallPromptEvent;
}

onMounted(() => {
  const savedTheme = localStorage.getItem("sunrise-theme");
  const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
  const shouldUseDark = savedTheme === "dark" || (!savedTheme && prefersDark);
  document.documentElement.classList.toggle("dark", shouldUseDark);
  dark.value = shouldUseDark;
  online.value = navigator.onLine;
  window.addEventListener("online", updateOnline);
  window.addEventListener("offline", updateOnline);
  window.addEventListener("beforeinstallprompt", handleInstall);
  if ("serviceWorker" in navigator) {
    if (import.meta.env.DEV) {
      navigator.serviceWorker.getRegistrations()
        .then((registrations) => Promise.all(registrations.map((registration) => registration.unregister())))
        .catch(() => undefined);
      if ("caches" in window) {
        caches.keys()
          .then((keys) => Promise.all(keys.filter((key) => key.startsWith("sunrise-public-")).map((key) => caches.delete(key))))
          .catch(() => undefined);
      }
    } else {
      navigator.serviceWorker.register("/sw.js").catch(() => undefined);
    }
  }
  fetch("/api/backend/support/notifications")
    .then((response) => response.ok ? response.json() : [])
    .then((items: Array<{ readAt?: string | null }>) => { unread.value = items.filter((item) => !item.readAt).length; })
    .catch(() => undefined);
});

onUnmounted(() => {
  window.removeEventListener("online", updateOnline);
  window.removeEventListener("offline", updateOnline);
  window.removeEventListener("beforeinstallprompt", handleInstall);
});

function toggleTheme() {
  dark.value = !dark.value;
  document.documentElement.classList.toggle("dark", dark.value);
  localStorage.setItem("sunrise-theme", dark.value ? "dark" : "light");
}

async function install() {
  if (!installPrompt.value) return;
  await installPrompt.value.prompt();
  const choice = await installPrompt.value.userChoice;
  if (choice.outcome === "accepted") {
    installPrompt.value = null;
    await csrfFetch("/api/backend/support/analytics/events", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ eventName: "PWA_INSTALLED", consentGranted: localStorage.getItem("sunrise-analytics-consent") === "granted" }) }).catch(() => undefined);
  }
}
</script>

<template>
  <div class="flex items-center gap-1">
    <span :title="online ? 'Terhubung' : 'Sedang offline'" :class="['hidden items-center gap-1 rounded-full px-2 py-1 text-xs font-bold md:flex', online ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800']"><Wifi v-if="online" aria-hidden="true" :size="14" /><WifiOff v-else aria-hidden="true" :size="14" />{{ online ? "Online" : "Offline" }}</span>
    <button v-if="installPrompt" type="button" @click="install" aria-label="Pasang aplikasi" title="Pasang aplikasi" class="flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 hover:bg-orange-50"><Download aria-hidden="true" :size="18" /></button>
    <DashLink to="/dashboard/dukungan" :aria-label="`${unread} notifikasi belum dibaca`" class="relative flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 hover:bg-orange-50"><Bell aria-hidden="true" :size="19" /><span v-if="unread > 0" class="absolute right-1 top-1 min-w-4 rounded-full bg-red-600 px-1 text-center text-[10px] font-bold text-white">{{ Math.min(unread, 99) }}</span></DashLink>
    <button type="button" @click="toggleTheme" :aria-label="dark ? 'Gunakan tema terang' : 'Gunakan tema gelap'" class="flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 hover:bg-orange-50"><Sun v-if="dark" aria-hidden="true" :size="19" /><Moon v-else aria-hidden="true" :size="19" /></button>
  </div>
</template>
