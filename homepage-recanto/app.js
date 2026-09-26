/* Recanto dos Moinhos · Home page
   Toda a página é montada em JavaScript a partir dos dados abaixo.
   Para mudar textos, suítes, experiências ou contatos, edite só a seção "Conteúdo". */
(() => {
  'use strict';

  /* =========================================================
     Conteúdo
     ========================================================= */
  const HOTEL = {
    nome: 'Recanto dos Moinhos',
    tipo: 'Boutique Hotel',
    endereco: 'Rua Januário Pereira, 575',
    bairro: 'Alto do Capivari',
    cidade: 'Campos do Jordão',
    uf: 'SP',
    cep: '12460-000',
    telefone: '(12) 99612-3546',
    whatsapp: '5512996123546',
    email: 'andrefelippe@recantodosmoinhos.com.br',
    site: 'https://recantodosmoinhos.com.br',
    cnpj: '54.678.795/0001-10',
    omnibees: 13841,
    lat: -22.7394,
    lng: -45.5914,
    altitude: '1.628 m',
    coordenadas: '22°44′ S · 45°35′ O',
    suites: 16,
  };

  const SUITES = [
    {
      id: 'moinho',
      aba: 'Suíte Moinho',
      rotulo: 'A assinatura da casa',
      nome: 'Suíte Moinho',
      legenda: 'Pôr do sol visto do moinho',
      texto: 'A suíte mais romântica de Campos do Jordão foi construída dentro de um moinho. Lá do alto, a janela se abre para a serra e para o pôr do sol, que desce bem atrás das pás.',
      itens: ['Construída dentro do moinho', 'Vista para o pôr do sol', 'Hidromassagem interna privativa', 'Cama com colchão pillow-top', 'Frigobar e Wi-Fi', 'Café da manhã incluso'],
      cena: { seed: 7, millX: .56, millS: .36, lake: null, trees: 5, shift: .06, alt: 1.5, frac: .93 },
    },
    {
      id: 'lago',
      aba: 'Vista para o lago',
      rotulo: 'Manhãs de neblina',
      nome: 'Suítes com vista para o lago',
      legenda: 'Manhã sobre o lago',
      texto: 'A varanda fica de frente para o lago. Nas manhãs frias, a neblina sobe da água e as araucárias vão aparecendo aos poucos do outro lado.',
      itens: ['Varanda com vista para o lago', 'Hidromassagem interna privativa', 'Cama com colchão pillow-top', 'Mesa de trabalho', 'Frigobar e Wi-Fi', 'Café da manhã incluso'],
      cena: { seed: 21, millX: null, lake: .7, trees: 10, mist: 2.2, shift: 0, alt: 6, frac: .12 },
    },
    {
      id: 'jardim',
      aba: 'Vista para o jardim',
      rotulo: 'Perto da lareira',
      nome: 'Suítes com vista para o jardim',
      legenda: 'Fim de tarde no jardim',
      texto: 'A varanda dá para os jardins, perto da lareira ao ar livre e do gramado de piquenique. É a escolha de quem quer passar a tarde do lado de fora.',
      itens: ['Varanda com vista para o jardim', 'Hidromassagem interna privativa', 'Cama com colchão pillow-top', 'Mesa de trabalho', 'Frigobar e Wi-Fi', 'Café da manhã incluso'],
      cena: { seed: 33, millX: null, lake: null, trees: 12, flores: true, shift: .02, alt: 14, frac: .72 },
    },
  ];

  const EXPERIENCIAS = [
    { icone: 'lareira', titulo: 'Lareira ao ar livre', texto: 'Fim de tarde com o fogo aceso, manta nos ombros e o frio da serra do lado de fora.' },
    { icone: 'lago', titulo: 'Lago e jardins', texto: 'Caminhos pelo jardim até a beira do lago, entre araucárias e canteiros.' },
    { icone: 'sol', titulo: 'Terraço ao sol', texto: 'Espreguiçadeiras para as manhãs de céu limpo, que no inverno de Campos são as mais bonitas do ano.' },
    { icone: 'cesta', titulo: 'Área de piquenique', texto: 'Um gramado reservado para piqueniques a dois, com o lago à frente.' },
    { icone: 'xicara', titulo: 'Café da manhã', texto: 'Servido todos os dias, com opções no estilo continental e americano.' },
    { icone: 'chave', titulo: 'Chegada reservada', texto: 'Check-in e check-out privativos e estacionamento privativo sem custo.' },
  ];

  const ARREDORES = [
    { nome: 'Praça São Benedito', km: '2,7 km', nota: 'O coração do Capivari, com cafés, chocolaterias e o teleférico do Morro do Elefante.' },
    { nome: 'Parque Capivari', km: '≈ 3 km', nota: 'Lago, pedalinhos e o movimento da temporada de inverno.' },
    { nome: 'Cachoeira Véu da Noiva', km: '≈ 6 km', nota: 'Trilha curta até a queda-d’água, boa para uma manhã de sol.' },
    { nome: 'Horto Florestal', km: '≈ 15 km', nota: 'O Parque Estadual de Campos do Jordão, com trilhas entre araucárias centenárias.' },
  ];

  /* =========================================================
     Utilitários
     ========================================================= */
  const h = (tag, props, ...filhos) => {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(props || {})) {
      if (v == null || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'html') el.innerHTML = v;
      else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
      else el.setAttribute(k, v === true ? '' : v);
    }
    for (const f of filhos.flat(Infinity)) {
      if (f == null || f === false) continue;
      el.append(f.nodeType ? f : document.createTextNode(String(f)));
    }
    return el;
  };
  const $ = (s, r = document) => r.querySelector(s);
  const clamp = (v, a = 0, b = 1) => Math.min(b, Math.max(a, v));
  const pad = n => String(n).padStart(2, '0');
  const reduz = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Eventos de conversão para Google Tag Manager / GA4 (dataLayer).
  window.dataLayer = window.dataLayer || [];
  const rastrear = (event, dados = {}) => window.dataLayer.push({ event, ...dados });

  const UTM = 'utm_source=site&utm_medium=home&utm_campaign=reserva_direta';

  /* ---------- tempo local de Campos do Jordão ---------- */
  const TZ = 'America/Sao_Paulo';
  const OFFSET_H = 3; // UTC−3, sem horário de verão desde 2019
  const fmtISO = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' });
  const fmtHora = new Intl.DateTimeFormat('pt-BR', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
  const fmtDiaLongo = new Intl.DateTimeFormat('pt-BR', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long' });
  const fmtDiaCurto = new Intl.DateTimeFormat('pt-BR', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short' });

  const hojeLocal = (d = new Date()) => { const [y, m, dd] = fmtISO.format(d).split('-').map(Number); return { y, m, d: dd }; };
  const meiaNoite = ({ y, m, d }) => new Date(Date.UTC(y, m - 1, d, OFFSET_H));
  const minutosAgora = () => Math.floor((Date.now() - meiaNoite(hojeLocal())) / 60000);
  const horaCurta = d => fmtHora.format(d).replace(':', 'h');

  /* ---------- posição do sol (algoritmo do SunCalc, simplificado) ---------- */
  const Sol = (() => {
    const rad = Math.PI / 180, dia = 864e5, J1970 = 2440588, J2000 = 2451545, e = rad * 23.4397;
    const dias = d => d.valueOf() / dia - 0.5 + J1970 - J2000;
    const deJuliano = j => new Date((j + 0.5 - J1970) * dia);
    const anom = d => rad * (357.5291 + 0.98560028 * d);
    const longi = m => m + rad * (1.9148 * Math.sin(m) + 0.02 * Math.sin(2 * m) + 0.0003 * Math.sin(3 * m)) + rad * 102.9372 + Math.PI;
    const decl = l => Math.asin(Math.sin(l) * Math.sin(e));
    const ascR = l => Math.atan2(Math.sin(l) * Math.cos(e), Math.cos(l));
    function altitude(data, lat, lng) {
      const lw = rad * -lng, phi = rad * lat, d = dias(data);
      const l = longi(anom(d)), dc = decl(l);
      const H = rad * (280.16 + 360.9856235 * d) - lw - ascR(l);
      return Math.asin(Math.sin(phi) * Math.sin(dc) + Math.cos(phi) * Math.cos(dc) * Math.cos(H)) / rad;
    }
    function tempos(data, lat, lng) {
      const lw = rad * -lng, phi = rad * lat, d = dias(data);
      const n = Math.round(d - 0.0009 - lw / (2 * Math.PI));
      const ds = 0.0009 + lw / (2 * Math.PI) + n;
      const m = anom(ds), l = longi(m), dc = decl(l);
      const jMeio = J2000 + ds + 0.0053 * Math.sin(m) - 0.0069 * Math.sin(2 * l);
      const w = Math.acos((Math.sin(rad * -0.833) - Math.sin(phi) * Math.sin(dc)) / (Math.cos(phi) * Math.cos(dc)));
      const a = 0.0009 + (w + lw) / (2 * Math.PI) + n;
      const jPor = J2000 + a + 0.0053 * Math.sin(m) - 0.0069 * Math.sin(2 * l);
      return { nascer: deJuliano(jMeio - (jPor - jMeio)), por: deJuliano(jPor) };
    }
    return { altitude, tempos };
  })();

  /* =========================================================
     Paisagem da Mantiqueira (canvas)
     Serra em camadas, araucárias, moinho, lago, neblina, sol e estrelas.
     A cor de tudo vem da altura real do sol sobre Campos do Jordão.
     ========================================================= */
  const hex = s => [1, 3, 5].map(i => parseInt(s.slice(i, i + 2), 16));
  const mix = (a, b, t) => a.map((v, i) => v + (b[i] - v) * t);
  const rgb = (c, a = 1) => `rgba(${c[0] | 0},${c[1] | 0},${c[2] | 0},${a})`;
  const PRETO = [0, 0, 0], BRANCO = [255, 255, 255];

  const CEU = [
    { a: -18, top: '#03060c', bot: '#0a1320', far: '#121b25', near: '#060c0a', lake: '#050a10', luz: 0 },
    { a: -9, top: '#0a142c', bot: '#27365a', far: '#222d45', near: '#0a1310', lake: '#0d1627', luz: .1 },
    { a: -3, top: '#1d2a55', bot: '#c47658', far: '#3d3c5a', near: '#0f1713', lake: '#2a2d47', luz: .4 },
    { a: 2, top: '#33538a', bot: '#eea566', far: '#5a6383', near: '#15211b', lake: '#4b5876', luz: .7 },
    { a: 10, top: '#4b7db4', bot: '#f1d2a6', far: '#7c90ab', near: '#1c2e24', lake: '#6a849f', luz: .9 },
    { a: 30, top: '#3d7bbb', bot: '#c6dbe9', far: '#8ba6bb', near: '#22372b', lake: '#7391a7', luz: 1 },
    { a: 90, top: '#3474b6', bot: '#bad4e5', far: '#8ca7bc', near: '#243e2e', lake: '#7896ab', luz: 1 },
  ].map(k => ({ a: k.a, luz: k.luz, top: hex(k.top), bot: hex(k.bot), far: hex(k.far), near: hex(k.near), lake: hex(k.lake) }));

  function paleta(alt) {
    let i = 0;
    while (i < CEU.length - 2 && alt > CEU[i + 1].a) i++;
    const A = CEU[i], B = CEU[i + 1], t = clamp((alt - A.a) / (B.a - A.a));
    const o = { luz: A.luz + (B.luz - A.luz) * t };
    for (const k of ['top', 'bot', 'far', 'near', 'lake']) o[k] = mix(A[k], B[k], t);
    return o;
  }

  function aleatorio(a) {
    return () => { a |= 0; a = a + 0x6D2B79F5 | 0; let t = Math.imul(a ^ a >>> 15, 1 | a); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; };
  }

  function Paisagem(canvas, opcoes) {
    const cfg = Object.assign({ seed: 1628, millX: .72, millS: .2, lake: .845, shift: 0, trees: 14, mist: 1, flores: false, retrato: false }, opcoes);
    const ctx = canvas.getContext('2d');
    const r = aleatorio(cfg.seed);
    const camadas = [0, 1, 2, 3].map(i => ({
      base: [.52, .6, .67, .76][i], amp: [.09, .075, .06, .035][i], f: [1.1, 1.7, 2.4, 3.1][i],
      p: [r() * 6.3, r() * 6.3, r() * 6.3, r() * 6.3],
    }));
    const estrelas = Array.from({ length: 240 }, () => ({ x: r(), y: r() * .62, s: r() * 1.3 + .3, t: r() * 6.3 }));
    const nevoa = Array.from({ length: 18 }, () => ({ x: r(), y: .55 + r() * .3, w: .18 + r() * .3, h: .03 + r() * .04, v: .004 + r() * .01 }));
    const brilhos = Array.from({ length: 50 }, () => ({ x: r(), y: r(), w: .01 + r() * .05, v: .2 + r() * .6, p: r() * 6.3 }));
    const arvores = [];
    for (let i = 0; i < cfg.trees; i++) {
      const camada = i % 3 === 0 ? 2 : 3;
      let x = r();
      if (cfg.millX != null && camada === 3 && Math.abs(x - cfg.millX) < .1) x = (x + .3) % 1;
      arvores.push({ camada, x, s: (camada === 3 ? .17 : .1) * (.7 + r() * .6) });
    }
    const flores = cfg.flores ? Array.from({ length: 120 }, () => ({ x: r(), y: r(), c: r(), s: r() })) : [];
    let W = 1, H = 1, shift = cfg.shift, millX = cfg.millX;

    function redimensionar() {
      const b = canvas.getBoundingClientRect();
      const dpr = Math.min(2, window.devicePixelRatio || 1);
      W = Math.max(1, b.width); H = Math.max(1, b.height);
      canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      const retrato = cfg.retrato && W / H < .9;
      shift = retrato ? -.14 : cfg.shift;
      millX = cfg.millX == null ? null : (retrato ? .7 : cfg.millX);
    }

    function crista(i, x) {
      const c = camadas[i];
      const v = Math.sin(x * c.f * Math.PI + c.p[0]) * .55 + Math.sin(x * c.f * 2.3 * Math.PI + c.p[1]) * .25
        + Math.sin(x * c.f * 5.1 * Math.PI + c.p[2]) * .13 + Math.sin(x * c.f * 11.7 * Math.PI + c.p[3]) * .07;
      let y = c.base - v * c.amp;
      if (i === 3 && millX != null) y -= .055 * Math.exp(-(((x - millX) / .09) ** 2));
      return y + shift;
    }

    function araucaria(x, y, S, cor) {
      ctx.strokeStyle = cor; ctx.fillStyle = cor; ctx.lineCap = 'round';
      ctx.lineWidth = Math.max(1, S * .03);
      ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x, y - S); ctx.stroke();
      ctx.lineWidth = Math.max(.8, S * .018);
      for (const [hy, sp] of [[.62, .22], [.72, .34], [.81, .4], [.89, .36], [.96, .22]]) {
        const ty = y - S * hy, w = sp * S;
        for (const d of [-1, 1]) {
          ctx.beginPath(); ctx.moveTo(x, ty);
          ctx.quadraticCurveTo(x + d * w * .55, ty + S * .035, x + d * w, ty - S * .05); ctx.stroke();
          ctx.beginPath(); ctx.ellipse(x + d * w * .92, ty - S * .055, S * .075, S * .032, 0, 0, 7); ctx.fill();
        }
      }
      ctx.beginPath(); ctx.ellipse(x, y - S, S * .06, S * .025, 0, 0, 7); ctx.fill();
    }

    function moinho(x, y, S, p, s, base) {
      const corpo = mix(base, p.far, .32);
      const top = y - S;
      const aceso = clamp((3 - s.alt) / 9);
      ctx.fillStyle = rgb(corpo);
      ctx.beginPath(); ctx.moveTo(x - .2 * S, y); ctx.lineTo(x - .12 * S, top); ctx.lineTo(x + .12 * S, top); ctx.lineTo(x + .2 * S, y); ctx.closePath(); ctx.fill();
      // luz lateral do sol poente
      ctx.strokeStyle = rgb(mix(corpo, [255, 226, 180], .15 + .25 * p.luz), .9);
      ctx.lineWidth = Math.max(1, S * .012);
      ctx.beginPath(); ctx.moveTo(x + .12 * S, top); ctx.lineTo(x + .2 * S, y); ctx.stroke();
      // cúpula
      ctx.fillStyle = rgb(corpo);
      ctx.beginPath(); ctx.moveTo(x - .16 * S, top + .01 * S); ctx.quadraticCurveTo(x, top - .26 * S, x + .16 * S, top + .01 * S); ctx.closePath(); ctx.fill();
      // janelas e porta (acendem ao anoitecer)
      const janela = (wx, wy, ww, wh) => {
        ctx.beginPath(); ctx.moveTo(wx - ww / 2, wy + wh / 2); ctx.lineTo(wx - ww / 2, wy - wh / 2 + ww / 2);
        ctx.arc(wx, wy - wh / 2 + ww / 2, ww / 2, Math.PI, 0); ctx.lineTo(wx + ww / 2, wy + wh / 2); ctx.closePath(); ctx.fill();
      };
      const vaos = [[x, top + .3 * S, .07 * S, .12 * S], [x, top + .6 * S, .08 * S, .13 * S], [x, y - .09 * S, .11 * S, .18 * S]];
      ctx.fillStyle = 'rgba(0,0,0,.35)';
      vaos.forEach(v => janela(...v));
      if (aceso > 0) {
        ctx.save();
        ctx.shadowColor = `rgba(255,170,80,${aceso})`; ctx.shadowBlur = S * .18;
        ctx.fillStyle = `rgba(255,198,118,${aceso})`;
        vaos.forEach(v => janela(...v));
        ctx.restore();
      }
      // pás
      const L = .8 * S, cor = mix(base, p.far, .55);
      ctx.save(); ctx.translate(x, top - .07 * S);
      ctx.strokeStyle = rgb(cor); ctx.fillStyle = rgb(cor, .32);
      for (let b = 0; b < 4; b++) {
        ctx.save(); ctx.rotate(s.ang + b * Math.PI / 2);
        ctx.lineWidth = Math.max(1, S * .014);
        ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(0, -L); ctx.stroke();
        const w = .13 * S, fim = -.16 * L;
        ctx.fillRect(0, -L, w, L + fim);
        ctx.lineWidth = Math.max(.6, S * .006);
        ctx.strokeRect(0, -L, w, L + fim);
        ctx.beginPath();
        for (let k = 1; k < 6; k++) { const yy = -L + (L + fim) * k / 6; ctx.moveTo(0, yy); ctx.lineTo(w, yy); }
        ctx.moveTo(w / 2, -L); ctx.lineTo(w / 2, fim); ctx.stroke();
        ctx.restore();
      }
      ctx.fillStyle = rgb(corpo); ctx.beginPath(); ctx.arc(0, 0, S * .035, 0, 7); ctx.fill();
      ctx.restore();
    }

    function desenhar(s) {
      const p = paleta(s.alt);
      const hz = (.6 + shift) * H;
      // céu
      const g = ctx.createLinearGradient(0, 0, 0, hz + H * .1);
      g.addColorStop(0, rgb(p.top)); g.addColorStop(1, rgb(p.bot));
      ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
      // estrelas e lua
      const noite = clamp((-s.alt - 3) / 9);
      if (noite > 0) {
        for (const e of estrelas) {
          const tw = .6 + .4 * Math.sin(s.t * 1.3 + e.t);
          ctx.fillStyle = `rgba(255,250,235,${noite * tw * .9})`;
          ctx.fillRect(e.x * W, (e.y + shift * .5) * H, e.s, e.s);
        }
        const mx = W * .2, my = H * (.16 + shift * .4), mr = Math.max(7, H * .02);
        ctx.save(); ctx.globalAlpha = noite;
        const mg = ctx.createRadialGradient(mx, my, 0, mx, my, mr * 7);
        mg.addColorStop(0, 'rgba(240,235,215,.3)'); mg.addColorStop(1, 'rgba(240,235,215,0)');
        ctx.fillStyle = mg; ctx.fillRect(mx - mr * 7, my - mr * 7, mr * 14, mr * 14);
        ctx.fillStyle = '#f1ecdd'; ctx.beginPath(); ctx.arc(mx, my, mr, 0, 7); ctx.fill();
        ctx.restore();
      }
      // sol
      const sx = W * (.1 + .8 * clamp(s.frac, -.2, 1.2));
      const sy = hz - (s.alt / 55) * hz * .95;
      if (s.alt > -6) {
        const quente = clamp(1 - Math.abs(s.alt) / 14);
        const R = H * (.25 + .4 * quente);
        const sg = ctx.createRadialGradient(sx, sy, 0, sx, sy, R);
        sg.addColorStop(0, `rgba(255,${200 - 50 * quente | 0},${140 - 60 * quente | 0},${.55 * clamp((s.alt + 6) / 6)})`);
        sg.addColorStop(1, 'rgba(255,170,90,0)');
        ctx.fillStyle = sg; ctx.fillRect(0, 0, W, H);
        ctx.fillStyle = `rgba(255,${240 - 40 * quente | 0},${215 - 85 * quente | 0},${clamp((s.alt + 1.5) / 3)})`;
        ctx.beginPath(); ctx.arc(sx, sy, Math.max(6, H * .018), 0, 7); ctx.fill();
      }
      // serra, araucárias e moinho
      const tons = [0, .35, .66, 1];
      for (let i = 0; i < 4; i++) {
        const cor = mix(p.far, p.near, tons[i]);
        const off = s.scroll * (.04 + i * .05);
        ctx.fillStyle = rgb(cor);
        ctx.beginPath(); ctx.moveTo(0, H);
        for (let x = 0; x <= W + 4; x += 4) ctx.lineTo(x, crista(i, x / W) * H + off);
        ctx.lineTo(W, H); ctx.closePath(); ctx.fill();
        // bruma entre as camadas
        if (i < 3) {
          const by = crista(i + 1, .5) * H + off;
          const bg = ctx.createLinearGradient(0, by - H * .12, 0, by + H * .05);
          bg.addColorStop(0, rgb(p.bot, 0)); bg.addColorStop(1, rgb(p.bot, .12 + .1 * cfg.mist * (1 - p.luz * .5)));
          ctx.fillStyle = bg; ctx.fillRect(0, by - H * .12, W, H * .17);
        }
        const corArv = rgb(mix(cor, PRETO, .2));
        for (const a of arvores) if (a.camada === i) araucaria(a.x * W, crista(i, a.x) * H + off + a.s * H * .06, a.s * H, corArv);
        if (i === 3 && millX != null) {
          const S = Math.min(cfg.millS * H, W * .3);
          moinho(millX * W, crista(3, millX) * H + off + S * .05, S, p, s, cor);
        }
      }
      // flores (jardim)
      if (flores.length) {
        const fy = H * (.86 + shift);
        ctx.fillStyle = rgb(mix(p.near, PRETO, .25));
        ctx.fillRect(0, fy, W, H - fy);
        for (const f of flores) {
          const x = f.x * W, y = fy - H * .02 + f.y * (H - fy + H * .02), rr = H * (.006 + .012 * f.y) * (.7 + f.s * .6);
          const cor = mix(mix([143, 166, 214], [184, 154, 208], f.c), BRANCO, .15 * p.luz);
          ctx.fillStyle = rgb(mix(cor, PRETO, .35 - .25 * p.luz)); ctx.beginPath(); ctx.arc(x, y, rr, 0, 7); ctx.fill();
        }
      }
      // lago
      if (cfg.lake != null) {
        const ly = (cfg.lake + shift) * H + s.scroll * .25;
        const lg = ctx.createLinearGradient(0, ly, 0, H);
        lg.addColorStop(0, rgb(mix(p.lake, p.bot, .35))); lg.addColorStop(1, rgb(mix(p.lake, PRETO, .45)));
        ctx.fillStyle = lg; ctx.fillRect(0, ly, W, H - ly);
        ctx.fillStyle = rgb(mix(p.near, PRETO, .3), .7); ctx.fillRect(0, ly, W, Math.max(1, H * .004));
        const brilho = mix(p.bot, BRANCO, .3);
        ctx.lineWidth = 1;
        for (const b of brilhos) {
          const y = ly + 3 + b.y * (H - ly), perto = (y - ly) / (H - ly);
          const x = ((b.x + s.t * b.v * .01) % 1) * W;
          ctx.strokeStyle = rgb(brilho, .08 + .16 * Math.sin(s.t * b.v * 3 + b.p) ** 2);
          ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x + b.w * W * (.5 + perto), y); ctx.stroke();
        }
        if (s.alt > -3 && sx > 0 && sx < W) {
          const a = clamp((s.alt + 3) / 6) * clamp(1 - (s.alt - 10) / 40, .25, 1);
          for (let j = 0; j < 24; j++) {
            const y = ly + 2 + (j / 24) * (H - ly), w = (8 + j * 3.2) * (.6 + .4 * Math.sin(s.t * 2 + j * 1.7));
            ctx.fillStyle = `rgba(255,214,160,${.35 * a * (1 - j / 28)})`;
            ctx.fillRect(sx - w / 2, y, w, 1.5);
          }
        }
      }
      // neblina
      const neb = cfg.mist * (.35 + .65 * clamp(1 - Math.abs(s.alt - 3) / 14));
      const corNeb = mix(p.bot, BRANCO, .5);
      for (const n of nevoa) {
        const x = (((n.x + s.t * n.v) % 1.4) - .2) * W, y = (n.y + shift) * H + s.scroll * .15;
        const rw = n.w * W, rh = n.h * H;
        ctx.save(); ctx.translate(x, y); ctx.scale(1, rh / rw);
        const ng = ctx.createRadialGradient(0, 0, 0, 0, 0, rw);
        ng.addColorStop(0, rgb(corNeb, Math.min(.3, .1 * neb))); ng.addColorStop(1, rgb(corNeb, 0));
        ctx.fillStyle = ng; ctx.beginPath(); ctx.arc(0, 0, rw, 0, 7); ctx.fill();
        ctx.restore();
      }
    }

    redimensionar();
    observarTamanho(canvas, redimensionar);
    return { redimensionar, desenhar };
  }

  // Redesenha quando o canvas muda de tamanho (inclusive quando entra na página).
  function observarTamanho(canvas, fn) {
    if (!('ResizeObserver' in window)) return;
    new ResizeObserver(() => { fn(); if (typeof estado !== 'undefined') estado.sujo = true; }).observe(canvas);
  }

  /* ---------- vela do Due Mulini ---------- */
  function Vela(canvas) {
    const ctx = canvas.getContext('2d');
    let W = 1, H = 1;
    function redimensionar() {
      const b = canvas.getBoundingClientRect(), dpr = Math.min(2, window.devicePixelRatio || 1);
      W = Math.max(1, b.width); H = Math.max(1, b.height);
      canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr); ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    function desenhar(t) {
      ctx.clearRect(0, 0, W, H);
      const cx = W / 2, base = H * .58;
      const f = 1 + .06 * Math.sin(t * 9.1) + .04 * Math.sin(t * 14.3 + 1) + .03 * Math.sin(t * 23.7);
      const halo = ctx.createRadialGradient(cx, base - H * .15, 0, cx, base - H * .15, W * .9 * f);
      halo.addColorStop(0, 'rgba(255,176,90,.35)'); halo.addColorStop(1, 'rgba(255,176,90,0)');
      ctx.fillStyle = halo; ctx.fillRect(0, 0, W, H);
      const cw = W * .34;
      const cg = ctx.createLinearGradient(cx - cw / 2, 0, cx + cw / 2, 0);
      cg.addColorStop(0, '#d9cfbd'); cg.addColorStop(.45, '#f3ece0'); cg.addColorStop(1, '#bfb4a1');
      ctx.fillStyle = cg; ctx.fillRect(cx - cw / 2, base + H * .04, cw, H - base);
      ctx.beginPath(); ctx.ellipse(cx, base + H * .04, cw / 2, H * .015, 0, 0, 7); ctx.fillStyle = '#f7f1e6'; ctx.fill();
      ctx.strokeStyle = '#2a211a'; ctx.lineWidth = 1.5; ctx.beginPath(); ctx.moveTo(cx, base + H * .04); ctx.lineTo(cx, base - H * .01); ctx.stroke();
      const fh = H * .3 * f, fw = W * .1 * (2 - f);
      const bal = Math.sin(t * 2.3) * W * .03 + Math.sin(t * 5.1) * W * .015;
      const fg = ctx.createLinearGradient(0, base, 0, base - fh);
      fg.addColorStop(0, 'rgba(255,244,214,1)'); fg.addColorStop(.45, 'rgba(255,196,102,.95)'); fg.addColorStop(1, 'rgba(255,120,40,0)');
      ctx.fillStyle = fg;
      ctx.beginPath(); ctx.moveTo(cx, base);
      ctx.bezierCurveTo(cx - fw * 1.3, base - fh * .3, cx - fw * .4 + bal, base - fh * .78, cx + bal, base - fh);
      ctx.bezierCurveTo(cx + fw * .4 + bal, base - fh * .78, cx + fw * 1.3, base - fh * .3, cx, base);
      ctx.fill();
      ctx.fillStyle = 'rgba(120,150,255,.45)'; ctx.beginPath(); ctx.ellipse(cx, base - H * .015, fw * .45, H * .025, 0, 0, 7); ctx.fill();
    }
    redimensionar();
    observarTamanho(canvas, redimensionar);
    return { redimensionar, desenhar };
  }

  /* =========================================================
     Ícones (SVG, traço)
     ========================================================= */
  const ICONES = {
    lareira: '<path d="M20 33c-6 0-9.5-4-9.5-9.2 0-6.3 5.5-8.4 6.5-15.3 3.3 3 4.5 6.4 4.5 9.6 2-1 3.2-3.2 3.2-5.2 3.4 3.3 5.3 6.8 5.3 10.9 0 5.2-3.7 9.2-10 9.2z"/><path d="M6 36.5h28"/>',
    lago: '<circle cx="27" cy="11" r="4.5"/><path d="M4 23c4-3 8-3 12 0s8 3 12 0 8-3 8 0"/><path d="M4 30c4-3 8-3 12 0s8 3 12 0 8-3 8 0"/>',
    sol: '<circle cx="20" cy="20" r="6.5"/><path d="M20 5v5M20 30v5M5 20h5M30 20h5M9.4 9.4l3.5 3.5M27.1 27.1l3.5 3.5M9.4 30.6l3.5-3.5M27.1 12.9l3.5-3.5"/>',
    cesta: '<path d="M6 18h28l-3.5 15h-21z"/><path d="M13 18c0-7.5 14-7.5 14 0"/><path d="M12 24h16M13.5 29h13"/>',
    xicara: '<path d="M8 16h19v8a8 8 0 0 1-8 8h-3a8 8 0 0 1-8-8z"/><path d="M27 18.5h2.5a3.5 3.5 0 0 1 0 7H27"/><path d="M14 6c-1.5 2 1.5 3 0 5M20 6c-1.5 2 1.5 3 0 5"/><path d="M6 36h24"/>',
    chave: '<circle cx="13" cy="20" r="6.5"/><path d="M19.5 20H35M30 20v5.5M34.5 20v4"/>',
  };
  const icone = n => `<svg viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONES[n]}</svg>`;
  const ICONE_WHATS = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2c-1.5 0-3-.4-4.3-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.4.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3z"/></svg>';
  const ICONE_SETA = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M2 8h11M9 4l4 4-4 4"/></svg>';

  function logoMoinho() {
    let pas = '';
    for (let k = 0; k < 4; k++) pas += `<g transform="rotate(${k * 90} 20 15)"><line x1="20" y1="15" x2="20" y2="2.5" stroke="currentColor" stroke-width="1.1"/><rect x="20.6" y="3" width="3.4" height="9" fill="none" stroke="currentColor" stroke-width=".8"/></g>`;
    return `<svg viewBox="0 0 40 40" aria-hidden="true"><path d="M14.6 39 16.8 18h6.4l2.2 21z" fill="currentColor"/><path d="M15.8 18.3Q20 11.6 24.2 18.3z" fill="currentColor"/><g class="logo-pas">${pas}</g><circle cx="20" cy="15" r="1.3" fill="currentColor"/></svg>`;
  }

  /* =========================================================
     Montagem da página
     ========================================================= */
  const app = $('#app');
  const estado = { minutos: minutosAgora(), aoVivo: true, ang: 0, vel: .5, scrollVel: 0, scroll: 0, sujo: true, mexeuRelogio: false };
  const cenas = [];

  /* ---------- navegação ---------- */
  const nav = h('nav', { class: 'nav', 'aria-label': 'Principal' },
    h('div', { class: 'nav__in' },
      h('a', { class: 'marca', href: '#inicio', 'aria-label': `${HOTEL.nome}, início` },
        h('span', { html: logoMoinho() }),
        h('span', null, h('b', null, HOTEL.nome), h('small', null, HOTEL.tipo))),
      h('button', { class: 'nav__menu', type: 'button', 'aria-label': 'Abrir menu', 'aria-expanded': 'false', 'aria-controls': 'nav-links', onClick: () => alternarMenu() }, h('span')),
      h('div', { class: 'nav__links', id: 'nav-links' },
        [['Suítes', '#suites'], ['Due Mulini', '#due-mulini'], ['Experiências', '#experiencias'], ['Localização', '#localizacao']]
          .map(([t, href]) => h('a', { href, onClick: () => alternarMenu(false) }, t)),
        h('a', { class: 'btn btn--latao btn--pequeno', href: '#reservar', onClick: () => { alternarMenu(false); rastrear('cta_reservar', { local: 'nav' }); } }, 'Reservar'))));
  function alternarMenu(forca) {
    const aberto = typeof forca === 'boolean' ? forca : !nav.classList.contains('aberta');
    nav.classList.toggle('aberta', aberto);
    $('.nav__menu', nav).setAttribute('aria-expanded', String(aberto));
    if (aberto) nav.classList.add('solida'); else atualizarNav();
  }
  const logoPas = () => $('.logo-pas', nav);

  /* ---------- hero ---------- */
  const heroCanvas = h('canvas', { 'aria-hidden': 'true' });
  const tituloHero = h('h1');
  [['Onde'], ['o'], ['vento'], ['da'], ['serra'], ['faz', true], ['girar', true], ['o'], ['tempo.']].forEach(([p, em], i) => {
    const s = h('span', { class: 'p', style: { '--i': i } }, p);
    tituloHero.append(em ? h('em', null, s) : s, ' ');
  });
  const horaRotulo = h('b', null, '00h00');
  const btnAgora = h('button', { class: 'relogio__agora', type: 'button', 'aria-pressed': 'true', onClick: () => irParaAgora() }, h('i'), 'Agora');
  const slider = h('input', { class: 'hora', id: 'hora-cena', type: 'range', min: '0', max: '1439', step: '1', 'aria-label': 'Hora do dia mostrada na paisagem' });
  const marcaNascer = h('span'), marcaPor = h('span');
  const heroConteudo = h('div', { class: 'hero__conteudo' },
    h('p', { class: 'eyebrow' }, `${HOTEL.tipo} · ${HOTEL.bairro} · ${HOTEL.cidade}`),
    tituloHero,
    h('p', { class: 'hero__sub' }, `Dezesseis suítes com hidromassagem privativa, jardins à beira do lago e uma suíte construída dentro de um moinho, a ${HOTEL.altitude} de altitude na Serra da Mantiqueira.`),
    h('div', { class: 'hero__linha' },
      h('div', { class: 'hero__ctas' },
        h('a', { class: 'btn btn--latao', href: '#reservar', onClick: () => rastrear('cta_reservar', { local: 'hero' }) }, 'Reservar estadia', h('span', { html: ICONE_SETA })),
        h('a', { class: 'btn btn--fantasma', href: '#suites' }, 'Conhecer a Suíte Moinho')),
      h('div', { class: 'relogio' },
        h('div', { class: 'relogio__topo' },
          h('span', { class: 'relogio__rotulo' }, 'Campos do Jordão', horaRotulo),
          btnAgora),
        slider,
        h('div', { class: 'relogio__marcas' }, marcaNascer, marcaPor),
        h('p', { class: 'relogio__dica' }, 'Arraste para ver o Recanto em outra hora do dia.'))));
  const hero = h('header', { class: 'hero', id: 'inicio' }, heroCanvas, h('div', { class: 'hero__veu' }), heroConteudo);

  /* ---------- intro ---------- */
  const intro = h('section', { class: 'secao', id: 'o-recanto', 'aria-labelledby': 'titulo-recanto' },
    h('div', { class: 'caixa intro' },
      h('div', null,
        h('p', { class: 'eyebrow', id: 'titulo-recanto' }, 'O Recanto'),
        h('p', { class: 'intro__lead', html: 'Dezesseis suítes, um lago, jardins entre araucárias e um <em>moinho de verdade</em> no alto do Capivari.' }),
        h('p', { class: 'corpo' }, 'O Recanto dos Moinhos é um boutique hotel pensado para casais. Fica a poucos minutos do movimento do Capivari e longe o bastante para se ouvir o vento na serra. Todas as suítes têm hidromassagem interna privativa, e as noites terminam à luz de velas no Due Mulini.')),
      h('dl', { class: 'ficha' },
        [['Altitude', HOTEL.altitude], ['Coordenadas', HOTEL.coordenadas], ['Suítes', String(HOTEL.suites)], ['Hidromassagem', 'em todas'], ['Até o Capivari', '≈ 3 km']]
          .map(([dt, dd]) => h('div', null, h('dt', null, dt), h('dd', null, dd))))));

  /* ---------- suítes ---------- */
  const abas = h('div', { class: 'abas', role: 'tablist', 'aria-label': 'Tipos de suíte' });
  const painel = h('div', { class: 'suite', role: 'tabpanel', id: 'painel-suite', tabindex: '0' });
  let suiteAtual = null;
  const cenaSuite = { vis: false, paisagem: null, cfg: null, canvas: null,
    desenhar(t) { if (this.paisagem) this.paisagem.desenhar({ alt: this.cfg.alt, frac: this.cfg.frac, ang: estado.ang * .8, scroll: 0, t }); } };
  cenas.push(cenaSuite);

  SUITES.forEach((s, i) => {
    abas.append(h('button', { class: 'aba', role: 'tab', type: 'button', id: `aba-${s.id}`, 'aria-controls': 'painel-suite', 'aria-selected': 'false', tabindex: '-1',
      onClick: () => mostrarSuite(i, true),
      onKeydown: e => {
        const d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (!d) return;
        e.preventDefault();
        const n = (i + d + SUITES.length) % SUITES.length;
        mostrarSuite(n, true); $(`#aba-${SUITES[n].id}`).focus();
      } }, s.aba));
  });

  function mostrarSuite(i, peloUsuario) {
    if (suiteAtual === i) return;
    suiteAtual = i;
    const s = SUITES[i];
    abas.querySelectorAll('.aba').forEach((b, k) => { b.setAttribute('aria-selected', String(k === i)); b.tabIndex = k === i ? 0 : -1; });
    painel.setAttribute('aria-labelledby', `aba-${s.id}`);
    const canvas = h('canvas', { 'aria-hidden': 'true' });
    painel.replaceChildren(
      h('div', { class: 'suite__janela entra', role: 'img', 'aria-label': `Ilustração: ${s.legenda.toLowerCase()}` }, canvas, h('span', { class: 'suite__legenda' }, s.legenda)),
      h('div', { class: 'suite__texto entra', style: { animationDelay: '.08s' } },
        h('p', { class: 'eyebrow' }, s.rotulo),
        h('h3', null, s.nome),
        h('p', { class: 'corpo' }, s.texto),
        h('ul', { class: 'suite__lista' }, s.itens.map(t => h('li', null, t))),
        h('div', { class: 'suite__acoes' },
          h('a', { class: 'btn btn--escuro', href: '#reservar', onClick: () => { selecionarSuiteNoForm(s.nome); rastrear('cta_reservar', { local: 'suite', suite: s.id }); } }, 'Reservar esta suíte', h('span', { html: ICONE_SETA })))));
    cenaSuite.canvas = canvas;
    cenaSuite.cfg = s.cena;
    cenaSuite.paisagem = Paisagem(canvas, s.cena);
    if (painel.isConnected) cenaSuite.desenhar(0);
    estado.sujo = true;
    if (peloUsuario) rastrear('suite_view', { suite: s.id });
  }

  const suites = h('section', { class: 'secao secao--papel', id: 'suites', 'aria-labelledby': 'titulo-suites' },
    h('div', { class: 'caixa' },
      h('div', { class: 'cabeca' },
        h('div', null, h('p', { class: 'eyebrow' }, 'Suítes'), h('h2', { class: 'titulo', id: 'titulo-suites', html: 'Dormir dentro de um <em>moinho</em>.' })),
        h('p', { class: 'corpo' }, 'São dezesseis suítes, cada uma com hidromassagem interna privativa e cama com colchão pillow-top. Escolha pela vista.')),
      abas, painel));

  /* ---------- Due Mulini ---------- */
  const velaCanvas = h('canvas', { 'aria-hidden': 'true' });
  const sarauQuando = h('p', { class: 'sarau__quando' });
  const cont = { d: h('b'), h: h('b'), m: h('b'), s: h('b') };
  const mulini = h('section', { class: 'secao secao--noite escuro', id: 'due-mulini', 'aria-labelledby': 'titulo-mulini' },
    h('div', { class: 'caixa mulini' },
      h('div', null,
        h('p', { class: 'eyebrow' }, 'Restaurante'),
        h('h2', { class: 'mulini__nome', id: 'titulo-mulini' }, 'Due Mulini'),
        h('p', { class: 'mulini__trad', html: 'Italiano para <b>“dois moinhos”</b>' }),
        h('p', { class: 'corpo' }, 'Jantar à luz de velas, cozinha italiana e o ambiente mais romântico de Campos do Jordão. Aos sábados, música clássica ao vivo acompanha o pôr do sol.'),
        h('p', { style: { marginTop: '34px' } },
          h('a', { class: 'btn btn--fantasma', href: `https://wa.me/${HOTEL.whatsapp}?text=${encodeURIComponent('Olá! Gostaria de reservar uma mesa no Due Mulini.')}`, target: '_blank', rel: 'noopener', onClick: () => rastrear('whatsapp_click', { local: 'due_mulini' }) },
            h('span', { html: ICONE_WHATS }), 'Reservar mesa pelo WhatsApp'))),
      h('aside', { class: 'sarau', 'aria-label': 'Próximo sarau ao pôr do sol' },
        h('div', { class: 'sarau__topo' }, velaCanvas,
          h('div', null, h('p', { class: 'eyebrow' }, 'Próximo sarau'), h('h3', null, 'Música clássica ao vivo no pôr do sol'), sarauQuando)),
        h('div', { class: 'contagem', 'aria-live': 'off' },
          h('div', null, cont.d, h('span', null, 'dias')), h('div', null, cont.h, h('span', null, 'horas')),
          h('div', null, cont.m, h('span', null, 'min')), h('div', null, cont.s, h('span', null, 'seg'))),
        h('p', { class: 'sarau__nota' }, 'Horário calculado a partir do pôr do sol em Campos do Jordão.'))));

  /* ---------- experiências ---------- */
  const experiencias = h('section', { class: 'secao', id: 'experiencias', 'aria-labelledby': 'titulo-exp' },
    h('div', { class: 'caixa' },
      h('div', { class: 'cabeca' },
        h('div', null, h('p', { class: 'eyebrow' }, 'Experiências'), h('h2', { class: 'titulo', id: 'titulo-exp', html: 'Os dias aqui têm o ritmo da <em>serra</em>.' })),
        h('p', { class: 'corpo' }, 'Manhãs de sol no terraço, tardes no jardim e noites em volta do fogo. Tudo dentro da propriedade.')),
      h('div', { class: 'grade' }, EXPERIENCIAS.map(e => h('article', { class: 'exp' }, h('span', { html: icone(e.icone) }), h('h3', null, e.titulo), h('p', null, e.texto))))));

  /* ---------- localização ---------- */
  const mapa = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${HOTEL.nome}, ${HOTEL.endereco}, ${HOTEL.cidade} - ${HOTEL.uf}`)}`;
  const localizacao = h('section', { class: 'secao secao--papel', id: 'localizacao', 'aria-labelledby': 'titulo-local' },
    h('div', { class: 'caixa local' },
      h('div', null,
        h('p', { class: 'eyebrow' }, 'Localização'),
        h('h2', { class: 'titulo', id: 'titulo-local', html: 'No alto do Capivari, <em>perto de tudo</em>.' }),
        h('p', { class: 'endereco' }, `${HOTEL.endereco} · ${HOTEL.bairro}`, h('br'), `${HOTEL.cidade}/${HOTEL.uf}`, h('small', null, `CEP ${HOTEL.cep} · ${HOTEL.coordenadas}`)),
        h('p', { class: 'corpo' }, 'De São Paulo são cerca de 180 km pelas rodovias Carvalho Pinto e Floriano Rodrigues Pinheiro (SP-123), a estrada que sobe a serra até Campos do Jordão.'),
        h('p', { style: { marginTop: '30px' } }, h('a', { class: 'btn btn--escuro', href: mapa, target: '_blank', rel: 'noopener', onClick: () => rastrear('mapa_click') }, 'Abrir no Google Maps', h('span', { html: ICONE_SETA })))),
      h('div', null,
        h('ul', { class: 'distancias' }, ARREDORES.map(a => h('li', null, h('strong', null, a.nome), h('span', null, a.km), h('em', null, a.nota)))),
        h('p', { class: 'local__nota' }, 'Distâncias aproximadas a partir do hotel. Traslado disponível sob consulta, com custo adicional.'))));

  /* ---------- reserva ---------- */
  const iso = d => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
  const deIso = s => { const [y, m, d] = s.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d)); };
  const hoje = (() => { const t = hojeLocal(); return new Date(Date.UTC(t.y, t.m - 1, t.d)); })();
  const somaDias = (d, n) => new Date(d.getTime() + n * 864e5);
  const proxSexta = (() => { let d = somaDias(hoje, 3); while (d.getUTCDay() !== 5) d = somaDias(d, 1); return d; })();

  const inEntrada = h('input', { type: 'date', id: 'entrada', name: 'entrada', required: true, min: iso(hoje), value: iso(proxSexta) });
  const inSaida = h('input', { type: 'date', id: 'saida', name: 'saida', required: true, min: iso(somaDias(hoje, 1)), value: iso(somaDias(proxSexta, 2)) });
  const inAdultos = h('select', { id: 'adultos', name: 'adultos' }, [1, 2, 3, 4].map(n => h('option', { value: String(n), selected: n === 2 }, `${n} ${n === 1 ? 'adulto' : 'adultos'}`)));
  const inSuite = h('select', { id: 'suite', name: 'suite' }, ['Sem preferência', ...SUITES.map(s => s.nome)].map(n => h('option', { value: n }, n)));
  const resumoNoites = h('p', { class: 'resumo__noites' });
  const resumoDatas = h('p', { class: 'resumo__datas' });
  const btnMotor = h('a', { class: 'btn btn--latao', target: '_blank', rel: 'noopener', onClick: () => rastrear('begin_checkout', dadosReserva()) }, 'Ver disponibilidade e tarifas', h('span', { html: ICONE_SETA }));
  const btnWhats = h('a', { class: 'btn btn--fantasma', target: '_blank', rel: 'noopener', onClick: () => rastrear('whatsapp_click', { local: 'reserva', ...dadosReserva() }) }, h('span', { html: ICONE_WHATS }), 'Reservar pelo WhatsApp');

  const campo = (rotulo, id, el, largo) => h('div', { class: `campo${largo ? ' campo--largo' : ''}` }, h('label', { for: id }, rotulo), el);
  const form = h('form', { class: 'form', id: 'form-reserva', novalidate: true, 'aria-label': 'Consultar disponibilidade', onSubmit: e => { e.preventDefault(); btnMotor.click(); } },
    h('div', { class: 'campos' },
      campo('Check-in', 'entrada', inEntrada), campo('Check-out', 'saida', inSaida),
      campo('Hóspedes', 'adultos', inAdultos), campo('Suíte', 'suite', inSuite)),
    h('div', { class: 'resumo', 'aria-live': 'polite' }, resumoNoites, resumoDatas),
    h('div', { class: 'form__acoes' }, btnMotor, btnWhats),
    h('p', { class: 'form__nota' }, 'O motor de reservas abre em uma nova aba com as datas já preenchidas.'));

  const aviso = h('div', { class: 'aviso', role: 'status' });
  let avisoTimer;
  function avisar(txt) { aviso.textContent = txt; aviso.classList.add('mostra'); clearTimeout(avisoTimer); avisoTimer = setTimeout(() => aviso.classList.remove('mostra'), 1800); }
  function botaoCopiar(texto, rotulo) {
    return h('button', { class: 'copiar', type: 'button', 'aria-label': `Copiar ${rotulo}`, onClick: e => {
      const alvo = e.currentTarget.previousElementSibling;
      const selecionar = () => { const r = document.createRange(); r.selectNodeContents(alvo); const s = getSelection(); s.removeAllRanges(); s.addRange(r); avisar('Texto selecionado'); };
      try { navigator.clipboard.writeText(texto).then(() => avisar('Copiado'), selecionar); } catch (_) { selecionar(); }
      rastrear('copiar_contato', { campo: rotulo });
    } }, 'Copiar');
  }

  const reserva = h('section', { class: 'secao secao--musgo escuro', id: 'reservar', 'aria-labelledby': 'titulo-reserva' },
    h('div', { class: 'caixa reserva' },
      h('div', null,
        h('p', { class: 'eyebrow' }, 'Reservas'),
        h('h2', { class: 'titulo', id: 'titulo-reserva', html: 'Sua estadia começa <em>aqui</em>.' }),
        h('p', { class: 'corpo', style: { marginTop: '22px' } }, 'Consulte disponibilidade e tarifas em tempo real no nosso motor de reservas, ou fale direto com a recepção pelo WhatsApp.'),
        h('dl', { class: 'contato' },
          h('div', null, h('dt', null, 'Telefone e WhatsApp'), h('dd', null, HOTEL.telefone), botaoCopiar(HOTEL.telefone, 'telefone')),
          h('div', null, h('dt', null, 'E-mail'), h('dd', null, HOTEL.email), botaoCopiar(HOTEL.email, 'e-mail')))),
      form));

  /* ---------- rodapé ---------- */
  const rodape = h('footer', { class: 'rodape' },
    h('div', { class: 'caixa' },
      h('div', { class: 'rodape__grade' },
        h('div', null,
          h('a', { class: 'marca', href: '#inicio' }, h('span', { html: logoMoinho() }), h('span', null, h('b', null, HOTEL.nome), h('small', null, HOTEL.tipo))),
          h('p', { style: { marginTop: '22px', maxWidth: '36ch' } }, 'Boutique hotel no Alto do Capivari, em Campos do Jordão, com o restaurante italiano Due Mulini.')),
        h('div', null, h('h4', null, 'Endereço'),
          h('p', null, HOTEL.endereco), h('p', null, `${HOTEL.bairro} · ${HOTEL.cidade}/${HOTEL.uf}`), h('p', null, `CEP ${HOTEL.cep}`)),
        h('div', null, h('h4', null, 'Contato'),
          h('p', null, h('a', { href: `https://wa.me/${HOTEL.whatsapp}`, target: '_blank', rel: 'noopener', onClick: () => rastrear('whatsapp_click', { local: 'rodape' }) }, `WhatsApp ${HOTEL.telefone}`)),
          h('p', null, HOTEL.email),
          h('p', null, h('a', { href: HOTEL.site, target: '_blank', rel: 'noopener' }, 'recantodosmoinhos.com.br')))),
      h('div', { class: 'rodape__base' },
        h('span', null, `© ${new Date().getFullYear()} ${HOTEL.nome} · CNPJ ${HOTEL.cnpj}`),
        h('span', null, `${HOTEL.coordenadas} · ${HOTEL.altitude}`))));

  /* ---------- barra fixa no celular ---------- */
  const barra = h('div', { class: 'barra', 'aria-label': 'Reserva rápida' },
    h('a', { class: 'btn btn--latao', href: '#reservar', onClick: () => rastrear('cta_reservar', { local: 'barra_mobile' }) }, 'Reservar'),
    h('a', { class: 'btn btn--fantasma', href: `https://wa.me/${HOTEL.whatsapp}`, target: '_blank', rel: 'noopener', onClick: () => rastrear('whatsapp_click', { local: 'barra_mobile' }) }, h('span', { html: ICONE_WHATS }), 'WhatsApp'));

  app.append(nav, hero, h('main', null, intro, suites, mulini, experiencias, localizacao, reserva), rodape, barra, aviso);

  /* ---------- SEO: dados estruturados ---------- */
  document.head.append(h('script', { type: 'application/ld+json' }, JSON.stringify({
    '@context': 'https://schema.org', '@type': 'Hotel', name: HOTEL.nome, url: HOTEL.site, telephone: '+55 12 99612-3546', email: HOTEL.email,
    description: 'Boutique hotel no Alto do Capivari, Campos do Jordão, com 16 suítes com hidromassagem, a Suíte Moinho e o restaurante italiano Due Mulini.',
    numberOfRooms: HOTEL.suites,
    address: { '@type': 'PostalAddress', streetAddress: HOTEL.endereco, addressLocality: HOTEL.cidade, addressRegion: HOTEL.uf, postalCode: HOTEL.cep, addressCountry: 'BR' },
    geo: { '@type': 'GeoCoordinates', latitude: HOTEL.lat, longitude: HOTEL.lng },
    amenityFeature: ['Hidromassagem privativa', 'Restaurante', 'Estacionamento privativo gratuito', 'Wi-Fi', 'Café da manhã', 'Lareira ao ar livre', 'Jardim']
      .map(name => ({ '@type': 'LocationFeatureSpecification', name, value: true })),
  })));

  /* =========================================================
     Comportamento
     ========================================================= */

  /* ---------- relógio da paisagem ---------- */
  const heroCena = { vis: true, paisagem: Paisagem(heroCanvas, { retrato: true, trees: 16 }),
    desenhar(t) {
      const data = new Date(meiaNoite(hojeLocal()).getTime() + estado.minutos * 60000);
      const { nascer, por } = Sol.tempos(new Date(meiaNoite(hojeLocal()).getTime() + 12 * 36e5), HOTEL.lat, HOTEL.lng);
      this.paisagem.desenhar({ alt: Sol.altitude(data, HOTEL.lat, HOTEL.lng), frac: (data - nascer) / (por - nascer), ang: estado.ang, scroll: estado.scroll, t });
    } };
  cenas.push(heroCena);

  function atualizarRelogio() {
    const m = estado.minutos;
    horaRotulo.textContent = `${pad(Math.floor(m / 60))}h${pad(m % 60)}`;
    slider.value = String(m);
    btnAgora.setAttribute('aria-pressed', String(estado.aoVivo));
    slider.setAttribute('aria-valuetext', `${pad(Math.floor(m / 60))} horas e ${pad(m % 60)} minutos`);
    estado.sujo = true;
  }
  function marcasDoSol() {
    const meio = new Date(meiaNoite(hojeLocal()).getTime() + 12 * 36e5);
    const { nascer, por } = Sol.tempos(meio, HOTEL.lat, HOTEL.lng);
    const minDe = d => (d - meiaNoite(hojeLocal())) / 60000;
    slider.style.setProperty('--a', `${(minDe(nascer) / 1439 * 100).toFixed(2)}%`);
    slider.style.setProperty('--b', `${(minDe(por) / 1439 * 100).toFixed(2)}%`);
    marcaNascer.textContent = `Nascer do sol ${horaCurta(nascer)}`;
    marcaPor.textContent = `Pôr do sol ${horaCurta(por)}`;
  }
  function irParaAgora() { estado.aoVivo = true; estado.minutos = minutosAgora(); atualizarRelogio(); }
  slider.addEventListener('input', () => {
    estado.aoVivo = false; estado.minutos = Number(slider.value); atualizarRelogio();
    if (!estado.mexeuRelogio) { estado.mexeuRelogio = true; rastrear('hero_relogio'); }
  });
  setInterval(() => { if (estado.aoVivo) { estado.minutos = minutosAgora(); atualizarRelogio(); } marcasDoSol(); }, 30000);
  marcasDoSol(); atualizarRelogio();

  /* ---------- sarau de sábado ---------- */
  let proximoSarau = null;
  function calcularSarau() {
    const agora = new Date(), base = meiaNoite(hojeLocal(agora));
    for (let i = 0; i < 9; i++) {
      const dia = new Date(base.getTime() + i * 864e5);
      if (new Date(dia.getTime() - OFFSET_H * 36e5).getUTCDay() !== 6) continue;
      const { por } = Sol.tempos(new Date(dia.getTime() + 12 * 36e5), HOTEL.lat, HOTEL.lng);
      if (por > agora) { proximoSarau = por; break; }
    }
    const dia = fmtDiaLongo.format(proximoSarau);
    sarauQuando.textContent = `${dia.charAt(0).toUpperCase() + dia.slice(1)} · ${horaCurta(proximoSarau)}`;
  }
  function tique() {
    if (!proximoSarau || proximoSarau <= new Date()) calcularSarau();
    let s = Math.max(0, Math.floor((proximoSarau - Date.now()) / 1000));
    const d = Math.floor(s / 86400); s -= d * 86400;
    const hh = Math.floor(s / 3600); s -= hh * 3600;
    const mm = Math.floor(s / 60); s -= mm * 60;
    cont.d.textContent = d; cont.h.textContent = pad(hh); cont.m.textContent = pad(mm); cont.s.textContent = pad(s);
  }
  tique(); setInterval(tique, 1000);

  const vela = Vela(velaCanvas);
  const cenaVela = { vis: false, desenhar(t) { vela.desenhar(t); } };
  cenas.push(cenaVela);

  /* ---------- formulário de reserva ---------- */
  const ddmmyyyy = d => `${pad(d.getUTCDate())}${pad(d.getUTCMonth() + 1)}${d.getUTCFullYear()}`;
  const ddmm = d => `${pad(d.getUTCDate())}/${pad(d.getUTCMonth() + 1)}`;
  function dadosReserva() {
    const e = deIso(inEntrada.value), s = deIso(inSaida.value);
    return { checkin: inEntrada.value, checkout: inSaida.value, noites: Math.round((s - e) / 864e5), adultos: Number(inAdultos.value), suite: inSuite.value };
  }
  function atualizarReserva(origem) {
    if (!inEntrada.value || deIso(inEntrada.value) < hoje) inEntrada.value = iso(hoje);
    let e = deIso(inEntrada.value);
    if (!inSaida.value || deIso(inSaida.value) <= e) inSaida.value = iso(somaDias(e, origem === 'saida' ? 1 : 2));
    inSaida.min = iso(somaDias(e, 1));
    const s = deIso(inSaida.value), r = dadosReserva();
    resumoNoites.textContent = `${r.noites} ${r.noites === 1 ? 'noite' : 'noites'} no Recanto`;
    resumoDatas.textContent = `${fmtDiaCurto.format(e)} → ${fmtDiaCurto.format(s)} · ${r.adultos} ${r.adultos === 1 ? 'adulto' : 'adultos'} · ${r.suite}`;
    btnMotor.href = `https://book.omnibees.com/hotelresults?q=${HOTEL.omnibees}&lang=pt-BR&currencyId=16&NRooms=1&CheckIn=${ddmmyyyy(e)}&CheckOut=${ddmmyyyy(s)}&ad=${r.adultos}&ch=0&${UTM}`;
    const msg = `Olá! Gostaria de reservar no ${HOTEL.nome}: ${r.suite === 'Sem preferência' ? 'qualquer suíte disponível' : r.suite}, de ${ddmm(e)} a ${ddmm(s)} (${r.noites} ${r.noites === 1 ? 'noite' : 'noites'}), ${r.adultos} ${r.adultos === 1 ? 'adulto' : 'adultos'}.`;
    btnWhats.href = `https://wa.me/${HOTEL.whatsapp}?text=${encodeURIComponent(msg)}`;
  }
  function selecionarSuiteNoForm(nome) { inSuite.value = nome; atualizarReserva(); }
  inEntrada.addEventListener('change', () => atualizarReserva('entrada'));
  inSaida.addEventListener('change', () => atualizarReserva('saida'));
  inAdultos.addEventListener('change', () => atualizarReserva());
  inSuite.addEventListener('change', () => atualizarReserva());
  atualizarReserva();

  /* ---------- rolagem: navegação, parallax, pás do moinho ---------- */
  let ultimoY = window.scrollY, ultimoT = performance.now();
  function atualizarNav() { if (!nav.classList.contains('aberta')) nav.classList.toggle('solida', window.scrollY > 40); }
  window.addEventListener('scroll', () => {
    const y = window.scrollY, t = performance.now();
    estado.scrollVel = Math.max(estado.scrollVel, Math.abs(y - ultimoY) / Math.max(16, t - ultimoT) * 1000);
    ultimoY = y; ultimoT = t;
    const hh = hero.offsetHeight;
    estado.scroll = Math.min(y, hh);
    if (!reduz) {
      heroConteudo.style.transform = `translateY(${(estado.scroll * .22).toFixed(1)}px)`;
      heroConteudo.style.opacity = String(clamp(1 - estado.scroll / (hh * .85)));
    }
    atualizarNav();
    estado.sujo = true;
  }, { passive: true });
  atualizarNav();

  /* ---------- visibilidade ---------- */
  const io = new IntersectionObserver(entradas => {
    for (const en of entradas) {
      if (en.target === hero) heroCena.vis = en.isIntersecting;
      if (en.target === painel) cenaSuite.vis = en.isIntersecting;
      if (en.target === velaCanvas) cenaVela.vis = en.isIntersecting;
    }
    estado.sujo = true;
  });
  [hero, painel, velaCanvas].forEach(el => io.observe(el));

  const visiveis = new Set();
  const ioBarra = new IntersectionObserver(entradas => {
    entradas.forEach(en => en.isIntersecting ? visiveis.add(en.target) : visiveis.delete(en.target));
    barra.classList.toggle('visivel', !visiveis.has(hero) && !visiveis.has(reserva));
  });
  ioBarra.observe(hero); ioBarra.observe(reserva);

  /* ---------- tamanho ---------- */
  let rzTimer;
  window.addEventListener('resize', () => {
    clearTimeout(rzTimer);
    rzTimer = setTimeout(() => { heroCena.paisagem.redimensionar(); cenaSuite.paisagem && cenaSuite.paisagem.redimensionar(); vela.redimensionar(); estado.sujo = true; }, 120);
  });

  /* ---------- laço de animação ---------- */
  mostrarSuite(0, false);
  let anterior = 0;
  function quadro(ms) {
    const t = ms / 1000, dt = Math.min(.05, anterior ? t - anterior : 0);
    anterior = t;
    if (!reduz) {
      const alvo = .5 + Math.min(5, estado.scrollVel / 350);
      estado.vel += (alvo - estado.vel) * Math.min(1, dt * 1.6);
      estado.ang += estado.vel * dt;
      estado.scrollVel *= Math.pow(.04, dt);
      const lp = logoPas();
      if (lp) lp.setAttribute('transform', `rotate(${(estado.ang * 57.2958 % 360).toFixed(2)} 20 15)`);
      $('.rodape .logo-pas')?.setAttribute('transform', `rotate(${(estado.ang * 57.2958 % 360).toFixed(2)} 20 15)`);
    }
    if (!reduz || estado.sujo) {
      const tt = reduz ? 0 : t;
      for (const c of cenas) if (c.vis) c.desenhar(tt);
      estado.sujo = false;
    }
    requestAnimationFrame(quadro);
  }
  // primeiro quadro síncrono: a página já aparece completa
  heroCena.desenhar(0); cenaSuite.desenhar(0); vela.desenhar(0);
  requestAnimationFrame(quadro);
})();
