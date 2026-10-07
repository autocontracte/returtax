/* ReturTax — Dexter, asistentul conversațional (versiune locală, fără server).
 *
 * Pentru început, asistentul înțelege câteva teme prin cuvinte-cheie și
 * ghidează omul spre o discuție telefonică cu un coleg. Mai târziu,
 * funcția `replyTo()` poate fi înlocuită cu un apel către un backend AI
 * (ex. un Cloudflare Worker), fără să schimbăm interfața.
 */
(function () {
  "use strict";

  const log = document.getElementById("chat-log");
  const form = document.getElementById("chat-form");
  const input = document.getElementById("chat-input");
  const quick = document.getElementById("quick-replies");
  const micBtn = document.getElementById("mic-btn");

  const tplBot = document.getElementById("bot-tpl");
  const STORE_KEY = "returtax_leads";

  // Ce știm despre om până acum
  const lead = { ani: null, acte: null, nume: null, telefon: null, cand: null, note: [] };
  // Ce așteptăm ca răspuns următor (null = conversație liberă)
  let expecting = null;

  /* ---------- Afișarea mesajelor ---------- */
  function scrollDown() { log.scrollTop = log.scrollHeight; }

  function addUser(text) {
    const li = document.createElement("li");
    li.className = "msg msg-user";
    const b = document.createElement("div");
    b.className = "bubble";
    b.textContent = text;
    li.appendChild(b);
    log.appendChild(li);
    scrollDown();
  }

  /* ---------- Dexter (avatarul asistentului) ---------- */
  function makeBot(extra) {
    const svg = tplBot.content.firstElementChild.cloneNode(true);
    if (extra) svg.classList.add(...extra.split(" "));
    return svg;
  }

  // Doar ultimul Dexter din conversație e „viu” (clipește, se uită la ce scrieți)
  function setLive(bot) {
    log.querySelectorAll(".bot.live").forEach((t) => t.classList.remove("live", "listening"));
    bot.classList.add("live");
  }

  function addBot(html) {
    const li = document.createElement("li");
    li.className = "msg msg-bot";
    const bot = makeBot("avatar");
    li.appendChild(bot);
    li.insertAdjacentHTML("beforeend", '<div class="bubble">' + html + "</div>");
    log.appendChild(li);
    setLive(bot);
    scrollDown();
  }

  function showTyping() {
    const li = document.createElement("li");
    li.className = "msg msg-bot typing";
    li.setAttribute("aria-label", "Dexter scrie");
    const bot = makeBot("avatar talking");
    li.appendChild(bot);
    li.insertAdjacentHTML("beforeend", '<div class="bubble"><i></i><i></i><i></i></div>');
    log.appendChild(li);
    setLive(bot);
    scrollDown();
    return li;
  }

  function setChips(options) {
    quick.innerHTML = "";
    (options || []).forEach((label) => {
      const b = document.createElement("button");
      b.type = "button";
      b.className = "chip";
      b.textContent = label;
      b.addEventListener("click", () => handle(label));
      quick.appendChild(b);
    });
  }

  // Trimite răspunsurile botului unul după altul, cu o mică pauză (pare mai omenesc)
  function botSay(messages, chips) {
    setChips([]);
    const list = Array.isArray(messages) ? messages : [messages];
    let i = 0;
    (function next() {
      if (i >= list.length) { setChips(chips); return; }
      const t = showTyping();
      const delay = reduceMotion ? 150 : Math.min(500 + list[i].length * 6, 1400);
      setTimeout(() => { t.remove(); addBot(list[i++]); next(); }, delay);
    })();
  }

  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------- Înțelegerea mesajelor ---------- */
  function norm(s) {
    return s.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/\s+/g, " ").trim();
  }
  const has = (t, words) => words.some((w) => t.includes(w));

  const CHIPS_MAIN = ["Am lucrat în Norvegia", "Cât pot primi înapoi?", "Ce acte îmi trebuie?", "Vreau să mă sune cineva"];

  function detectIntent(t) {
    if (has(t, ["suna", "sune", "sunat", "telefon", "apel", "vorbesc cu un om", "vorbi cu cineva", "om real"])) return "call";
    if (has(t, ["cost", "comision", "platesc", "plata", "pret", "cat luati", "procent"])) return "cost";
    if (has(t, ["acte", "document", "hartii", "ce trebuie", "ce imi trebuie", "fluturas", "payslip"])) return "docs";
    if (has(t, ["cat dureaza", "cand primesc", "cat timp", "termen", "luni"])) return "time";
    if (has(t, ["cat pot", "cati bani", "suma", "cat primesc", "inapoi", "returnare", "recuperez", "recupera"])) return "money";
    if (has(t, ["lucrat", "muncit", "norvegia", "norge", "am fost", "constructii", "pescarie", "santier"])) return "worked";
    if (has(t, ["multumesc", "mersi", "multam"])) return "thanks";
    if (has(t, ["buna", "salut", "neata", "ziua", "seara"]) && t.length < 25) return "hello";
    return "unknown";
  }

  function findYears(t) {
    const ys = (t.match(/20[12]\d/g) || []).map(Number).filter((y) => y >= 2010 && y <= new Date().getFullYear());
    return [...new Set(ys)].sort();
  }

  function cleanPhone(raw) {
    const d = raw.replace(/[^\d+]/g, "");
    const digits = d.replace(/\D/g, "");
    if (digits.length < 8 || digits.length > 15) return null;
    return d;
  }

  /* ---------- Conversația ---------- */
  function handle(raw) {
    const text = raw.trim();
    if (!text) return;
    startChat();
    addUser(text);
    setChips([]);
    replyTo(text);
  }

  function replyTo(text) {
    let t = norm(text);

    // Butoane care au nevoie de tratare specială
    if (expecting === null && t === "da, sa incepem") t = "am lucrat";
    if (expecting === "ani" && t === "mai multi ani") {
      return botSay("Scrieți-mi, vă rog, toți anii, de exemplu: <strong>2021, 2022, 2023</strong>.");
    }

    // Răspunsuri la întrebări puse de noi
    if (expecting === "ani") {
      const ys = findYears(t);
      lead.ani = ys.length ? ys.join(", ") : text;
      expecting = "acte";
      return botSay(
        [
          ys.length
            ? "Am notat: <strong>" + ys.join(", ") + "</strong>. Mulțumesc!"
            : "Am notat. Mulțumesc!",
          "Mai aveți fluturașii de salariu sau hârtia de la Skatteetaten (fisc-ul norvegian)? Nu e nicio problemă dacă nu le mai aveți — le putem cere noi.",
        ],
        ["Da, le am", "Am doar o parte", "Nu le mai am"]
      );
    }

    if (expecting === "acte") {
      lead.acte = text;
      expecting = "vrea_apel";
      return botSay(
        [
          "Foarte bine. Din ce ne-ați spus, merită să verificăm situația dumneavoastră.",
          "Mulți români care au lucrat în Norvegia au plătit mai mult decât trebuia — mai ales cei care și-au plătit singuri drumul sau cazarea, sau au avut familia acasă în România.",
          "Cel mai simplu este să vă sune un coleg, gratuit, să vă explice exact ce urmează. Sunteți de acord?",
        ],
        ["Da, să mă sune", "Mai am o întrebare"]
      );
    }

    if (expecting === "vrea_apel") {
      expecting = null;
      if (has(t, ["da", "sa ma sune", "sunati", "ok", "bine", "sigur"])) return askName();
      return botSay("Sigur, întrebați-mă orice. Scrieți-mi sau folosiți microfonul.", CHIPS_MAIN);
    }

    if (expecting === "nume") {
      lead.nume = text.replace(/^(ma numesc|numele meu e(ste)?|sunt)\s+/i, "");
      expecting = "telefon";
      return botSay(
        "Mulțumesc, " + escapeHtml(lead.nume.split(" ")[0]) + "! La ce număr de telefon vă putem suna? Poate fi număr din România sau din Norvegia."
      );
    }

    if (expecting === "telefon") {
      const p = cleanPhone(text);
      if (!p) {
        return botSay("Nu am înțeles bine numărul. Vă rog scrieți-l doar cu cifre, de exemplu: <strong>0722 123 456</strong>.");
      }
      lead.telefon = p;
      expecting = "cand";
      return botSay("Când vă este cel mai bine să vă sunăm?", ["Dimineața", "După-amiaza", "Seara", "Oricând"]);
    }

    if (expecting === "cand") {
      lead.cand = text;
      expecting = null;
      saveLead();
      return botSay(
        [
          "Gata, am notat totul! ✅",
          "<p><strong>" + escapeHtml(lead.nume) + "</strong>, un coleg vă va suna la <strong>" + escapeHtml(lead.telefon) +
            "</strong> (" + escapeHtml(lead.cand.toLowerCase()) + ").</p><p>Până atunci, dacă aveți întrebări, sunt aici.</p>",
        ],
        ["Ce acte îmi trebuie?", "Cât costă?", "Cât durează?"]
      );
    }

    // Conversație liberă
    lead.note.push(text);
    switch (detectIntent(t)) {
      case "worked":
        expecting = "ani";
        return botSay(
          [
            "Foarte bine că mi-ați scris! Eu sunt Dexter și asta e exact ce facem la ReturTax: recuperăm banii pe care statul norvegian i-a reținut în plus.",
            "În ce ani ați lucrat în Norvegia? Puteți scrie anii, de exemplu: <strong>2023, 2024</strong>.",
          ],
          yearChips()
        );
      case "money":
        return botSay(
          [
            "Suma diferă de la om la om — depinde de cât ați câștigat, câte luni ați stat și ce cheltuieli ați avut (drum, cazare, familia din România).",
            "Ca să vă spunem o cifră corectă, trebuie să ne uităm pe acte. Verificarea este <strong>gratuită</strong>. Vreți să începem?",
          ],
          ["Da, să începem", "Vreau să mă sune cineva"]
        );
      case "docs":
        return botSay(
          [
            "<p>De obicei ne ajută:</p><ul>" +
              "<li>buletinul sau pașaportul</li>" +
              "<li>numărul norvegian (D-nummer sau fødselsnummer)</li>" +
              "<li>fluturașii de salariu sau contractul</li>" +
              "<li>contul bancar (IBAN) unde vreți banii</li></ul>",
            "Nu vă îngrijorați dacă vă lipsesc unele hârtii — multe le putem cere noi de la Skatteetaten.",
          ],
          ["Am lucrat în Norvegia", "Vreau să mă sune cineva"]
        );
      case "time":
        return botSay(
          "De obicei durează câteva luni, în funcție de răspunsul fiscului norvegian. Vă ținem la curent la fiecare pas, la telefon, în română.",
          ["Am lucrat în Norvegia", "Vreau să mă sune cineva"]
        );
      case "cost":
        return botSay(
          "Verificarea este gratuită. Dacă nu recuperăm nimic, nu plătiți nimic. Colegul nostru vă spune exact comisionul la telefon, înainte să semnați ceva.",
          ["Vreau să mă sune cineva", "Am lucrat în Norvegia"]
        );
      case "call":
        return askName();
      case "thanks":
        return botSay("Cu mare drag! Mai pot să vă ajut cu ceva?", CHIPS_MAIN);
      case "hello":
        return botSay("Bună ziua! Eu sunt Dexter. Spuneți-mi pe scurt: ați lucrat în Norvegia? În ce ani?", CHIPS_MAIN);
      default:
        return botSay(
          [
            "Vă mulțumesc că mi-ați spus. Vreau să fiu sigur că vă ajut corect.",
            "Un coleg poate să vă sune și să discutați liniștit, în română. Sau alegeți una din variantele de mai jos.",
          ],
          CHIPS_MAIN
        );
    }
  }

  function askName() {
    expecting = "nume";
    botSay("Cu plăcere vă sunăm. Cum vă numiți?");
    setTimeout(() => input.focus(), 600);
  }

  function yearChips() {
    const y = new Date().getFullYear();
    return [String(y - 3), String(y - 2), String(y - 1), "Mai mulți ani"];
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }

  // Deocamdată salvăm local. TODO: trimitere către backend (Cloudflare Worker / e-mail).
  function saveLead() {
    const entry = Object.assign({ data: new Date().toISOString() }, lead);
    try {
      const all = JSON.parse(localStorage.getItem(STORE_KEY) || "[]");
      all.push(entry);
      localStorage.setItem(STORE_KEY, JSON.stringify(all));
    } catch (e) {}
    console.info("[ReturTax] Lead nou:", entry);
  }

  /* ---------- Formular ---------- */
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    const v = input.value;
    input.value = "";
    autosize();
    handle(v);
  });
  // Căsuța crește după text; când e goală, încape tot textul-ajutor (pe telefon trece pe 2 rânduri)
  function autosize() {
    input.style.height = "auto";
    const empty = !input.value;
    if (empty) input.value = input.placeholder;
    const h = input.scrollHeight;
    if (empty) input.value = "";
    input.style.height = Math.min(h, 160) + "px";
  }
  autosize();
  if (document.fonts) document.fonts.ready.then(autosize); // remăsurăm după ce se încarcă Montserrat
  window.addEventListener("resize", autosize);
  input.addEventListener("input", () => {
    autosize();
    // Dexter „ascultă” cât timp omul scrie
    const typing = input.value.trim().length > 0;
    document.querySelectorAll(".bot.live").forEach((t) => t.classList.toggle("listening", typing));
  });

  // Enter trimite, Shift+Enter rând nou
  input.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });

  /* ---------- Microfon (dictare în română) ---------- */
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (SR) {
    micBtn.hidden = false;
    const rec = new SR();
    rec.lang = "ro-RO";
    rec.interimResults = true;
    rec.continuous = false;
    let listening = false;

    rec.onresult = (ev) => {
      input.value = Array.from(ev.results).map((r) => r[0].transcript).join(" ");
    };
    rec.onend = () => {
      listening = false;
      micBtn.setAttribute("aria-pressed", "false");
      if (input.value.trim()) form.requestSubmit();
    };
    rec.onerror = () => rec.onend();

    micBtn.addEventListener("click", () => {
      if (listening) return rec.stop();
      input.value = "";
      try { rec.start(); } catch (e) { return; }
      listening = true;
      micBtn.setAttribute("aria-pressed", "true");
    });
  }

  /* ---------- Început ---------- */
  // Ecran de start ca la ChatGPT: titlu + căsuță de scris. La primul mesaj titlul dispare.
  let started = false;
  function startChat() {
    if (started) return;
    started = true;
    document.body.classList.add("chatting");
    window.scrollTo(0, 0);
  }

  document.getElementById("hero-bot").appendChild(makeBot("live"));
  document.getElementById("year").textContent = new Date().getFullYear();

  // Butonul „Începeți conversația” din josul paginii: urcăm la chat și deschidem căsuța de scris
  document.querySelectorAll("[data-start-chat]").forEach((a) =>
    a.addEventListener("click", (e) => {
      e.preventDefault();
      window.scrollTo({ top: 0, behavior: reduceMotion ? "auto" : "smooth" });
      setTimeout(() => input.focus({ preventScroll: true }), reduceMotion ? 0 : 400);
    })
  );
  setChips(CHIPS_MAIN);
  if (window.matchMedia("(hover: hover)").matches) input.focus(); // pe telefon nu deschidem tastatura singuri
})();
