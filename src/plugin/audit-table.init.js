/*!
 * audit-table.init.js
 * ------------------------------------------------------------------------
 * Widget de UI para o pacote gsebastiao/laravel-auditable.
 *
 * 100% OPCIONAL. O pacote PHP funciona perfeitamente sem este arquivo —
 * ele só existe para quem quer uma tela de "histórico de alterações"
 * pronta, sem escrever HTML/CSS/JS do zero.
 *
 * Zero dependências: sem jQuery, sem Bootstrap, sem DataTables, sem
 * Font Awesome. Um único <script> que você inclui na página e pronto —
 * o modal, a tabela, a paginação e o estilo vêm todos daqui de dentro.
 *
 * Expõe dois widgets independentes no objeto global `GaAudit`:
 *
 *   GaAudit.full.open({ endpoint, id })
 *     Modal completo: busca, filtro por ação, paginação, alterações
 *     agrupadas por batch. Pensado para quem tem permissão de auditor
 *     (vê o histórico inteiro de um registro, com todos os detalhes).
 *
 *   GaAudit.simple.open({ endpoint, id })
 *     Modal enxuto: lista direta de ações (o quê, quem, quando), sem
 *     filtros nem paginação. Pensado para expor a um usuário comum um
 *     resumo rápido, sem sobrecarregar a tela.
 *
 * Cada um tem seu próprio contrato de resposta HTTP — veja o bloco
 * "CONTRATOS DE RESPOSTA" logo abaixo. Nenhuma rota do lado do servidor
 * vem pronta: você escreve o endpoint Laravel que devolve esse JSON
 * (normalmente chamando ->operation() ou ->auditsFor() do próprio
 * pacote). O README do pacote tem um exemplo completo de cada rota.
 *
 * Todo o CSS injetado usa classes prefixadas com "ga-audit-" para nunca
 * colidir com o CSS de um template já existente na sua aplicação
 * (AdminLTE, Bootstrap customizado, etc.). Nada aqui depende de classes
 * de terceiros, e nada aqui usa uma classe "genérica" (como .modal ou
 * .table) que possa ser pega por acidente por um seletor CSS já
 * existente no seu site.
 *
 * ------------------------------------------------------------------------
 * CONTRATOS DE RESPOSTA
 * ------------------------------------------------------------------------
 *
 * GaAudit.full — a rota de "endpoint" deve responder um POST com JSON:
 *
 *   {
 *     "record_id": "42",
 *     "groups": [
 *       {
 *         "batch_id": "01J...",
 *         "actions": [
 *           {
 *             "action": "updated",
 *             "type": "success",
 *             "user_id": "Maria Silva",
 *             "created_at": "2026-08-01 14:32:10",
 *             "changes": { "Status": ["Ativo", "Bloqueado"] }
 *           }
 *         ]
 *       }
 *     ]
 *   }
 *
 * GaAudit.simple — a rota de "endpoint" deve responder um POST com JSON:
 *
 *   {
 *     "audits": [
 *       { "action": "updated", "user_id": "Maria Silva", "created_at": "2026-08-01 14:32:10" }
 *     ]
 *   }
 *
 * Em ambos os casos, o "id" passado em .open({ id }) chega no corpo do
 * POST como { id: ... } — é o valor que a sua rota vai usar para buscar
 * o registro certo.
 * ------------------------------------------------------------------------
 */
