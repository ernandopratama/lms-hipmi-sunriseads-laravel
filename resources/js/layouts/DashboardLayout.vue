<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useRoute } from "vue-router";
import {
  AlertTriangle, BadgeCheck, BookOpen, ChartNoAxesCombined, CheckSquare, CreditCard, Database, FileSpreadsheet,
  Headphones, LayoutDashboard, ListTree, LogOut, Menu, Scale, Settings, ShieldCheck, Store, UserRound, X,
} from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";
import BrandLogo from "@/components/BrandLogo.vue";
import PwaShell from "@/components/PwaShell.vue";
import DashLink from "@/components/dashboard/DashLink.vue";
import DashboardNavigation from "@/components/dashboard/DashboardNavigation.vue";
import SchoolPicker from "@/components/dashboard/SchoolPicker.vue";
import SchoolSelectionGate from "@/components/dashboard/SchoolSelectionGate.vue";
import UserSummary from "@/components/dashboard/UserSummary.vue";
import { isActiveRoute, roleCopy, routeCopy } from "@/components/dashboard/route-copy";
import type { NavGroup, NavItem, SchoolOption, SessionProfile } from "@/components/dashboard/types";

const route = useRoute();
const profile = ref<SessionProfile | null>(null);
const loading = ref(true);
const mobileMenuOpen = ref(false);
const schools = ref<SchoolOption[]>([]);
const selectedSchoolId = ref<string | null>(null);
const switchingSchool = ref(false);
const schoolError = ref("");

onMounted(() => {
  Promise.all([fetch("/api/session"), fetch("/api/backend/auth/schools")])
    .then(async ([sessionResponse, schoolResponse]) => {
      if (!sessionResponse.ok) throw new Error("Sesi tidak valid");
      const session = await sessionResponse.json() as SessionProfile;
      const schoolContext = schoolResponse.ok
        ? await schoolResponse.json()
        : { schools: [], selectedSchoolId: session.schoolId || null };
      return { session, schoolContext };
    })
    .then(({ session, schoolContext }) => {
      profile.value = session;
      schools.value = Array.isArray(schoolContext.schools) ? schoolContext.schools : [];
      selectedSchoolId.value = schoolContext.selectedSchoolId || session.schoolId || null;
      loading.value = false;
    })
    .catch(() => window.location.replace("/login"));
});

watch(() => route.path, () => { mobileMenuOpen.value = false; });

const roles = computed(() => Array.isArray(profile.value?.roles) ? profile.value.roles : []);
const permissions = computed(() => Array.isArray(profile.value?.permissions) ? profile.value.permissions : []);
const hasPermission = (resource: string, action: string) => roles.value.includes("super-admin") || permissions.value.some((permission) => permission.resource === resource && permission.action === action);

