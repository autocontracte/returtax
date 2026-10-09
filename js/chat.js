/* Returtax — Marcel, asistentul conversațional.
 *
 * Tot ce scrie omul, inclusiv butoanele de sugestii, merge la AI (api/chat.php), ca Marcel
 * să știe mereu ce s-a discutat. Doar pașii pentru nume / telefon / ora apelului rămân locali.
 * Conversația se păstrează în sessionStorage, deci rămâne și după reîncărcarea paginii.
 * Dacă AI-ul nu răspunde (local, fără server, sau peste limită), Marcel trece
 * automat pe răspunsurile pe bază de cuvinte-cheie de mai jos.
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
  const lead = { ani: null, dnummerMinid: null, nume: null, telefon: null, cand: null, note: [] };
  // Ce așteptăm ca răspuns următor (null = conversație liberă)
  let expecting = null;
  // Conversația, ca text simplu, pentru AI
  const history = [];
  const conversationId = () => (window.rtConversationId ? window.rtConversationId() : "");

  // Ce s-a afișat în chat, ca să refacem conversația dacă pagina se reîncarcă
  const SAVE_KEY = "rt_chat";
  let transcript = [];
  let restoring = false;
  function saveChat() {
    if (restoring) return;
    try {
      sessionStorage.setItem(SAVE_KEY, JSON.stringify({ transcript: transcript.slice(-60), expecting, lead, yesno: yesNoPending }));
    } catch (e) {}
  }

  // Mesajele care nu trec prin AI se salvează separat, pentru panoul de admin
  function logMessage(role, content, source) {
    fetch("/api/log.php", {
      method: "POST",
      keepalive: true,
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ type: "message", conversation_id: conversationId(), role, content, source }),
    }).catch(() => {});
  }
  function remember(role, text) {
    const last = history[history.length - 1];
    if (last && last.role === role) last.content += "\n" + text;
    else history.push({ role, content: text });
  }

  /* ---------- Afișarea mesajelor ---------- */
  // Derulare automată până la ultimul mesaj (după ce browserul a așezat conținutul nou)
  function scrollDown() {
    requestAnimationFrame(() => log.scrollTo({ top: log.scrollHeight, behavior: reduceMotion || restoring ? "auto" : "smooth" }));
  }

  // logSource: „buton” / „script” = salvăm aici; null = mesajul merge la AI și îl salvează serverul
  function addUser(text, logSource) {
    const li = document.createElement("li");
    li.className = "msg msg-user";
    const b = document.createElement("div");
    b.className = "bubble";
    b.textContent = text;
    li.appendChild(b);
    log.appendChild(li);
    remember("user", text);
    if (!restoring) {
      if (logSource) logMessage("user", text, logSource);
      transcript.push({ role: "user", text });
      saveChat();
    }
    scrollDown();
  }

  /* ---------- Marcel (avatarul asistentului) ---------- */
  function makeBot(extra) {
    const svg = tplBot.content.firstElementChild.cloneNode(true);
    if (extra) svg.classList.add(...extra.split(" "));
    return svg;
  }

  // Doar ultimul Marcel din conversație e „viu” (clipește, se uită la ce scrieți)
  function setLive(bot) {
    log.querySelectorAll(".bot.live").forEach((t) => t.classList.remove("live", "listening"));
    bot.classList.add("live");
  }

  function addBot(html, fromAI) {
    const li = document.createElement("li");
    li.className = "msg msg-bot";
    const bot = makeBot("avatar");
    li.appendChild(bot);
    li.insertAdjacentHTML("beforeend", '<div class="bubble">' + html + "</div>");
    log.appendChild(li);
    const text = li.querySelector(".bubble").textContent;
    remember("assistant", text);
    if (!restoring) {
      if (!fromAI) logMessage("assistant", text, "script");
      transcript.push({ role: "assistant", html });
      saveChat();
    }
    setLive(bot);
    scrollDown();
  }

  function showTyping() {
    const li = document.createElement("li");
    li.className = "msg msg-bot typing";
    li.setAttribute("aria-label", "Marcel scrie");
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
      b.addEventListener("click", () => handle(label, true));
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

  const CHIPS_MAIN = ["Am lucrat în Norvegia", "Cât pot primi înapoi?", "Cât costă?", "Vreau să mă sune cineva"];

  function detectIntent(t) {
    if (has(t, ["suna", "sune", "sunat", "telefon", "apel", "vorbesc cu un om", "vorbi cu cineva", "om real"])) return "call";
    if (has(t, ["cost", "comision", "platesc", "plata", "pret", "tarif", "gratis", "gratuit", "cat luati", "procent", "taxa voastra"])) return "cost";
    if (has(t, ["acte", "document", "hartii", "ce trebuie", "ce imi trebuie", "d-nummer", "d nummer", "dnummer", "minid", "min id"])) return "docs";
    if (has(t, ["cat dureaza", "cand primesc", "cat timp", "termen", "luni"])) return "time";
    if (has(t, ["cat pot", "cati bani", "suma", "cat primesc", "inapoi", "returnare", "recuperez", "recupera"])) return "money";
    if (has(t, ["lucrat", "muncit", "norvegia", "norge", "am fost", "constructii", "pescarie", "santier"])) return "worked";
    if (has(t, ["iban", "teapa", "inselat", "sigur", "incredere", "contul cui", "contul vostru", "contul meu", "unde vin banii", "cine primeste"])) return "safety";
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
  function handle(raw, fromChip) {
    const text = raw.trim();
    if (!text) return;
    startChat();
    // După ce Marcel propune apelul: doar un „da” pornește pașii pentru telefon; orice altă întrebare merge la AI
    if (expecting === "vrea_apel") {
      const t = norm(text);
      const yes = t === "da, sa ma sune" ||
        (/\b(da|ok|sigur|bine|vreau|sunati|suna|sune)\b/.test(t) && t.length < 40 && !text.includes("?"));
      if (!yes && t !== "mai am o intrebare") expecting = null;
    }
    const viaAI = expecting === null;
    addUser(text, viaAI ? null : fromChip ? "buton" : "script");
    setChips([]);
    if (viaAI) return askAI(text, fromChip);
    replyTo(text);
  }

  /* ---------- Marcel cu AI ---------- */
  let aiBusy = false;

  async function askAI(text, fromChip) {
    const source = fromChip ? "buton" : "script";
    if (aiBusy) { logMessage("user", text, source); return replyTo(text); }
    aiBusy = true;
    lead.note.push(text);
    const typing = showTyping();
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 30000);
    try {
      const res = await fetch("/api/chat.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ conversation_id: conversationId(), source: fromChip ? "buton" : "ai", messages: history.slice(-24) }),
        signal: ctrl.signal,
      });
      const data = await res.json();
      if (!res.ok || !data.ok || !data.reply) throw new Error(data.error || res.status);
      typing.remove();
      addBot(formatReply(data.reply), true);
      if (data.action === "yesno") {
        showYesNo();
      } else if (data.action === "call") {
        expecting = "vrea_apel";
        setChips(["Da, să mă sune", "Mai am o întrebare"]);
      } else {
        setChips(["Vreau să mă sune cineva", "Cât costă?"]);
      }
      saveChat();
    } catch (e) {
      // Fără AI (local, eroare sau limită atinsă): răspunsurile pe bază de cuvinte-cheie
      typing.remove();
      lead.note.pop();
      logMessage("user", text, source);
      replyTo(text);
    } finally {
      clearTimeout(timer);
      aiBusy = false;
    }
  }

  /* ---------- Formularul Da / Nu (pentru estimare) ---------- */
  const YES_NO = [
    ["cazare", "Mi-am plătit singur cazarea în Norvegia"],
    ["drumuri", "Am venit acasă, în România, pe banii mei"],
    ["familie", "Am familia (soț/soție, copii) în România"],
    ["credit", "Am un credit la o bancă din România"],
  ];
  let yesNoPending = false;

  function showYesNo() {
    yesNoPending = true;
    saveChat();
    setChips([]);
    const li = document.createElement("li");
    li.className = "msg msg-form";
    const form = document.createElement("form");
    form.className = "yesno";
    const answers = {};
    YES_NO.forEach(([key, label], i) => {
      const row = document.createElement("div");
      row.className = "yesno-row";
      row.setAttribute("role", "group");
      row.setAttribute("aria-labelledby", "yn-" + i);
      row.innerHTML = '<span class="yesno-q" id="yn-' + i + '">' + escapeHtml(label) + "</span>" +
        '<span class="yesno-btns"><button type="button" data-v="da" aria-pressed="false">Da</button>' +
        '<button type="button" data-v="nu" aria-pressed="false">Nu</button></span>';
      row.addEventListener("click", (e) => {
        const btn = e.target.closest("button");
        if (!btn) return;
        answers[key] = btn.dataset.v;
        row.querySelectorAll("button").forEach((b) => b.setAttribute("aria-pressed", String(b === btn)));
        send.disabled = Object.keys(answers).length < YES_NO.length;
      });
      form.appendChild(row);
    });
    const send = document.createElement("button");
    send.type = "submit";
    send.className = "btn btn-dark yesno-send";
    send.textContent = "Trimite răspunsurile";
    send.disabled = true;
    form.appendChild(send);
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      if (Object.keys(answers).length < YES_NO.length) return;
      yesNoPending = false;
      li.remove();
      handle(YES_NO.map(([key, label]) => label + ": " + answers[key]).join(". ") + ".", true);
    });
    li.appendChild(form);
    log.appendChild(li);
    scrollDown();
  }

  // Textul de la AI: totul escapat, apoi **îngroșat** și rânduri noi
  function formatReply(text) {
    return escapeHtml(text)
      .replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>")
      .split(/\n{2,}/)
      .map((para) => "<p>" + para.replace(/\n/g, "<br>") + "</p>")
      .join("");
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
          "Aveți <strong>D-nummer</strong> (numărul norvegian de identificare) și <strong>MinID</strong> (contul cu care intrați pe site-urile statului norvegian)?",
        ],
        ["Am amândouă", "Am doar D-nummer", "Nu am / nu știu"]
      );
    }

    if (expecting === "acte") {
      lead.dnummerMinid = text;
      expecting = "vrea_apel";
      const both = has(t, ["amandoua", "ambele", "am tot", "da"]) && !has(t, ["doar", "nu "]);
      const onlyD = has(t, ["doar d", "numai d", "doar nummer", "fara minid", "nu am minid"]);
      return botSay(
        [
          both
            ? "Perfect! Cu D-nummer și MinID putem lucra foarte repede."
            : onlyD
              ? "Bine, D-nummer e cel mai important. Pentru MinID vă explicăm noi, pas cu pas, cum îl faceți."
              : "Nu-i nicio problemă. Vă ajutăm noi să aflați D-nummer-ul și să faceți MinID.",
          "Din ce ne-ați spus, merită să verificăm situația dumneavoastră.",
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
            "Foarte bine că mi-ați scris! Eu sunt Marcel și asta e exact ce facem la Returtax: recuperăm banii pe care statul norvegian i-a reținut în plus.",
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
              "<li><strong>D-nummer</strong> — numărul norvegian de identificare</li>" +
              "<li><strong>MinID</strong> — contul cu care intrați pe site-urile statului norvegian</li>" +
              "<li>buletinul sau pașaportul</li>" +
              "<li>contul bancar (IBAN) unde vreți banii</li></ul>",
            "Dacă nu le mai știți sau nu aveți MinID, nu vă îngrijorați — vă ajutăm noi să le recuperați.",
          ],
          ["Am lucrat în Norvegia", "Vreau să mă sune cineva"]
        );
      case "time":
        return botSay(
          "Depinde de Skatteetaten și de acte. Cu D-nummer și MinID în regulă, se poate rezolva în câteva săptămâni. Vă ținem la curent la fiecare pas, la telefon, în română.",
          ["Am lucrat în Norvegia", "Vreau să mă sune cineva"]
        );
      case "cost":
        return botSay(
          [
            "<p>Prețul e simplu, fără procente:</p><ul>" +
              "<li>dacă recuperați <strong>sub 1.000 €</strong>, nu plătiți <strong>nimic</strong>;</li>" +
              "<li>dacă recuperați <strong>peste 1.000 €</strong>, plătiți doar <strong>100 € fix</strong>, oricât ar fi suma.</li></ul>",
            "Verificarea este gratuită. Vreți să vedem cât puteți primi?",
          ],
          ["Am lucrat în Norvegia", "Vreau să mă sune cineva"]
        );
      case "call":
        return askName();
      case "safety":
        return botSay(
          [
            "Foarte bună întrebare! La noi, banii vin <strong>direct de la Skatteetaten, în contul dumneavoastră</strong>, pe numele dumneavoastră. În extras apare plata de la Skatteetaten.",
            "Nu înregistrăm niciodată contul nostru bancar în profilul dumneavoastră de MinID. Vă recomandăm să nu acceptați acest lucru din partea nimănui — rambursarea ar ajunge mai întâi la acea persoană.",
            "Colaborarea se face pe bază de contract semnat electronic, cu toate condițiile stabilite de la început.",
          ],
          ["Cât costă?", "Am lucrat în Norvegia"]
        );
      case "thanks":
        return botSay("Cu mare drag! Mai pot să vă ajut cu ceva?", CHIPS_MAIN);
      case "hello":
        return botSay("Bună ziua! Eu sunt Marcel. Spuneți-mi pe scurt: ați lucrat în Norvegia? În ce ani?", CHIPS_MAIN);
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
    const fd = new FormData();
    fd.append("source", "chat");
    fd.append("name", lead.nume || "");
    fd.append("phone", lead.telefon || "");
    fd.append("message", [
      "Ani: " + (lead.ani || "-"),
      "D-nummer / MinID: " + (lead.dnummerMinid || "-"),
      "Când să sunăm: " + (lead.cand || "-"),
      "Alte mesaje: " + (lead.note.join(" | ") || "-"),
    ].join(" / "));
    fd.append("consent", "chat");
    fd.append("conversation_id", conversationId());
    fetch("/api/contact.php", { method: "POST", body: fd }).catch(() => {});
  }

  /* ---------- Formular ---------- */
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    const v = input.value;
    input.value = "";
    autosize();
    handle(v);
  });
  // Căsuța crește după textul scris
  function autosize() {
    input.style.height = "auto";
    if (input.value) input.style.height = Math.min(input.scrollHeight, 160) + "px";
  }
  input.addEventListener("input", () => {
    autosize();
    // Marcel „ascultă” cât timp omul scrie
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

  /* ---------- Ochii lui Marcel urmăresc cursorul (sau degetul) ---------- */
  let pointer = null;
  let lookFrame = 0;

  function lookAt() {
    lookFrame = 0;
    document.querySelectorAll(".bot.live").forEach((svg) => {
      const pupils = svg.querySelectorAll(".pupil");
      const m = svg.getScreenCTM();
      // Cât timp omul scrie, Marcel se uită la căsuța de text (din CSS)
      if (!pointer || !m || svg.classList.contains("listening")) {
        pupils.forEach((p) => (p.style.transform = ""));
        return;
      }
      const target = new DOMPoint(pointer.x, pointer.y).matrixTransform(m.inverse());
      svg.querySelectorAll(".eye").forEach((eye) => {
        const white = eye.querySelector("circle");
        const dx = target.x - white.cx.baseVal.value;
        const dy = target.y - white.cy.baseVal.value;
        const dist = Math.hypot(dx, dy) || 1;
        const r = Math.min(18, dist / 6); // pupila nu iese din albul ochiului
        eye.querySelector(".pupil").style.transform = "translate(" + (dx / dist) * r + "px," + (dy / dist) * r + "px)";
      });
    });
  }
  function scheduleLook() { if (!lookFrame) lookFrame = requestAnimationFrame(lookAt); }

  const hasMouse = window.matchMedia("(hover: hover) and (pointer: fine)").matches;

  if (hasMouse) {
    window.addEventListener("pointermove", (e) => { pointer = { x: e.clientX, y: e.clientY }; scheduleLook(); }, { passive: true });
    document.documentElement.addEventListener("mouseleave", () => { pointer = null; scheduleLook(); });
    window.addEventListener("scroll", scheduleLook, { passive: true });
    input.addEventListener("input", scheduleLook);
  } else if (!reduceMotion) {
    // Pe telefon nu există cursor: Marcel privește în mijloc și din când în când se uită în jur
    const glances = [[-15, -4], [15, -4], [-12, 8], [12, 8], [0, -14], [-15, 0], [15, 0]];
    (function wander() {
      setTimeout(() => {
        const [x, y] = glances[Math.floor(Math.random() * glances.length)];
        document.querySelectorAll(".bot.live:not(.listening) .pupil").forEach((p) => {
          p.style.transform = "translate(" + x + "px," + y + "px)";
        });
        setTimeout(() => {
          document.querySelectorAll(".bot .pupil").forEach((p) => (p.style.transform = ""));
          wander();
        }, 900 + Math.random() * 700);
      }, 2200 + Math.random() * 2800);
    })();
  }

  /* ---------- Început ---------- */
  // Ecran de start ca la ChatGPT: titlu + căsuță de scris. La primul mesaj titlul dispare.
  let started = false;
  function startChat() {
    if (started) return;
    started = true;
    document.body.classList.add("chatting");
    input.placeholder = "Scrieți un mesaj…";
    window.scrollTo(0, 0);
  }

  document.getElementById("hero-bot").appendChild(makeBot("live"));
  // Când se deschide tastatura pe telefon, rămânem la ultimul mesaj
  if (window.visualViewport) window.visualViewport.addEventListener("resize", () => started && scrollDown());
  input.addEventListener("focus", () => started && setTimeout(scrollDown, 300));
  // Calculatorul (din site.js) poate porni conversația cu un mesaj gata scris
  window.ReturTaxChat = {
    start(text) {
      window.scrollTo({ top: 0, behavior: reduceMotion ? "auto" : "smooth" });
      handle(text, true);
    },
  };
  let saved = null;
  try { saved = JSON.parse(sessionStorage.getItem(SAVE_KEY) || "null"); } catch (e) {}
  if (saved && Array.isArray(saved.transcript) && saved.transcript.length) {
    restoring = true;
    startChat();
    saved.transcript.forEach((m) => (m.role === "user" ? addUser(m.text) : addBot(m.html)));
    transcript = saved.transcript;
    expecting = saved.expecting || null;
    Object.assign(lead, saved.lead || {});
    restoring = false;
    // După refacere, sărim direct la ultimul mesaj
    setTimeout(() => { log.scrollTop = log.scrollHeight; }, 60);
    if (saved.yesno) showYesNo();
    else setChips(expecting === "vrea_apel" ? ["Da, să mă sune", "Mai am o întrebare"]
      : expecting === null ? ["Vreau să mă sune cineva", "Cât costă?"] : []);
  } else {
    setChips(CHIPS_MAIN);
  }
  if (window.matchMedia("(hover: hover)").matches) input.focus(); // pe telefon nu deschidem tastatura singuri
})();
