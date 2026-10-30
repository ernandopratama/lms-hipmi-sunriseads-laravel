<script setup lang="ts">
import { onMounted, ref, watch } from "vue";
import { AlertCircle, Building2, ChevronLeft, ChevronRight, GraduationCap, Loader2, Plus, School, Search, Users, UserRound, X } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";
import CreateClassForm from "@/components/dashboard/master-data/CreateClassForm.vue";
import StudentImportForm from "@/components/dashboard/master-data/StudentImportForm.vue";

type Tab = "school" | "classes" | "students" | "teachers" | "parents";

type MasterData = {
  school: {
    id: string;
    code: string;
    npsn?: string | null;
    name: string;
    level: string;
    status: string;
    city?: string | null;
    province?: string | null;
    principal?: string | null;
    picName?: string | null;
    picEmail?: string | null;
    picPhone?: string | null;
  };
  classes: Array<{ id: string; code: string; name: string; grade?: number | null; capacity?: number | null; isActive: boolean; _count: { students: number } }>;
  students: Array<{ id: string; studentNumber: string; fullName: string; phone?: string | null; status: string; class?: { name: string; code: string } | null; user: { email: string; status: string }; _count: { studentParents: number } }>;
  teachers: Array<{ id: string; nip?: string | null; fullName: string; phone?: string | null; isActive: boolean; user: { email: string; status: string }; _count: { materials: number; assignments: number } }>;
  parents: Array<{ id: string; fullName: string; phone?: string | null; isActive: boolean; user: { email: string; status: string }; studentParents: Array<{ student: { id: string; fullName: string; studentNumber: string } }> }>;
  counts: { classes: number; students: number; teachers: number; parents: number };
};

type MasterDataItem = MasterData["classes"][number] | MasterData["students"][number] | MasterData["teachers"][number] | MasterData["parents"][number];
type MasterDataListing = { items: MasterDataItem[]; page: number; pageSize: number; total: number; totalPages: number };

const tabs: Array<{ id: Tab; label: string }> = [
  { id: "school", label: "Sekolah" },
  { id: "classes", label: "Kelas" },
  { id: "students", label: "Siswa" },
  { id: "teachers", label: "Guru" },
  { id: "parents", label: "Orang Tua" },
];

const studentStatuses = ["DRAFT", "PENDING_ACTIVATION", "ACTIVE", "INACTIVE", "TRANSFERRED", "GRADUATED", "SUSPENDED"];

const activeTab = ref<Tab>("school");
const data = ref<MasterData | null>(null);
const loading = ref(true);
const error = ref("");
const showInvite = ref(false);
const inviteRole = ref("student");
const inviteMessage = ref("");
const inviteActivationPath = ref("");
const inviteError = ref("");
const submittingInvite = ref(false);
const listing = ref<MasterDataListing | null>(null);
const listingLoading = ref(false);
const listingVersion = ref(0);
const query = ref("");
const statusFilter = ref("");
const classFilter = ref("");
const page = ref(1);

function loadData() {
  fetch("/api/backend/master-data")
    .then(async (response) => {
      if (!response.ok) {
        const payload = await response.json().catch(() => null);
        throw new Error(payload?.message || "Master Data belum dapat dimuat");
      }
      return response.json();
    })
    .then((payload) => { data.value = payload; })
    .catch((caught) => { error.value = caught instanceof Error ? caught.message : "Master Data belum dapat dimuat"; })
    .finally(() => { loading.value = false; });
}

onMounted(loadData);

watch([activeTab, classFilter, data, listingVersion, page, query, statusFilter], (_value, _old, onCleanup) => {
  if (activeTab.value === "school" || !data.value) return;
  let cancelled = false;
  const timeout = window.setTimeout(() => {
    const params = new URLSearchParams({ page: String(page.value), pageSize: "20" });
    if (query.value.trim()) params.set("query", query.value.trim());
    if (statusFilter.value) params.set("status", statusFilter.value);
    if (activeTab.value === "students" && classFilter.value) params.set("classId", classFilter.value);
    listingLoading.value = true;
    fetch(`/api/backend/master-data/lists/${activeTab.value}?${params}`)
      .then(async (response) => {
        const payload = await response.json().catch(() => null);
        if (!response.ok) throw new Error(payload?.message || "Daftar belum dapat dimuat");
        return payload;
      })
      .then((payload) => { if (!cancelled) listing.value = payload; })
      .catch((caught) => { if (!cancelled) error.value = caught instanceof Error ? caught.message : "Daftar belum dapat dimuat"; })
      .finally(() => { if (!cancelled) listingLoading.value = false; });
  }, 250);
  onCleanup(() => { cancelled = true; window.clearTimeout(timeout); });
}, { immediate: true });

