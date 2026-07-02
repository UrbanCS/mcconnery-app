(function () {
  const SCRIPT_NAME = 'joomla-sympathy-widget.js';
  const MOUNT_CLASS = 'mcconnery-sympathy-widget';

  function currentScript() {
    return (
      document.currentScript ||
      Array.from(document.scripts).find((script) => script.src && script.src.includes(SCRIPT_NAME))
    );
  }

  function apiBase() {
    const script = currentScript();
    if (!script || !script.src) {
      return '/pwa/api';
    }

    const url = new URL(script.src, window.location.href);
    url.pathname = url.pathname.replace(new RegExp('/?' + SCRIPT_NAME + '$'), '/api');
    url.search = '';
    url.hash = '';
    return url.toString().replace(/\/$/, '');
  }

  function obituarySourceId() {
    const explicit = document.querySelector('[data-mcconnery-obituary-source-id], .' + MOUNT_CLASS + '[data-source-id]');
    const explicitValue = explicit && (explicit.getAttribute('data-mcconnery-obituary-source-id') || explicit.getAttribute('data-source-id'));
    if (explicitValue) {
      return explicitValue.trim();
    }

    const meta = document.querySelector('meta[name="mcconnery-obituary-source-id"]');
    if (meta && meta.content) {
      return meta.content.trim();
    }

    const path = decodeURIComponent(window.location.pathname.replace(/\/+$/, ''));
    const match = path.match(/(?:-|\/)(\d{1,10})$/);
    if (match) {
      return match[1];
    }

    const context = window.McConneryObituary || {};
    if (context.sourceId) {
      return String(context.sourceId).trim();
    }
    if (context.articleId) {
      return 'joomla-' + String(context.articleId).trim();
    }

    const queryArticleId = new URLSearchParams(window.location.search).get('id');
    if (queryArticleId && /^\d+$/.test(queryArticleId)) {
      return 'joomla-' + queryArticleId;
    }

    return '';
  }

  function obituaryTitle() {
    const heading =
      document.querySelector('.article-details .article-header h1') ||
      document.querySelector('.item-page .article-header h1') ||
      document.querySelector('h1[itemprop="headline"]') ||
      document.querySelector('h1');

    const title = heading && heading.textContent ? heading.textContent.trim() : '';
    if (title) {
      return title;
    }

    return String(document.title || '').split('|')[0].trim();
  }

  function isObituaryArticle() {
    if (document.querySelector('.' + MOUNT_CLASS + ', [data-mcconnery-obituary-source-id]')) {
      return true;
    }

    const body = document.body;
    return body && body.classList.contains('view-article') && window.location.pathname.includes('avis-de-deces');
  }

  function findMount() {
    const existing = document.querySelector('.' + MOUNT_CLASS);
    if (existing) {
      return existing;
    }

    const target =
      document.querySelector('.article-details') ||
      document.querySelector('.item-page') ||
      document.querySelector('[itemprop="articleBody"]') ||
      document.querySelector('#sp-component article') ||
      document.querySelector('main article');

    if (!target || !target.parentNode) {
      return null;
    }

    const mount = document.createElement('section');
    mount.className = MOUNT_CLASS;
    target.parentNode.insertBefore(mount, target.nextSibling);
    return mount;
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function readableError(error, fallback) {
    const message = error && error.message ? String(error.message) : '';
    if (message === 'Failed to fetch' || message === 'Load failed') {
      return "Impossible de joindre le livre de sympathies. Veuillez réessayer.";
    }

    return message || fallback;
  }

  function formatDate(value) {
    if (!value) {
      return '';
    }
    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
      return value;
    }
    return new Intl.DateTimeFormat('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
  }

  function ensureStyles() {
    if (document.getElementById('mcconnery-sympathy-widget-css')) {
      return;
    }

    const style = document.createElement('style');
    style.id = 'mcconnery-sympathy-widget-css';
    style.textContent = `
      .${MOUNT_CLASS} {
        clear: both;
        margin: 42px 0 0;
        padding: 28px;
        border: 1px solid var(--border_color, #ded8cf);
        background: var(--bg_color, #fff);
      }
      .${MOUNT_CLASS} * {
        box-sizing: border-box;
      }
      .${MOUNT_CLASS}__eyebrow {
        margin: 0 0 4px;
        color: #696941;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }
      .${MOUNT_CLASS} h2 {
        margin: 0 0 18px;
        font-size: 28px;
        line-height: 1.25;
      }
      .${MOUNT_CLASS}__note,
      .${MOUNT_CLASS}__empty,
      .${MOUNT_CLASS}__status {
        color: var(--text_color, #696e77);
        font-size: 15px;
        line-height: 1.6;
      }
      .${MOUNT_CLASS}__messages {
        display: grid;
        gap: 14px;
        margin: 20px 0 26px;
      }
      .${MOUNT_CLASS}__message {
        padding: 18px;
        border: 1px solid var(--border_color, #ded8cf);
        background: var(--bg_color_dark, #f7f5f1);
      }
      .${MOUNT_CLASS}__message-head {
        display: flex;
        gap: 12px;
        justify-content: space-between;
        margin-bottom: 8px;
      }
      .${MOUNT_CLASS}__author {
        margin: 0;
        color: var(--headings_color, #141623);
        font-weight: 700;
      }
      .${MOUNT_CLASS}__date {
        color: #696941;
        font-size: 14px;
        white-space: nowrap;
      }
      .${MOUNT_CLASS}__text {
        margin: 0;
        white-space: pre-line;
      }
      .${MOUNT_CLASS}__form {
        display: grid;
        gap: 14px;
      }
      .${MOUNT_CLASS}__grid {
        display: grid;
        gap: 14px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .${MOUNT_CLASS} label {
        display: block;
        color: var(--headings_color, #141623);
        font-weight: 700;
      }
      .${MOUNT_CLASS} input,
      .${MOUNT_CLASS} textarea {
        display: block;
        width: 100%;
        margin-top: 7px;
        padding: 10px 12px;
        border: 1px solid var(--border_color, #ded8cf);
        border-radius: 4px;
        background: #fff;
        color: var(--text_color, #212529);
        font: inherit;
      }
      .${MOUNT_CLASS} textarea {
        min-height: 150px;
        resize: vertical;
      }
      .${MOUNT_CLASS} button[type="submit"] {
        justify-self: start;
        min-height: 46px;
        padding: 0 22px;
        border: 0;
        border-radius: 4px;
        background: #696941;
        color: #fff;
        font-weight: 700;
        cursor: pointer;
      }
      .${MOUNT_CLASS} button[disabled] {
        opacity: 0.65;
        cursor: default;
      }
      .${MOUNT_CLASS}__success {
        padding: 12px 14px;
        border: 1px solid rgba(105, 105, 65, 0.28);
        background: rgba(105, 105, 65, 0.09);
        color: #696941;
        font-weight: 700;
      }
      .${MOUNT_CLASS}__error {
        padding: 12px 14px;
        border: 1px solid rgba(140, 70, 70, 0.28);
        background: rgba(140, 70, 70, 0.09);
        color: #8c4646;
        font-weight: 700;
      }
      .${MOUNT_CLASS}__trap {
        display: none !important;
      }
      @media (max-width: 767.98px) {
        .${MOUNT_CLASS} {
          padding: 22px 18px;
        }
        .${MOUNT_CLASS}__grid,
        .${MOUNT_CLASS}__message-head {
          grid-template-columns: 1fr;
          flex-direction: column;
        }
        .${MOUNT_CLASS}__date {
          white-space: normal;
        }
        .${MOUNT_CLASS} button[type="submit"] {
          width: 100%;
        }
      }
    `;
    document.head.appendChild(style);
  }

  function renderMessages(messages) {
    if (!messages.length) {
      return '<p class="' + MOUNT_CLASS + '__empty">Aucun message actuellement dans le livre de sympathies. Soyez le premier à laisser un message.</p>';
    }

    return messages
      .map(
        (message) => `
          <article class="${MOUNT_CLASS}__message">
            <div class="${MOUNT_CLASS}__message-head">
              <p class="${MOUNT_CLASS}__author">${escapeHtml(message.author_name || 'Anonyme')}</p>
              <span class="${MOUNT_CLASS}__date">${escapeHtml(formatDate(message.posted_at || message.created_at))}</span>
            </div>
            <p class="${MOUNT_CLASS}__text">${escapeHtml(message.message)}</p>
          </article>
        `
      )
      .join('');
  }

  function render(mount, sourceId, messages, statusHtml) {
    mount.innerHTML = `
      <p class="${MOUNT_CLASS}__eyebrow">Livre de sympathies</p>
      <h2>Messages de sympathie</h2>
      <p class="${MOUNT_CLASS}__note">Les nouveaux messages sont approuvés avant publication.</p>
      <div class="${MOUNT_CLASS}__messages">${renderMessages(messages)}</div>
      <form class="${MOUNT_CLASS}__form">
        <div class="${MOUNT_CLASS}__grid">
          <label>Nom
            <input name="author_name" autocomplete="name" required>
          </label>
          <label>Courriel
            <input name="author_email" type="email" autocomplete="email" required>
          </label>
        </div>
        <label>Téléphone
          <input name="author_phone" type="tel" autocomplete="tel">
        </label>
        <label>Message
          <textarea name="message" maxlength="2000" required></textarea>
        </label>
        <input class="${MOUNT_CLASS}__trap" name="website" autocomplete="off" tabindex="-1">
        ${statusHtml || ''}
        <button type="submit">Ajouter un message</button>
      </form>
    `;

    const form = mount.querySelector('form');
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      button.textContent = 'Envoi...';

      const payload = Object.fromEntries(new FormData(form).entries());
      payload.obituary_source_id = sourceId;
      payload.obituary_title = obituaryTitle();

      try {
        const response = await fetch(apiBase() + '/sympathy-messages.php', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(payload),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) {
          throw new Error(result.error || "Impossible d'envoyer le message.");
        }
        render(
          mount,
          sourceId,
          messages,
          '<p class="' + MOUNT_CLASS + '__success">Votre message a été reçu. Il sera publié après approbation.</p>'
        );
      } catch (error) {
        render(
          mount,
          sourceId,
          messages,
          '<p class="' + MOUNT_CLASS + '__error">' + escapeHtml(readableError(error, "Impossible d'envoyer le message.")) + '</p>'
        );
      }
    });
  }

  async function init() {
    if (!isObituaryArticle()) {
      return;
    }

    const sourceId = obituarySourceId();
    if (!sourceId) {
      return;
    }

    const mount = findMount();
    if (!mount) {
      return;
    }

    ensureStyles();
    mount.innerHTML = '<p class="' + MOUNT_CLASS + '__status">Chargement du livre de sympathies...</p>';

    try {
      const response = await fetch(apiBase() + '/sympathy-messages.php?source_id=' + encodeURIComponent(sourceId));
      const result = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(result.error || 'Impossible de charger les messages.');
      }
      render(mount, sourceId, Array.isArray(result.data) ? result.data : []);
    } catch (error) {
      mount.innerHTML = '<p class="' + MOUNT_CLASS + '__error">' + escapeHtml(readableError(error, 'Impossible de charger les messages.')) + '</p>';
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