const navGroups = computed<NavGroup[]>(() => {
  const isStudent = roles.value.includes("student");
  const isTeacher = roles.value.includes("teacher");
  const canManageSchool = roles.value.some((role) => ["super-admin", "school-admin", "principal", "program-manager"].includes(role));
  const learning: NavItem[] = [];
  const institution: NavItem[] = [];

  if (isStudent || isTeacher) learning.push({ href: "/dashboard/tugas", label: "Materi & Tugas", description: isStudent ? "Belajar dan kumpulkan tugas" : "Kelola materi pembelajaran", icon: BookOpen });
  if (isTeacher) {
    learning.push(
      { href: "/dashboard/review", label: "Antrean Review", description: "Nilai tugas yang masuk", icon: CheckSquare },
      { href: "/dashboard/kurikulum", label: "Struktur & Rubrik", description: "Atur jalur dan penilaian", icon: ListTree },
      { href: "/dashboard/toko/review", label: "Review Toko", description: "Periksa praktik toko murid", icon: Store },
      { href: "/dashboard/affiliate/review", label: "Verifikasi Affiliate", description: "Validasi konten affiliate", icon: BadgeCheck },
    );
  }
  if (isStudent) {
    learning.push(
      { href: "/dashboard/toko", label: "Student Store", description: "Kelola praktik toko", icon: Store },
      { href: "/dashboard/affiliate", label: "Affiliate Saya", description: "Pantau konten dan hasil", icon: ChartNoAxesCombined },
      { href: "/dashboard/identitas", label: "Identitas Saya", description: "Lengkapi data dengan aman", icon: UserRound },
    );
  }

  if (canManageSchool) institution.push({ href: "/dashboard/master-data", label: "Data Sekolah", description: "Murid, guru, kelas, dan pengguna", icon: Database });
  if (hasPermission("identity", "verify")) institution.push({ href: "/dashboard/identitas/verifikasi", label: "Verifikasi Identitas", description: "Tinjau pengajuan identitas", icon: BadgeCheck });
  if (hasPermission("monitoring", "manage")) institution.push({ href: "/dashboard/monitoring", label: "Pendampingan Murid", description: "Tindak lanjuti sinyal bantuan", icon: AlertTriangle });
  if (hasPermission("reports", "read")) institution.push({ href: "/dashboard/laporan", label: "Laporan & Ekspor", description: "Ringkasan untuk instansi", icon: FileSpreadsheet });
  if (hasPermission("access-grants", "manage")) institution.push({ href: "/dashboard/pembelian/approval", label: "Approval Pembayaran", description: "Verifikasi dan aktifkan paket siswa", icon: BadgeCheck });
  if (canManageSchool) institution.push({ href: "/dashboard/pengelolaan", label: "Pengelolaan", description: "Konten, sertifikat, dan lead", icon: Settings });

  return [
    { label: "Utama", items: [{ href: "/dashboard", label: "Ringkasan", description: isStudent ? "Lihat progres belajarmu" : "Pantau kondisi terbaru", icon: LayoutDashboard }] },
    ...(learning.length ? [{ label: isStudent ? "Belajar & Berkarya" : "Pembelajaran", items: learning }] : []),
    ...(institution.length ? [{ label: "Operasional Instansi", items: institution }] : []),
    { label: "Bantuan & Akun", items: [
      { href: "/dashboard/pembelian", label: "Pembelian & Akses", description: "Pesanan dan status paket", icon: CreditCard },
      { href: "/dashboard/dukungan", label: "Bantuan & Sertifikat", description: "Pusat bantuan dan dokumen", icon: Headphones },
      { href: "/dashboard/privasi", label: "Privasi", description: "Persetujuan penggunaan data", icon: Scale },
      { href: "/dashboard/keamanan", label: "Keamanan Akun", description: "Kata sandi dan verifikasi", icon: ShieldCheck },
    ] },
  ];
});

const requiresSchoolSelection = computed(() => schools.value.length > 1 && !selectedSchoolId.value);
const roleLabel = computed(() => roles.value.map((role) => roleCopy[role] || role).join(" · ") || "Pengguna");
const currentPage = computed(() => routeCopy.find((item) => isActiveRoute(route.path, item.path)) || routeCopy[routeCopy.length - 1]);

async function selectSchool(schoolId: string) {
  if (!schoolId || schoolId === selectedSchoolId.value) return;
  switchingSchool.value = true;
  schoolError.value = "";
  try {
    const response = await csrfFetch("/api/school-context", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ schoolId }),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.message || "Sekolah belum dapat dipilih");
    window.location.reload();
  } catch (caught) {
    schoolError.value = caught instanceof Error ? caught.message : "Sekolah belum dapat dipilih";
    switchingSchool.value = false;
  }
}

async function handleLogout() {
  try {
    await csrfFetch("/api/session", { method: "DELETE" });
  } finally {
    window.location.replace("/login");
  }
}
</script>

