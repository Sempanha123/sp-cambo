export type PlaygroundBackgroundStatus = 'idle' | 'connecting' | 'streaming' | 'saving' | 'done' | 'failed' | 'stopped'

export interface PlaygroundBackgroundRunState {
  token: string | null
  status: PlaygroundBackgroundStatus
  running: boolean
  stopping: boolean
  chat_id: number | null
  chat_key: string | null
  model_alias: string | null
  request_id: string | null
  assistant_text: string
  error: string | null
  started_at: number | null
  finished_at: number | null
}

let activeController: AbortController | null = null
let activeControllerToken: string | null = null

const emptyState = (): PlaygroundBackgroundRunState => ({
  token: null,
  status: 'idle',
  running: false,
  stopping: false,
  chat_id: null,
  chat_key: null,
  model_alias: null,
  request_id: null,
  assistant_text: '',
  error: null,
  started_at: null,
  finished_at: null
})

/**
 * Keeps the browser-side Playground stream alive across Nuxt page navigation.
 *
 * Playground is kept alive by Nuxt while the customer navigates inside the dashboard,
 * while its AbortController and serializable progress are also promoted to application
 * state. Route changes therefore do not tear down the stream. When the customer comes
 * back, the same page instance and shared state continue rendering the live response.
 *
 * A full browser reload naturally terminates the browser connection; server-side request
 * activity/history remains the source of truth in that case.
 */
export const usePlaygroundBackgroundRun = () => {
  const state = useState<PlaygroundBackgroundRunState>('sp:playground-background-run', emptyState)

  const start = (input: { chatId: number | null, chatKey: string | null, modelAlias: string }) => {
    // Only one hosted Playground stream is attached to the global Stop control at a time.
    // The UI already prevents a second send in the same active chat while this is running.
    const controller = new AbortController()
    const token = `${Date.now()}:${Math.random().toString(36).slice(2)}`
    activeController = controller
    activeControllerToken = token
    state.value = {
      token,
      status: 'connecting',
      running: true,
      stopping: false,
      chat_id: input.chatId,
      chat_key: input.chatKey,
      model_alias: input.modelAlias,
      request_id: null,
      assistant_text: '',
      error: null,
      started_at: Date.now(),
      finished_at: null
    }
    return { token, signal: controller.signal }
  }

  const matches = (token: string) => state.value.token === token

  const setRequestId = (token: string, requestId?: string | null) => {
    if (!matches(token) || !requestId) return
    state.value.request_id = requestId
  }

  const append = (token: string, delta: string) => {
    if (!matches(token) || !delta) return
    state.value.status = 'streaming'
    state.value.assistant_text += delta
  }

  const saving = (token: string) => {
    if (!matches(token)) return
    state.value.status = 'saving'
  }

  const finish = (token: string) => {
    if (!matches(token)) return
    state.value.status = 'done'
    state.value.running = false
    state.value.stopping = false
    state.value.finished_at = Date.now()
    if (activeControllerToken === token) {
      activeController = null
      activeControllerToken = null
    }
  }

  const fail = (token: string, error: string, stopped = false) => {
    if (!matches(token)) return
    state.value.status = stopped ? 'stopped' : 'failed'
    state.value.running = false
    state.value.stopping = false
    state.value.error = stopped ? null : error
    state.value.finished_at = Date.now()
    if (activeControllerToken === token) {
      activeController = null
      activeControllerToken = null
    }
  }

  const stop = () => {
    if (!state.value.running || !activeController) return false
    state.value.stopping = true
    activeController.abort()
    return true
  }

  const resetFinished = () => {
    if (state.value.running) return
    state.value = emptyState()
  }

  return { state, start, setRequestId, append, saving, finish, fail, stop, resetFinished }
}