function refreshData() {
  loadData();
  listingVersion.value += 1;
}

function selectTab(tab: Tab) {
  activeTab.value = tab;
  query.value = "";
  statusFilter.value = "";
  classFilter.value = "";
  page.value = 1;
  listing.value = null;
}

function toggleUserForm() {
  if (showInvite.value) {
    showInvite.value = false;
    return;
  }
  if (["students", "teachers", "parents"].includes(activeTab.value)) {
    inviteRole.value = activeTab.value === "students" ? "student" : activeTab.value === "teachers" ? "teacher" : "parent";
  }
  inviteMessage.value = "";
  inviteError.value = "";
  inviteActivationPath.value = "";
  showInvite.value = true;
}

async function sendInvitation(event: Event) {
  if (!data.value) return;
  submittingInvite.value = true;
  inviteError.value = "";
  inviteMessage.value = "";
  inviteActivationPath.value = "";
  const formElement = event.currentTarget as HTMLFormElement;
  const form = new FormData(formElement);
  const firstName = String(form.get("firstName") || "");
  const lastName = String(form.get("lastName") || "");
  const profileData: Record<string, string> = {
    fullName: `${firstName} ${lastName}`.trim(),
    phone: String(form.get("phone") || "").trim(),
  };
  if (inviteRole.value === "student") {
    profileData.studentNumber = String(form.get("studentNumber") || "");
    const classId = String(form.get("classId") || "");
    if (classId) profileData.classId = classId;
  }
  if (inviteRole.value === "teacher") profileData.nip = String(form.get("nip") || "");
  if (inviteRole.value === "parent") {
    profileData.studentId = String(form.get("studentId") || "");
    profileData.relationship = String(form.get("relationship") || "WALI");
  }
  const response = await csrfFetch("/api/backend/invitations", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({
      email: String(form.get("email") || ""),
      firstName,
      lastName,
      roleSlug: inviteRole.value,
      schoolId: data.value.school.id,
      profileData,
    }),
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) {
    inviteError.value = Array.isArray(result.message) ? result.message.join(", ") : result.message || "Undangan gagal dibuat";
  } else {
    const activationPath = result.activationToken ? `/aktivasi?token=${encodeURIComponent(result.activationToken)}` : "";
    inviteActivationPath.value = activationPath;
    inviteMessage.value = activationPath ? "Pengguna dan peran berhasil disiapkan. Bagikan tautan aktivasi satu kali kepada pengguna." : "Pengguna dan peran berhasil disiapkan. Undangan siap dikirim melalui layanan email.";
    formElement.reset();
  }
  submittingInvite.value = false;
}

function schoolRows(school: MasterData["school"]) {
  return [
    ["Nama sekolah", school.name],
    ["Kode / NPSN", `${school.code}${school.npsn ? ` / ${school.npsn}` : ""}`],
    ["Jenjang", school.level],
    ["Lokasi", [school.city, school.province].filter(Boolean).join(", ") || "-"],
    ["Kepala sekolah", school.principal || "-"],
    ["PIC", school.picName || "-"],
    ["Kontak PIC", [school.picEmail, school.picPhone].filter(Boolean).join(" · ") || "-"],
    ["Status", school.status],
  ];
}

const statusClass = (active: boolean) => ["rounded-full px-2.5 py-1 text-xs font-bold", active ? "bg-green-50 text-green-700" : "bg-slate-100 text-slate-600"];
</script>

