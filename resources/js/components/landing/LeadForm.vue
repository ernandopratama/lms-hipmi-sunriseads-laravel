<script setup lang="ts">
import { reactive, ref, watch } from "vue";
import { AlertCircle, CheckCircle2, Loader2, Send } from "@lucide/vue";
import { vReveal } from "./reveal";
import { csrfFetch } from "@/lib/csrf";

const blankForm = () => ({
  website: "", // Honeypot
  schoolName: "",
  educationLevel: "SMK",
  city: "",
  province: "",
  picName: "",
  picTitle: "",
  email: "",
  phone: "",
  estimatedStudents: "" as number | "",
  programOfInterest: "Kewirausahaan Digital",
  message: "",
  consent: false,
});

const form = reactive(blankForm());
const errors = ref<Record<string, string>>({});
const submitted = ref(false);
const loading = ref(false);
const error = ref("");
const success = ref(false);

// Same rules as the original react-hook-form setup; fields without a message block submit silently, as before.
function validate() {
  const found: Record<string, string> = {};
  if (!form.schoolName) found.schoolName = "Nama sekolah wajib diisi";
  if (!form.city) found.city = "";
  if (!form.province) found.province = "";
  if (!form.picName) found.picName = "";
  if (!form.picTitle) found.picTitle = "";
  if (!form.email) found.email = "Email wajib diisi";
  else if (!/^\S+@\S+\.\S+$/.test(form.email)) found.email = "Format email belum sesuai";
  if (!form.phone || form.phone.length < 9) found.phone = "";
  if (form.estimatedStudents === "" || Number(form.estimatedStudents) < 1) found.estimatedStudents = "";
  if (!form.consent) found.consent = "Persetujuan diperlukan";
  return found;
}

const fieldIds: Record<string, string> = {
  schoolName: "lead-school-name", city: "lead-city", province: "lead-province", picName: "lead-pic-name", picTitle: "lead-pic-title",
  email: "lead-email", phone: "lead-phone", estimatedStudents: "lead-estimated-students", consent: "lead-consent",
};

watch(form, () => { if (submitted.value) errors.value = validate(); });

