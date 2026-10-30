<script setup lang="ts">
import { onMounted, ref } from "vue";
import {
  Award,
  FileText,
  GraduationCap,
  Loader2,
  Megaphone,
  UserRoundSearch,
} from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

type Announcement = {
  id: string;
  title: string;
  audience: string;
  status: string;
  createdAt: string;
};
type Template = { id: string; name: string; titleTemplate: string };
type Student = { id: string; fullName: string; studentNumber: string };
type Achievement = { id: string; title: string; code: string };
type Cms = {
  id: string;
  type: string;
  title: string;
  status: string;
  slug: string;
};
type Lead = {
  id: string;
  schoolName: string;
  picName: string;
  email: string;
  phone: string;
  status: string;
  estimatedStudents: number;
  createdAt: string;
};

async function json<T>(response: Response): Promise<T> {
  const data = await response.json().catch(() => ({}));
  if (!response.ok)
    throw new Error(
      (data as { message?: string }).message ||
        "Permintaan belum dapat diproses",
    );
  return data as T;
}

const announcements = ref<Announcement[]>([]);
const templates = ref<Template[]>([]);
const students = ref<Student[]>([]);
const achievements = ref<Achievement[]>([]);
const cms = ref<Cms[]>([]);
const leads = ref<Lead[]>([]);
const loading = ref(true);
const busy = ref("");
const message = ref("");
const error = ref("");

async function load() {
  try {
    const urls = [
      "/api/backend/support/announcements/manage",
      "/api/backend/support/certificate-templates",
      "/api/backend/students",
      "/api/backend/support/cms",
      "/api/backend/leads",
      "/api/backend/support/achievement-definitions",
    ];
    const responses = await Promise.all(urls.map((url) => fetch(url)));
    const [
      announcementData,
      templateData,
      studentData,
      cmsData,
      leadData,
      achievementData,
    ] = await Promise.all([
      json<Announcement[]>(responses[0]),
      json<Template[]>(responses[1]),
      json<{ data?: Student[] } | Student[]>(responses[2]),
      json<Cms[]>(responses[3]),
      json<Lead[]>(responses[4]),
      json<Achievement[]>(responses[5]),
    ]);
    announcements.value = announcementData;
    templates.value = templateData;
    students.value = Array.isArray(studentData) ? studentData : studentData.data || [];
    cms.value = cmsData;
    leads.value = leadData;
    achievements.value = achievementData;
  } catch (caught) {
    error.value =
      caught instanceof Error
        ? caught.message
        : "Data pengelolaan belum dapat dimuat";
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  load();
});

// Inputs with a React defaultValue bind `:value.attr`: it is the value attribute, which form.reset() restores.
async function submit(
  event: Event,
  path: string,
  payload: (form: FormData) => Record<string, unknown>,
  success: string,
) {
  event.preventDefault();
  const form = event.currentTarget as HTMLFormElement;
  const data = new FormData(form);
  busy.value = path;
  error.value = "";
  try {
    await json(
      await csrfFetch(path, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(payload(data)),
      }),
    );
    form.reset();
    message.value = success;
    await load();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Data belum dapat disimpan";
  }
  busy.value = "";
}

async function publish(path: string) {
  busy.value = path;
  error.value = "";
  try {
    await json(await csrfFetch(path, { method: "POST" }));
    message.value = "Konten berhasil dipublikasikan.";
    await load();
  } catch (caught) {
    error.value =
      caught instanceof Error
        ? caught.message
        : "Konten belum dapat dipublikasikan";
  }
  busy.value = "";
}

async function updateLead(id: string, status: string) {
  busy.value = id;
  try {
    await json(
      await csrfFetch(`/api/backend/leads/${id}/status`, {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ status }),
      }),
    );
    await load();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Status lead belum berubah";
  }
  busy.value = "";
}