<template>
  <div v-if="loading" class="flex min-h-[60vh] items-center justify-center gap-2 text-sm font-bold text-orange-600"><Loader2 aria-hidden="true" class="animate-spin" :size="18" /> Memuat Master Data...</div>

  <div v-else-if="error || !data" class="p-4 sm:p-8"><div role="alert" class="flex gap-2 rounded-xl bg-red-50 p-5 text-sm text-red-700"><AlertCircle aria-hidden="true" :size="18" />{{ error || "Master Data tidak tersedia" }}</div></div>

  <div v-else class="min-h-screen bg-[#FFFFFF] p-4 sm:p-8">
    <div class="mx-auto max-w-7xl">
      <p class="text-sm font-bold uppercase tracking-[0.16em] text-orange-600">Administrasi Sekolah</p>
      <div class="mt-2 flex flex-wrap items-center justify-between gap-3"><h2 class="text-2xl font-extrabold text-[#1F2937]">Master Data</h2><button type="button" @click="toggleUserForm" class="bg-sunrise inline-flex min-h-11 items-center gap-2 rounded-xl px-4 text-sm font-extrabold text-white"><X v-if="showInvite" aria-hidden="true" :size="17" /><Plus v-else aria-hidden="true" :size="17" />{{ showInvite ? "Tutup form" : activeTab === "students" ? "Tambah siswa" : "Tambah pengguna" }}</button></div>
      <p class="mt-2 text-sm text-[#64748B]">Data umum sekolah sesuai konteks akun. Dokumen identitas tetap berada di menu verifikasi terpisah.</p>

      <form v-if="showInvite" @submit.prevent="sendInvitation" class="mt-6 grid gap-4 rounded-2xl border border-orange-200 bg-white p-5 shadow-sm sm:grid-cols-2">
        <div class="sm:col-span-2"><p class="text-xs font-extrabold uppercase tracking-[0.16em] text-orange-700">Pengguna baru</p><h3 class="mt-1 text-xl font-extrabold text-slate-950">Tambahkan pengguna ke {{ data.school.name }}</h3><p class="mt-2 text-sm leading-6 text-slate-600">Pilih peran dan lengkapi datanya. Pengguna akan membuat kata sandi sendiri melalui tautan aktivasi satu kali.</p></div>
        <div><label class="text-sm font-bold text-slate-700" for="invite-role">Peran pengguna</label><select id="invite-role" v-model="inviteRole" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="student">Siswa</option><option value="teacher">Guru</option><option value="parent">Orang tua/wali</option></select></div>
        <div><label class="text-sm font-bold text-slate-700" for="invite-school">Sekolah</label><input id="invite-school" disabled :value="data.school.name" class="mt-1 min-h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-slate-500" /></div>
        <div><label class="text-sm font-bold text-slate-700" for="invite-email">Email</label><input id="invite-email" name="email" type="email" required class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3" /></div>
        <div><label class="text-sm font-bold text-slate-700" for="invite-first">Nama depan</label><input id="invite-first" name="firstName" required class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3" /></div>
        <div><label class="text-sm font-bold text-slate-700" for="invite-last">Nama belakang</label><input id="invite-last" name="lastName" required class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3" /></div>
        <template v-if="inviteRole === 'student'"><div><label class="text-sm font-bold text-slate-700" for="student-number">Nomor induk siswa</label><input id="student-number" name="studentNumber" required :maxlength="50" placeholder="Contoh: 2026001" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3" /></div><div><label class="text-sm font-bold text-slate-700" for="student-class">Kelas</label><select id="student-class" name="classId" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="">Belum ditentukan</option><option v-for="item in data.classes" :key="item.id" :value="item.id">{{ item.name }}</option></select></div></template>
        <div v-if="inviteRole === 'teacher'"><label class="text-sm font-bold text-slate-700" for="teacher-nip">NIP/ID Guru</label><input id="teacher-nip" name="nip" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3" /></div>
        <template v-if="inviteRole === 'parent'"><div><label class="text-sm font-bold text-slate-700" for="parent-student">Anak yang dihubungkan</label><select id="parent-student" name="studentId" required class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option v-for="item in data.students" :key="item.id" :value="item.id">{{ item.fullName }} ({{ item.studentNumber }})</option></select></div><div><label class="text-sm font-bold text-slate-700" for="relationship">Hubungan</label><select id="relationship" name="relationship" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="AYAH">Ayah</option><option value="IBU">Ibu</option><option value="WALI">Wali</option></select></div></template>
        <div><label class="text-sm font-bold text-slate-700" for="invite-phone">Nomor telepon <span class="font-normal text-slate-400">(opsional)</span></label><input id="invite-phone" name="phone" type="tel" :maxlength="30" autocomplete="tel" placeholder="Contoh: 0812 3456 7890" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3" /></div>
        <p v-if="inviteError" role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-700 sm:col-span-2">{{ inviteError }}</p>
        <div v-if="inviteMessage" role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800 sm:col-span-2"><p>{{ inviteMessage }}</p><a v-if="inviteActivationPath" :href="inviteActivationPath" class="mt-3 inline-flex min-h-10 items-center rounded-lg bg-emerald-700 px-4 font-extrabold text-white">Buka halaman aktivasi</a></div>
        <button :disabled="submittingInvite" class="bg-sunrise min-h-11 rounded-xl px-4 text-sm font-extrabold text-white disabled:opacity-60 sm:col-span-2">{{ submittingInvite ? "Menyiapkan pengguna..." : `Tambahkan ${inviteRole === "student" ? "siswa" : inviteRole === "teacher" ? "guru" : "orang tua/wali"}` }}</button>
      </form>

      <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="flex items-center gap-4 rounded-xl border border-[#DDEBE8] bg-white p-4 shadow-sm"><span class="grid size-10 place-items-center rounded-xl bg-orange-100 text-orange-700"><School aria-hidden="true" :size="20" /></span><div><p class="text-xs font-semibold text-[#64748B]">Kelas</p><p class="text-2xl font-extrabold text-[#1F2937]">{{ data.counts.classes }}</p></div></div>
        <div class="flex items-center gap-4 rounded-xl border border-[#DDEBE8] bg-white p-4 shadow-sm"><span class="grid size-10 place-items-center rounded-xl bg-orange-100 text-orange-700"><GraduationCap aria-hidden="true" :size="20" /></span><div><p class="text-xs font-semibold text-[#64748B]">Siswa</p><p class="text-2xl font-extrabold text-[#1F2937]">{{ data.counts.students }}</p></div></div>
        <div class="flex items-center gap-4 rounded-xl border border-[#DDEBE8] bg-white p-4 shadow-sm"><span class="grid size-10 place-items-center rounded-xl bg-orange-100 text-orange-700"><Users aria-hidden="true" :size="20" /></span><div><p class="text-xs font-semibold text-[#64748B]">Guru</p><p class="text-2xl font-extrabold text-[#1F2937]">{{ data.counts.teachers }}</p></div></div>
        <div class="flex items-center gap-4 rounded-xl border border-[#DDEBE8] bg-white p-4 shadow-sm"><span class="grid size-10 place-items-center rounded-xl bg-orange-100 text-orange-700"><UserRound aria-hidden="true" :size="20" /></span><div><p class="text-xs font-semibold text-[#64748B]">Orang Tua</p><p class="text-2xl font-extrabold text-[#1F2937]">{{ data.counts.parents }}</p></div></div>
      </div>

      <div class="mt-6 flex gap-2 overflow-x-auto rounded-xl border border-[#DDEBE8] bg-white p-2" role="tablist" aria-label="Jenis Master Data">
        <button v-for="tab in tabs" :key="tab.id" type="button" role="tab" :aria-selected="activeTab === tab.id" @click="selectTab(tab.id)" :class="['min-h-11 shrink-0 rounded-lg px-4 text-sm font-bold', activeTab === tab.id ? 'bg-orange-100 text-orange-700' : 'text-slate-600 hover:bg-slate-50']">{{ tab.label }}</button>
      </div>

      <section class="mt-5" aria-live="polite">
        <div v-if="activeTab === 'school'" class="rounded-2xl border border-[#DDEBE8] bg-white p-5 shadow-sm"><h3 class="mb-5 flex items-center gap-2 text-lg font-bold text-[#1F2937]"><Building2 aria-hidden="true" class="text-orange-600" :size="21" /> Profil Sekolah</h3><dl class="grid gap-4 sm:grid-cols-2"><div v-for="[label, value] in schoolRows(data.school)" :key="label" class="rounded-xl bg-[#FFFFFF] p-4"><dt class="text-xs font-bold text-[#64748B]">{{ label }}</dt><dd class="mt-1 text-sm font-semibold text-[#1F2937]">{{ value }}</dd></div></dl></div>
        <div v-if="activeTab !== 'school'" class="mb-4 flex flex-wrap gap-3 rounded-xl border border-orange-100 bg-white p-4">
          <label class="relative min-w-64 flex-1"><span class="sr-only">Cari data</span><Search aria-hidden="true" class="absolute left-3 top-3.5 text-slate-400" :size="17" /><input :value="query" @input="query = ($event.target as HTMLInputElement).value; page = 1" placeholder="Cari nama, nomor, kode, atau email" class="min-h-11 w-full rounded-xl border border-slate-300 pl-10 pr-3" /></label>
          <label><span class="sr-only">Filter status</span><select :value="statusFilter" @change="statusFilter = ($event.target as HTMLSelectElement).value; page = 1" class="min-h-11 rounded-xl border border-slate-300 px-3"><option value="">Semua status</option><template v-if="activeTab === 'students'"><option v-for="item in studentStatuses" :key="item" :value="item">{{ item }}</option></template><template v-else><option value="active">Aktif</option><option value="inactive">Nonaktif</option></template></select></label>
          <label v-if="activeTab === 'students'"><span class="sr-only">Filter kelas</span><select :value="classFilter" @change="classFilter = ($event.target as HTMLSelectElement).value; page = 1" class="min-h-11 rounded-xl border border-slate-300 px-3"><option value="">Semua kelas</option><option v-for="item in data.classes" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
        </div>
        <CreateClassForm v-if="activeTab === 'classes'" @created="refreshData" />
        <StudentImportForm v-if="activeTab === 'students'" @completed="refreshData" />
        <div v-if="activeTab !== 'school' && listingLoading && !listing" class="flex min-h-40 items-center justify-center gap-2 rounded-xl border border-orange-100 bg-white text-sm font-bold text-orange-600"><Loader2 aria-hidden="true" class="animate-spin" :size="18" /> Memuat daftar...</div>
        <template v-else-if="activeTab === 'classes'">
          <div v-if="!((listing?.items || data.classes) as MasterData['classes']).length" class="rounded-xl border border-[#DDEBE8] bg-white p-8 text-center text-sm text-[#64748B]">Belum ada kelas.</div>
          <div v-else class="overflow-x-auto rounded-xl border border-[#DDEBE8] bg-white shadow-sm"><table class="min-w-full text-left text-sm"><thead class="bg-[#FFF7ED] text-xs uppercase tracking-wide text-orange-800"><tr><th v-for="header in ['Kode', 'Kelas', 'Tingkat', 'Siswa', 'Kapasitas', 'Status']" :key="header" class="whitespace-nowrap px-4 py-3">{{ header }}</th></tr></thead><tbody class="divide-y divide-amber-100"><tr v-for="row in ((listing?.items || data.classes) as MasterData['classes'])" :key="row.id"><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.code }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600 font-bold text-slate-900">{{ row.name }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.grade || "-" }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row._count.students }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.capacity || "-" }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600"><span :class="statusClass(row.isActive)">{{ row.isActive ? "AKTIF" : "NONAKTIF" }}</span></td></tr></tbody></table></div>
        </template>
        <template v-else-if="activeTab === 'students'">
          <div v-if="!((listing?.items || data.students) as MasterData['students']).length" class="rounded-xl border border-[#DDEBE8] bg-white p-8 text-center text-sm text-[#64748B]">Belum ada siswa.</div>
          <div v-else class="overflow-x-auto rounded-xl border border-[#DDEBE8] bg-white shadow-sm"><table class="min-w-full text-left text-sm"><thead class="bg-[#FFF7ED] text-xs uppercase tracking-wide text-orange-800"><tr><th v-for="header in ['No. Induk', 'Nama', 'Kelas', 'Email', 'Orang Tua', 'Status']" :key="header" class="whitespace-nowrap px-4 py-3">{{ header }}</th></tr></thead><tbody class="divide-y divide-amber-100"><tr v-for="row in ((listing?.items || data.students) as MasterData['students'])" :key="row.id"><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.studentNumber }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600 font-bold text-slate-900">{{ row.fullName }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.class?.name || "-" }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.user.email }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row._count.studentParents }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600"><span :class="statusClass(row.status === 'ACTIVE')">{{ row.status || (row.status === "ACTIVE" ? "AKTIF" : "NONAKTIF") }}</span></td></tr></tbody></table></div>
        </template>
        <template v-else-if="activeTab === 'teachers'">
          <div v-if="!((listing?.items || data.teachers) as MasterData['teachers']).length" class="rounded-xl border border-[#DDEBE8] bg-white p-8 text-center text-sm text-[#64748B]">Belum ada guru.</div>
          <div v-else class="overflow-x-auto rounded-xl border border-[#DDEBE8] bg-white shadow-sm"><table class="min-w-full text-left text-sm"><thead class="bg-[#FFF7ED] text-xs uppercase tracking-wide text-orange-800"><tr><th v-for="header in ['NIP', 'Nama', 'Email', 'Materi', 'Tugas', 'Status']" :key="header" class="whitespace-nowrap px-4 py-3">{{ header }}</th></tr></thead><tbody class="divide-y divide-amber-100"><tr v-for="row in ((listing?.items || data.teachers) as MasterData['teachers'])" :key="row.id"><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.nip || "-" }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600 font-bold text-slate-900">{{ row.fullName }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.user.email }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row._count.materials }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row._count.assignments }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600"><span :class="statusClass(row.isActive)">{{ row.isActive ? "AKTIF" : "NONAKTIF" }}</span></td></tr></tbody></table></div>
        </template>
        <template v-else-if="activeTab === 'parents'">
          <div v-if="!((listing?.items || data.parents) as MasterData['parents']).length" class="rounded-xl border border-[#DDEBE8] bg-white p-8 text-center text-sm text-[#64748B]">Belum ada akun orang tua yang terhubung.</div>
          <div v-else class="overflow-x-auto rounded-xl border border-[#DDEBE8] bg-white shadow-sm"><table class="min-w-full text-left text-sm"><thead class="bg-[#FFF7ED] text-xs uppercase tracking-wide text-orange-800"><tr><th v-for="header in ['Nama', 'Email', 'Telepon', 'Anak Terhubung', 'Status']" :key="header" class="whitespace-nowrap px-4 py-3">{{ header }}</th></tr></thead><tbody class="divide-y divide-amber-100"><tr v-for="row in ((listing?.items || data.parents) as MasterData['parents'])" :key="row.id"><td class="whitespace-nowrap px-4 py-3 text-slate-600 font-bold text-slate-900">{{ row.fullName }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.user.email }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.phone || "-" }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.studentParents.map((link) => `${link.student.fullName} (${link.student.studentNumber})`).join(", ") || "-" }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600"><span :class="statusClass(row.isActive)">{{ row.isActive ? "AKTIF" : "NONAKTIF" }}</span></td></tr></tbody></table></div>
        </template>
        <div v-if="activeTab !== 'school' && listing" class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600"><p>{{ listing.total }} data · Halaman {{ listing.page }} dari {{ listing.totalPages }}</p><div class="flex gap-2"><button type="button" :disabled="listingLoading || listing.page <= 1" @click="page = listing.page - 1" class="inline-flex min-h-11 items-center gap-1 rounded-xl border border-slate-300 px-3 font-bold disabled:opacity-40"><ChevronLeft aria-hidden="true" :size="16" /> Sebelumnya</button><button type="button" :disabled="listingLoading || listing.page >= listing.totalPages" @click="page = listing.page + 1" class="inline-flex min-h-11 items-center gap-1 rounded-xl border border-slate-300 px-3 font-bold disabled:opacity-40">Berikutnya <ChevronRight aria-hidden="true" :size="16" /></button></div></div>
      </section>
    </div>
  </div>
</template>
