<script setup lang="ts">
import { vReveal } from "./reveal";

const team = [
  { number: "01", name: "Surya Indra Permana", credentials: ", S.Kom", role: "Chief Executive Officer", roleShort: "CEO", image: "/images/team/surya-indra-permana.jpg", initials: "SI" },
  { number: "02", name: "Hendi Suryo Leksono", credentials: ", S.E., M.M.", role: "Chief Operating Officer", roleShort: "COO", image: "/images/team/hendi-suryo-leksono.jpg", initials: "HS" },
  { number: "03", name: "Nabila Putri Restaningsih", credentials: "", role: "Mentor", roleShort: "Mentor", image: "", initials: "NP" },
  { number: "04", name: "Lutfhan Bayu Zulkarnain", credentials: "", role: "Mentor dan Chief Marketing Officer", roleShort: "Mentor · CMO", image: "", initials: "LB" },
  { number: "05", name: "Ernando Januar Pratama", credentials: "", role: "Tim IT", roleShort: "Tim IT", image: "", initials: "EJ" },
];
</script>

<template>
  <section id="team" class="team-area sub-bg section-pad">
    <div class="team__line"><img class="sway_Y__animation" src="/images/landing/squiggle.png" alt="" /></div>
    <div class="team__earth"><img class="animation__arryUpDown" src="/images/landing/earth.png" alt="" /></div>
    <div class="team__right-rectangle"><img src="/images/landing/shape-right.png" alt="" /></div>
    <div class="container">
      <div v-reveal class="section-header mx-auto mb-[60px] max-w-[760px] text-center">
        <h5>Struktur tim</h5>
        <h2>Orang-orang yang menggerakkan <span>Sunrise Ads Academy. <img src="/images/landing/header-shape.png" alt="" /></span></h2>
        <p>Tim inti yang menjaga arah program, pengelolaan, dan keberlanjutan pembelajaran digital untuk sekolah.</p>
      </div>

      <div class="team-grid">
        <article v-for="member in team" :key="member.name" v-reveal class="team__item">
          <div class="team__image">
            <img
              v-if="member.image"
              :src="member.image"
              :alt="`Foto ${member.name}`"
              width="373"
              height="400"
              sizes="(max-width: 768px) 92vw, (max-width: 1200px) 45vw, 373px"
              loading="lazy"
            />
            <div v-else class="initials" role="img" :aria-label="`Foto ${member.name} belum tersedia`">{{ member.initials }}</div>
          </div>
          <div class="team__content">
            <h4>{{ member.name }}<small>{{ member.credentials }}</small></h4>
            <span class="role">{{ member.role }}</span>
            <span class="badge">{{ member.number }} · {{ member.roleShort }}</span>
          </div>
        </article>
      </div>
    </div>
  </section>
</template>
