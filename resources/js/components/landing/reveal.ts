import type { Directive } from "vue";

// Stands in for the template's WOW.js fade-ups: elements marked v-reveal fade in once they scroll into view.
let observer: IntersectionObserver | null = null;

function getObserver() {
  if (observer || typeof IntersectionObserver === "undefined") return observer;
  observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (!entry.isIntersecting) continue;
      entry.target.classList.add("is-visible");
      observer?.unobserve(entry.target);
    }
  }, { rootMargin: "0px 0px -60px 0px" });
  return observer;
}

export const vReveal: Directive<HTMLElement> = {
  mounted(el) {
    const io = getObserver();
    if (!io) return;
    el.classList.add("reveal");
    // Hold page entrance animations until the preloader overlay has gone.
    if (document.documentElement.classList.contains("sd-loading")) {
      addEventListener("sd:preloader-done", () => io.observe(el), { once: true });
    } else {
      io.observe(el);
    }
  },
  unmounted(el) {
    observer?.unobserve(el);
  },
};
