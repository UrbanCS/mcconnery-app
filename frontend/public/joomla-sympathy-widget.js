(function () {
  const SCRIPT_NAME = 'joomla-sympathy-widget.js';
  const MOUNT_CLASS = 'mcconnery-sympathy-widget';
  const FALLBACK_ARTICLE_CLASS = 'mcconnery-obituary-fallback';

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
      document.querySelector('.' + FALLBACK_ARTICLE_CLASS + ' h1') ||
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
      document.querySelector('.' + FALLBACK_ARTICLE_CLASS) ||
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

  function hasArticleOutput() {
    return Boolean(
      document.querySelector('.' + FALLBACK_ARTICLE_CLASS) ||
        document.querySelector('.article-details') ||
        document.querySelector('.item-page') ||
        document.querySelector('[itemprop="articleBody"]') ||
        document.querySelector('#sp-component article') ||
        document.querySelector('main article')
    );
  }

  function componentContainer() {
    return (
      document.querySelector('#sp-component .sp-column') ||
      document.querySelector('#sp-component') ||
      document.querySelector('main') ||
      document.body
    );
  }

  function plainTextToHtml(value) {
    return String(value || '')
      .split(/\n{2,}/)
      .map((paragraph) => paragraph.trim())
      .filter(Boolean)
      .map((paragraph) => '<p>' + escapeHtml(paragraph).replace(/\n/g, '<br>') + '</p>')
      .join('');
  }

  function normalizeImageUrl(value) {
    const raw = String(value || '').trim();
    if (!raw) {
      return '';
    }

    const withoutJoomlaMetadata = raw.split('#joomlaImage:')[0];
    if (/^https?:\/\//i.test(withoutJoomlaMetadata)) {
      return withoutJoomlaMetadata;
    }

    if (withoutJoomlaMetadata.charAt(0) === '/') {
      return window.location.origin + withoutJoomlaMetadata;
    }

    return window.location.origin + '/' + withoutJoomlaMetadata.replace(/^\/+/, '');
  }

  function normalizeObituaryPayload(result) {
    if (result && result.data && typeof result.data === 'object') {
      return result.data;
    }
    if (result && result.item && typeof result.item === 'object') {
      return result.item;
    }
    return result && typeof result === 'object' ? result : null;
  }

  async function fetchObituary(sourceId) {
    const response = await fetch(apiBase() + '/obituary.php?id=' + encodeURIComponent(sourceId), {
      headers: { Accept: 'application/json' },
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(result.error || "Impossible de charger l'avis.");
    }

    return normalizeObituaryPayload(result);
  }

  function renderObituaryFallback(item) {
    const container = componentContainer();
    if (!container || document.querySelector('.' + FALLBACK_ARTICLE_CLASS)) {
      return null;
    }

    const sourceId = item.source_id || obituarySourceId();
    const title = item.title || item.person_name || obituaryTitle();
    const date = item.death_date || item.published_at || item.created_at || '';
    const imageUrl = normalizeImageUrl(item.image_url);
    const contentHtml = plainTextToHtml(item.content || item.excerpt || '');
    const sourceUrl = item.source_url || window.location.href;

    const article = document.createElement('article');
    article.className = FALLBACK_ARTICLE_CLASS + ' article-details';
    if (sourceId) {
      article.setAttribute('data-mcconnery-obituary-source-id', sourceId);
    }

    article.innerHTML = `
      <div class="${FALLBACK_ARTICLE_CLASS}__header">
        ${imageUrl ? '<img class="' + FALLBACK_ARTICLE_CLASS + '__image" src="' + escapeHtml(imageUrl) + '" alt="' + escapeHtml(title) + '">' : ''}
        <div class="${FALLBACK_ARTICLE_CLASS}__summary">
          <h1>${escapeHtml(title)}</h1>
          ${date ? '<p class="' + FALLBACK_ARTICLE_CLASS + '__date">' + escapeHtml(formatDate(date)) + '</p>' : ''}
          <div class="${FALLBACK_ARTICLE_CLASS}__actions">
            <button type="button" class="${FALLBACK_ARTICLE_CLASS}__button" data-share-obituary>Partager</button>
            <a class="${FALLBACK_ARTICLE_CLASS}__button ${FALLBACK_ARTICLE_CLASS}__button--secondary" href="${escapeHtml(sourceUrl)}">Source</a>
          </div>
        </div>
      </div>
      ${contentHtml ? '<div class="' + FALLBACK_ARTICLE_CLASS + '__content">' + contentHtml + '</div>' : ''}
    `;

    const searchModule = container.querySelector('.sp-module-content-top');
    if (searchModule && searchModule.nextSibling) {
      container.insertBefore(article, searchModule.nextSibling);
    } else if (searchModule) {
      container.appendChild(article);
    } else {
      container.insertBefore(article, container.firstChild);
    }

    const shareButton = article.querySelector('[data-share-obituary]');
    if (shareButton) {
      shareButton.addEventListener('click', async () => {
        const shareData = { title, url: sourceUrl };
        if (navigator.share) {
          try {
            await navigator.share(shareData);
            return;
          } catch (error) {
            if (error && error.name === 'AbortError') {
              return;
            }
          }
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(sourceUrl);
          shareButton.textContent = 'Lien copie';
          setTimeout(() => {
            shareButton.textContent = 'Partager';
          }, 1800);
        }
      });
    }

    return article;
  }

  async function ensureObituaryFallback(sourceId) {
    if (hasArticleOutput()) {
      return;
    }

    const container = componentContainer();
    if (!container) {
      return;
    }

    const loading = document.createElement('p');
    loading.className = FALLBACK_ARTICLE_CLASS + '__loading';
    loading.textContent = "Chargement de l'avis...";
    container.insertBefore(loading, container.firstChild);

    try {
      const item = await fetchObituary(sourceId);
      if (item) {
        renderObituaryFallback(item);
      }
    } catch (error) {
      loading.textContent = readableError(error, "Impossible de charger l'avis.");
      loading.className = FALLBACK_ARTICLE_CLASS + '__error';
      return;
    }

    loading.remove();
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

    const raw = String(value).trim();
    const localDate = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+00:00:00)?$/);
    if (localDate) {
      const date = new Date(Number(localDate[1]), Number(localDate[2]) - 1, Number(localDate[3]));
      return new Intl.DateTimeFormat('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
    }

    const localDateTime = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
    const date = localDateTime
      ? new Date(
          Number(localDateTime[1]),
          Number(localDateTime[2]) - 1,
          Number(localDateTime[3]),
          Number(localDateTime[4]),
          Number(localDateTime[5]),
          Number(localDateTime[6] || 0)
        )
      : new Date(raw.replace(' ', 'T'));

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
      .${FALLBACK_ARTICLE_CLASS} {
        clear: both;
        margin: 28px 0 34px;
      }
      .${FALLBACK_ARTICLE_CLASS}__header,
      .${FALLBACK_ARTICLE_CLASS}__content {
        border: 1px solid var(--border_color, #ded8cf);
        background: var(--bg_color, #fff);
      }
      .${FALLBACK_ARTICLE_CLASS}__header {
        display: grid;
        grid-template-columns: minmax(180px, 260px) minmax(0, 1fr);
        gap: 30px;
        align-items: start;
        padding: 26px;
      }
      .${FALLBACK_ARTICLE_CLASS}__image {
        display: block;
        width: 100%;
        max-height: 320px;
        object-fit: cover;
        border-radius: 4px;
      }
      .${FALLBACK_ARTICLE_CLASS} h1 {
        margin: 0 0 10px;
        font-size: 36px;
        line-height: 1.15;
      }
      .${FALLBACK_ARTICLE_CLASS}__date {
        margin: 0 0 20px;
        color: #8c4646;
        font-weight: 700;
      }
      .${FALLBACK_ARTICLE_CLASS}__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
      }
      .${FALLBACK_ARTICLE_CLASS}__button {
        display: inline-flex;
        min-height: 46px;
        align-items: center;
        justify-content: center;
        padding: 0 22px;
        border: 1px solid #696941;
        border-radius: 4px;
        background: #696941;
        color: #fff;
        font: inherit;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
      }
      .${FALLBACK_ARTICLE_CLASS}__button:hover,
      .${FALLBACK_ARTICLE_CLASS}__button:focus {
        color: #fff;
        background: #555534;
        border-color: #555534;
      }
      .${FALLBACK_ARTICLE_CLASS}__button--secondary {
        background: var(--bg_color_dark, #f7f5f1);
        color: var(--headings_color, #141623);
        border-color: var(--border_color, #ded8cf);
      }
      .${FALLBACK_ARTICLE_CLASS}__button--secondary:hover,
      .${FALLBACK_ARTICLE_CLASS}__button--secondary:focus {
        color: var(--headings_color, #141623);
        background: #fff;
        border-color: #696941;
      }
      .${FALLBACK_ARTICLE_CLASS}__content {
        margin-top: 22px;
        padding: 28px;
        font-size: 17px;
        line-height: 1.85;
      }
      .${FALLBACK_ARTICLE_CLASS}__content p:last-child {
        margin-bottom: 0;
      }
      .${FALLBACK_ARTICLE_CLASS}__loading,
      .${FALLBACK_ARTICLE_CLASS}__error {
        margin: 22px 0;
        padding: 14px 16px;
        border: 1px solid var(--border_color, #ded8cf);
        background: var(--bg_color, #fff);
      }
      .${FALLBACK_ARTICLE_CLASS}__error {
        border-color: rgba(140, 70, 70, 0.28);
        background: rgba(140, 70, 70, 0.09);
        color: #8c4646;
        font-weight: 700;
      }
      @media (max-width: 767.98px) {
        .${MOUNT_CLASS} {
          padding: 22px 18px;
        }
        .${FALLBACK_ARTICLE_CLASS}__header {
          grid-template-columns: 1fr;
          padding: 20px;
        }
        .${FALLBACK_ARTICLE_CLASS} h1 {
          font-size: 30px;
        }
        .${FALLBACK_ARTICLE_CLASS}__content {
          padding: 20px;
          font-size: 16px;
        }
        .${FALLBACK_ARTICLE_CLASS}__button {
          width: 100%;
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

    ensureStyles();
    await ensureObituaryFallback(sourceId);

    const mount = findMount();
    if (!mount) {
      return;
    }

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
