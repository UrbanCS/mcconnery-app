import { ArrowLeft, Bell, BellOff, Download, ExternalLink, Home, Mail, MapPin, Newspaper, Phone, Search, Share2, X } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { fetchObituaries, fetchObituary, fetchPublicConfig, fetchSympathyMessages, submitSympathyMessage } from './api';
import { disablePushNotifications, enablePushNotifications, getCurrentSubscription, supportsPush } from './push';
import type { Obituary, PublicConfig, SympathyMessage } from './types';

type Route =
  | { name: 'home' }
  | { name: 'obituaries' }
  | { name: 'detail'; id: string }
  | { name: 'contact' };

type InstallPlatform = 'ios' | 'android' | 'windows' | 'other';

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
};

function parseRoute(): Route {
  const hash = window.location.hash.replace(/^#\/?/, '');
  const [page, id] = hash.split('/');

  if (page === 'avis' && id) {
    return { name: 'detail', id };
  }
  if (page === 'avis') {
    return { name: 'obituaries' };
  }
  if (page === 'contact') {
    return { name: 'contact' };
  }
  return { name: 'home' };
}

function formatDate(value?: string | null): string {
  if (!value) {
    return '';
  }
  const dateOnlyMatch = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  const date = dateOnlyMatch
    ? new Date(Number(dateOnlyMatch[1]), Number(dateOnlyMatch[2]) - 1, Number(dateOnlyMatch[3]))
    : new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return new Intl.DateTimeFormat('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
}

function normalizeSearch(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();
}

function go(path: string) {
  window.location.hash = path;
}

function detectInstallPlatform(): InstallPlatform {
  const userAgent = navigator.userAgent || '';
  const isTouchMac = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;

  if (/iPad|iPhone|iPod/.test(userAgent) || isTouchMac) {
    return 'ios';
  }
  if (/Android/i.test(userAgent)) {
    return 'android';
  }
  if (/Windows/i.test(userAgent)) {
    return 'windows';
  }

  return 'other';
}

function isAppStandalone(): boolean {
  const nav = navigator as Navigator & { standalone?: boolean };
  return window.matchMedia('(display-mode: standalone)').matches || nav.standalone === true;
}

function App() {
  const [route, setRoute] = useState<Route>(parseRoute);
  const [config, setConfig] = useState<PublicConfig | null>(null);
  const [obituaries, setObituaries] = useState<Obituary[]>([]);
  const [selected, setSelected] = useState<Obituary | null>(null);
  const [loading, setLoading] = useState(true);
  const [obituaryListLoading, setObituaryListLoading] = useState(false);
  const [allObituariesLoaded, setAllObituariesLoaded] = useState(false);
  const [notice, setNotice] = useState('');
  const [pushEnabled, setPushEnabled] = useState(false);
  const [installPlatform, setInstallPlatform] = useState<InstallPlatform>('other');
  const [isStandalone, setIsStandalone] = useState(false);
  const [installHelpOpen, setInstallHelpOpen] = useState(false);
  const [deferredInstallPrompt, setDeferredInstallPrompt] = useState<BeforeInstallPromptEvent | null>(null);

  useEffect(() => {
    const onHashChange = () => setRoute(parseRoute());
    window.addEventListener('hashchange', onHashChange);
    return () => window.removeEventListener('hashchange', onHashChange);
  }, []);

  useEffect(() => {
    Promise.all([fetchPublicConfig(), fetchObituaries(12)])
      .then(([publicConfig, items]) => {
        setConfig(publicConfig);
        setObituaries(items);
      })
      .catch((error) => setNotice(error instanceof Error ? error.message : 'Une erreur est survenue.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (route.name !== 'obituaries' || allObituariesLoaded || obituaryListLoading) {
      return;
    }

    setObituaryListLoading(true);
    fetchObituaries(5000, { sync: true })
      .then((items) => {
        setObituaries(items);
        setAllObituariesLoaded(true);
      })
      .catch((error) => setNotice(error instanceof Error ? error.message : 'Impossible de charger tous les avis.'))
      .finally(() => setObituaryListLoading(false));
  }, [allObituariesLoaded, obituaryListLoading, route.name]);

  useEffect(() => {
    if (!supportsPush()) {
      return;
    }
    getCurrentSubscription()
      .then((subscription) => setPushEnabled(Boolean(subscription)))
      .catch(() => setPushEnabled(false));
  }, []);

  useEffect(() => {
    setInstallPlatform(detectInstallPlatform());
    setIsStandalone(isAppStandalone());

    const onBeforeInstallPrompt = (event: Event) => {
      event.preventDefault();
      setDeferredInstallPrompt(event as BeforeInstallPromptEvent);
    };
    const onAppInstalled = () => {
      setDeferredInstallPrompt(null);
      setIsStandalone(true);
      setNotice("L'application est installée sur cet appareil.");
    };

    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt);
    window.addEventListener('appinstalled', onAppInstalled);

    return () => {
      window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt);
      window.removeEventListener('appinstalled', onAppInstalled);
    };
  }, []);

  useEffect(() => {
    if (route.name !== 'detail') {
      setSelected(null);
      return;
    }
    setLoading(true);
    fetchObituary(route.id)
      .then(setSelected)
      .catch((error) => setNotice(error instanceof Error ? error.message : "L'avis demandé n'a pas été trouvé."))
      .finally(() => setLoading(false));
  }, [route]);

  const latest = useMemo(() => obituaries.slice(0, 4), [obituaries]);

  async function togglePush() {
    setNotice('');
    try {
      if (pushEnabled) {
        await disablePushNotifications();
        setPushEnabled(false);
        setNotice('Les notifications sont désactivées sur cet appareil.');
      } else {
        await enablePushNotifications();
        setPushEnabled(true);
        setNotice('Les notifications sont activées sur cet appareil.');
      }
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "Impossible d'activer les notifications.");
    }
  }

  async function handleInstallClick() {
    setNotice('');
    const platform = detectInstallPlatform();
    setInstallPlatform(platform);

    if (isAppStandalone()) {
      setIsStandalone(true);
      setNotice("L'application est déjà installée sur cet appareil.");
      return;
    }

    if (platform === 'ios') {
      setInstallHelpOpen(true);
      return;
    }

    if (!deferredInstallPrompt) {
      setInstallHelpOpen(true);
      return;
    }

    const prompt = deferredInstallPrompt;
    setDeferredInstallPrompt(null);
    await prompt.prompt();
    const choice = await prompt.userChoice;

    if (choice.outcome === 'accepted') {
      setNotice("L'installation de l'application est lancée.");
    } else {
      setNotice("L'installation a été annulée.");
    }
  }

  function shareObituary(item: Obituary) {
    const url = `${window.location.origin}${window.location.pathname}#/avis/${item.source_id || item.id}`;
    if (navigator.share) {
      navigator.share({ title: item.person_name || item.title, url }).catch(() => undefined);
      return;
    }
    navigator.clipboard?.writeText(url).then(() => setNotice('Lien copie dans le presse-papiers.'));
  }

  return (
    <div className="min-h-screen bg-paper text-ink">
      <header className="sticky top-0 z-20 border-b border-line bg-paper/95 backdrop-blur">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
          <button className="flex min-h-11 items-center text-left" onClick={() => go('/')} aria-label="Maison Funéraire McConnery">
            <img
              className="h-12 w-auto max-w-[220px] object-contain sm:h-14 sm:max-w-[280px]"
              src="logo-mcconnery2026.png"
              alt="Maison Funéraire McConnery"
            />
          </button>
          <nav className="flex items-center gap-1">
            <button className="rounded p-3 text-action" aria-label="Accueil" onClick={() => go('/')}>
              <Home size={21} />
            </button>
            <button className="rounded p-3 text-action" aria-label="Avis de décès" onClick={() => go('/avis')}>
              <Newspaper size={21} />
            </button>
          </nav>
        </div>
      </header>

      <main className="mx-auto max-w-5xl px-4 pb-32 pt-5">
        {notice ? (
          <div className="mb-4 rounded border border-line bg-white px-4 py-3 text-sm text-ink shadow-soft">{notice}</div>
        ) : null}

        {route.name === 'home' ? (
          <HomePage
            latest={latest}
            loading={loading}
            config={config}
            pushEnabled={pushEnabled}
            onTogglePush={togglePush}
            installLabel={
              isStandalone
                ? 'Application installée'
                : installPlatform === 'ios'
                  ? 'Voir comment installer'
                  : "Télécharger l'application"
            }
            installDisabled={isStandalone}
            onInstallClick={handleInstallClick}
          />
        ) : null}

        {route.name === 'obituaries' ? <ObituaryDirectory items={obituaries} loading={loading || obituaryListLoading} allLoaded={allObituariesLoaded} /> : null}

        {route.name === 'detail' ? (
          <ObituaryDetail item={selected} loading={loading} onShare={shareObituary} />
        ) : null}

        {route.name === 'contact' ? <ContactPage config={config} /> : null}
      </main>

      <footer className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-white safe-bottom">
        <div className="mx-auto grid max-w-5xl grid-cols-3 gap-2 px-5 pt-3">
          <BottomButton active={route.name === 'home'} label="Accueil" icon={<Home size={20} />} onClick={() => go('/')} />
          <BottomButton active={route.name === 'obituaries' || route.name === 'detail'} label="Avis" icon={<Newspaper size={20} />} onClick={() => go('/avis')} />
          <BottomButton active={route.name === 'contact'} label="Contact" icon={<Phone size={20} />} onClick={() => go('/contact')} />
        </div>
      </footer>

      {installHelpOpen ? (
        <InstallHelpDialog platform={installPlatform} onClose={() => setInstallHelpOpen(false)} />
      ) : null}
    </div>
  );
}