async function onSubmit() {
  submitted.value = true;
  errors.value = validate();
  const firstInvalid = Object.keys(fieldIds).find((field) => field in errors.value);
  if (firstInvalid) {
    document.getElementById(fieldIds[firstInvalid])?.focus();
    return;
  }

  loading.value = true;
  error.value = "";
  success.value = false;

  try {
    const res = await csrfFetch("/api/backend/leads", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ...form, estimatedStudents: Number(form.estimatedStudents) }),
    });

    const resData = await res.json();
    if (!res.ok) throw new Error(resData.message || "Gagal mengirim pendaftaran");

    success.value = true;
    submitted.value = false;
    Object.assign(form, blankForm());
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : "Pendaftaran belum dapat dikirim. Silakan coba lagi.";
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <section class="contact-area sub-bg section-pad" id="register">
    <div class="container">
      <div class="contact-grid">
        <!-- Left Column: Info -->
        <div v-reveal class="contact__aside">
          <div class="section-header">
            <h5>Untuk sekolah</h5>
            <h2>Tidak perlu menyiapkan <span>proposal <img src="/images/landing/header-shape.png" alt="" /></span> panjang.</h2>
            <p>Ceritakan kondisi sekolah, jumlah peserta, dan program yang ingin dijalankan. Informasi ini membantu tim kami menyiapkan percakapan yang lebih relevan.</p>
          </div>
          <h4 class="mt-8 mb-4">Yang akan dibahas bersama:</h4>
          <ol class="grid gap-3">
            <li v-for="(item, index) in ['Kesiapan sekolah dan peserta', 'Alur kerja guru dan pengelola', 'Ruang lingkup praktik digital']" :key="item" class="contact__info-item items-center"><span class="n">0{{ index + 1 }}</span>{{ item }}</li>
          </ol>
          <p class="mt-6 !text-sm">Mengirim formulir tidak otomatis membuat akun atau tenant sekolah.</p>
        </div>

        <!-- Right Column: Form Card -->
        <div v-reveal class="contact__item">
          <div v-if="success" role="status" class="alert success">
            <CheckCircle2 aria-hidden="true" class="h-5 w-5 flex-shrink-0" />
            <span>Pendaftaran berhasil dikirim! Tim kami akan segera menghubungi Anda.</span>
          </div>

          <div v-if="error" role="alert" class="alert error">
            <AlertCircle aria-hidden="true" class="h-5 w-5 flex-shrink-0" />
            <span>{{ error }}</span>
          </div>

          <form @submit.prevent="onSubmit" novalidate>
            <!-- Honeypot field (hidden from real users, autocomplete off) -->
            <div style="display: none">
              <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" placeholder="Your website URL" />
            </div>

            <div class="form-row">
              <div>
                <label for="lead-school-name">Nama Sekolah</label>
                <input id="lead-school-name" v-model="form.schoolName" name="schoolName" type="text" placeholder="Contoh: SMK Negeri 1 Jakarta" autocomplete="organization" :aria-invalid="'schoolName' in errors" />
                <p v-if="errors.schoolName" class="field-error">{{ errors.schoolName }}</p>
              </div>
              <div>
                <label for="lead-education-level">Jenjang Pendidikan</label>
                <select id="lead-education-level" v-model="form.educationLevel" name="educationLevel">
                  <option value="SMK">SMK / Vokasi</option>
                  <option value="SMA">SMA</option>
                  <option value="SMP">SMP</option>
                </select>
              </div>
            </div>

            <div class="form-row">
              <div>
                <label for="lead-city">Kota / Kabupaten</label>
                <input id="lead-city" v-model="form.city" name="city" type="text" placeholder="Jakarta Pusat" autocomplete="address-level2" :aria-invalid="'city' in errors" />
              </div>
              <div>
                <label for="lead-province">Provinsi</label>
                <input id="lead-province" v-model="form.province" name="province" type="text" placeholder="DKI Jakarta" autocomplete="address-level1" :aria-invalid="'province' in errors" />
              </div>
            </div>

            <div class="form-row">
              <div>
                <label for="lead-pic-name">Nama Penanggung Jawab (PIC)</label>
                <input id="lead-pic-name" v-model="form.picName" name="picName" type="text" placeholder="Ahmad Yani" autocomplete="name" :aria-invalid="'picName' in errors" />
              </div>
              <div>
                <label for="lead-pic-title">Jabatan PIC</label>
                <input id="lead-pic-title" v-model="form.picTitle" name="picTitle" type="text" placeholder="Kepala Sekolah / Waka Hubin" :aria-invalid="'picTitle' in errors" />
              </div>
            </div>

            <div class="form-row">
              <div>
                <label for="lead-email">Email Sekolah / PIC</label>
                <input id="lead-email" v-model="form.email" name="email" type="email" placeholder="admin@sekolah.sch.id" autocomplete="email" :aria-invalid="'email' in errors" />
                <p v-if="errors.email" class="field-error">{{ errors.email }}</p>
              </div>
              <div>
                <label for="lead-phone">No. WhatsApp</label>
                <input id="lead-phone" v-model="form.phone" name="phone" type="tel" placeholder="08123456789" autocomplete="tel" inputmode="tel" :aria-invalid="'phone' in errors" />
              </div>
            </div>

            <div class="form-row">
              <div>
                <label for="lead-estimated-students">Perkiraan Jumlah Siswa</label>
                <input id="lead-estimated-students" v-model="form.estimatedStudents" name="estimatedStudents" type="number" placeholder="150" inputmode="numeric" :aria-invalid="'estimatedStudents' in errors" />
              </div>
              <div>
                <label for="lead-program">Program yang Diminati</label>
                <select id="lead-program" v-model="form.programOfInterest" name="programOfInterest">
                  <option value="Kewirausahaan Digital">Kewirausahaan Digital</option>
                  <option value="Praktik Affiliate">Praktik Content &amp; Affiliate</option>
                  <option value="Full Program">Semua Program</option>
                </select>
              </div>
            </div>

            <label for="lead-message">Pesan / Harapan (Opsional)</label>
            <textarea id="lead-message" v-model="form.message" name="message" placeholder="Tulis pesan jika ada..." maxlength="1000" />

            <div class="consent">
              <input id="lead-consent" v-model="form.consent" name="consent" type="checkbox" :aria-invalid="'consent' in errors" />
              <label for="lead-consent">Saya menyetujui <a href="/kebijakan-privasi" class="font-semibold text-[#2eb97e] hover:underline">Kebijakan Privasi</a> dan mengizinkan pengolahan data formulir untuk verifikasi serta tindak lanjut kemitraan.</label>
            </div>
            <p v-if="errors.consent" class="mt-2 text-[13px] font-semibold text-red-600">{{ errors.consent }}</p>

            <button type="submit" :disabled="loading || success" class="btn-one mt-[30px] w-full justify-center sm:w-auto">
              <Loader2 aria-hidden="true" v-if="loading" class="h-4 w-4 animate-spin" />
              <Send aria-hidden="true" v-else class="h-4 w-4" />
              Kirim Permintaan
            </button>
          </form>
        </div>
      </div>
    </div>
  </section>
</template>
