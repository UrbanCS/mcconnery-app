import { ArrowLeft, Bell, BellOff, ExternalLink, Home, Mail, MapPin, Newspaper, Phone, Share2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { fetchObituaries, fetchObituary, fetchPublicConfig } from './api';
import { disablePushNotifications, enablePushNotifications, getCurrentSubscription, supportsPush } from './push';
import type { Obituary, PublicConfig } from './types';

type Route =
  | { name: 'home' }
  | { name: 'obituaries' }
  | { name: 'detail'; id: string }
  | { name: 'contact' };

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
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return new Intl.DateTimeFormat('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
}

function go(path: string) {
  window.location.hash = path;
}

function App() {
  const [route, setRoute] = useState<Route>(parseRoute);
  const [config, setConfig] = useState<PublicConfig | null>(null);
  const [obituaries, setObituaries] = useState<Obituary[]>([]);
  const [selected, setSelected] = useState<Obituary | null>(null);
  const [loading, setLoading] = useState(true);
  const [notice, setNotice] = useState('');
  const [pushEnabled, setPushEnabled] = useState(false);

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
    if (!supportsPush()) {
      return;
    }
    getCurrentSubscription()
      .then((subscription) => setPushEnabled(Boolean(subscription)))
      .catch(() => setPushEnabled(false));
  }, []);

  useEffect(() => {
    if (route.name !== 'detail') {
      setSelected(null);
      return;
    }
    setLoading(true);
    fetchObituary(route.id)
      .then(setSelected)
      .catch((error) => setNotice(error instanceof Error ? error.message : "L'avis demande n'a pas ete trouve."))
      .finally(() => setLoading(false));
  }, [route]);

  const latest = useMemo(() => obituaries.slice(0, 4), [obituaries]);

  async function togglePush() {
    setNotice('');
    try {
      if (pushEnabled) {
        await disablePushNotifications();
        setPushEnabled(false);
        setNotice('Les notifications sont desactivees sur cet appareil.');
      } else {
        await enablePushNotifications();
        setPushEnabled(true);
        setNotice('Les notifications sont activees sur cet appareil.');
      }
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "Impossible d'activer les notifications.");
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
          <button className="flex min-h-11 items-center gap-3 text-left" onClick={() => go('/')}>
            <span className="flex h-10 w-10 items-center justify-center rounded bg-cedar text-lg font-semibold text-white">M</span>
            <span>
              <span className="block text-sm font-semibold leading-tight">Maison Funeraire</span>
              <span className="block text-lg font-semibold leading-tight">McConnery</span>
            </span>
          </button>
          <nav className="flex items-center gap-1">
            <button className="rounded p-3 text-cedar" aria-label="Accueil" onClick={() => go('/')}>
              <Home size={21} />
            </button>
            <button className="rounded p-3 text-cedar" aria-label="Avis de deces" onClick={() => go('/avis')}>
              <Newspaper size={21} />
            </button>
          </nav>
        </div>
      </header>

      <main className="mx-auto max-w-5xl px-4 pb-24 pt-5">
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
          />
        ) : null}

        {route.name === 'obituaries' ? <ObituaryList items={obituaries} loading={loading} /> : null}

        {route.name === 'detail' ? (
          <ObituaryDetail item={selected} loading={loading} onShare={shareObituary} />
        ) : null}

        {route.name === 'contact' ? <ContactPage config={config} /> : null}
      </main>

      <footer className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-white safe-bottom">
        <div className="mx-auto grid max-w-5xl grid-cols-3 gap-1 px-3 pt-2">
          <BottomButton active={route.name === 'home'} label="Accueil" icon={<Home size={20} />} onClick={() => go('/')} />
          <BottomButton active={route.name === 'obituaries' || route.name === 'detail'} label="Avis" icon={<Newspaper size={20} />} onClick={() => go('/avis')} />
          <BottomButton active={route.name === 'contact'} label="Contact" icon={<Phone size={20} />} onClick={() => go('/contact')} />
        </div>
      </footer>
    </div>
  );
}

function BottomButton({ active, label, icon, onClick }: { active: boolean; label: string; icon: React.ReactNode; onClick: () => void }) {
  return (
    <button
      className={`flex min-h-12 flex-col items-center justify-center rounded px-2 text-xs font-medium ${
        active ? 'bg-cedar text-white' : 'text-ink'
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
  onTogglePush
}: {
  latest: Obituary[];
  loading: boolean;
  config: PublicConfig | null;
  pushEnabled: boolean;
  onTogglePush: () => void;
}) {
  return (
    <div className="space-y-7">
      <section className="rounded border border-line bg-white p-5 shadow-soft">
        <p className="text-sm font-semibold uppercase tracking-wide text-rosewood">Avis de deces</p>
        <h1 className="mt-2 text-3xl font-semibold leading-tight">Maison Funeraire McConnery</h1>
        <p className="mt-3 max-w-2xl text-base leading-7 text-ink/75">
          Consultez les avis recents et activez les notifications pour etre avise lorsqu'un nouvel avis est publie.
        </p>
        <div className="mt-5 flex flex-col gap-3 sm:flex-row">
          <button
            className="inline-flex min-h-12 items-center justify-center gap-2 rounded bg-cedar px-5 font-semibold text-white"
            onClick={onTogglePush}
          >
            {pushEnabled ? <BellOff size={20} /> : <Bell size={20} />}
            {pushEnabled ? 'Desactiver les notifications' : 'Activer les notifications'}
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
          <button className="text-sm font-semibold text-cedar" onClick={() => go('/avis')}>
            Tout voir
          </button>
        </div>
        <ObituaryList items={latest} loading={loading} compact />
      </section>
    </div>
  );
}

function ObituaryList({ items, loading, compact = false }: { items: Obituary[]; loading: boolean; compact?: boolean }) {
  if (loading && items.length === 0) {
    return <div className="rounded border border-line bg-white p-5 text-sm text-ink/70">Chargement des avis...</div>;
  }

  if (items.length === 0) {
    return <div className="rounded border border-line bg-white p-5 text-sm text-ink/70">Aucun avis disponible pour le moment.</div>;
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
              <span className="mt-3 inline-block text-sm font-semibold text-cedar">Voir l'avis</span>
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
              <button className="inline-flex min-h-12 items-center justify-center gap-2 rounded bg-cedar px-5 font-semibold text-white" onClick={() => onShare(item)}>
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
    </article>
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
        <a className="inline-flex min-h-12 items-center justify-center gap-2 rounded bg-cedar px-5 font-semibold text-white" href={contact?.official_contact_url || 'https://mcconnery.ca/contact'}>
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
    <span className="flex items-start gap-3">
      <span className="mt-1 text-cedar">{icon}</span>
      <span>{label}</span>
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