function BottomButton({ active, label, icon, onClick }: { active: boolean; label: string; icon: React.ReactNode; onClick: () => void }) {
  return (
    <button
      className={`flex min-h-12 flex-col items-center justify-center rounded-md px-2 text-xs font-medium ${
        active ? 'bg-action text-white' : 'text-ink'
      }`}
      onClick={onClick}
    >
      {icon}
      <span className="mt-1">{label}</span>
    </button>
  );
}

function HomePage({
  latest,
  loading,
  config,
  pushEnabled,
  onTogglePush,
  installLabel,
  installDisabled,
  onInstallClick
}: {
  latest: Obituary[];
  loading: boolean;
  config: PublicConfig | null;
  pushEnabled: boolean;
  onTogglePush: () => void;
  installLabel: string;
  installDisabled: boolean;
  onInstallClick: () => void;
}) {
  return (
    <div className="space-y-7">
      <section className="rounded border border-line bg-white p-5 shadow-soft">
        <p className="text-sm font-semibold uppercase tracking-wide text-rosewood">Avis de décès</p>
        <h1 className="mt-2 text-3xl font-semibold leading-tight">Maison Funéraire McConnery</h1>
        <p className="mt-3 max-w-2xl text-base leading-7 text-ink/75">
          Consultez les avis récents et activez les notifications pour être avisé lorsqu'un nouvel avis est publié.
        </p>
        <div className="mt-5 flex flex-col gap-3 sm:flex-row">
          <button
            className="inline-flex min-h-12 items-center justify-center gap-2 rounded bg-action px-5 font-semibold text-white"
            onClick={onTogglePush}
          >
            {pushEnabled ? <BellOff size={20} /> : <Bell size={20} />}
            {pushEnabled ? 'Désactiver les notifications' : 'Activer les notifications'}
          </button>
          <button
            className="inline-flex min-h-12 items-center justify-center gap-2 rounded border border-action bg-paper px-5 font-semibold text-action disabled:cursor-default disabled:opacity-70"
            onClick={onInstallClick}
            disabled={installDisabled}
          >
            <Download size={20} />
            {installLabel}
          </button>
          <a
            className="inline-flex min-h-12 items-center justify-center gap-2 rounded border border-line bg-paper px-5 font-semibold text-ink"
            href={config?.final_site_url || 'https://mcconnery.ca/'}
          >
            Site complet
            <ExternalLink size={18} />
          </a>
        </div>
      </section>

      <section>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-xl font-semibold">Derniers avis</h2>
          <button className="text-sm font-semibold text-action" onClick={() => go('/avis')}>
            Tout voir
          </button>
        </div>
        <ObituaryList items={latest} loading={loading} compact />
      </section>
    </div>
  );
}

