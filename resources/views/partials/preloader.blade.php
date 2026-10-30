{{--
    Sunrise Digital preloader. Plain HTML/CSS so it paints before the Vue bundle loads.
    The logo is the original PNG stacked in layers; each layer is clipped to one part
    (sun disc, each ray, SUNRISE, DIGITAL) so the parts animate separately without redrawing the logo.
    Shown on every full page load, for at least 2 seconds, before the page is revealed.
--}}
<style>
    html.sd-loading { overflow: hidden; background: #fef7ea; }
    #sdp {
        --sdp-orange: #ff570b;
        --sdp-navy: #071b3b;
        position: fixed; inset: 0; z-index: 9999;
        display: grid; place-items: center; align-content: center; gap: clamp(20px, 4vw, 32px);
        background: #fef7ea;
        transition: opacity .5s ease, visibility .5s;
    }
    #sdp.sdp-exit { opacity: 0; visibility: hidden; }
    #sdp.sdp-exit .sdp-logo { transform: scale(1.03); }
    .sdp-glow {
        position: absolute; left: 50%; top: 50%; width: min(70vw, 520px); aspect-ratio: 1;
        translate: -50% -50%; border-radius: 50%; pointer-events: none;
        background: radial-gradient(circle, rgba(255, 87, 11, .16) 0%, rgba(255, 87, 11, .05) 40%, transparent 68%);
        animation: sdp-glow .7s ease-out both;
    }
    .sdp-logo {
        position: relative; width: min(78vw, 420px); aspect-ratio: 2032 / 774;
        transition: transform .5s ease;
    }
    .sdp-layer, .sdp-sun { position: absolute; inset: 0; }
    .sdp-layer { width: 100%; height: 100%; user-select: none; -webkit-user-drag: none; }
    /* Sun group starts centred in the logo box (stages 02–03), then settles on the left (stage 04). */
    .sdp-sun {
        animation: sdp-sun-shift .6s cubic-bezier(.65, 0, .35, 1) 1s both, sdp-sun-glow 1.4s ease-in-out .6s both;
    }
    .sdp-disc {
        clip-path: ellipse(10.93% 31.01% at 21.46% 62.79%);
        transform-origin: 21.46% 62.79%;
        animation: sdp-rise .75s cubic-bezier(.34, 1.3, .64, 1) .3s both;
    }
    .sdp-ray { transform-origin: 21.46% 62.79%; animation: sdp-ray .45s cubic-bezier(.34, 1.4, .64, 1) both; }
    .sdp-r1 { clip-path: inset(56.59% 90.26% 33.85% 1.67%); animation-delay: .6s; }
    .sdp-r2 { clip-path: inset(33.59% 88.58% 51.16% 4.33%); animation-delay: .65s; }
    .sdp-r3 { clip-path: inset(16.8% 83.56% 64.34% 10.73%); animation-delay: .7s; }
    .sdp-r4 { clip-path: inset(5.68% 76.87% 70.03% 19.78%); animation-delay: .75s; }
    .sdp-r5 { clip-path: inset(16.8% 67.62% 64.34% 26.67%); animation-delay: .8s; }
    .sdp-r6 { clip-path: inset(33.85% 61.42% 51.16% 31.5%); animation-delay: .85s; }
    .sdp-r7 { clip-path: inset(56.59% 58.96% 33.85% 33.07%); animation-delay: .9s; }
    .sdp-sunrise {
        clip-path: inset(32.3% 1.77% 38.76% 42.81%);
        animation: sdp-wipe .7s cubic-bezier(.22, 1, .36, 1) 1.1s both;
    }
    .sdp-digital {
        clip-path: inset(65.5% 1.77% 19.12% 42.81%);
        transform-origin: 42.81% 72%;
        animation: sdp-track .65s cubic-bezier(.22, 1, .36, 1) 1.4s both;
    }
    .sdp-bar {
        position: relative; width: min(56vw, 240px); height: 6px; border-radius: 99px; overflow: hidden;
        background: rgba(255, 87, 11, .15);
        animation: sdp-fade .4s ease 1.4s both;
    }
    .sdp-bar span {
        position: absolute; inset: 0; border-radius: inherit; background: var(--sdp-orange);
        transform: scaleX(0); transform-origin: left; transition: transform .35s ease-out;
    }
    .sdp-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }

    @keyframes sdp-fade { from { opacity: 0; } }
    @keyframes sdp-glow { from { opacity: 0; transform: scale(.3); } }
    @keyframes sdp-rise { from { opacity: 0; transform: translateY(55%) scale(.55); } }
    @keyframes sdp-ray { from { opacity: 0; transform: scale(.35); } }
    @keyframes sdp-sun-shift { from { transform: translateX(28.54%); } }
    @keyframes sdp-sun-glow {
        0%, 100% { filter: drop-shadow(0 0 0 rgba(255, 87, 11, 0)); }
        45% { filter: drop-shadow(0 0 10px rgba(255, 87, 11, .35)); }
    }
    @keyframes sdp-wipe {
        from { opacity: 0; transform: translateX(-4%); clip-path: inset(32.3% 57.19% 38.76% 42.81%); }
    }
    @keyframes sdp-track { from { opacity: 0; transform: scaleX(1.18); } }

    @media (prefers-reduced-motion: reduce) {
        #sdp, #sdp *, .sdp-sun { animation: none !important; }
    }
