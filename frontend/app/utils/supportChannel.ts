/**
 * The support channel a deployment publishes — if it publishes one.
 *
 * Four surfaces tell a customer or reseller to contact SP Cambo, and none of them
 * could say where: a suspended account (`SpStateForbidden`, and the
 * `account_suspended` copy in `spApiError.ts`), a payment that failed verification
 * (`docs/errors.vue`), and a managed customer that cannot be funded
 * (`reseller/customers/[id].vue`). No address, inbox or handle was configured
 * anywhere in the frontend, so "contact SP Cambo support" was an instruction with
 * nowhere to go: a customer whose account had just been barred from every page had
 * no recourse unless they already happened to know somebody.
 *
 * No address is invented here, and none is hard-coded. `NUXT_PUBLIC_SUPPORT_URL` is
 * optional: set it and those surfaces gain a link that works, leave it unset and
 * they read exactly as they do today. A guessed `support@spcambo.com` that nobody
 * monitors would be worse than no link, because it looks like it worked.
 *
 * Only `http`, `https` and `mailto` are accepted. This value comes from deployment
 * configuration and ends up in an `href`, so restricting the scheme keeps a typo —
 * or a compromised environment file — from turning into a `javascript:` link on a
 * page a signed-in customer is looking at.
 *
 * Pure and unit-tested in `tests/unit/supportChannel.spec.ts`.
 */

/** The variable that publishes the channel. Named for the operator error messages. */
export const SUPPORT_URL_ENV = 'NUXT_PUBLIC_SUPPORT_URL'

export interface SupportChannel {
  /** Safe to bind to `href`: the scheme has been checked. */
  href: string
  /** What the link reads as — the address itself, never marketing copy. */
  label: string
  /**
   * `email` opens the customer's mail client; `link` is a page, help desk or chat
   * handle and is opened in a new tab so a half-finished form is not lost.
   */
  kind: 'email' | 'link'
}

/**
 * A bare `local@domain.tld`.
 *
 * Accepted because an operator filling in a deployment template is far more likely
 * to write the address than to write `mailto:` in front of it, and silently ignoring
 * it would reproduce the dead end this exists to close.
 */
const BARE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/**
 * The channel to show, or `null` when there is nothing publishable.
 *
 * `null` covers both "not configured", which is ordinary, and "configured with
 * something that is not reachable", which is a deployment mistake — the caller
 * renders nothing either way, and `supportChannelProblem` is what reports the
 * second at build time rather than leaving it silent.
 */
export function resolveSupportChannel(raw: string | undefined | null): SupportChannel | null {
  const value = raw?.trim()

  if (!value) {
    return null
  }

  let url: URL

  try {
    url = new URL(value)
  } catch {
    /*
     * Not a URL at all. A bare address is the one shape worth rescuing — and this
     * is tried second rather than first because `mailto:a@b.com` also satisfies the
     * bare-address pattern, which would produce `mailto:mailto:a@b.com`.
     */
    return BARE_EMAIL.test(value)
      ? { href: `mailto:${value}`, label: value, kind: 'email' }
      : null
  }

  if (url.protocol === 'mailto:') {
    const address = decodeURIComponent(url.pathname).trim()

    if (!BARE_EMAIL.test(address)) {
      return null
    }

    // `?subject=` and friends are kept: an operator who routed support mail with a
    // tag would lose the routing otherwise. Only the address is shown.
    return { href: `mailto:${address}${url.search}`, label: address, kind: 'email' }
  }

  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    return null
  }

  return {
    href: url.toString(),
    // Host and path only. The scheme is noise to a reader, and a trailing slash
    // makes a deliberate address look like a truncated one.
    label: `${url.host}${url.pathname.replace(/\/+$/, '')}${url.search}`,
    kind: 'link'
  }
}

/**
 * What is wrong with a configured support channel, or `null` when nothing is.
 *
 * An unset channel is not a defect — most deployments will not publish one on day
 * one. A channel that is set and unusable is, and it fails in the worst way
 * available: the operator believes support is reachable, the link never renders, and
 * the surfaces go back to saying "contact SP Cambo" with no way to. Reported by the
 * strict release check in `nuxt.config.ts` alongside the required endpoints.
 */
export function supportChannelProblem(raw: string | undefined | null): string | null {
  const value = raw?.trim()

  if (!value || resolveSupportChannel(value) !== null) {
    return null
  }

  return `supportUrl is set to ${value}, which is neither an email address nor an http(s) URL, `
    + `so no support link will be shown anywhere. Set ${SUPPORT_URL_ENV} to a channel customers can `
    + 'reach, or leave it unset.'
}