function InstallHelpDialog({ platform, onClose }: { platform: InstallPlatform; onClose: () => void }) {
  const isIos = platform === 'ios';
  const title = isIos ? 'Installer sur iPhone' : "Installer l'application";
  const steps = isIos
    ? [
        'Ouvrez cette page dans Safari.',
        'Touchez le bouton Partager dans la barre du bas.',
        'Choisissez Ajouter à l’écran d’accueil.',
        'Touchez Ajouter, puis ouvrez McConnery depuis l’icône créée.'
      ]
    : platform === 'windows'
      ? [
          'Dans Chrome ou Edge, cliquez sur l’icône d’installation dans la barre d’adresse.',
          'Si elle n’apparaît pas, ouvrez le menu du navigateur.',
          'Choisissez Installer l’application, puis confirmez.'
        ]
      : [
          'Dans Chrome, touchez le menu du navigateur.',
          'Choisissez Installer l’application ou Ajouter à l’écran d’accueil.',
          'Confirmez, puis ouvrez McConnery depuis l’icône créée.'
        ];

  return (
    <div className="fixed inset-0 z-50 flex items-end bg-ink/45 px-4 py-5 sm:items-center sm:justify-center" role="dialog" aria-modal="true" aria-labelledby="install-help-title">
      <div className="w-full max-w-md rounded border border-line bg-white p-5 shadow-soft">
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-rosewood">Application</p>
            <h2 id="install-help-title" className="mt-1 text-2xl font-semibold">{title}</h2>
          </div>
          <button className="rounded p-2 text-action" onClick={onClose} aria-label="Fermer">
            <X size={22} />
          </button>
        </div>

        <ol className="mt-5 space-y-3 text-base leading-7 text-ink/80">
          {steps.map((step, index) => (
            <li key={step} className="flex gap-3">
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-cedar text-sm font-semibold text-white">
                {index + 1}
              </span>
              <span>{step}</span>
            </li>
          ))}
        </ol>

        {isIos ? (
          <p className="mt-5 rounded border border-line bg-paper p-3 text-sm leading-6 text-ink/75">
            Sur iPhone, les notifications fonctionnent après l’installation de la PWA sur l’écran d’accueil.
          </p>
        ) : null}

        <button className="mt-5 inline-flex min-h-12 w-full items-center justify-center rounded bg-action px-5 font-semibold text-white" onClick={onClose}>
          Compris
        </button>
      </div>
    </div>
  );
}