(function (window, document) {
    'use strict';

    // Evita registrar tudo de novo se o arquivo for incluído 2x na página.
    if (window.GaAudit && window.GaAudit.__loaded) {
        return;
    }

    /* ======================================================================
     * 1. ÍCONES
     * ====================================================================
     * Por padrão usamos símbolos Unicode simples — zero peso extra, zero
     * dependência de fonte de ícones. Se preferir usar SVGs ou outra
     * fonte de ícones, sobrescreva o que quiser em GaAudit.icons ANTES
     * de chamar .open() pela primeira vez:
     *
     *   GaAudit.icons.close = '<svg>...</svg>';
     *   GaAudit.icons.first = '<img src="/icons/first.svg" alt="">';
     */
    var ICONS = {
        close: '\u2715',   // ✕
        search: '\u26B2',  // ⚲
        first: '\u00AB',   // «
        prev: '\u2039',    // ‹
        next: '\u203A',    // ›
        last: '\u00BB'     // »
    };

    /* ======================================================================
     * 2. CSS INJETADO (uma vez só, reaproveitado pelos dois widgets)
     * ====================================================================
     * Tudo prefixado com "ga-audit-" para nunca vazar nem ser atingido
     * por acidente pelo CSS do site. Usa variáveis CSS (--ga-audit-*)
     * para permitir customização de cor/raio/espaçamento sem precisar
     * sobrescrever regra por regra — veja "CUSTOMIZAÇÃO" no README.
     */
    var STYLE_ID = 'ga-audit-styles';

    function injectStyles() {
        if (document.getElementById(STYLE_ID)) return;

        var css = ''
            + ':root{'
            + '--ga-audit-accent:#2563a8;'
            + '--ga-audit-accent-contrast:#ffffff;'
            + '--ga-audit-bg:#ffffff;'
            + '--ga-audit-bg-subtle:#f6f7f9;'
            + '--ga-audit-border:#e2e5ea;'
            + '--ga-audit-text:#1f2430;'
            + '--ga-audit-text-muted:#6b7280;'
            + '--ga-audit-success:#1a7f4b;'
            + '--ga-audit-danger:#c0362c;'
            + '--ga-audit-warning:#a86a05;'
            + '--ga-audit-radius:10px;'
            + '--ga-audit-radius-sm:6px;'
            + '--ga-audit-font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;'
            + '--ga-audit-z:2147483000;'
            + '}'

            // ---- overlay + shell -------------------------------------------
            + '.ga-audit-overlay{position:fixed;inset:0;background:rgba(17,20,27,.5);'
            + 'display:flex;align-items:center;justify-content:center;padding:16px;'
            + 'z-index:var(--ga-audit-z);opacity:0;transition:opacity .15s ease;box-sizing:border-box;}'
            + '.ga-audit-overlay.is-open{opacity:1;}'
            + '.ga-audit-overlay *{box-sizing:border-box;}'

            + '.ga-audit-modal{background:var(--ga-audit-bg);color:var(--ga-audit-text);'
            + 'font-family:var(--ga-audit-font);font-size:14px;line-height:1.45;'
            + 'width:100%;max-width:860px;max-height:min(680px,calc(100vh - 32px));'
            + 'border-radius:var(--ga-audit-radius);box-shadow:0 20px 60px rgba(17,20,27,.25);'
            + 'display:flex;flex-direction:column;overflow:hidden;'
            + 'transform:translateY(8px) scale(.98);transition:transform .15s ease;}'
            + '.ga-audit-overlay.is-open .ga-audit-modal{transform:translateY(0) scale(1);}'
            + '.ga-audit-modal.ga-audit-modal--simple{max-width:560px;max-height:min(480px,calc(100vh - 32px));}'

            // modal ocupa a tela toda em telas pequenas (compatível com "todas as telas")
            + '@media (max-width:560px){'
            + '.ga-audit-overlay{padding:0;align-items:flex-end;}'
            + '.ga-audit-modal{max-width:100%;width:100%;max-height:92vh;'
            + 'border-radius:var(--ga-audit-radius) var(--ga-audit-radius) 0 0;}'
            + '}'

            // ---- header ---------------------------------------------------
            + '.ga-audit-header{display:flex;align-items:flex-start;gap:12px;'
            + 'padding:16px 18px;border-bottom:1px solid var(--ga-audit-border);flex:0 0 auto;}'
            + '.ga-audit-header-text{flex:1 1 auto;min-width:0;}'
            + '.ga-audit-title{margin:0;font-size:16px;font-weight:600;color:var(--ga-audit-text);}'
            + '.ga-audit-subtitle{margin:6px 0 0;font-size:14px;color:var(--ga-audit-text);}'
            + '.ga-audit-subtitle:empty{margin:0;}'
            + '.ga-audit-close{flex:0 0 auto;appearance:none;border:0;background:transparent;'
            + 'width:30px;height:30px;border-radius:var(--ga-audit-radius-sm);cursor:pointer;'
            + 'font-size:15px;color:var(--ga-audit-text-muted);display:flex;align-items:center;justify-content:center;}'
            + '.ga-audit-close:hover{background:var(--ga-audit-bg-subtle);color:var(--ga-audit-text);}'
            + '.ga-audit-close:focus-visible{outline:2px solid var(--ga-audit-accent);outline-offset:2px;}'

            // ---- toolbar: grupo esquerdo (Ação: / Linhas:) + busca (direita) ----
            + '.ga-audit-toolbar{display:flex;align-items:flex-end;justify-content:space-between;'
            + 'gap:16px;padding:14px 18px;border-bottom:1px solid var(--ga-audit-border);'
            + 'flex:0 0 auto;flex-wrap:wrap;}'
            + '.ga-audit-toolbar-group{display:flex;align-items:flex-end;gap:20px;flex-wrap:wrap;}'
            + '.ga-audit-field{display:flex;flex-direction:column;gap:4px;font-size:13px;}'
            + '.ga-audit-field-label{color:var(--ga-audit-text);white-space:nowrap;}'
            + '.ga-audit-field--search{flex:0 1 260px;min-width:180px;}'
            + '.ga-audit-search{position:relative;}'
            + '.ga-audit-search-icon{position:absolute;right:10px;top:50%;transform:translateY(-50%);'
            + 'font-size:12px;color:var(--ga-audit-text-muted);pointer-events:none;}'
            + '.ga-audit-input,.ga-audit-select{font:inherit;font-size:13px;color:var(--ga-audit-text);'
            + 'background:var(--ga-audit-bg);border:1px solid var(--ga-audit-border);'
            + 'border-radius:var(--ga-audit-radius-sm);padding:7px 10px;}'
            + '.ga-audit-field--search .ga-audit-input{width:100%;padding-right:30px;}'
            + '.ga-audit-input:focus,.ga-audit-select:focus{outline:none;border-color:var(--ga-audit-accent);'
            + 'box-shadow:0 0 0 3px rgba(37,99,168,.15);}'
            + '.ga-audit-select{min-width:110px;}'

            // ---- body / estados ----------------------------------------------
            + '.ga-audit-body{flex:1 1 auto;overflow-y:auto;overflow-x:hidden;padding:0 18px;}'
            + '.ga-audit-state{padding:36px 12px;text-align:center;color:var(--ga-audit-text-muted);}'
            + '.ga-audit-state-title{font-weight:600;color:var(--ga-audit-text);margin:0 0 4px;font-size:13.5px;}'
            + '.ga-audit-state-desc{margin:0;font-size:13px;}'
            + '.ga-audit-spinner{width:26px;height:26px;border-radius:50%;'
            + 'border:3px solid var(--ga-audit-border);border-top-color:var(--ga-audit-accent);'
            + 'margin:0 auto 12px;animation:ga-audit-spin .7s linear infinite;}'
            + '@keyframes ga-audit-spin{to{transform:rotate(360deg);}}'

            // ---- tabela -----------------------------------------------------
            // O código do batch (frequentemente um UUID de 36 caracteres) não
            // precisa ocupar a largura da coluna no dia a dia — só importa quando
            // o usuário reporta um problema e precisa repassá-lo a um dev, por
            // isso ele vem truncado por padrão (.ga-audit-batch-toggle) com uma
            // opção de expandir + copiar. Isso já reduz bastante a largura mínima
            // necessária da tabela. Ainda assim, .ga-audit-table-scroll existe
            // para rolar SÓ horizontalmente quando a tela é mesmo muito estreita
            // (o scroll vertical continua sendo do .ga-audit-body por fora), com
            // sombras internas nas bordas avisando que há mais conteúdo pros lados.
            + '.ga-audit-table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;'
            + 'box-shadow:inset 8px 0 6px -6px rgba(17,20,27,.15),inset -8px 0 6px -6px rgba(17,20,27,.15);}'
            + '.ga-audit-table{width:100%;min-width:420px;border-collapse:collapse;font-size:13.5px;}'
            + '.ga-audit-table th{text-align:left;font-weight:600;color:var(--ga-audit-text);'
            + 'font-size:13.5px;padding:10px;border-bottom:2px solid var(--ga-audit-border);'
            + 'position:sticky;top:0;background:var(--ga-audit-bg);white-space:nowrap;}'
            + '.ga-audit-table td{padding:12px 10px;border-bottom:1px solid var(--ga-audit-border);vertical-align:top;}'
            + '.ga-audit-table tr:last-child td{border-bottom:0;}'
            + '.ga-audit-cell-muted{color:var(--ga-audit-text-muted);white-space:nowrap;}'
            + '.ga-audit-batch{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;'
            + 'font-size:12px;color:var(--ga-audit-text);white-space:nowrap;}'

            // ---- código do batch truncado: toggle + painel expandido -----------
            + '.ga-audit-batch-wrap{display:inline-block;}'
            + '.ga-audit-batch-toggle{appearance:none;border:0;background:transparent;padding:0;'
            + 'cursor:pointer;text-decoration:underline;text-decoration-style:dotted;'
            + 'text-underline-offset:2px;}'
            + '.ga-audit-batch-toggle:hover{color:var(--ga-audit-accent);}'
            + '.ga-audit-batch-toggle:focus-visible{outline:2px solid var(--ga-audit-accent);'
            + 'outline-offset:2px;border-radius:2px;}'
            + '.ga-audit-batch-full{display:flex;align-items:center;gap:8px;flex-wrap:wrap;cursor:pointer;}'
            + '.ga-audit-batch-copy{appearance:none;border:1px solid var(--ga-audit-border);'
            + 'background:var(--ga-audit-bg-subtle);color:var(--ga-audit-text);border-radius:var(--ga-audit-radius-sm);'
            + 'padding:2px 8px;font-size:11px;cursor:pointer;white-space:nowrap;}'
            + '.ga-audit-batch-copy:hover{background:var(--ga-audit-border);}'
            + '.ga-audit-batch-copy:focus-visible{outline:2px solid var(--ga-audit-accent);outline-offset:1px;}'

            // telas pequenas: menos min-width (nem todo celular precisa do scroll)
            // e menos padding nas células, para caber mais antes de precisar rolar
            + '@media (max-width:560px){'
            + '.ga-audit-table{min-width:380px;}'
            + '.ga-audit-table th,.ga-audit-table td{padding:9px 8px;}'
            + '}'

            // ---- ações / mudanças: link de ação + painel de detalhe --------------
            + '.ga-audit-action-item{margin:0 0 4px;}'
            + '.ga-audit-action-item:last-child{margin-bottom:0;}'
            + '.ga-audit-action-toggle{appearance:none;border:0;background:transparent;padding:0;'
            + 'font:inherit;font-size:13.5px;font-weight:400;color:var(--ga-audit-accent);cursor:pointer;'
            + 'text-decoration:none;}'
            + '.ga-audit-action-toggle:hover{text-decoration:underline;}'
            + '.ga-audit-action-toggle:focus-visible{outline:2px solid var(--ga-audit-accent);outline-offset:2px;'
            + 'border-radius:2px;}'
            + '.ga-audit-action-panel{display:none;margin:6px 0 2px;padding:8px 10px;'
            + 'background:var(--ga-audit-bg-subtle);border-radius:var(--ga-audit-radius-sm);}'
            + '.ga-audit-action-panel.is-open{display:block;}'
            + '.ga-audit-type-row{margin:0 0 6px;}'
            + '.ga-audit-type-row:last-child{margin-bottom:0;}'
            + '.ga-audit-type-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;}'
            + '.ga-audit-type-success{color:var(--ga-audit-success);}'
            + '.ga-audit-type-failed{color:var(--ga-audit-danger);}'
            + '.ga-audit-type-warning{color:var(--ga-audit-warning);}'
            + '.ga-audit-type-info{color:var(--ga-audit-accent);}'
            + '.ga-audit-change-line{font-size:12.5px;color:var(--ga-audit-text-muted);padding:2px 0;}'

            // ---- footer / paginação -------------------------------------------
            + '.ga-audit-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;'
            + 'padding:12px 18px;border-top:1px solid var(--ga-audit-border);flex:0 0 auto;flex-wrap:wrap;}'
            + '.ga-audit-counter{font-size:12.5px;color:var(--ga-audit-text-muted);}'
            + '.ga-audit-pagination{display:flex;align-items:center;gap:4px;flex-wrap:wrap;}'
            + '.ga-audit-page-btn{appearance:none;border:1px solid var(--ga-audit-border);'
            + 'background:var(--ga-audit-bg);color:var(--ga-audit-text);border-radius:var(--ga-audit-radius-sm);'
            + 'min-width:28px;height:28px;padding:0 6px;font-size:12.5px;cursor:pointer;}'
            + '.ga-audit-page-btn:hover:not(:disabled){background:var(--ga-audit-bg-subtle);}'
            + '.ga-audit-page-btn:focus-visible{outline:2px solid var(--ga-audit-accent);outline-offset:1px;}'
            + '.ga-audit-page-btn:disabled{opacity:.35;cursor:default;}'
            + '.ga-audit-page-btn.is-current{background:var(--ga-audit-accent);border-color:var(--ga-audit-accent);'
            + 'color:var(--ga-audit-accent-contrast);cursor:default;}'

            // ---- acessibilidade: reduced motion --------------------------------
            + '@media (prefers-reduced-motion:reduce){'
            + '.ga-audit-overlay,.ga-audit-modal{transition:none;}'
            + '.ga-audit-spinner{animation-duration:1.4s;}'
            + '}';

        var styleEl = document.createElement('style');
        styleEl.id = STYLE_ID;
        styleEl.textContent = css;
        document.head.appendChild(styleEl);
    }

    /* ======================================================================
     * 3. HELPERS GERAIS
     * ==================================================================== */

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatFieldLabel(field) {
        if (!field) return '';
        return String(field)
            .split('_')
            .map(function (w) { return w.charAt(0).toUpperCase() + w.slice(1); })
            .join(' ');
    }

    function debounce(fn, wait) {
        var t;
        return function () {
            var args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(null, args); }, wait);
        };
    }

    // POST simples via fetch, form-urlencoded (mesmo formato que um
    // $.ajax comum enviaria), então funciona direto com Request::input()
    // no Laravel sem precisar mudar nada do lado do servidor.
    function postForm(url, data) {
        var body = Object.keys(data || {})
            .map(function (k) {
                var v = data[k] === undefined || data[k] === null ? '' : data[k];
                return encodeURIComponent(k) + '=' + encodeURIComponent(v);
            })
            .join('&');

        var headers = {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
        };

        var csrfToken = getCsrfToken();
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        return fetch(url, { method: 'POST', headers: headers, body: body, credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) {
                    var err = new Error('Requisição falhou com status ' + res.status);
                    err.status = res.status;
                    throw err;
                }
                return res.json();
            });
    }

    // Procura o token CSRF em <meta name="csrf-token"> (padrão Laravel/Blade)
    // sem exigir nenhuma configuração extra de quem for usar o widget.
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : null;
    }

    var FOCUSABLE_SELECTOR = 'a[href],button:not([disabled]),textarea,input,select,[tabindex]:not([tabindex="-1"])';

    // Modal shell reutilizado pelos dois widgets: cuida de overlay, foco
    // preso dentro do modal (focus trap), tecla Esc, clique fora e
    // scroll-lock do body. Cada widget só monta o conteúdo interno
    // (header/toolbar/body/footer) — o "esqueleto" é sempre este.
    function createModalShell(opts) {
        injectStyles();

        var overlay = document.createElement('div');
        overlay.className = 'ga-audit-overlay';

        var modal = document.createElement('div');
        modal.className = 'ga-audit-modal' + (opts.variant ? ' ga-audit-modal--' + opts.variant : '');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        overlay.appendChild(modal);

        var previouslyFocused = document.activeElement;
        var previousOverflow = document.body.style.overflow;
        var closed = false;

        function close() {
            if (closed) return;
            closed = true;
            overlay.classList.remove('is-open');
            document.removeEventListener('keydown', onKeydown, true);
            setTimeout(function () {
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                document.body.style.overflow = previousOverflow;
                if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                    previouslyFocused.focus();
                }
            }, 150);
            if (typeof opts.onClose === 'function') opts.onClose();
        }

        function onKeydown(e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                close();
                return;
            }
            if (e.key === 'Tab') {
                var focusables = modal.querySelectorAll(FOCUSABLE_SELECTOR);
                if (!focusables.length) return;
                var first = focusables[0];
                var last = focusables[focusables.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }

        overlay.addEventListener('mousedown', function (e) {
            if (e.target === overlay) close();
        });

        document.addEventListener('keydown', onKeydown, true);
        document.body.style.overflow = 'hidden';
        document.body.appendChild(overlay);

        // Força reflow antes de disparar a transição de entrada.
        void overlay.offsetHeight;
        var raf = window.requestAnimationFrame || function (cb) { return setTimeout(cb, 16); };
        raf(function () { overlay.classList.add('is-open'); });

        return { overlay: overlay, modal: modal, close: close };
    }

    function buildHeader(modal, opts) {
        var header = document.createElement('div');
        header.className = 'ga-audit-header';

        var textWrap = document.createElement('div');
        textWrap.className = 'ga-audit-header-text';

        var titleId = 'ga-audit-title-' + Math.random().toString(36).slice(2, 9);
        var title = document.createElement('h2');
        title.className = 'ga-audit-title';
        title.id = titleId;
        title.textContent = opts.title;
        textWrap.appendChild(title);

        var subtitle = document.createElement('p');
        subtitle.className = 'ga-audit-subtitle';
        textWrap.appendChild(subtitle);

        header.appendChild(textWrap);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'ga-audit-close';
        closeBtn.setAttribute('aria-label', 'Fechar');
        closeBtn.innerHTML = ICONS.close;
        header.appendChild(closeBtn);

        modal.appendChild(header);
        modal.setAttribute('aria-labelledby', titleId);

        return { header: header, subtitle: subtitle, closeBtn: closeBtn };
    }

    function renderState(container, message, showSpinner) {
        var wrap = document.createElement('div');
        wrap.className = 'ga-audit-state';

        if (showSpinner) {
            var spinner = document.createElement('div');
            spinner.className = 'ga-audit-spinner';
            wrap.appendChild(spinner);
        }

        var title = document.createElement('p');
        title.className = 'ga-audit-state-title';
        title.textContent = message.title;
        wrap.appendChild(title);

        if (message.desc) {
            var desc = document.createElement('p');
            desc.className = 'ga-audit-state-desc';
            desc.textContent = message.desc;
            wrap.appendChild(desc);
        }

        container.innerHTML = '';
        container.appendChild(wrap);
    }

    /* ======================================================================
     * 4. GaAudit.full — modal completo (busca, filtro, paginação, batches)
     * ==================================================================== */

    var Full = (function () {
        var defaults = {
            endpoint: null,        // obrigatório: URL que devolve { record_id, groups }
            id: null,              // obrigatório: id do registro a consultar
            title: 'Histórico de Auditoria',
            limit: 10,             // batches por página
            limitOptions: [10, 25, 50, 100],
            onClose: null
        };

        function open(userOptions) {
            var opts = Object.assign({}, defaults, userOptions || {});

            if (!opts.endpoint || !opts.id) {
                throw new Error('GaAudit.full.open() precisa de { endpoint, id }.');
            }

            var shell = createModalShell({ variant: 'full', onClose: opts.onClose });
            var modal = shell.modal;

            var head = buildHeader(modal, { title: opts.title });
            head.closeBtn.addEventListener('click', shell.close);

            // ---- toolbar: filtro de ação + linhas por página (esquerda),
            // busca (direita) — cada controle com seu label de texto visível,
            // como um formulário comum, não só um placeholder.
            var toolbar = document.createElement('div');
            toolbar.className = 'ga-audit-toolbar';

            var toolbarLeft = document.createElement('div');
            toolbarLeft.className = 'ga-audit-toolbar-group';

            var actionField = document.createElement('label');
            actionField.className = 'ga-audit-field';
            var actionFieldLabel = document.createElement('span');
            actionFieldLabel.className = 'ga-audit-field-label';
            actionFieldLabel.textContent = 'Ação:';
            var actionSelect = document.createElement('select');
            actionSelect.className = 'ga-audit-select';
            actionField.appendChild(actionFieldLabel);
            actionField.appendChild(actionSelect);

            var limitField = document.createElement('label');
            limitField.className = 'ga-audit-field';
            var limitFieldLabel = document.createElement('span');
            limitFieldLabel.className = 'ga-audit-field-label';
            limitFieldLabel.textContent = 'Linhas:';
            var limitSelect = document.createElement('select');
            limitSelect.className = 'ga-audit-select';
            opts.limitOptions.forEach(function (n) {
                var o = document.createElement('option');
                o.value = String(n);
                o.textContent = String(n);
                if (n === opts.limit) o.selected = true;
                limitSelect.appendChild(o);
            });
            limitField.appendChild(limitFieldLabel);
            limitField.appendChild(limitSelect);

            toolbarLeft.appendChild(actionField);
            toolbarLeft.appendChild(limitField);

            var searchField = document.createElement('label');
            searchField.className = 'ga-audit-field ga-audit-field--search';
            var searchFieldLabel = document.createElement('span');
            searchFieldLabel.className = 'ga-audit-field-label';
            searchFieldLabel.textContent = 'Buscar:';
            var searchWrap = document.createElement('div');
            searchWrap.className = 'ga-audit-search';
            var searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'ga-audit-input';
            searchInput.placeholder = 'Pesquisar em ações, usuário...';
            searchInput.setAttribute('aria-label', 'Buscar no histórico');
            var searchIcon = document.createElement('span');
            searchIcon.className = 'ga-audit-search-icon';
            searchIcon.setAttribute('aria-hidden', 'true');
            searchIcon.innerHTML = ICONS.search;
            searchWrap.appendChild(searchInput);
            searchWrap.appendChild(searchIcon);
            searchField.appendChild(searchFieldLabel);
            searchField.appendChild(searchWrap);

            toolbar.appendChild(toolbarLeft);
            toolbar.appendChild(searchField);
            modal.appendChild(toolbar);

            // ---- body ---------------------------------------------------------
            var body = document.createElement('div');
            body.className = 'ga-audit-body';
            modal.appendChild(body);

            // ---- footer: contador + paginação -----------------------------------
            var footer = document.createElement('div');
            footer.className = 'ga-audit-footer';

            var counter = document.createElement('span');
            counter.className = 'ga-audit-counter';

            var pagination = document.createElement('div');
            pagination.className = 'ga-audit-pagination';

            footer.appendChild(counter);
            footer.appendChild(pagination);
            modal.appendChild(footer);

            // ---- estado interno -------------------------------------------------
            var state = {
                allGroups: [],
                filtered: [],
                searchTerm: '',
                actionFilter: 'Todas',
                limit: opts.limit,
                currentPage: 1
            };

            renderState(body, { title: 'Carregando histórico…' }, true);

            postForm(opts.endpoint, { id: opts.id, page: 1, limit: 10000 })
                .then(function (response) {
                    state.allGroups = response.groups || [];
                    head.subtitle.textContent = response.record_id !== undefined && response.record_id !== null
                        ? ('Registro: ' + response.record_id)
                        : '';
                    populateActionFilter();
                    applyFiltersAndRender();
                })
                .catch(function () {
                    renderState(body, {
                        title: 'Não foi possível carregar a auditoria.',
                        desc: 'Verifique a rota configurada em "endpoint" e tente novamente.'
                    });
                    counter.textContent = '';
                });

            function populateActionFilter() {
                var actions = ['Todas'];
                state.allGroups.forEach(function (g) {
                    (g.actions || []).forEach(function (a) {
                        if (a.action && actions.indexOf(a.action) === -1) actions.push(a.action);
                    });
                });
                actionSelect.innerHTML = '';
                actions.forEach(function (a) {
                    var o = document.createElement('option');
                    o.value = a;
                    o.textContent = a;
                    actionSelect.appendChild(o);
                });
            }

            function applyFilters() {
                var term = state.searchTerm.toLowerCase();

                state.filtered = state.allGroups
                    .map(function (group) {
                        var actions = (group.actions || []).filter(function (audit) {
                            if (state.actionFilter !== 'Todas' && audit.action !== state.actionFilter) {
                                return false;
                            }
                            if (!term) return true;

                            var haystack = [
                                audit.action,
                                audit.user_id,
                                audit.created_at,
                                group.batch_id,
                                audit.changes ? (typeof audit.changes === 'string' ? audit.changes : JSON.stringify(audit.changes)) : ''
                            ].join(' ').toLowerCase();

                            return haystack.indexOf(term) !== -1;
                        });

                        return actions.length ? Object.assign({}, group, { actions: actions }) : null;
                    })
                    .filter(Boolean);
            }

            function applyFiltersAndRender() {
                applyFilters();
                renderTable();
                renderPagination();
                renderCounter();
            }

            // Reaplica só tabela + paginação + contador (usado ao trocar de
            // página, já que os filtros em si não mudaram).
            function renderPageOnly() {
                renderTable();
                renderPagination();
                renderCounter();
            }

            function renderCounter() {
                var total = state.allGroups.length;
                var filteredCount = state.filtered.length;

                if (total === 0) {
                    counter.textContent = 'Nenhum dado carregado';
                    return;
                }
                if (filteredCount === 0) {
                    counter.textContent = (state.searchTerm || state.actionFilter !== 'Todas')
                        ? 'Nenhum resultado para os filtros aplicados'
                        : 'Nenhum batch encontrado';
                    return;
                }

                var start = 1;
                var end = filteredCount;
                if (filteredCount > state.limit) {
                    start = ((state.currentPage - 1) * state.limit) + 1;
                    end = Math.min(state.currentPage * state.limit, filteredCount);
                }

                var text = 'Mostrando ' + start + '\u2013' + end + ' de ' + filteredCount;
                if (filteredCount !== total) text += ' (filtradas de ' + total + ')';
                counter.textContent = text;
            }

            function typeClass(type) {
                var t = (type || '').toLowerCase();
                if (t === 'success' || t === 'failed' || t === 'warning' || t === 'info') {
                    return 'ga-audit-type-' + t;
                }
                return '';
            }

            function typeLabel(type) {
                var t = (type || '').toLowerCase();
                var map = { success: 'Sucesso', failed: 'Falhou', warning: 'Aviso', info: 'Informação' };
                return map[t] || (type || 'Outro');
            }

            function parseChanges(changesRaw) {
                var lines = [];
                var obj = changesRaw;
                try {
                    if (typeof changesRaw === 'string') obj = JSON.parse(changesRaw);
                    Object.keys(obj || {}).forEach(function (field) {
                        var val = obj[field];
                        var label = formatFieldLabel(field);

                        if (Array.isArray(val) && val.length === 2) {
                            var oldVal = field === 'password' ? '********' : val[0];
                            var newVal = field === 'password' ? '********' : val[1];
                            lines.push(label + ': ' + (oldVal != null ? oldVal : '') + ' \u2192 ' + (newVal != null ? newVal : ''));
                        } else if (val && typeof val === 'object' && val.old !== undefined && val.new !== undefined) {
                            var oldLabel = (val.old && (val.old.label || val.old.id)) || '';
                            var newLabel = (val.new && (val.new.label || val.new.id)) || '';
                            lines.push(label + ': ' + oldLabel + ' \u2192 ' + newLabel);
                        }
                    });
                } catch (e) {
                    lines.push(String(changesRaw));
                }
                return lines;
            }

            function renderTable() {
                if (!state.filtered.length) {
                    var emptyMsg = (state.searchTerm || state.actionFilter !== 'Todas')
                        ? { title: 'Nenhum resultado para os filtros aplicados.' }
                        : { title: 'Nenhum dado encontrado.' };
                    renderState(body, emptyMsg, false);
                    return;
                }

                var display = state.filtered;
                if (display.length > state.limit) {
                    var startIdx = (state.currentPage - 1) * state.limit;
                    display = display.slice(startIdx, startIdx + state.limit);
                }

                var table = document.createElement('table');
                table.className = 'ga-audit-table';

                var thead = document.createElement('thead');
                thead.innerHTML = ''
                    + '<tr>'
                    + '<th scope="col">Batch</th>'
                    + '<th scope="col">Alterações</th>'
                    + '<th scope="col">Usuário</th>'
                    + '<th scope="col">Data</th>'
                    + '</tr>';
                table.appendChild(thead);

                var tbody = document.createElement('tbody');

                display.forEach(function (group, gIdx) {
                    var groupedByAction = {};
                    (group.actions || []).forEach(function (audit) {
                        var action = audit.action || 'Outro';
                        var type = audit.type || 'Outro';
                        groupedByAction[action] = groupedByAction[action] || {};
                        groupedByAction[action][type] = groupedByAction[action][type] || [];
                        var parsed = audit.changes ? parseChanges(audit.changes) : ['-'];
                        groupedByAction[action][type].push.apply(groupedByAction[action][type], parsed);
                    });

                    var changesCell = document.createElement('td');
                    Object.keys(groupedByAction).forEach(function (action, aIdx) {
                        var actionItem = document.createElement('div');
                        actionItem.className = 'ga-audit-action-item';

                        var toggle = document.createElement('button');
                        toggle.type = 'button';
                        toggle.className = 'ga-audit-action-toggle';
                        var panelId = 'ga-audit-panel-' + gIdx + '-' + aIdx + '-' + Math.random().toString(36).slice(2, 7);
                        toggle.setAttribute('aria-expanded', 'false');
                        toggle.setAttribute('aria-controls', panelId);
                        toggle.textContent = action;

                        var panel = document.createElement('div');
                        panel.className = 'ga-audit-action-panel';
                        panel.id = panelId;

                        Object.keys(groupedByAction[action]).forEach(function (type) {
                            var typeRow = document.createElement('div');
                            typeRow.className = 'ga-audit-type-row';

                            var typeLabelEl = document.createElement('div');
                            typeLabelEl.className = 'ga-audit-type-label ' + typeClass(type);
                            typeLabelEl.textContent = typeLabel(type);
                            typeRow.appendChild(typeLabelEl);

                            groupedByAction[action][type].forEach(function (line) {
                                var lineEl = document.createElement('div');
                                lineEl.className = 'ga-audit-change-line';
                                lineEl.textContent = '\u2013 ' + line;
                                typeRow.appendChild(lineEl);
                            });

                            panel.appendChild(typeRow);
                        });

                        toggle.addEventListener('click', function () {
                            var isOpen = panel.classList.toggle('is-open');
                            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                        });

                        actionItem.appendChild(toggle);
                        actionItem.appendChild(panel);
                        changesCell.appendChild(actionItem);
                    });

                    var lastAction = group.actions[group.actions.length - 1];

                    var row = document.createElement('tr');

                    var batchCell = document.createElement('td');
                    batchCell.appendChild(buildBatchCell(group.batch_id));

                    var userCell = document.createElement('td');
                    userCell.textContent = (lastAction && lastAction.user_id) || '\u2013';

                    var dateCell = document.createElement('td');
                    dateCell.className = 'ga-audit-cell-muted';
                    dateCell.textContent = (lastAction && lastAction.created_at) || '\u2013';

                    row.appendChild(batchCell);
                    row.appendChild(changesCell);
                    row.appendChild(userCell);
                    row.appendChild(dateCell);
                    tbody.appendChild(row);
                });

                table.appendChild(tbody);

                var scrollWrap = document.createElement('div');
                scrollWrap.className = 'ga-audit-table-scroll';
                scrollWrap.appendChild(table);

                body.innerHTML = '';
                body.appendChild(scrollWrap);
            }

            // O código do batch (frequentemente um UUID de 36 caracteres) só
            // importa no dia a dia quando o usuário precisa repassá-lo a um
            // desenvolvedor para investigar um problema — não vale ocupar a
            // largura da coluna o tempo todo. Mostra truncado; um clique expande
            // o valor completo (com botão de copiar) sem sair da linha.
            var BATCH_TRUNCATE_AT = 13;

            function buildBatchCell(batchId) {
                var fullValue = batchId || '\u2013';
                var isTruncatable = fullValue.length > BATCH_TRUNCATE_AT;

                if (!isTruncatable) {
                    var plain = document.createElement('span');
                    plain.className = 'ga-audit-batch';
                    plain.textContent = fullValue;
                    return plain;
                }

                var wrap = document.createElement('span');
                wrap.className = 'ga-audit-batch-wrap';

                var toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'ga-audit-batch ga-audit-batch-toggle';
                toggle.textContent = fullValue.slice(0, BATCH_TRUNCATE_AT) + '\u2026';
                toggle.title = 'Clique para ver o código completo';
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-label', 'Código do batch, truncado. Clique para expandir.');

                var expandedPanel = document.createElement('span');
                expandedPanel.className = 'ga-audit-batch-full';
                expandedPanel.hidden = true;

                var fullText = document.createElement('span');
                fullText.className = 'ga-audit-batch';
                fullText.textContent = fullValue;

                var copyBtn = document.createElement('button');
                copyBtn.type = 'button';
                copyBtn.className = 'ga-audit-batch-copy';
                copyBtn.textContent = 'Copiar';
                copyBtn.setAttribute('aria-label', 'Copiar código completo do batch');

                copyBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    copyToClipboard(fullValue, copyBtn);
                });

                expandedPanel.appendChild(fullText);
                expandedPanel.appendChild(copyBtn);

                toggle.addEventListener('click', function () {
                    var expand = toggle.getAttribute('aria-expanded') !== 'true';
                    toggle.setAttribute('aria-expanded', expand ? 'true' : 'false');
                    toggle.hidden = expand;
                    expandedPanel.hidden = !expand;
                });

                // clicar em qualquer ponto do texto expandido recolhe de novo,
                // exceto no botão "Copiar" (que já tem seu próprio handler acima)
                expandedPanel.addEventListener('click', function (e) {
                    if (e.target === copyBtn) return;
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.hidden = false;
                    expandedPanel.hidden = true;
                });

                wrap.appendChild(toggle);
                wrap.appendChild(expandedPanel);
                return wrap;
            }

            // Copia para a área de transferência quando a Clipboard API está
            // disponível (exige contexto seguro: https, ou localhost). Sem ela,
            // o texto completo já está visível e selecionável na tela — só não
            // copia automaticamente. Falha de permissão é tratada do mesmo jeito.
            // Usa um id de chamada para não deixar o texto preso em "Copiado!"
            // caso o usuário clique de novo antes do primeiro timeout terminar.
            function copyToClipboard(text, feedbackEl) {
                var callId = (feedbackEl.__gaAuditCopyCallId = (feedbackEl.__gaAuditCopyCallId || 0) + 1);

                var showFeedback = function (ok) {
                    feedbackEl.textContent = ok ? 'Copiado!' : 'Não foi possível copiar';
                    setTimeout(function () {
                        // só restaura o texto padrão se nenhum clique mais recente
                        // já tiver iniciado outro ciclo de feedback
                        if (feedbackEl.__gaAuditCopyCallId === callId) {
                            feedbackEl.textContent = 'Copiar';
                        }
                    }, 1500);
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(
                        function () { showFeedback(true); },
                        function () { showFeedback(false); }
                    );
                } else {
                    showFeedback(false);
                }
            }

            function renderPagination() {
                pagination.innerHTML = '';
                var total = state.filtered.length;
                if (total === 0 || total <= state.limit) return;

                var totalPages = Math.ceil(total / state.limit);
                var current = state.currentPage;

                function pageBtn(label, page, cfg) {
                    cfg = cfg || {};
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'ga-audit-page-btn' + (cfg.current ? ' is-current' : '');
                    btn.innerHTML = label;
                    if (cfg.ariaLabel) btn.setAttribute('aria-label', cfg.ariaLabel);
                    if (cfg.current) btn.setAttribute('aria-current', 'page');
                    btn.disabled = !!cfg.disabled;
                    if (!cfg.disabled && !cfg.current) {
                        btn.addEventListener('click', function () {
                            state.currentPage = page;
                            renderPageOnly();
                        });
                    }
                    return btn;
                }

                pagination.appendChild(pageBtn(ICONS.first, 1, { disabled: current === 1, ariaLabel: 'Primeira página' }));
                pagination.appendChild(pageBtn(ICONS.prev, current - 1, { disabled: current === 1, ariaLabel: 'Página anterior' }));

                var startPage = Math.max(1, current - 2);
                var endPage = Math.min(totalPages, current + 2);
                if (current <= 2) endPage = Math.min(5, totalPages);
                if (current >= totalPages - 1) startPage = Math.max(totalPages - 4, 1);
                if (endPage - startPage < 4) {
                    if (startPage === 1) endPage = Math.min(startPage + 4, totalPages);
                    else if (endPage === totalPages) startPage = Math.max(endPage - 4, 1);
                }

                for (var i = startPage; i <= endPage; i++) {
                    pagination.appendChild(pageBtn(String(i), i, { current: i === current }));
                }

                pagination.appendChild(pageBtn(ICONS.next, current + 1, { disabled: current === totalPages, ariaLabel: 'Próxima página' }));
                pagination.appendChild(pageBtn(ICONS.last, totalPages, { disabled: current === totalPages, ariaLabel: 'Última página' }));
            }

            // ---- eventos da toolbar -----------------------------------------------
            var onSearch = debounce(function (value) {
                state.searchTerm = value;
                state.currentPage = 1;
                applyFiltersAndRender();
            }, 200);

            searchInput.addEventListener('input', function () {
                onSearch(searchInput.value);
            });

            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && searchInput.value) {
                    e.stopPropagation();
                    searchInput.value = '';
                    state.searchTerm = '';
                    state.currentPage = 1;
                    applyFiltersAndRender();
                }
            });

            actionSelect.addEventListener('change', function () {
                state.actionFilter = actionSelect.value;
                state.currentPage = 1;
                applyFiltersAndRender();
            });

            limitSelect.addEventListener('change', function () {
                state.limit = parseInt(limitSelect.value, 10) || opts.limit;
                state.currentPage = 1;
                applyFiltersAndRender();
            });

            return shell;
        }

        return { open: open };
    })();

    /* ======================================================================
     * 5. GaAudit.simple — modal enxuto (lista direta, sem filtros)
     * ==================================================================== */

    var Simple = (function () {
        var defaults = {
            endpoint: null,
            id: null,
            title: 'Auditoria do Registro',
            onClose: null
        };

        function open(userOptions) {
            var opts = Object.assign({}, defaults, userOptions || {});

            if (!opts.endpoint || !opts.id) {
                throw new Error('GaAudit.simple.open() precisa de { endpoint, id }.');
            }

            var shell = createModalShell({ variant: 'simple', onClose: opts.onClose });
            var modal = shell.modal;

            var head = buildHeader(modal, { title: opts.title });
            head.subtitle.remove(); // modal simples não usa subtítulo
            head.closeBtn.addEventListener('click', shell.close);

            var body = document.createElement('div');
            body.className = 'ga-audit-body';
            modal.appendChild(body);

            renderState(body, { title: 'Carregando…' }, true);

            postForm(opts.endpoint, { id: opts.id })
                .then(function (response) {
                    var audits = response.audits || [];
                    if (!audits.length) {
                        renderState(body, { title: 'Nenhum registro encontrado.' }, false);
                        return;
                    }
                    renderTable(audits);
                })
                .catch(function () {
                    renderState(body, {
                        title: 'Não foi possível carregar o histórico.',
                        desc: 'Verifique a rota configurada em "endpoint" e tente novamente.'
                    }, false);
                });

            function renderTable(audits) {
                var table = document.createElement('table');
                table.className = 'ga-audit-table';

                var thead = document.createElement('thead');
                thead.innerHTML = ''
                    + '<tr>'
                    + '<th scope="col">Ação</th>'
                    + '<th scope="col">Usuário</th>'
                    + '<th scope="col">Data</th>'
                    + '</tr>';
                table.appendChild(thead);

                var tbody = document.createElement('tbody');
                audits.forEach(function (audit) {
                    var row = document.createElement('tr');

                    var actionCell = document.createElement('th');
                    actionCell.setAttribute('scope', 'row');
                    actionCell.style.fontWeight = '600';
                    actionCell.style.textAlign = 'left';
                    actionCell.textContent = audit.action || '\u2013';

                    var userCell = document.createElement('td');
                    userCell.textContent = audit.user_id || '\u2013';

                    var dateCell = document.createElement('td');
                    dateCell.className = 'ga-audit-cell-muted';
                    dateCell.textContent = audit.created_at || '\u2013';

                    row.appendChild(actionCell);
                    row.appendChild(userCell);
                    row.appendChild(dateCell);
                    tbody.appendChild(row);
                });

                table.appendChild(tbody);

                var scrollWrap = document.createElement('div');
                scrollWrap.className = 'ga-audit-table-scroll';
                scrollWrap.appendChild(table);

                body.innerHTML = '';
                body.appendChild(scrollWrap);
            }

            return shell;
        }

        return { open: open };
    })();

    /* ======================================================================
     * 6. AUTO-BIND opcional via atributos data-*
     * ====================================================================
     * Você pode chamar GaAudit.full.open(...) / GaAudit.simple.open(...)
     * manualmente a partir do seu próprio JS, OU deixar o widget cuidar
     * do clique sozinho marcando o botão com data-ga-audit:
     *
     *   <button data-ga-audit="full"   data-ga-audit-id="42" data-ga-audit-url="/audit/readGrouped">
     *       Ver histórico completo
     *   </button>
     *
     *   <button data-ga-audit="simple" data-ga-audit-id="42" data-ga-audit-url="/audit/read">
     *       Ver histórico
     *   </button>
     *
     * Nenhum HTML de modal precisa existir na página — o widget cria e
     * destrói o próprio modal a cada abertura, então não há necessidade
     * de manter nem um <div id="auditModal"> vazio no layout.
     */
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest ? e.target.closest('[data-ga-audit]') : null;
        if (!trigger) return;

        var kind = trigger.getAttribute('data-ga-audit');
        var id = trigger.getAttribute('data-ga-audit-id');
        var url = trigger.getAttribute('data-ga-audit-url');
        var titleAttr = trigger.getAttribute('data-ga-audit-title');

        if (!id || !url) return;

        var widget = kind === 'simple' ? Simple : Full;
        var openOpts = { endpoint: url, id: id };
        if (titleAttr) openOpts.title = titleAttr;

        widget.open(openOpts);
    });

    /* ======================================================================
     * 7. API PÚBLICA
     * ==================================================================== */

    window.GaAudit = {
        __loaded: true,
        icons: ICONS,
        full: Full,
        simple: Simple
    };

})(window, document);