<template>
  <div v-if="loading || !profile" class="grid min-h-screen place-items-center bg-[#FFFFFF]">
    <div class="flex items-center gap-3 rounded-2xl border border-orange-100 bg-white px-5 py-4 text-sm font-bold text-orange-700 shadow-sm">
      <span class="size-2.5 animate-pulse rounded-full bg-orange-500" />Menyiapkan ruang belajar…
    </div>
  </div>

  <div v-else class="dashboard-shell min-h-screen bg-white text-slate-900">
    <a href="#dashboard-content" class="sr-only z-[100] rounded-lg bg-white px-4 py-3 font-bold text-orange-700 focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Lewati ke konten utama</a>

    <aside class="fixed inset-y-0 left-0 z-40 hidden w-[284px] flex-col border-r border-slate-200 bg-white lg:flex">
      <div class="flex h-[76px] items-center border-b border-slate-100 px-6">
        <DashLink to="/dashboard" aria-label="Sunrise Academy — Ringkasan"><BrandLogo class="text-xl" /></DashLink>
      </div>
      <div class="flex-1 overflow-y-auto px-4 py-5 [scrollbar-width:thin]">
        <DashboardNavigation :groups="navGroups" :pathname="route.path" />
      </div>
      <div class="border-t border-slate-100 p-4">
        <div class="mb-3 rounded-2xl bg-orange-50 p-3"><UserSummary :profile="profile" :role-label="roleLabel" /></div>
        <button type="button" @click="handleLogout" class="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 text-sm font-bold text-red-600 transition hover:bg-red-50">
          <LogOut class="size-[18px]" aria-hidden="true" /> Keluar
        </button>
      </div>
    </aside>

    <div v-if="mobileMenuOpen" class="fixed inset-0 z-50 lg:hidden">
      <button type="button" aria-label="Tutup menu" @click="mobileMenuOpen = false" class="absolute inset-0 bg-slate-950/45 backdrop-blur-[2px]" />
      <aside class="relative flex h-full w-[min(88vw,340px)] flex-col bg-white shadow-2xl">
        <div class="flex min-h-[72px] items-center justify-between border-b border-slate-100 px-5">
          <BrandLogo class="text-lg" />
          <button type="button" aria-label="Tutup menu navigasi" @click="mobileMenuOpen = false" class="grid size-11 place-items-center rounded-xl text-slate-600 hover:bg-slate-100"><X aria-hidden="true" class="size-5" /></button>
        </div>
        <div class="border-b border-slate-100 p-4">
          <div class="rounded-2xl bg-slate-50 p-3"><UserSummary :profile="profile" :role-label="roleLabel" /></div>
          <div v-if="schools.length > 0" class="mt-3 rounded-2xl border border-orange-100 bg-orange-50/70 p-3"><SchoolPicker :schools="schools" :selected-school-id="selectedSchoolId" :switching="switchingSchool" @select="selectSchool" compact /></div>
          <p v-if="schoolError" role="alert" class="mt-2 text-xs font-semibold text-red-700">{{ schoolError }}</p>
        </div>
        <div class="flex-1 overflow-y-auto px-4 py-5"><DashboardNavigation :groups="navGroups" :pathname="route.path" @navigate="mobileMenuOpen = false" /></div>
        <div class="border-t border-slate-100 p-4">
          <button type="button" @click="handleLogout" class="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 text-sm font-bold text-red-600 hover:bg-red-50"><LogOut aria-hidden="true" class="size-[18px]" /> Keluar dari akun</button>
        </div>
      </aside>
    </div>

    <div class="min-h-screen lg:pl-[284px]">
      <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur-xl">
        <div class="flex min-h-[72px] items-center gap-3 px-4 sm:px-6 lg:px-8">
          <button type="button" aria-label="Buka menu navigasi" :aria-expanded="mobileMenuOpen" @click="mobileMenuOpen = true" class="grid size-11 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-700 shadow-sm hover:bg-slate-50 lg:hidden"><Menu aria-hidden="true" class="size-5" /></button>
          <div class="min-w-0 flex-1">
            <p class="text-[10px] font-extrabold uppercase tracking-[0.16em] text-orange-700">{{ currentPage.eyebrow }}</p>
            <h1 class="truncate text-xl font-extrabold tracking-tight text-slate-950 sm:text-2xl">{{ currentPage.title }}</h1>
          </div>
          <div class="hidden min-w-0 xl:block">
            <SchoolPicker :schools="schools" :selected-school-id="selectedSchoolId" :switching="switchingSchool" @select="selectSchool" />
            <p v-if="schoolError" role="alert" class="mt-1 text-xs font-semibold text-red-700">{{ schoolError }}</p>
          </div>
          <PwaShell />
        </div>
        <div v-if="schools.length > 0" class="border-t border-slate-100 px-4 py-2.5 sm:px-6 xl:hidden"><SchoolPicker :schools="schools" :selected-school-id="selectedSchoolId" :switching="switchingSchool" @select="selectSchool" compact /><p v-if="schoolError" role="alert" class="mt-1 text-xs font-semibold text-red-700">{{ schoolError }}</p></div>
      </header>

      <div id="dashboard-content" tabindex="-1">
        <SchoolSelectionGate v-if="requiresSchoolSelection" :schools="schools" :switching="switchingSchool" :error="schoolError" @select="selectSchool" />
        <RouterView v-else />
      </div>
    </div>
  </div>
</template>