function ObituaryDirectory({ items, loading, allLoaded }: { items: Obituary[]; loading: boolean; allLoaded: boolean }) {
  const [query, setQuery] = useState('');
  const normalizedQuery = normalizeSearch(query.trim());
  const filteredItems = useMemo(() => {
    if (!normalizedQuery) {
      return items;
    }

    return items.filter((item) => {
      const searchable = normalizeSearch(
        [item.person_name, item.title, item.death_date, item.published_at, item.excerpt]
          .filter(Boolean)
          .join(' ')
      );

      return searchable.includes(normalizedQuery);
    });
  }, [items, normalizedQuery]);

  return (
    <section className="space-y-4">
      <div className="rounded border border-line bg-white p-4 shadow-soft">
        <label className="block text-sm font-semibold uppercase tracking-wide text-rosewood" htmlFor="obituary-search">
          Rechercher un avis
        </label>
        <div className="mt-2 flex min-h-12 items-center gap-2 rounded border border-line bg-paper px-3">
          <Search className="shrink-0 text-action" size={20} />
          <input
            id="obituary-search"
            className="min-h-11 flex-1 bg-transparent text-base outline-none placeholder:text-ink/45"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Nom, date, mot-clé..."
            type="search"
          />
          {query ? (
            <button className="rounded px-2 py-1 text-sm font-semibold text-action" onClick={() => setQuery('')}>
              Effacer
            </button>
          ) : null}
        </div>
        <p className="mt-2 text-sm text-ink/65">
          {loading && !allLoaded ? 'Chargement de tous les avis...' : `${filteredItems.length} avis affiché${filteredItems.length > 1 ? 's' : ''}`}
        </p>
      </div>

      <ObituaryList
        items={filteredItems}
        loading={loading && items.length === 0}
        emptyText={query ? 'Aucun avis ne correspond à cette recherche.' : 'Aucun avis disponible pour le moment.'}
      />
    </section>
  );
}