function submitAward(event: Event) {
  const values = new FormData(event.currentTarget as HTMLFormElement);
  const achievementId = String(values.get("achievementId") || "");
  submit(
    event,
    `/api/backend/support/achievements/${achievementId}/award`,
    (form) => ({
      studentId: form.get("studentId"),
      evidence: { note: form.get("evidence") },
    }),
    "Pencapaian berhasil diberikan.",
  );
}
</script>

<template>
  <div v-if="loading" class="flex min-h-[60vh] items-center justify-center gap-2 text-orange-600"><Loader2 aria-hidden="true" class="animate-spin" :size="18" /> Memuat pengelolaan...</div>

  <main v-else class="min-h-screen bg-[#FFFFFF] p-4 sm:p-8">
    <div class="mx-auto max-w-7xl">
      <p class="text-sm font-bold uppercase tracking-[0.16em] text-orange-600">Pengelolaan Fase 7</p>
      <h2 class="mt-2 text-2xl font-extrabold text-slate-900">Konten, Pencapaian &amp; Lead</h2>
      <p class="mt-2 text-sm text-slate-600">Terbitkan komunikasi resmi, kelola kelayakan sertifikat, dan tindak lanjuti minat sekolah. Saat ini ada {{ announcements.length }} pengumuman tercatat.</p>
      <p v-if="error" role="alert" class="mt-5 rounded-xl bg-red-50 p-4 text-sm font-semibold text-red-700">{{ error }}</p>
      <p v-if="message" role="status" class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{{ message }}</p>

      <div class="mt-7 grid gap-5 xl:grid-cols-3">
        <form
          @submit.prevent="submit(
            $event,
            '/api/backend/support/announcements',
            (form) => ({
              title: form.get('title'),
              body: form.get('body'),
              audience: form.get('audience'),
              publishNow: form.get('publishNow') === 'on',
            }),
            'Pengumuman berhasil dibuat.',
          )"
          class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"
        >
          <h3 class="flex items-center gap-2 font-bold"><Megaphone aria-hidden="true" :size="18" class="text-orange-600" /> Pengumuman baru</h3>
          <label class="mt-4 block text-sm font-bold">Judul<input name="title" required minlength="3" class="form-control mt-1" /></label>
          <label class="mt-3 block text-sm font-bold">Isi<textarea name="body" required minlength="3" class="form-control mt-1 min-h-28" /></label>
          <label class="mt-3 block text-sm font-bold">Audiens<select name="audience" class="form-control mt-1"><option value="ALL">Semua</option><option value="STUDENTS">Siswa</option><option value="TEACHERS">Guru</option><option value="PARENTS">Orang tua</option><option value="STAFF">Staf</option></select></label>
          <label class="mt-3 flex items-center gap-2 text-sm"><input name="publishNow" type="checkbox" /> Langsung publikasikan</label>
          <button :disabled="busy.includes('announcements')" class="bg-sunrise mt-4 min-h-11 rounded-xl px-4 text-sm font-bold text-white">Simpan</button>
        </form>

        <form
          @submit.prevent="submit(
            $event,
            '/api/backend/support/certificate-templates',
            (form) => ({
              name: form.get('name'),
              titleTemplate: form.get('titleTemplate'),
              description: form.get('description'),
              eligibilityRule: {
                minApprovedSubmissions: Number(form.get('submissions')),
                minLearningProgress: Number(form.get('progress')),
                minApprovedStores: Number(form.get('stores')),
              },
            }),
            'Template sertifikat berhasil dibuat.',
          )"
          class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"
        >
          <h3 class="flex items-center gap-2 font-bold"><GraduationCap aria-hidden="true" :size="18" class="text-orange-600" /> Template sertifikat</h3>
          <label class="mt-4 block text-sm font-bold">Nama template<input name="name" required class="form-control mt-1" /></label>
          <label class="mt-3 block text-sm font-bold">Judul sertifikat<input name="titleTemplate" required :value.attr="'Sertifikat Kelulusan {{studentName}}'" class="form-control mt-1" /></label>
          <label class="mt-3 block text-sm font-bold">Deskripsi<input name="description" class="form-control mt-1" /></label>
          <div class="mt-3 grid grid-cols-3 gap-2">
            <label class="text-xs font-bold">Tugas<input name="submissions" type="number" min="0" :value.attr="'0'" class="form-control mt-1" /></label>
            <label class="text-xs font-bold">Progress %<input name="progress" type="number" min="0" max="100" :value.attr="'0'" class="form-control mt-1" /></label>
            <label class="text-xs font-bold">Toko<input name="stores" type="number" min="0" :value.attr="'0'" class="form-control mt-1" /></label>
          </div>
          <button :disabled="busy.includes('certificate-templates')" class="bg-sunrise mt-4 min-h-11 rounded-xl px-4 text-sm font-bold text-white">Buat template</button>
        </form>

        <form
          @submit.prevent="submit(
            $event,
            '/api/backend/support/certificates',
            (form) => ({
              templateId: form.get('templateId'),
              studentId: form.get('studentId'),
            }),
            'Sertifikat masuk antrean penerbitan.',
          )"
          class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"
        >
          <h3 class="flex items-center gap-2 font-bold"><GraduationCap aria-hidden="true" :size="18" class="text-orange-600" /> Terbitkan sertifikat</h3>
          <label class="mt-4 block text-sm font-bold">Template<select name="templateId" required class="form-control mt-1"><option value="">Pilih template</option><option v-for="item in templates" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
          <label class="mt-3 block text-sm font-bold">Siswa<select name="studentId" required class="form-control mt-1"><option value="">Pilih siswa</option><option v-for="student in students" :key="student.id" :value="student.id">{{ student.fullName }} ({{ student.studentNumber }})</option></select></label>
          <p class="mt-3 text-xs leading-5 text-slate-500">Sistem memeriksa kelayakan sebelum membuat PDF. Sertifikat dapat dicabut dengan jejak audit.</p>
          <button :disabled="busy === '/api/backend/support/certificates'" class="bg-sunrise mt-4 min-h-11 rounded-xl px-4 text-sm font-bold text-white">Terbitkan</button>
        </form>
      </div>

      <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <div class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
          <form
            @submit.prevent="submit(
              $event,
              '/api/backend/support/achievements',
              (form) => ({
                code: form.get('code'),
                title: form.get('title'),
                description: form.get('description'),
                icon: 'AWARD',
                criteria: { note: form.get('criteria') },
              }),
              'Definisi pencapaian berhasil dibuat.',
            )"
          >
            <h3 class="flex items-center gap-2 font-bold"><Award aria-hidden="true" :size="18" class="text-orange-600" /> Definisi pencapaian</h3>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
              <label class="text-sm font-bold">Kode<input name="code" required class="form-control mt-1" /></label>
              <label class="text-sm font-bold">Judul<input name="title" required class="form-control mt-1" /></label>
            </div>
            <label class="mt-3 block text-sm font-bold">Deskripsi<textarea name="description" required class="form-control mt-1" /></label>
            <label class="mt-3 block text-sm font-bold">Kriteria<input name="criteria" class="form-control mt-1" /></label>
            <button class="bg-sunrise mt-4 min-h-11 rounded-xl px-4 text-sm font-bold text-white">Buat pencapaian</button>
          </form>
          <form @submit.prevent="submitAward" class="mt-6 border-t pt-5">
            <h4 class="text-sm font-bold">Berikan kepada siswa</h4>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              <select name="achievementId" required class="form-control"><option value="">Pilih pencapaian</option><option v-for="item in achievements" :key="item.id" :value="item.id">{{ item.title }}</option></select>
              <select name="studentId" required class="form-control"><option value="">Pilih siswa</option><option v-for="student in students" :key="student.id" :value="student.id">{{ student.fullName }}</option></select>
            </div>
            <input name="evidence" placeholder="Catatan bukti (opsional)" class="form-control mt-3" />
            <button class="mt-3 min-h-11 rounded-xl border border-orange-200 px-4 text-sm font-bold text-orange-700">Berikan pencapaian</button>
          </form>
        </div>
        <form
          @submit.prevent="submit(
            $event,
            '/api/backend/support/cms',
            (form) => ({
              slug: form.get('slug'),
              type: form.get('type'),
              title: form.get('title'),
              excerpt: form.get('excerpt'),
              body: { text: form.get('body') },
              position: 0,
            }),
            'Konten CMS tersimpan sebagai draf.',
          )"
          class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"
        >
          <h3 class="flex items-center gap-2 font-bold"><FileText aria-hidden="true" :size="18" class="text-orange-600" /> Konten landing page</h3>
          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <label class="text-sm font-bold">Jenis<select name="type" class="form-control mt-1"><option value="FAQ">FAQ</option><option value="TESTIMONIAL">Testimoni</option><option value="CASE_STUDY">Studi kasus</option><option value="PARTNER">Mitra</option><option value="PAGE">Halaman</option></select></label>
            <label class="text-sm font-bold">Slug<input name="slug" required class="form-control mt-1" /></label>
          </div>
          <label class="mt-3 block text-sm font-bold">Judul<input name="title" required class="form-control mt-1" /></label>
          <label class="mt-3 block text-sm font-bold">Ringkasan<input name="excerpt" class="form-control mt-1" /></label>
          <label class="mt-3 block text-sm font-bold">Isi<textarea name="body" required class="form-control mt-1" /></label>
          <button class="bg-sunrise mt-4 min-h-11 rounded-xl px-4 text-sm font-bold text-white">Simpan draf</button>
        </form>
      </div>

      <section class="mt-6 rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
        <h3 class="font-bold">Konten menunggu publikasi</h3>
        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <article v-for="item in cms" :key="item.id" class="rounded-xl bg-slate-50 p-4">
            <p class="text-xs font-bold text-orange-700">{{ item.type }} · {{ item.status }}</p>
            <p class="mt-1 font-bold">{{ item.title }}</p>
            <button v-if="item.status === 'DRAFT'" :disabled="busy.includes(item.id)" @click="publish(`/api/backend/support/cms/${item.id}/publish`)" class="mt-3 text-xs font-bold text-orange-700">Publikasikan</button>
          </article>
        </div>
      </section>

      <section class="mt-6 rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
        <h3 class="flex items-center gap-2 font-bold"><UserRoundSearch aria-hidden="true" :size="18" class="text-orange-600" /> Lead sekolah</h3>
        <div class="mt-4 overflow-x-auto">
          <table class="w-full min-w-[760px] text-left text-sm">
            <thead>
              <tr class="border-b text-xs text-slate-500">
                <th class="p-3">Sekolah</th>
                <th class="p-3">PIC</th>
                <th class="p-3">Kontak</th>
                <th class="p-3">Siswa</th>
                <th class="p-3">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="lead in leads" :key="lead.id" class="border-b">
                <td class="p-3 font-bold">{{ lead.schoolName }}<span class="block text-xs font-normal text-slate-500">{{ new Date(lead.createdAt).toLocaleDateString("id-ID") }}</span></td>
                <td class="p-3">{{ lead.picName }}</td>
                <td class="p-3">{{ lead.email }}<span class="block text-xs text-slate-500">{{ lead.phone }}</span></td>
                <td class="p-3">{{ lead.estimatedStudents }}</td>
                <td class="p-3">
                  <select :disabled="busy === lead.id" :value="lead.status" @change="updateLead(lead.id, ($event.target as HTMLSelectElement).value)" class="min-h-10 rounded-lg border bg-white px-2"><option value="PENDING">Menunggu</option><option value="CONTACTED">Dihubungi</option><option value="APPROVED">Disetujui</option><option value="REJECTED">Ditolak</option></select>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </main>
</template>
