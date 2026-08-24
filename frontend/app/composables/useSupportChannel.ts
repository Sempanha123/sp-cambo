import { resolveSupportChannel } from '~/utils/supportChannel'

/**
 * The support channel this deployment publishes, or `null` when it publishes none.
 *
 * Read from `runtimeConfig.public.supportUrl`, which Nitro fills from
 * `NUXT_PUBLIC_SUPPORT_URL` when the server boots — so an operator can change where
 * support is reached, or start publishing it at all, by restarting the container
 * rather than rebuilding the image.
 *
 * Callers must handle `null`: it is the default, and a surface that assumed a channel
 * existed would render an empty link. `SpSupportLink` handles it by rendering
 * nothing, which leaves the surrounding copy as the only thing said — see
 * `~/utils/supportChannel` for why no address is guessed.
 */
export function useSupportChannel() {
  const config = useRuntimeConfig()

  return computed(() => resolveSupportChannel(config.public.supportUrl))
}