function ObituaryList({
  items,
  loading,
  compact = false,
  emptyText = 'Aucun avis disponible pour le moment.'
}: {
  items: Obituary[];
  loading: boolean;
  compact?: boolean;
  emptyText?: string;
}) {
  if (loading && items.length === 0) {
    return <div className="rounded border border-line bg-white p-5 text-sm text-ink/70">Chargement des avis...</div>;
  }

  if (items.length === 0) {
    return <div className="rounded border border-line bg-white p-5 text-sm text-ink/70">{emptyText}</div>;
  }

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {items.map((item) => (
        <article key={`${item.source_id}-${item.id}`} className="rounded border border-line bg-white p-3 shadow-soft">
          <button className="flex w-full gap-3 text-left" onClick={() => go(`/avis/${item.source_id || item.id}`)}>
            <div className="h-24 w-20 shrink-0 overflow-hidden rounded bg-paper">
              {item.image_url ? (
                <img className="h-full w-full object-cover" src={item.image_url} alt="" loading="lazy" />
              ) : (
                <div className="flex h-full w-full items-center justify-center text-sm font-semibold text-cedar">M</div>
              )}
            </div>
            <div className="min-w-0 flex-1">
              <h2 className="text-lg font-semibold leading-snug">{item.person_name || item.title}</h2>
              <p className="mt-1 text-sm text-rosewood">{formatDate(item.death_date || item.published_at)}</p>
              {!compact ? <p className="mt-2 line-clamp-3 text-sm leading-6 text-ink/70">{item.excerpt}</p> : null}
              <span className="mt-3 inline-block text-sm font-semibold text-action">Voir l'avis</span>
            </div>
          </button>
        </article>
      ))}
    </div>
  );
}

