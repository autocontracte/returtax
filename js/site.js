/* Returtax — comportament comun pentru toate paginile:
 * meniul de pe telefon, antetul, anul din subsol, calculatorul, formularele de contact,
 * pop-up-ul „Sunați-ne” (pe calculator) și butonul de WhatsApp. */
(function () {
  "use strict";

  /* ---------- Id-ul vizitei (leagă chat-ul, formularele și WhatsApp în panoul de admin) ---------- */
  function conversationId() {
    let id = "";
    try { id = sessionStorage.getItem("rt_conv") || ""; } catch (e) {}
    if (!id) {
      id = window.crypto && crypto.randomUUID
        ? crypto.randomUUID()
        : "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
          });
      try { sessionStorage.setItem("rt_conv", id); } catch (e) {}
    }
    return id;
  }
  window.rtConversationId = conversationId;

  /* ---------- Meniu (telefon) ---------- */
  const menuBtn = document.querySelector(".menu-btn");
  const menu = document.getElementById("mobile-menu");

  function setMenu(open) {
    if (!menuBtn || !menu) return;
    menuBtn.setAttribute("aria-expanded", String(open));
    menuBtn.setAttribute("aria-label", open ? "Închide meniul" : "Deschide meniul");
    menu.hidden = !open;
  }
  document.addEventListener("click", (e) => {
    if (e.target.closest('a[href^="/#"], a[href^="#"]')) document.body.classList.add("browsing");
  });
  if (menuBtn && menu) {
    menuBtn.addEventListener("click", () => setMenu(menu.hidden));
    menu.addEventListener("click", (e) => { if (e.target.closest("a")) setMenu(false); });
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") setMenu(false); });
    document.addEventListener("click", (e) => {
      if (!menu.hidden && !e.target.closest(".topbar")) setMenu(false);
    });
  }

  /* ---------- Antet: linie fină după ce dați scroll ---------- */
  const topbar = document.querySelector(".topbar");
  if (topbar) {
    const onScroll = () => topbar.classList.toggle("scrolled", window.scrollY > 4);
    window.addEventListener("scroll", onScroll, { passive: true });
    onScroll();
  }

  document.querySelectorAll(".year").forEach((el) => (el.textContent = new Date().getFullYear()));

  const eur = (n) => new Intl.NumberFormat("ro-RO", { maximumFractionDigits: 0 }).format(n) + " €";

  /* ---------- Calculator ----------
   * Estimare după regulile fiscale norvegiene din 2025 (aceeași logică ca rt_estimate din api/_lib.php):
   * cât s-a reținut prin schema PAYE (25% fix, fără deduceri) minus cât trebuia plătit
   * cu impozitarea obișnuită (trygdeavgift, trinnskatt, 22% după deduceri).
   * Valorile marcate „ipoteză” se pot ajusta pe măsură ce aveți date din dosarele reale. */
  const TAX = {
    nokPerEur: 11.7,
    payeRate: 0.25, payeMax: 697150,
    trygdRate: 0.077, trygdMin: 99650,
    trinn: [[217401, 0.017], [306051, 0.04], [697151, 0.137], [942401, 0.167], [1410751, 0.177]],
    ordinaryRate: 0.22,
    minsteRate: 0.46, minsteMax: 92000,
    personfradrag: 108550,
    lodgingMonth: 4500,   // ipoteză: cost lunar de cazare plătit singur
    trips: 4,             // ipoteză: drumuri dus-întors acasă pe an
    kmOneWay: 2400,       // ipoteză: distanța medie România – Norvegia
    kmRate: 1.83, travelFloor: 15250, travelMax: 100880,
    loanInterest: 15000,  // ipoteză: dobânzi anuale la un credit
    spread: 0.10,         // intervalul afișat: ±10%
    freeUnder: 1000, fee: 100,
  };

  function taxOrdinary(g, deductions) {
    const trygd = g > TAX.trygdMin ? Math.min(TAX.trygdRate * g, 0.25 * (g - TAX.trygdMin)) : 0;
    let trinn = 0;
    TAX.trinn.forEach(([from, rate], i) => {
      const to = i + 1 < TAX.trinn.length ? TAX.trinn[i + 1][0] : Infinity;
      if (g > from) trinn += (Math.min(g, to) - from) * rate;
    });
    return trygd + trinn + TAX.ordinaryRate * Math.max(0, g - deductions);
  }

  function refundYear(g, months, housing, travel, family, loan) {
    const basic = Math.min(TAX.minsteRate * g, TAX.minsteMax) + (TAX.personfradrag * months) / 12;
    let extra = 0;
    if (housing && (family || travel)) extra += TAX.lodgingMonth * months;
    if (travel) extra += Math.max(0, Math.min(TAX.trips * 2 * TAX.kmOneWay * TAX.kmRate, TAX.travelMax) - TAX.travelFloor);
    if (loan) extra += TAX.loanInterest;
    const actual = taxOrdinary(g, basic + extra);
    const withheld = g <= TAX.payeMax ? TAX.payeRate * g : taxOrdinary(g, basic);
    return Math.max(0, withheld - actual);
  }

  const calc = document.getElementById("calc-form");
  if (calc) {
    const $ = (id) => document.getElementById(id);
    const months = $("calc-months");
    const monthsOut = $("calc-months-out");
    let lastYears = 2;

    function compute() {
      const salary = Math.max(0, parseFloat($("calc-salary").value) || 0);
      const currency = $("calc-currency").value;
      const m = parseInt(months.value, 10);
      const years = parseInt($("calc-years").value, 10);
      lastYears = years;
      monthsOut.textContent = m + (m === 1 ? " lună" : " luni");

      const monthlyNok = currency === "NOK" ? salary : salary * TAX.nokPerEur;
      const perYear = refundYear(monthlyNok * m, m, calc.housing.checked, calc.travel.checked, calc.family.checked, calc.loan.checked);
      const total = (perYear * years) / TAX.nokPerEur;

      const low = Math.round((total * (1 - TAX.spread)) / 10) * 10;
      const high = Math.round((total * (1 + TAX.spread)) / 10) * 10;
      // Comisionul depinde de suma recuperată: 0 € sub 1.000 €, 100 € fix peste
      const feeLow = low >= TAX.freeUnder ? TAX.fee : 0;
      const feeHigh = high >= TAX.freeUnder ? TAX.fee : 0;

      $("calc-low").textContent = eur(low);
      $("calc-high").textContent = eur(high);
      $("calc-fee").textContent = feeLow === feeHigh
        ? (feeHigh ? eur(feeHigh) + " fix" : "0 € (gratuit)")
        : "0 € sau 100 €";
      $("calc-net").textContent = eur(low - feeLow) + " – " + eur(high - feeHigh);
    }

    calc.addEventListener("input", compute);
    calc.addEventListener("change", compute);
    compute();

    // „Vreau verificarea exactă”: deschidem pop-up-ul cu formular, cu datele din calculator deja trecute
    const dialog = $("calc-dialog");
    $("calc-cta").addEventListener("click", () => {
      const estimate = $("calc-low").textContent + " – " + $("calc-high").textContent;
      const checks = ["housing", "travel", "family", "loan"]
        .filter((n) => calc[n].checked)
        .map((n) => calc[n].parentElement.textContent.trim());
      $("calc-dialog-estimate").textContent = estimate;
      $("calc-dialog-message").value = [
        "Din calculator: " + $("calc-salary").value + " " + $("calc-currency").value + "/lună",
        months.value + " luni/an",
        lastYears + (lastYears === 1 ? " an" : " ani"),
        checks.join("; ") || "fără situații bifate",
        "estimare " + estimate,
      ].join(" · ");
      if (dialog && dialog.showModal) dialog.showModal();
      else location.href = "/#contact";
    });
    if (dialog) {
      dialog.querySelector("[data-close]").addEventListener("click", () => dialog.close());
      // clic pe fundalul întunecat închide pop-up-ul
      dialog.addEventListener("click", (e) => { if (e.target === dialog) dialog.close(); });
    }
  }

  /* ---------- Formulare de contact (pagina de contact și pop-up-ul din calculator) ---------- */
  document.querySelectorAll(".lead-form").forEach((form) => {
    const status = form.querySelector(".form-status");
    const show = (msg, ok) => { status.textContent = msg; status.className = "form-status " + (ok ? "ok" : "err"); };

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!form.reportValidity()) return;
      const digits = form.phone.value.replace(/\D/g, "");
      if (digits.length < 8 || digits.length > 15) {
        show("Vă rugăm verificați numărul de telefon.", false);
        form.phone.focus();
        return;
      }
      const btn = form.querySelector("button[type=submit]");
      btn.disabled = true;
      try {
        const data = new FormData(form);
        data.append("conversation_id", conversationId());
        const res = await fetch(form.action, { method: "POST", body: data, headers: { Accept: "application/json" } });
        if (!res.ok) throw new Error(res.status);
        const keep = form.querySelector("[name=message][type=hidden]");
        const kept = keep && keep.value;
        form.reset();
        if (keep) keep.value = kept;
        show("Mulțumim! Vă sunăm în cel mai scurt timp.", true);
        const dialog = form.closest("dialog");
        if (dialog) setTimeout(() => dialog.close(), 2500);
      } catch (err) {
        show("Nu am putut trimite mesajul. Vă rugăm sunați-ne la 0752 176 807.", false);
      } finally {
        btn.disabled = false;
      }
    });
  });
  /* ---------- Contact rapid: „Sunați-ne” pe calculator + buton WhatsApp ---------- */
  const PHONE_DISPLAY = "0752 176 807";
  const PHONE_TEL = "+40752176807";
  const WA_NUMBER = "40752176807";
  const hasMouse = window.matchMedia("(hover: hover) and (pointer: fine)").matches;

  const WA_ICON =
    '<svg aria-hidden="true" viewBox="0 0 24 24" width="28" height="28"><path fill="currentColor" d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.69.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.05 21.5a9.4 9.4 0 0 1-4.79-1.31l-.34-.2-3.56.93.95-3.47-.22-.36a9.4 9.4 0 0 1-1.44-5.01c0-5.2 4.23-9.43 9.44-9.43 2.52 0 4.89.98 6.67 2.77a9.36 9.36 0 0 1 2.76 6.67c0 5.2-4.24 9.43-9.44 9.43zm8.03-17.46A11.3 11.3 0 0 0 12.05.7C5.8.7.7 5.79.7 12.05c0 2 .52 3.95 1.52 5.67L.6 23.3l5.72-1.5a11.3 11.3 0 0 0 5.73 1.46c6.25 0 11.35-5.09 11.35-11.35 0-3.03-1.18-5.88-3.33-8.02z"/></svg>';
  const CLOSE_ICON =
    '<svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z"/></svg>';

  function closeOnBackdrop(dialog) {
    dialog.addEventListener("click", (e) => { if (e.target === dialog) dialog.close(); });
    dialog.querySelectorAll("[data-close]").forEach((b) => b.addEventListener("click", () => dialog.close()));
  }

  // Pe calculator, „Sunați-ne” deschide un pop-up cu numărul și un cod QR pentru WhatsApp.
  // Pe telefon, linkul tel: sună direct.
  if (hasMouse) {
    const callDialog = document.createElement("dialog");
    callDialog.className = "modal call-modal";
    callDialog.setAttribute("aria-labelledby", "call-title");
    callDialog.innerHTML =
      '<button type="button" class="modal-close" aria-label="Închide" data-close>' + CLOSE_ICON + "</button>" +
      '<p class="kicker">Contact</p>' +
      '<h2 id="call-title">Vorbiți cu echipa Returtax</h2>' +
      '<a class="call-number" href="tel:' + PHONE_TEL + '">' + PHONE_DISPLAY + "</a>" +
      '<p class="call-hours">Luni – Vineri, 9:00 – 18:00</p>' +
      '<div class="call-qr">' +
      '<img src="/assets/whatsapp-qr.svg" alt="Cod QR pentru WhatsApp Returtax" width="148" height="148">' +
      "<div><p><strong>Preferați WhatsApp?</strong></p>" +
      "<p>Scanați codul cu camera telefonului și ne scrieți direct.</p>" +
      '<a class="btn btn-wa" href="https://wa.me/' + WA_NUMBER + '" target="_blank" rel="noopener">' + WA_ICON + "WhatsApp Web</a></div>" +
      "</div>";
    document.body.appendChild(callDialog);
    closeOnBackdrop(callDialog);

    document.addEventListener("click", (e) => {
      const link = e.target.closest('a[href^="tel:"]');
      if (!link || callDialog.contains(link)) return;
      e.preventDefault();
      callDialog.showModal();
    });
  }

  /* Butonul WhatsApp (dreapta jos): 3 întrebări scurte, apoi deschide WhatsApp cu mesajul deja scris */
  const fab = document.createElement("button");
  fab.type = "button";
  fab.className = "wa-fab";
  fab.setAttribute("aria-label", "Scrieți-ne pe WhatsApp");
  fab.setAttribute("aria-expanded", "false");
  fab.setAttribute("aria-controls", "wa-panel");
  fab.innerHTML = WA_ICON;

  const panel = document.createElement("section");
  panel.id = "wa-panel";
  panel.className = "wa-panel";
  panel.hidden = true;
  panel.setAttribute("aria-label", "Scrieți-ne pe WhatsApp");
  panel.innerHTML =
    '<header class="wa-head">' +
    // Echipa: ultima poză (cea din față) este persoana care răspunde pe WhatsApp
    '<div class="wa-team" aria-hidden="true">' +
    '<img src="/assets/echipa-1.webp" alt="" width="22" height="22">' +
    '<img src="/assets/echipa-3.webp" alt="" width="22" height="22">' +
    '<img src="/assets/echipa-2.webp" alt="" width="22" height="22">' +
    "</div>" +
    '<div class="wa-head-text"><p class="wa-title">Echipa Returtax</p>' +
    '<p class="wa-sub"><span class="wa-dot"></span>Echipa e disponibilă acum</p></div>' +
    '<button type="button" class="wa-close" aria-label="Închide">' + CLOSE_ICON + "</button>" +
    "</header>" +
    '<div class="wa-body" aria-live="polite"></div>';

  document.body.appendChild(panel);
  document.body.appendChild(fab);

  const waBody = panel.querySelector(".wa-body");
  const answers = {};
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));

  function say(html, who) {
    const p = document.createElement("div");
    p.className = "wa-msg " + (who === "user" ? "wa-user" : "wa-bot");
    p.innerHTML = html;
    waBody.appendChild(p);
    waBody.scrollTop = waBody.scrollHeight;
    return p;
  }

  function choices(options, onPick) {
    const box = document.createElement("div");
    box.className = "wa-choices";
    options.forEach((label) => {
      const b = document.createElement("button");
      b.type = "button";
      b.className = "chip";
      b.textContent = label;
      b.addEventListener("click", () => { box.remove(); say(escape(label), "user"); onPick(label); });
      box.appendChild(b);
    });
    waBody.appendChild(box);
    waBody.scrollTop = waBody.scrollHeight;
  }

  function escape(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }

  async function ask(html, delay) {
    await wait(delay || 450);
    say(html);
  }

  async function startFlow() {
    waBody.innerHTML = "";
    await ask("Bună ziua! 👋 Ca să vă punem în legătură cu persoana potrivită din echipă, aveți doar <strong>3 întrebări scurte</strong>.", 150);
    await ask("<strong>1/3</strong> · Pentru ce perioadă doriți să recuperați taxele?");
    choices(["Anul trecut", "Ultimii 2–3 ani", "Mai mulți ani", "Nu știu sigur"], async (v) => {
      answers.years = v;
      await ask("<strong>2/3</strong> · Aveți D-nummer și MinID?");
      choices(["Am amândouă", "Doar D-nummer", "Nu am / nu știu"], async (v2) => {
        answers.docs = v2;
        await ask("<strong>3/3</strong> · Cum vă numiți?");
        nameStep();
      });
    });
  }

  function nameStep() {
    const form = document.createElement("form");
    form.className = "wa-name";
    form.innerHTML =
      '<label class="visually-hidden" for="wa-name-input">Numele dumneavoastră</label>' +
      '<input id="wa-name-input" type="text" autocomplete="name" placeholder="Numele dumneavoastră">' +
      '<button type="submit" class="btn btn-dark">Continuă</button>';
    waBody.appendChild(form);
    waBody.scrollTop = waBody.scrollHeight;
    const field = form.querySelector("input");
    if (hasMouse) field.focus();
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      answers.name = field.value.trim();
      form.remove();
      if (answers.name) say(escape(answers.name), "user");
      matchColleague();
    });
  }

  // „Căutăm persoana potrivită” — dă impresia unei echipe care preia cererea
  async function matchColleague() {
    await wait(300);
    const box = say(
      '<div class="wa-search"><span class="wa-spinner" aria-hidden="true"></span>' +
      "<div><strong>Căutăm persoana potrivită pentru dumneavoastră…</strong>" +
      '<p class="wa-status">Verificăm cine din echipă e disponibil</p></div></div>'
    );
    const status = box.querySelector(".wa-status");
    await wait(1100);
    status.textContent = "Analizăm situația descrisă";
    await wait(1100);
    status.textContent = "Alegem un specialist pentru cazul dumneavoastră";
    await wait(1000);
    box.innerHTML =
      '<div class="wa-found"><span class="wa-avatar" aria-hidden="true"><img src="/assets/echipa-2.webp" alt="" width="26" height="26"><span class="wa-check">✓</span></span>' +
      "<div><strong>Am găsit un coleg disponibil.</strong>" +
      "<p>Vă răspunde pe WhatsApp, de obicei în câteva minute.</p></div></div>";

    // Salvăm în panoul de admin cine a ajuns la WhatsApp
    fetch("/api/log.php", {
      method: "POST",
      keepalive: true,
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ type: "whatsapp", conversation_id: conversationId(), name: answers.name, years: answers.years, docs: answers.docs }),
    }).catch(() => {});

    const lines = [
      "Bună ziua!" + (answers.name ? " Mă numesc " + answers.name + "." : ""),
      "Am lucrat în Norvegia și aș dori să recuperez taxele.",
      "Perioada: " + answers.years + ".",
      "D-nummer / MinID: " + answers.docs + ".",
    ];
    const link = document.createElement("a");
    link.className = "btn btn-wa wa-go";
    link.href = "https://wa.me/" + WA_NUMBER + "?text=" + encodeURIComponent(lines.join("\n"));
    link.target = "_blank";
    link.rel = "noopener";
    link.innerHTML = WA_ICON + "Continuați pe WhatsApp";
    waBody.appendChild(link);

    const again = document.createElement("button");
    again.type = "button";
    again.className = "wa-again";
    again.textContent = "Începeți din nou";
    again.addEventListener("click", startFlow);
    waBody.appendChild(again);
    waBody.scrollTop = waBody.scrollHeight;
  }

  let started = false;
  function setPanel(open) {
    panel.hidden = !open;
    fab.setAttribute("aria-expanded", String(open));
    fab.classList.toggle("open", open);
    if (open && !started) { started = true; startFlow(); }
  }
  fab.addEventListener("click", () => setPanel(panel.hidden));
  panel.querySelector(".wa-close").addEventListener("click", () => setPanel(false));
  document.addEventListener("keydown", (e) => { if (e.key === "Escape" && !panel.hidden) setPanel(false); });
})();
