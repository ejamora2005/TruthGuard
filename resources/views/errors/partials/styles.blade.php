/* Standalone error-page tokens follow TruthGuard's ocean and forest palettes. */
:root {
    color-scheme: light dark;
    --canvas: #ffffff;
    --ink: #20344c;
    --muted: #64748b;
    --line: #dde7ef;
    --surface: #ffffff;
    --blue: #2563eb;
    --teal: #187f89;
    --wave-blue: #f0f7fd;
    --wave-mint: #f1faf7;
    --paper-top: #ffffff;
    --paper-bottom: #e8f3fc;
}

* { box-sizing: border-box; }
body {
    margin: 0;
    background: var(--canvas);
    color: var(--ink);
    font-family: Poppins, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    -webkit-font-smoothing: antialiased;
}
a { color: inherit; }
button { font: inherit; }
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* Quiet edge decoration leaves the content on an open, uncluttered canvas. */
.error-page {
    position: relative;
    isolation: isolate;
    display: flex;
    flex-direction: column;
    min-height: 100svh;
    overflow: hidden;
    padding-inline: clamp(22px, 5vw, 76px);
}
.background {
    position: absolute;
    inset: 0;
    z-index: -1;
    pointer-events: none;
    background: radial-gradient(ellipse at 48% 27%, rgba(219, 239, 252, .22), transparent 38%);
}
.background svg { width: 100%; height: 100%; transform-origin: center; animation: wave-drift 20s ease-in-out infinite; }
.wave-blue { fill: var(--wave-blue); }
.wave-mint { fill: var(--wave-mint); }
.accent-dot { position: absolute; width: 5px; height: 5px; border-radius: 50%; background: #bddbdc; }
.dot-one { top: 30%; left: 21%; }
.dot-two { bottom: 28%; right: 22%; width: 4px; height: 4px; }
.accent-plus { position: absolute; top: 21%; right: 24%; color: #c8dfe9; font-size: 19px; font-weight: 300; }

.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    min-height: 100px;
}
.brand { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; font-size: 21px; font-weight: 700; letter-spacing: -.65px; }
.brand img { object-fit: contain; }
.header-home { display: inline-flex; align-items: center; justify-content: center; gap: 9px; min-height: 42px; padding: 10px 15px; border: 1px solid var(--line); border-radius: 9px; background: var(--surface); font-size: 14px; font-weight: 550; text-decoration: none; transition: border-color .18s, transform .18s; }
.header-home svg, .button svg { width: 17px; height: 17px; flex: none; }
.header-home:hover { border-color: #88afcb; transform: translateY(-1px); }

main {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    max-width: 720px;
    margin-inline: auto;
    padding-block: 22px 45px;
    text-align: center;
}
.illustration { width: clamp(190px, 19vw, 250px); margin-bottom: 8px; animation: enter .45s both; }
.error-art { display: block; width: 100%; height: auto; overflow: visible; }
.art-halo { fill: #eaf5fa; opacity: .65; }
.art-shadow { fill: #5685a8; opacity: .09; }
.art-dot { fill: #b7ded9; }
.art-spark { stroke: #bbd9e7; stroke-width: 2; }
.art-depth { fill: #b8d4e8; opacity: .4; }
.art-paper { fill: url(#paper); stroke: #a9c7df; }
.paper-top { stop-color: var(--paper-top); }
.paper-bottom { stop-color: var(--paper-bottom); }
.art-fold { fill: #d6eafb; stroke: #a9c7df; }
.art-lines { stroke: #a7c7df; stroke-width: 4; }
.lens { fill: #f4fcfc; fill-opacity: .88; stroke: #98d0cb; }
.error-code {
    margin: 0 0 12px;
    padding-inline: .06em;
    font-size: clamp(5rem, 10vw, 7.25rem);
    font-weight: 750;
    line-height: 1;
    letter-spacing: -.065em;
    color: #267ca4;
    background: linear-gradient(110deg, #2563b9 12%, #16869d 58%, #34977f 100%);
    background-clip: text;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    filter: drop-shadow(0 3px 2px rgba(30, 95, 145, .045));
    animation: enter .45s .06s both;
}
.error-copy { animation: appear .4s .12s both; }
h1 { margin: 0; font-size: clamp(30px, 3vw, 40px); line-height: 1.25; letter-spacing: -.035em; font-weight: 650; text-wrap: balance; }
.error-copy p { max-width: 540px; margin: 18px auto 0; font-size: 17px; line-height: 1.75; color: var(--muted); text-wrap: pretty; }
.actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 27px; animation: appear .35s .2s both; }
.button { display: inline-flex; justify-content: center; align-items: center; gap: 10px; min-height: 47px; padding: 12px 21px; border: 1px solid transparent; border-radius: 9px; font-size: 15px; font-weight: 600; text-decoration: none; cursor: pointer; transition: transform .18s, box-shadow .18s, border-color .18s; }
.primary { color: #fff; background: linear-gradient(110deg, #187f89, #2468b8); box-shadow: 0 5px 13px rgba(32, 107, 150, .15); }
.secondary { color: var(--ink); background: var(--surface); border-color: var(--line); box-shadow: 0 2px 4px rgba(33, 64, 95, .02); }
.button:hover { transform: translateY(-1px); box-shadow: 0 7px 17px rgba(32, 107, 150, .2); border-color: #72a5c4; }
.button:active, .header-home:active { transform: scale(.98); }
.button-arrow { margin-left: 7px; font-size: 17px; line-height: 1; transition: transform .18s; }
.button:hover .button-arrow { transform: translateX(3px); }
a:focus-visible, button:focus-visible { outline: 3px solid var(--blue); outline-offset: 5px; }

/* A gentle shared float gives the illustrations depth without moving the copy. */
.art-object { animation: illustration-float 5s ease-in-out infinite; }
.art-shadow { transform-box: fill-box; transform-origin: center; animation: shadow-drift 5s ease-in-out infinite; }
/* Each error keeps its small, distinctive animated detail. */
.search-glass { animation: float 7s ease-in-out infinite; }
.shield-lock { animation: breathe 7s ease-in-out infinite; }
.clock-hand { transform-origin: 115px 98px; animation: rotate 36s linear infinite; }
.activity { transform-box: fill-box; transform-origin: bottom; animation: activity 6s ease-in-out infinite; }
.bar-two { animation-delay: -2s; }
.bar-three { animation-delay: -4s; }
.warning { animation: breathe 7s ease-in-out infinite; }
.gear { transform-origin: 182px 138px; animation: rotate 30s linear infinite; }
@keyframes appear { from { opacity: 0; } to { opacity: 1; } }
@keyframes enter { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
@keyframes float { 50% { transform: translateY(-4px); } }
@keyframes breathe { 50% { opacity: .78; } }
@keyframes rotate { to { transform: rotate(360deg); } }
@keyframes activity { 50% { transform: scaleY(.88); } }
@keyframes illustration-float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-6px); }
}
@keyframes shadow-drift {
    0%, 100% { transform: scaleX(1); }
    50% { transform: scaleX(.88); }
}
@keyframes wave-drift {
    0%, 100% { transform: scale(1.03) translate(0, 0); }
    50% { transform: scale(1.03) translate(-.5%, .5%); }
}

/* Match the existing error pages' system-theme support, keeping the same composition. */
@media (prefers-color-scheme: dark) {
    :root { --canvas: #182331; --ink: #e1eaf3; --muted: #adbdcb; --line: #364655; --surface: #202f3e; --wave-blue: #1b2a3a; --wave-mint: #1a2d36; --blue: #87baff; --paper-top: #f0f8ff; --paper-bottom: #daeaf7; }
    .background { background: radial-gradient(ellipse at 48% 27%, rgba(71, 109, 137, .08), transparent 38%); }
    .art-halo { fill: #2c4353; }
    .art-shadow { fill: #0b1520; opacity: .2; }
    .art-dot, .accent-dot { opacity: .3; }
    .art-spark, .accent-plus { opacity: .25; }
    .error-code { background-image: linear-gradient(110deg, #8ebaff, #80c7db 58%, #86cfb9); }
    .primary { background: linear-gradient(110deg, #217c85, #2863ac); box-shadow: 0 4px 12px rgba(0, 0, 0, .12); }
}
@media (max-width: 650px) {
    .page-header { min-height: 80px; }
    .brand { font-size: 18px; gap: 7px; }
    .brand img { width: 34px; height: 34px; }
    .header-home { font-size: 13px; padding: 9px 11px; gap: 7px; }
    .header-home svg { width: 15px; height: 15px; }
    main { padding-block: 20px 32px; }
    .illustration { width: 190px; margin-bottom: 6px; }
    .error-copy p { max-width: 380px; font-size: 16px; margin-top: 14px; }
    .error-code { margin-bottom: 14px; }
    .actions { margin-top: 24px; gap: 10px; }
    .button { padding-inline: 16px; font-size: 14px; }
    .button-arrow { margin-left: 0; }
    .accent-dot, .accent-plus { display: none; }
    .background svg { opacity: .65; }
}
@media (max-width: 360px) {
    .header-home { padding: 10px; }
    .header-home span { font-size: 12px; }
    .page-header { gap: 8px; }
    .brand { font-size: 16px; }
    .brand img { width: 30px; height: 30px; }
    .actions { flex-direction: column; width: min(100%, 260px); }
}
@media (max-height: 760px) and (min-width: 651px) {
    .page-header { min-height: 80px; }
    main { padding-block: 12px 24px; }
    .illustration { width: 190px; }
    .error-code { font-size: 88px; }
}
@media (max-height: 760px) and (max-width: 650px) {
    .page-header { min-height: 72px; }
    main { padding-block: 8px 16px; }
    .illustration { width: 160px; }
    .error-code { font-size: 72px; margin-bottom: 10px; }
    h1 { font-size: 28px; }
    .error-copy p { margin-top: 12px; }
    .actions { margin-top: 22px; }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; }
}
@media (forced-colors: active) {
    .error-code { -webkit-text-fill-color: CanvasText; background: none; filter: none; }
    .primary { border-color: ButtonText; }
}