function ObituaryDetail({
  item,
  loading,
  onShare
}: {
  item: Obituary | null;
  loading: boolean;
  onShare: (item: Obituary) => void;
}) {
  if (loading && !item) {
    return <div className="rounded border border-line bg-white p-5 text-sm text-ink/70">Chargement de l'avis...</div>;
  }

  if (!item) {
    return <div className="rounded border border-line bg-white p-5 text-sm text-ink/70">Avis introuvable.</div>;
  }

  const paragraphs = (item.content || item.excerpt || '').split(/\n{2,}|\r\n{2,}/).filter(Boolean);

  return (
    <article className="space-y-5">
      <button className="inline-flex min-h-11 items-center gap-2 rounded border border-line bg-white px-4 font-semibold text-ink" onClick={() => go('/avis')}>
        <ArrowLeft size={18} />
        Retour
      </button>

      <section className="rounded border border-line bg-white p-5 shadow-soft">
        <div className="flex flex-col gap-5 sm:flex-row">
          {item.image_url ? (
            <img className="max-h-72 w-full rounded object-cover sm:w-56" src={item.image_url} alt="" />
          ) : null}
          <div className="flex-1">
            <h1 className="text-3xl font-semibold leading-tight">{item.person_name || item.title}</h1>
            <p className="mt-2 text-base font-medium text-rosewood">{formatDate(item.death_date || item.published_at)}</p>
            <div className="mt-5 flex flex-col gap-3 sm:flex-row">
              <button className="inline-flex min-h-12 items-center justify-center gap-2 rounded bg-action px-5 font-semibold text-white" onClick={() => onShare(item)}>
                <Share2 size={19} />
                Partager
              </button>
              {item.source_url ? (
                <a className="inline-flex min-h-12 items-center justify-center gap-2 rounded border border-line bg-paper px-5 font-semibold text-ink" href={item.source_url}>
                  Source
                  <ExternalLink size={18} />
                </a>
              ) : null}
            </div>
          </div>
        </div>
      </section>

      <section className="content-text rounded border border-line bg-white p-5 text-base leading-7 shadow-soft">
        {paragraphs.map((paragraph, index) => (
          <p key={index}>{paragraph.replace(/\s+/g, ' ').trim()}</p>
        ))}
      </section>

      <SympathySection sourceId={item.source_id || String(item.id)} />
    </article>
  );
}

