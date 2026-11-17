import type {createConsent} from './consent-client.ts';
import {record} from './tracking.ts';

/** Wire the rendered panel only. CMP initialization belongs to the caller. */
export function mountConsentUI(w: Window, cmp: ReturnType<typeof createConsent>, ready: boolean) {
  const root = w.document.querySelector<HTMLElement>('[data-consent-ui]');
  if (!root) return;
  const banner = root.querySelector<HTMLElement>('[data-consent-banner]')!;
  const reopen = root.querySelector<HTMLButtonElement>('[data-consent-reopen]')!;
  const dialog = root.querySelector<HTMLDialogElement>('dialog')!;
  const analytics = root.querySelector<HTMLInputElement>('[name="analytics"]')!;
  const marketing = root.querySelector<HTMLInputElement>('[name="marketing"]')!;
  const audit = root.querySelector<HTMLElement>('[data-consent-audit]')!;
  const resetAudit = () => { audit.textContent = ''; };
  let opener: HTMLElement = reopen;
  const blocked = () => !ready;
  const sync = () => {
    const choice = cmp.get();
    const reason = !ready ? 'Informativa completa non disponibile: storage opzionali e tag Google/Meta restano disattivati.' : '';
    root.querySelectorAll<HTMLElement>('[data-consent-reason]').forEach(el => { el.textContent = reason; el.hidden = !reason; });
    root.querySelector<HTMLButtonElement>('[data-consent-accept]')!.disabled = blocked();
    banner.hidden = cmp.hasChoice();
    analytics.disabled = marketing.disabled = blocked();
    analytics.checked = !blocked() && choice.analytics;
    marketing.checked = !blocked() && choice.marketing;
  };
  const open = (button: HTMLElement) => {
    opener = button;
    sync();
    if (!dialog.open) dialog.showModal();
  };
  root.querySelector<HTMLButtonElement>('[data-consent-customize]')!.addEventListener('click', event => open(event.currentTarget as HTMLElement));
  reopen.addEventListener('click', () => open(reopen));
  root.querySelector('[data-consent-close]')!.addEventListener('click', () => dialog.close());
  // ponytail: native dialog owns Escape and focus trapping; closing never sets consent.
  dialog.addEventListener('close', () => (banner.hidden ? reopen : opener).focus());
  const save = (analytics: boolean, marketing: boolean) => {
    resetAudit();
    cmp.set({necessary:true, analytics:!blocked() && analytics, marketing:!blocked() && marketing});
    sync();
    if (dialog.open) dialog.close();
    else reopen.focus();
  };
  root.querySelector('[data-consent-accept]')!.addEventListener('click', () => save(true,true));
  root.querySelectorAll('[data-consent-reject]').forEach(button => button.addEventListener('click', () => save(false,false)));
  root.querySelector('form')!.addEventListener('submit', event => {
    event.preventDefault();
    save(analytics.checked, marketing.checked);
  });
  w.addEventListener('alltracking:consent', () => { resetAudit(); sync(); });
  // ponytail: the audit trail stays server-side; only a failed save needs the visitor's attention.
  w.addEventListener('alltracking:audit', event => {
    const {status} = record((event as CustomEvent).detail);
    if (status === 'saving' || status === 'saved') resetAudit();
    else if (status === 'failed') audit.textContent = 'Registrazione non confermata. I nuovi consensi opzionali restano disattivati; il rifiuto è applicato subito.';
  });
  reopen.hidden = false;
  sync();
}