</style>
<noscript><style>html.sd-loading { overflow: auto; } #sdp { display: none; }</style></noscript>

<div id="sdp" role="status" aria-live="polite">
    <span class="sdp-sr">Memuat Sunrise Digital…</span>
    <div class="sdp-glow" aria-hidden="true"></div>
    <div class="sdp-logo" aria-hidden="true">
        <div class="sdp-sun">
            <img class="sdp-layer sdp-disc" src="/logo/06-logo-aplikasi-NoBg.png" alt="" fetchpriority="high">
            @foreach (range(1, 7) as $ray)
                <img class="sdp-layer sdp-ray sdp-r{{ $ray }}" src="/logo/06-logo-aplikasi-NoBg.png" alt="">
            @endforeach
        </div>
        <img class="sdp-layer sdp-sunrise" src="/logo/06-logo-aplikasi-NoBg.png" alt="">
        <img class="sdp-layer sdp-digital" src="/logo/06-logo-aplikasi-NoBg.png" alt="">
    </div>
    <div class="sdp-bar" aria-hidden="true"><span></span></div>
</div>

<script>
    (() => {
        const root = document.documentElement;
        const el = document.getElementById("sdp");
        if (!el) return;
        root.classList.add("sd-loading");

        const reduced = matchMedia("(prefers-reduced-motion: reduce)").matches;
        const minDuration = reduced ? 600 : 2000;
        const start = performance.now();
        const bar = el.querySelector(".sdp-bar span");
        // Time-driven on purpose: the overlay closes when the animation ends, whatever the network/API (e.g. a 401) does.
        let finished = false;
        let frame = 0;

        const finish = () => {
            if (finished) return;
            finished = true;
            cancelAnimationFrame(frame);
            clearTimeout(failsafe);
            bar.style.transform = "scaleX(1)";
            setTimeout(() => {
                el.classList.add("sdp-exit");
                root.classList.remove("sd-loading");
                dispatchEvent(new Event("sd:preloader-done"));
                setTimeout(() => el.remove(), 550);
            }, reduced ? 0 : 350);
        };

        const tick = () => {
            const elapsed = performance.now() - start;
            bar.style.transform = "scaleX(" + Math.min(1, elapsed / minDuration) + ")";
            if (elapsed >= minDuration) finish();
            else frame = requestAnimationFrame(tick);
        };
        frame = requestAnimationFrame(tick);
        // rAF is paused in background tabs; the timer still closes the overlay.
        const failsafe = setTimeout(finish, minDuration + 300);
    })();
</script>