function SympathySection({ sourceId }: { sourceId: string }) {
  const [messages, setMessages] = useState<SympathyMessage[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [form, setForm] = useState({
    author_name: '',
    author_email: '',
    author_phone: '',
    message: '',
    website: ''
  });

  useEffect(() => {
    if (!sourceId) {
      setMessages([]);
      return;
    }

    let active = true;
    setLoading(true);
    setError('');
    fetchSympathyMessages(sourceId)
      .then((items) => {
        if (active) {
          setMessages(items);
        }
      })
      .catch((requestError) => {
        if (active) {
          setError(requestError instanceof Error ? requestError.message : 'Impossible de charger les messages.');
        }
      })
      .finally(() => {
        if (active) {
          setLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, [sourceId]);

  function updateField(field: keyof typeof form, value: string) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    setSuccess('');
    setSubmitting(true);

    try {
      await submitSympathyMessage({ obituary_source_id: sourceId, ...form });
      setSuccess('Votre message a été reçu. Il sera publié après approbation.');
      setForm({ author_name: '', author_email: '', author_phone: '', message: '', website: '' });
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : "Impossible d'envoyer le message.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="rounded border border-line bg-white p-5 shadow-soft">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-semibold uppercase tracking-wide text-rosewood">Livre de sympathies</p>
          <h2 className="mt-1 text-2xl font-semibold">Messages de sympathie</h2>
        </div>
        <p className="text-sm text-ink/65">Les nouveaux messages sont approuvés avant publication.</p>
      </div>

      <div className="mt-5 space-y-3">
        {loading ? <p className="rounded border border-line bg-paper p-4 text-sm text-ink/70">Chargement des messages...</p> : null}
        {!loading && messages.length === 0 ? (
          <p className="rounded border border-line bg-paper p-4 text-sm text-ink/70">
            Aucun message actuellement dans le livre de sympathies. Soyez le premier à laisser un message.
          </p>
        ) : null}
        {messages.map((message) => (
          <article key={`${message.id}-${message.posted_at || message.created_at}`} className="rounded border border-line bg-paper p-4">
            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
              <h3 className="font-semibold">{message.author_name || 'Anonyme'}</h3>
              <p className="text-sm text-rosewood">{formatDate(message.posted_at || message.created_at)}</p>
            </div>
            <p className="mt-3 whitespace-pre-line leading-7 text-ink/75">{message.message}</p>
          </article>
        ))}
      </div>

      <form className="mt-6 space-y-4" onSubmit={handleSubmit}>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm font-semibold">
            Nom
            <input
              className="mt-2 min-h-12 w-full rounded border border-line bg-white px-3 font-normal outline-none focus:border-action"
              value={form.author_name}
              onChange={(event) => updateField('author_name', event.target.value)}
              autoComplete="name"
              required
            />
          </label>
          <label className="block text-sm font-semibold">
            Courriel
            <input
              className="mt-2 min-h-12 w-full rounded border border-line bg-white px-3 font-normal outline-none focus:border-action"
              type="email"
              value={form.author_email}
              onChange={(event) => updateField('author_email', event.target.value)}
              autoComplete="email"
              required
            />
          </label>
        </div>
        <label className="block text-sm font-semibold">
          Téléphone
          <input
            className="mt-2 min-h-12 w-full rounded border border-line bg-white px-3 font-normal outline-none focus:border-action"
            type="tel"
            value={form.author_phone}
            onChange={(event) => updateField('author_phone', event.target.value)}
            autoComplete="tel"
          />
        </label>
        <label className="block text-sm font-semibold">
          Message
          <textarea
            className="mt-2 min-h-36 w-full rounded border border-line bg-white px-3 py-3 font-normal outline-none focus:border-action"
            value={form.message}
            onChange={(event) => updateField('message', event.target.value)}
            maxLength={2000}
            required
          />
        </label>
        <input
          className="hidden"
          tabIndex={-1}
          autoComplete="off"
          value={form.website}
          onChange={(event) => updateField('website', event.target.value)}
        />

        {success ? <p className="rounded border border-action/30 bg-action/10 p-3 text-sm font-medium text-action">{success}</p> : null}
        {error ? <p className="rounded border border-rosewood/30 bg-rosewood/10 p-3 text-sm font-medium text-rosewood">{error}</p> : null}

        <button className="inline-flex min-h-12 w-full items-center justify-center rounded bg-action px-5 font-semibold text-white sm:w-auto" disabled={submitting}>
          {submitting ? 'Envoi...' : 'Ajouter un message'}
        </button>
      </form>
    </section>
  );
}

function ContactPage({ config }: { config: PublicConfig | null }) {
  const contact = config?.contact;
  return (
    <section className="rounded border border-line bg-white p-5 shadow-soft">
      <h1 className="text-2xl font-semibold">Contact et informations utiles</h1>
      <div className="mt-5 space-y-4 text-base">
        <ContactLine icon={<Phone size={20} />} label={contact?.phone || '(819) 449-2626'} href={`tel:${(contact?.phone || '8194492626').replace(/\D/g, '')}`} />
        <ContactLine icon={<Mail size={20} />} label={contact?.email || 'sympathies@maisonfunerairemcconnery.ca'} href={`mailto:${contact?.email || 'sympathies@maisonfunerairemcconnery.ca'}`} />
        <ContactLine icon={<MapPin size={20} />} label={contact?.address || '206 rue Cartier, Maniwaki (Quebec) J9E 1R3'} />
      </div>
      <div className="mt-6 flex flex-col gap-3 sm:flex-row">
        <a className="inline-flex min-h-12 items-center justify-center gap-2 rounded bg-action px-5 font-semibold text-white" href={contact?.official_contact_url || 'https://mcconnery.ca/contact'}>
          Page contact officielle
          <ExternalLink size={18} />
        </a>
        <a className="inline-flex min-h-12 items-center justify-center gap-2 rounded border border-line bg-paper px-5 font-semibold text-ink" href={config?.final_site_url || 'https://mcconnery.ca/'}>
          Site complet
          <ExternalLink size={18} />
        </a>
      </div>
    </section>
  );
}

function ContactLine({ icon, label, href }: { icon: React.ReactNode; label: string; href?: string }) {
  const content = (
    <span className="flex min-w-0 items-start gap-3">
      <span className="mt-1 shrink-0 text-cedar">{icon}</span>
      <span className="contact-line-label min-w-0">{label}</span>
    </span>
  );

  if (href) {
    return (
      <a className="block rounded border border-line bg-paper p-4 font-medium" href={href}>
        {content}
      </a>
    );
  }

  return <div className="rounded border border-line bg-paper p-4 font-medium">{content}</div>;
}

export default App;
