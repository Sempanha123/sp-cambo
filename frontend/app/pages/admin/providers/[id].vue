<script setup lang="ts">
import type { FormError } from '@nuxt/ui'
import type {
  AdminProviderAlias,
  AdminProviderModel,
  DiscoveredProviderModel,
  ProviderAliasInput,
  ProviderConnectionRevision,
  ProviderConnectionRevisionInput,
  ProviderConnectionRevisionUpdateInput,
  ProviderConnectionStatusTransition,
  ProviderConnectionStatusUpdateInput,
  ProviderModelInput
} from '~/types/admin'

/**
 * Admin provider detail page for managing connection revisions.
 */
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

const route = useRoute()
const api = useSpApi()
const toast = useToast()

const providerId = computed(() => String(route.params.id ?? ''))

// Fetch provider
const provider = await useSpResource(
  'admin:provider',
  () => api.admin.providers().then(providers => providers.find(p => p.id === providerId.value) ?? null),
  { server: false }
)

// Fetch connection revisions
const revisions = await useSpResource(
  'admin:provider-connection-revisions',
  () => api.admin.providerConnectionRevisions(providerId.value),
  { server: false }
)

// Create connection revision form
const createOpen = ref(false)
const creating = ref(false)
const createForm = ref<ProviderConnectionRevisionInput>({
  route_version: 1,
  origin: '',
  connection_type: 'omniroute',
  credential: '',
  timeout_ms: 30000,
  policy_version: 1
})
const createError = ref<string | null>(null)
const createFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('createFormRef')

const resetCreateForm = () => {
  createForm.value = {
    route_version: 1,
    origin: '',
    connection_type: 'omniroute',
    credential: '',
    timeout_ms: 30000,
    policy_version: 1
  }
  createError.value = null
  createFormRef.value?.setErrors([])
}

const validateCreateForm = (state: ProviderConnectionRevisionInput): FormError[] => {
  const errors: FormError[] = []

  if (!state.origin.trim()) {
    errors.push({ name: 'origin', message: 'Origin URL is required.' })
  } else if (!state.origin.startsWith('http://') && !state.origin.startsWith('https://')) {
    errors.push({ name: 'origin', message: 'Origin must start with http:// or https://' })
  }

  if (!state.credential.trim()) {
    errors.push({ name: 'credential', message: 'Credential is required.' })
  }

  if (state.timeout_ms < 1000 || state.timeout_ms > 60000) {
    errors.push({ name: 'timeout_ms', message: 'Timeout must be between 1000 and 60000 milliseconds.' })
  }

  return errors
}

const submitCreate = async () => {
  creating.value = true
  createError.value = null

  try {
    const revision = await api.admin.createProviderConnectionRevision(providerId.value, {
      ...createForm.value,
      origin: createForm.value.origin.trim(),
      credential: createForm.value.credential.trim()
    })

    createOpen.value = false
    resetCreateForm()
    await revisions.refresh()

    toast.add({
      title: 'Connection revision created',
      description: `Revision ${revision.route_version} has been created successfully.`,
      color: 'success',
      icon: 'i-lucide-plus-circle'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    createFormRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    createError.value = error.isValidation ? null : error.message
  } finally {
    creating.value = false
  }
}

// Edit an unused PENDING connection revision. Credentials are never read back;
// leaving the credential field blank preserves the encrypted secret server-side.
const editRevisionOpen = ref(false)
const editingRevision = ref(false)
const editRevisionTarget = ref<ProviderConnectionRevision | null>(null)
const editRevisionError = ref<string | null>(null)
const editRevisionFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('editRevisionFormRef')
const editRevisionForm = ref<ProviderConnectionRevisionUpdateInput>({
  route_version: 1,
  origin: '',
  connection_type: 'omniroute',
  credential: '',
  timeout_ms: 30000,
  policy_version: 1,
  resolve_until: null
})

const canEditRevision = (revision: ProviderConnectionRevision) =>
  revision.lifecycle_status === 'PENDING'
  && provider.data.value?.active_connection_revision_id !== revision.id

const openEditRevision = (revision: ProviderConnectionRevision) => {
  if (!canEditRevision(revision)) return

  editRevisionTarget.value = revision
  editRevisionForm.value = {
    route_version: revision.route_version,
    origin: revision.origin,
    connection_type: revision.connection_type,
    credential: '',
    timeout_ms: revision.timeout_ms,
    policy_version: revision.policy_version,
    resolve_until: revision.resolve_until
  }
  editRevisionError.value = null
  editRevisionFormRef.value?.setErrors([])
  editRevisionOpen.value = true
}

const validateEditRevisionForm = (state: ProviderConnectionRevisionUpdateInput): FormError[] => {
  const errors: FormError[] = []

  if (!state.origin.trim()) {
    errors.push({ name: 'origin', message: 'Origin URL is required.' })
  } else if (!state.origin.startsWith('http://') && !state.origin.startsWith('https://')) {
    errors.push({ name: 'origin', message: 'Origin must start with http:// or https://' })
  }

  if (state.timeout_ms < 1000 || state.timeout_ms > 60000) {
    errors.push({ name: 'timeout_ms', message: 'Timeout must be between 1000 and 60000 milliseconds.' })
  }

  return errors
}

const submitEditRevision = async () => {
  if (!editRevisionTarget.value) return
  editingRevision.value = true
  editRevisionError.value = null
  try {
    const updated = await api.admin.updateProviderConnectionRevision(providerId.value, editRevisionTarget.value.id, {
      ...editRevisionForm.value,
      origin: editRevisionForm.value.origin.trim(),
      credential: editRevisionForm.value.credential?.trim() || undefined
    })
    editRevisionOpen.value = false
    editRevisionTarget.value = null
    await revisions.refresh()
    toast.add({
      title: 'Connection revision updated',
      description: `Revision ${updated.route_version} has been updated successfully.`,
      color: 'success',
      icon: 'i-lucide-pencil'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    editRevisionFormRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )
    editRevisionError.value = error.isValidation ? null : error.message
  } finally {
    editingRevision.value = false
  }
}

const deletingRevision = ref(false)
const deleteRevisionTarget = ref<ProviderConnectionRevision | null>(null)
const deleteRevisionError = ref<string | null>(null)

const openDeleteRevision = (revision: ProviderConnectionRevision) => {
  deleteRevisionTarget.value = revision
  deleteRevisionError.value = null
}

const confirmDeleteRevision = async () => {
  if (!deleteRevisionTarget.value) return

  deletingRevision.value = true
  deleteRevisionError.value = null
  const target = deleteRevisionTarget.value

  try {
    await api.admin.deleteProviderConnectionRevision(providerId.value, target.id)
    deleteRevisionTarget.value = null
    await revisions.refresh()

    toast.add({
      title: 'Connection revision deleted',
      description: `Revision ${target.route_version} was deleted.`,
      color: 'success',
      icon: 'i-lucide-trash-2'
    })
  } catch (cause) {
    deleteRevisionError.value = toSpApiError(cause).message
  } finally {
    deletingRevision.value = false
  }
}

// Set active connection
const readyRevisionWithoutActive = computed(() => {
  if (provider.data.value?.active_connection_revision_id) return null

  return [...(revisions.data.value ?? [])]
    .filter(revision => revision.lifecycle_status === 'READY' && revision.last_probe_status === 'SUCCESS')
    .sort((left, right) => right.route_version - left.route_version)[0] ?? null
})

const setActiveOpen = ref(false)
const settingActive = ref(false)
const activeRevisionId = ref<string | null>(null)
const setActiveError = ref<string | null>(null)

const canSetActive = (revision: ProviderConnectionRevision) =>
  revision.lifecycle_status === 'READY'
  && provider.data.value?.active_connection_revision_id !== revision.id

const setActiveTitle = (revision: ProviderConnectionRevision) => {
  if (provider.data.value?.active_connection_revision_id === revision.id) {
    return 'This revision is already active'
  }

  if (revision.lifecycle_status !== 'READY') {
    return 'Only a successfully probed READY revision can be set active'
  }

  return 'Set this READY revision active'
}

const openSetActive = (revision: ProviderConnectionRevision) => {
  if (!canSetActive(revision)) return

  activeRevisionId.value = revision.id
  setActiveError.value = null
  setActiveOpen.value = true
}

const openBestReadyRevision = () => {
  if (readyRevisionWithoutActive.value) {
    openSetActive(readyRevisionWithoutActive.value)
  }
}

const submitSetActive = async () => {
  if (!activeRevisionId.value) return

  settingActive.value = true
  setActiveError.value = null

  try {
    await api.admin.updateProviderActiveConnection(providerId.value, {
      revision_id: activeRevisionId.value
    })

    setActiveOpen.value = false
    activeRevisionId.value = null
    await provider.refresh()
    await revisions.refresh()

    toast.add({
      title: 'Active connection updated',
      description: 'The active connection has been updated successfully.',
      color: 'success',
      icon: 'i-lucide-check-circle'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    setActiveError.value = error.message
  } finally {
    settingActive.value = false
  }
}

// Probe connection
const probing = ref(false)
const probeError = ref<string | null>(null)

const probeRevision = async (revision: ProviderConnectionRevision) => {
  probing.value = true
  probeError.value = null

  try {
    const updatedRevision = await api.admin.probeProviderConnectionRevision(providerId.value, revision.id)

    await Promise.all([revisions.refresh(), provider.refresh()])

    toast.add({
      title: updatedRevision.auto_activated ? 'Connection ready and active' : 'Connection probed',
      description: updatedRevision.probe_message,
      color: 'success',
      icon: 'i-lucide-check-circle'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    probeError.value = error.message
    toast.add({
      title: 'Probe failed',
      description: error.message,
      color: 'error',
      icon: 'i-lucide-circle-x'
    })
  } finally {
    probing.value = false
  }
}

// Update status
const statusOpen = ref(false)
const updatingStatus = ref(false)
const statusForm = ref<ProviderConnectionStatusUpdateInput>({ lifecycle_status: 'REVOKED', reason: '' })
const statusError = ref<string | null>(null)
const statusFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('statusFormRef')
const statusTarget = ref<ProviderConnectionRevision | null>(null)

const statusTransitionsFor = (
  revision: ProviderConnectionRevision
): ProviderConnectionStatusTransition[] => {
  switch (revision.lifecycle_status) {
    case 'PENDING':
    case 'DRAINING':
      return ['REVOKED']
    case 'READY':
      return ['DRAINING', 'REVOKED']
    case 'REVOKED':
      return []
  }
}

const statusTransitionOptions = computed<ProviderConnectionStatusTransition[]>(() =>
  statusTarget.value ? statusTransitionsFor(statusTarget.value) : []
)

const canUpdateStatus = (revision: ProviderConnectionRevision) =>
  statusTransitionsFor(revision).length > 0

const statusUpdateTitle = (revision: ProviderConnectionRevision) => {
  const transitions = statusTransitionsFor(revision)

  if (transitions.length === 0) {
    return 'A REVOKED revision cannot transition to another status'
  }

  return transitions.includes('DRAINING')
    ? 'Drain or revoke this connection revision'
    : 'Revoke this connection revision'
}

const openStatusUpdate = (revision: ProviderConnectionRevision) => {
  const transitions = statusTransitionsFor(revision)

  if (transitions.length === 0) return

  statusTarget.value = revision
  statusForm.value = { lifecycle_status: transitions[0]!, reason: '' }
  statusError.value = null
  statusOpen.value = true
}

// Public alias management
const aliases = await useSpResource(
  `admin:provider-aliases:${providerId.value}`,
  () => api.admin.providerAliases(providerId.value),
  { server: false }
)

// Create alias form
const createAliasOpen = ref(false)
const creatingAlias = ref(false)
const createAliasForm = ref<ProviderAliasInput>({
  model_id: '',
  public_alias: '',
  display_name: '',
  capabilities: {
    streaming: false,
    tools: false,
    vision: false,
    reasoning: false,
    messages_api: false,
    responses_api: false,
    chat_completions_api: false,
    context_tokens: 200000,
    max_output_tokens: 64000
  },
  limits: {
    requests_per_minute: null,
    tokens_per_minute: null,
    concurrency: null
  },
  enabled: true,
  customer_visible: false
})
const createAliasError = ref<string | null>(null)
const createAliasFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('createAliasFormRef')

const resetCreateAliasForm = () => {
  createAliasForm.value = {
    model_id: '',
    public_alias: '',
    display_name: '',
    capabilities: {
      streaming: false,
      tools: false,
      vision: false,
      reasoning: false,
      messages_api: false,
      responses_api: false,
      chat_completions_api: false,
      context_tokens: 200000,
      max_output_tokens: 64000
    },
    limits: {
      requests_per_minute: null,
      tokens_per_minute: null,
      concurrency: null
    },
    enabled: true,
    customer_visible: false
  }
  createAliasError.value = null
  createAliasFormRef.value?.setErrors([])
}

const _validateCreateAliasForm = (state: typeof createAliasForm.value): FormError[] => {
  const errors: FormError[] = []

  if (!state.public_alias.trim()) {
    errors.push({ name: 'public_alias', message: 'Public alias is required.' })
  } else if (!/^[a-z0-9-]+$/.test(state.public_alias.trim())) {
    errors.push({ name: 'public_alias', message: 'Alias must contain only lowercase letters, numbers, and hyphens.' })
  }

  if (!state.display_name.trim()) {
    errors.push({ name: 'display_name', message: 'Display name is required.' })
  }

  if (state.capabilities.context_tokens < 1) {
    errors.push({ name: 'capabilities.context_tokens', message: 'Context tokens must be at least 1.' })
  }

  if (state.capabilities.max_output_tokens < 1) {
    errors.push({ name: 'capabilities.max_output_tokens', message: 'Max output tokens must be at least 1.' })
  }

  return errors
}

// const submitCreateAlias = async () => {
//   creatingAlias.value = true
//   createAliasError.value = null
//
//   try {
//     const alias = await api.admin.createProviderAlias(providerId.value, {
//       ...createAliasForm.value,
//       public_alias: createAliasForm.value.public_alias.trim().toLowerCase(),
//       display_name: createAliasForm.value.display_name.trim()
//     })
//
//     createAliasOpen.value = false
//     resetCreateAliasForm()
//     await aliases.refresh()
//
//     toast.add({
//       title: 'Alias created',
//       description: `${alias.display_name} has been created successfully.`,
//       color: 'success',
//       icon: 'i-lucide-plus-circle'
//     })
//   } catch (cause) {
//     const error = toSpApiError(cause)
//
//     createAliasFormRef.value?.setErrors(
//       Object.entries(error.errors).map(([name, messages]) => ({
//         name,
//         message: messages[0] ?? 'This value is not valid.'
//       }))
//     )
//
//     createAliasError.value = error.isValidation ? null : error.message
//   } finally {
//     creatingAlias.value = false
//   }
// }

// Model management
const deleteModelTarget = ref<AdminProviderModel | null>(null)
const deletingModel = ref(false)

const confirmDeleteModel = async () => {
  const model = deleteModelTarget.value

  if (!model) {
    return
  }

  deletingModel.value = true

  try {
    await api.admin.deleteProviderModel(providerId.value, model.id)
    await models.refresh()

    toast.add({
      title: 'Model deleted',
      description: `${model.display_name} has been deleted successfully.`,
      color: 'success',
      icon: 'i-lucide-trash-2'
    })
  } catch (cause) {
    toast.add({
      title: 'Could not delete model',
      description: toSpApiError(cause).message,
      color: 'error',
      icon: 'i-lucide-circle-x'
    })
  } finally {
    deletingModel.value = false
    deleteModelTarget.value = null
  }
}

// Alias management
const deleteAliasTarget = ref<AdminProviderAlias | null>(null)
const deletingAlias = ref(false)

// const confirmDeleteAlias = async () => {
//   const alias = deleteAliasTarget.value
//
//   if (!alias) {
//     return
//   }
//
//   deletingAlias.value = true
//
//   try {
//     await api.admin.deleteProviderAlias(providerId.value, alias.id)
//     await aliases.refresh()
//
//     toast.add({
//       title: 'Alias deleted',
//       description: `${alias.display_name} has been deleted successfully.`,
//       color: 'success',
//       icon: 'i-lucide-trash-2'
//     })
//   } catch (cause) {
//     toast.add({
//       title: 'Could not delete alias',
//       description: toSpApiError(cause).message,
//       color: 'error',
//       icon: 'i-lucide-circle-x'
//     })
//   } finally {
//     deletingAlias.value = false
//     deleteAliasTarget.value = null
//   }
// }

const editAliasOpen = ref(false)
const editingAlias = ref(false)
const editAliasForm = ref<ProviderAliasInput>({
  model_id: '',
  public_alias: '',
  display_name: '',
  capabilities: {
    streaming: false,
    tools: false,
    vision: false,
    reasoning: false,
    messages_api: false,
    responses_api: false,
    chat_completions_api: false,
    context_tokens: 200000,
    max_output_tokens: 64000
  },
  limits: {
    requests_per_minute: null,
    tokens_per_minute: null,
    concurrency: null
  },
  enabled: true,
  customer_visible: false
})
const editAliasError = ref<string | null>(null)
const editAliasFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('editAliasFormRef')
const editAliasTarget = ref<AdminProviderAlias | null>(null)

const resetEditAliasForm = () => {
  editAliasForm.value = {
    model_id: '',
    public_alias: '',
    display_name: '',
    capabilities: {
      streaming: false,
      tools: false,
      vision: false,
      reasoning: false,
      messages_api: false,
      responses_api: false,
      chat_completions_api: false,
      context_tokens: 200000,
      max_output_tokens: 64000
    },
    limits: {
      requests_per_minute: null,
      tokens_per_minute: null,
      concurrency: null
    },
    enabled: true,
    customer_visible: false
  }
  editAliasError.value = null
  editAliasFormRef.value?.setErrors([])
}

const openEditAlias = (alias: AdminProviderAlias) => {
  editAliasTarget.value = alias
  editAliasForm.value = {
    model_id: alias.mapped_model_id ?? '',
    public_alias: alias.public_alias,
    display_name: alias.display_name,
    capabilities: { ...alias.capabilities },
    limits: { ...alias.limits },
    enabled: alias.enabled,
    customer_visible: alias.customer_visible
  }
  editAliasError.value = null
  editAliasFormRef.value?.setErrors([])
  editAliasOpen.value = true
}

// const validateEditAliasForm = (state: typeof editAliasForm.value): FormError[] => {
//   const errors: FormError[] = []
//
//   if (!state.display_name.trim()) {
//     errors.push({ name: 'display_name', message: 'Display name is required.' })
//   }
//
//   if (state.capabilities.context_tokens < 1) {
//     errors.push({ name: 'capabilities.context_tokens', message: 'Context tokens must be at least 1.' })
//   }
//
//   if (state.capabilities.max_output_tokens < 1) {
//     errors.push({ name: 'capabilities.max_output_tokens', message: 'Max output tokens must be at least 1.' })
//   }
//
//   return errors
// }

// const submitEditAlias = async () => {
//   if (!editAliasTarget.value) return
//
//   editingAlias.value = true
//   editAliasError.value = null
//
//   try {
//     const updatedAlias = await api.admin.updateProviderAlias(providerId.value, editAliasTarget.value.id, {
//       ...editAliasForm.value,
//       display_name: editAliasForm.value.display_name.trim()
//     })
//
//     editAliasOpen.value = false
//     resetEditAliasForm()
//     await aliases.refresh()
//
//     toast.add({
//       title: 'Alias updated',
//       description: `${updatedAlias.display_name} has been updated successfully.`,
//       color: 'success',
//       icon: 'i-lucide-check-circle'
//     })
//   } catch (cause) {
//     const error = toSpApiError(cause)
//
//     editAliasFormRef.value?.setErrors(
//       Object.entries(error.errors).map(([name, messages]) => ({
//         name,
//         message: messages[0] ?? 'This value is not valid.'
//       }))
//     )
//
//     editAliasError.value = error.isValidation ? null : error.message
//   } finally {
//     editingAlias.value = false
//   }
// }

// Model mapping
const _mapModelOpen = ref(false)
const _mappingModel = ref(false)
const mapModelForm = ref<{ model_id: string }>({
  model_id: ''
})
const mapModelError = ref<string | null>(null)
const mapModelFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('mapModelFormRef')
const _mapModelTarget = ref<AdminProviderAlias | null>(null)

const _resetMapModelForm = () => {
  mapModelForm.value = {
    model_id: ''
  }
  mapModelError.value = null
  mapModelFormRef.value?.setErrors([])
}

// const openMapModel = (alias: AdminProviderAlias) => {
//   mapModelTarget.value = alias
//   mapModelForm.value = {
//     model_id: alias.mapped_model_id || ''
//   }
//   mapModelError.value = null
//   mapModelFormRef.value?.setErrors([])
//   mapModelOpen.value = true
// }

// const validateMapModelForm = (state: typeof mapModelForm.value): FormError[] => {
//   const errors: FormError[] = []
//
//   if (!state.model_id) {
//     errors.push({ name: 'model_id', message: 'Model is required.' })
//   }
//
//   return errors
// }

// const submitMapModel = async () => {
//   if (!mapModelTarget.value) return
//
//   mappingModel.value = true
//   mapModelError.value = null
//
//   try {
//     await api.admin.mapAliasToModel(providerId.value, mapModelTarget.value.id, mapModelForm.value.model_id)
//
//     mapModelOpen.value = false
//     resetMapModelForm()
//     await aliases.refresh()
//
//     toast.add({
//       title: 'Model mapped',
//       description: `Model has been mapped to ${mapModelTarget.value.display_name} successfully.`,
//       color: 'success',
//       icon: 'i-lucide-check-circle'
//     })
//   } catch (cause) {
//     const error = toSpApiError(cause)
//
//     mapModelFormRef.value?.setErrors(
//       Object.entries(error.errors).map(([name, messages]) => ({
//         name,
//         message: messages[0] ?? 'This value is not valid.'
//       }))
//     )
//
//     mapModelError.value = error.isValidation ? null : error.message
//   } finally {
//     mappingModel.value = false
//   }
// }

const editModelOpen = ref(false)
const editingModel = ref(false)
const editModelForm = ref<ProviderModelInput>({
  internal_model_id: '',
  display_name: '',
  commercial_resale_verified: false,
  capabilities: {
    streaming: false,
    tools: false,
    vision: false,
    reasoning: false,
    context_tokens: 200000,
    max_output_tokens: 64000
  },
  limits: {
    requests_per_minute: null,
    tokens_per_minute: null,
    concurrency: null
  }
})
const editModelError = ref<string | null>(null)
const editModelFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('editModelFormRef')
const editModelTarget = ref<AdminProviderModel | null>(null)

const resetEditModelForm = () => {
  editModelForm.value = {
    internal_model_id: '',
    display_name: '',
    commercial_resale_verified: false,
    capabilities: {
      streaming: false,
      tools: false,
      vision: false,
      reasoning: false,
      context_tokens: 200000,
      max_output_tokens: 64000
    },
    limits: {
      requests_per_minute: null,
      tokens_per_minute: null,
      concurrency: null
    }
  }
  editModelError.value = null
  editModelFormRef.value?.setErrors([])
}

const openEditModel = (model: AdminProviderModel) => {
  editModelTarget.value = model
  editModelForm.value = {
    internal_model_id: model.internal_model_id,
    display_name: model.display_name,
    commercial_resale_verified: model.commercial_resale_verified,
    capabilities: { ...model.capabilities },
    limits: { ...model.limits }
  }
  editModelError.value = null
  editModelFormRef.value?.setErrors([])
  editModelOpen.value = true
}

const validateEditModelForm = (state: typeof editModelForm.value): FormError[] => {
  const errors: FormError[] = []

  if (!state.internal_model_id.trim()) {
    errors.push({ name: 'internal_model_id', message: 'Internal model ID is required.' })
  }

  if (!state.display_name.trim()) {
    errors.push({ name: 'display_name', message: 'Display name is required.' })
  }

  if (state.capabilities.context_tokens < 1) {
    errors.push({ name: 'capabilities.context_tokens', message: 'Context tokens must be at least 1.' })
  }

  if (state.capabilities.max_output_tokens < 1) {
    errors.push({ name: 'capabilities.max_output_tokens', message: 'Max output tokens must be at least 1.' })
  }

  return errors
}

const submitEditModel = async () => {
  if (!editModelTarget.value) return

  editingModel.value = true
  editModelError.value = null

  try {
    const updatedModel = await api.admin.updateProviderModel(providerId.value, editModelTarget.value.id, {
      ...editModelForm.value,
      internal_model_id: editModelForm.value.internal_model_id.trim(),
      display_name: editModelForm.value.display_name.trim()
    })

    editModelOpen.value = false
    resetEditModelForm()
    await models.refresh()

    toast.add({
      title: 'Model updated',
      description: `${updatedModel.display_name} has been updated successfully.`,
      color: 'success',
      icon: 'i-lucide-check-circle'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    editModelFormRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    editModelError.value = error.isValidation ? null : error.message
  } finally {
    editingModel.value = false
  }
}

// Private model management
const models = await useSpResource(
  `admin:provider-models:${providerId.value}`,
  () => api.admin.providerModels(providerId.value),
  { server: false }
)

const modelOptions = computed(() => (models.data.value ?? []).map(model => ({
  label: `${model.display_name} — ${model.internal_model_id}`,
  value: model.id
})))

const mappedModel = (alias: AdminProviderAlias) =>
  (models.data.value ?? []).find(model => model.id === alias.mapped_model_id) ?? null

const defaultAliasForModel = (model: AdminProviderModel) => {
  const leaf = model.internal_model_id.split('/').pop() || model.internal_model_id
  return leaf.toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '')
}

const aliasProtocolDefaults = (model: AdminProviderModel) => {
  const id = model.internal_model_id.toLowerCase()
  return {
    messages_api: id.includes('claude'),
    responses_api: id.includes('gpt') || /^o\d/.test(id) || id.includes('/o'),
    chat_completions_api: true
  }
}

const openCreateAliasForModel = (model?: AdminProviderModel) => {
  resetCreateAliasForm()
  if (model) {
    const protocols = aliasProtocolDefaults(model)
    createAliasForm.value = {
      model_id: model.id,
      public_alias: defaultAliasForModel(model),
      display_name: model.display_name,
      capabilities: {
        streaming: model.capabilities.streaming,
        tools: model.capabilities.tools,
        vision: model.capabilities.vision,
        reasoning: model.capabilities.reasoning,
        messages_api: protocols.messages_api,
        responses_api: protocols.responses_api,
        chat_completions_api: protocols.chat_completions_api,
        context_tokens: model.capabilities.context_tokens,
        max_output_tokens: model.capabilities.max_output_tokens
      },
      limits: { ...model.limits },
      enabled: true,
      customer_visible: false
    }
  }
  createAliasOpen.value = true
}

const normalizeNullablePositiveInt = (value: unknown): number | null => {
  if (value === null || value === undefined || value === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? Math.trunc(parsed) : null
}

const normalizedAliasPayload = (state: ProviderAliasInput): ProviderAliasInput => ({
  ...state,
  model_id: String(state.model_id),
  public_alias: state.public_alias.trim().toLowerCase(),
  display_name: state.display_name.trim(),
  capabilities: {
    ...state.capabilities,
    context_tokens: Number(state.capabilities.context_tokens),
    max_output_tokens: Number(state.capabilities.max_output_tokens)
  },
  limits: {
    requests_per_minute: normalizeNullablePositiveInt(state.limits.requests_per_minute),
    tokens_per_minute: normalizeNullablePositiveInt(state.limits.tokens_per_minute),
    concurrency: normalizeNullablePositiveInt(state.limits.concurrency)
  }
})

const validateAliasForm = (state: ProviderAliasInput): FormError[] => {
  const errors: FormError[] = []
  if (!state.model_id) errors.push({ name: 'model_id', message: 'Choose the private model this alias routes to.' })
  if (!state.public_alias.trim()) {
    errors.push({ name: 'public_alias', message: 'Public alias is required.' })
  } else if (!/^[a-z0-9][a-z0-9._-]*$/.test(state.public_alias.trim())) {
    errors.push({ name: 'public_alias', message: 'Use lowercase letters, numbers, dots, underscores or hyphens.' })
  }
  if (!state.display_name.trim()) errors.push({ name: 'display_name', message: 'Display name is required.' })
  if (!Number.isInteger(Number(state.capabilities.context_tokens)) || Number(state.capabilities.context_tokens) < 1) errors.push({ name: 'capabilities.context_tokens', message: 'Context tokens must be a positive whole number.' })
  if (!Number.isInteger(Number(state.capabilities.max_output_tokens)) || Number(state.capabilities.max_output_tokens) < 1) errors.push({ name: 'capabilities.max_output_tokens', message: 'Max output tokens must be a positive whole number.' })
  if (!state.capabilities.messages_api && !state.capabilities.responses_api && !state.capabilities.chat_completions_api) {
    errors.push({ name: 'capabilities.messages_api', message: 'Enable at least one API protocol.' })
  }
  return errors
}

const submitCreateAlias = async () => {
  creatingAlias.value = true
  createAliasError.value = null
  try {
    const alias = await api.admin.createProviderAlias(
      providerId.value,
      normalizedAliasPayload(createAliasForm.value)
    )
    createAliasOpen.value = false
    await aliases.refresh()
    toast.add({
      title: 'Public model created',
      description: `${alias.public_alias} can now be priced and assigned to packages.`,
      color: 'success',
      icon: 'i-lucide-route'
    })
  } catch (cause) {
    const error = toSpApiError(cause)
    createAliasFormRef.value?.setErrors(Object.entries(error.errors).map(([name, messages]) => ({
      name,
      message: messages[0] ?? 'This value is not valid.'
    })))
    createAliasError.value = error.isValidation ? null : error.message
  } finally {
    creatingAlias.value = false
  }
}

const submitEditAlias = async () => {
  if (!editAliasTarget.value || editingAlias.value) return

  const clientErrors = validateAliasForm(editAliasForm.value)
  editAliasFormRef.value?.setErrors(clientErrors)
  if (clientErrors.length > 0) {
    editAliasError.value = 'Review the highlighted public-model fields before saving.'
    return
  }

  editingAlias.value = true
  editAliasError.value = null

  try {
    const updated = await api.admin.updateProviderAlias(
      providerId.value,
      editAliasTarget.value.id,
      normalizedAliasPayload(editAliasForm.value)
    )

    await Promise.all([aliases.refresh(), models.refresh()])
    editAliasOpen.value = false
    editAliasTarget.value = null
    resetEditAliasForm()
    toast.add({
      title: 'Public model updated',
      description: `${updated.public_alias} was saved successfully.`,
      color: 'success',
      icon: 'i-lucide-check-circle'
    })
  } catch (cause) {
    const error = toSpApiError(cause)
    editAliasFormRef.value?.setErrors(Object.entries(error.errors).map(([name, messages]) => ({
      name,
      message: messages[0] ?? 'This value is not valid.'
    })))
    editAliasError.value = error.message
  } finally {
    editingAlias.value = false
  }
}

const confirmDeleteAlias = async () => {
  const alias = deleteAliasTarget.value
  if (!alias) return
  deletingAlias.value = true
  try {
    await api.admin.deleteProviderAlias(providerId.value, alias.id)
    deleteAliasTarget.value = null
    await aliases.refresh()
    toast.add({ title: 'Public model deleted', description: `${alias.public_alias} was deleted.`, color: 'success', icon: 'i-lucide-trash-2' })
  } catch (cause) {
    toast.add({ title: 'Could not delete public model', description: toSpApiError(cause).message, color: 'error', icon: 'i-lucide-circle-x' })
  } finally {
    deletingAlias.value = false
  }
}

const publishAliasTarget = ref<AdminProviderAlias | null>(null)
const publishingAlias = ref(false)
const publishAliasError = ref<string | null>(null)
const confirmResale = ref(false)

const openPublishAlias = (alias: AdminProviderAlias) => {
  publishAliasTarget.value = alias
  publishAliasError.value = null
  confirmResale.value = false
}

const closePublishAlias = () => {
  if (publishingAlias.value) return
  publishAliasTarget.value = null
  publishAliasError.value = null
  confirmResale.value = false
}

const confirmPublishAlias = async () => {
  const alias = publishAliasTarget.value
  if (!alias || !confirmResale.value) return

  publishingAlias.value = true
  publishAliasError.value = null
  try {
    const published = await api.admin.publishProviderAlias(providerId.value, alias.id, {
      confirm_commercial_resale: true
    })
    publishAliasTarget.value = null
    confirmResale.value = false
    await Promise.all([provider.refresh(), revisions.refresh(), models.refresh(), aliases.refresh()])
    toast.add({
      title: 'Model is ready for sale',
      description: `${published.public_alias} is enabled, customer-visible and routed through an active READY connection.`,
      color: 'success',
      icon: 'i-lucide-badge-check'
    })
  } catch (cause) {
    publishAliasError.value = toSpApiError(cause).message
  } finally {
    publishingAlias.value = false
  }
}

const discoverOpen = ref(false)
const discoveringModels = ref(false)
const importingModels = ref(false)
const discoveredModels = ref<DiscoveredProviderModel[]>([])
const selectedDiscoveredModelIds = ref<string[]>([])
const createPublicAliasesOnImport = ref(true)
const discoverError = ref<string | null>(null)

const discoverSelectableModels = computed(() => discoveredModels.value.filter(model =>
  !model.already_registered || (createPublicAliasesOnImport.value && !model.has_public_alias)
))

const isDiscoveredModelSelectable = (model: DiscoveredProviderModel) =>
  !model.already_registered || (createPublicAliasesOnImport.value && !model.has_public_alias)

const selectAllDiscoveredModels = () => {
  selectedDiscoveredModelIds.value = discoverSelectableModels.value.map(model => model.internal_model_id)
}

const unselectAllDiscoveredModels = () => {
  selectedDiscoveredModelIds.value = []
}

watch(createPublicAliasesOnImport, (createAliases) => {
  if (createAliases) {
    return
  }

  const newModelIds = new Set(discoveredModels.value
    .filter(model => !model.already_registered)
    .map(model => model.internal_model_id))
  selectedDiscoveredModelIds.value = selectedDiscoveredModelIds.value.filter(id => newModelIds.has(id))
})

const discoverProviderModels = async () => {
  discoveringModels.value = true
  discoverError.value = null
  try {
    discoveredModels.value = await api.admin.discoverProviderModels(providerId.value)
    createPublicAliasesOnImport.value = true
    selectAllDiscoveredModels()
    discoverOpen.value = true
  } catch (cause) {
    discoverError.value = toSpApiError(cause).message
    toast.add({ title: 'Could not discover provider models', description: discoverError.value, color: 'error', icon: 'i-lucide-circle-x' })
  } finally {
    discoveringModels.value = false
  }
}

const toggleDiscoveredModel = (modelId: string, checked: boolean) => {
  if (checked) {
    if (!selectedDiscoveredModelIds.value.includes(modelId)) selectedDiscoveredModelIds.value.push(modelId)
  } else {
    selectedDiscoveredModelIds.value = selectedDiscoveredModelIds.value.filter(id => id !== modelId)
  }
}

const importDiscoveredModels = async () => {
  if (selectedDiscoveredModelIds.value.length === 0) return
  importingModels.value = true
  discoverError.value = null
  try {
    const result = await api.admin.importProviderModels(
      providerId.value,
      selectedDiscoveredModelIds.value,
      createPublicAliasesOnImport.value
    )
    await Promise.all([models.refresh(), aliases.refresh()])
    discoverOpen.value = false
    const aliasSummary = createPublicAliasesOnImport.value
      ? ` ${result.public_aliases_created.length} public alias${result.public_aliases_created.length === 1 ? '' : 'es'} created so the models appear in Packages.`
      : ''
    toast.add({
      title: 'Provider models imported',
      description: `${result.created.length} new private model${result.created.length === 1 ? '' : 's'} added.${aliasSummary} Review capabilities, API protocols and resale verification before publishing for sale.`,
      color: 'success',
      icon: 'i-lucide-download'
    })
  } catch (cause) {
    discoverError.value = toSpApiError(cause).message
  } finally {
    importingModels.value = false
  }
}

// Create model form
const createModelOpen = ref(false)
const creatingModel = ref(false)
const createModelForm = ref<ProviderModelInput>({
  internal_model_id: '',
  display_name: '',
  commercial_resale_verified: false,
  capabilities: {
    streaming: false,
    tools: false,
    vision: false,
    reasoning: false,
    context_tokens: 200000,
    max_output_tokens: 64000
  },
  limits: {
    requests_per_minute: null,
    tokens_per_minute: null,
    concurrency: null
  }
})
const createModelError = ref<string | null>(null)
const createModelFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('createModelFormRef')

const resetCreateModelForm = () => {
  createModelForm.value = {
    internal_model_id: '',
    display_name: '',
    commercial_resale_verified: false,
    capabilities: {
      streaming: false,
      tools: false,
      vision: false,
      reasoning: false,
      context_tokens: 200000,
      max_output_tokens: 64000
    },
    limits: {
      requests_per_minute: null,
      tokens_per_minute: null,
      concurrency: null
    }
  }
  createModelError.value = null
  createModelFormRef.value?.setErrors([])
}

const validateCreateModelForm = (state: typeof createModelForm.value): FormError[] => {
  const errors: FormError[] = []

  if (!state.internal_model_id.trim()) {
    errors.push({ name: 'internal_model_id', message: 'Internal model ID is required.' })
  }

  if (!state.display_name.trim()) {
    errors.push({ name: 'display_name', message: 'Display name is required.' })
  }

  if (state.capabilities.context_tokens < 1) {
    errors.push({ name: 'capabilities.context_tokens', message: 'Context tokens must be at least 1.' })
  }

  if (state.capabilities.max_output_tokens < 1) {
    errors.push({ name: 'capabilities.max_output_tokens', message: 'Max output tokens must be at least 1.' })
  }

  return errors
}

const submitCreateModel = async () => {
  creatingModel.value = true
  createModelError.value = null

  try {
    const model = await api.admin.createProviderModel(providerId.value, {
      ...createModelForm.value,
      internal_model_id: createModelForm.value.internal_model_id.trim(),
      display_name: createModelForm.value.display_name.trim()
    })

    createModelOpen.value = false
    resetCreateModelForm()
    await models.refresh()

    toast.add({
      title: 'Model created',
      description: `${model.display_name} has been created successfully.`,
      color: 'success',
      icon: 'i-lucide-plus-circle'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    createModelFormRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    createModelError.value = error.isValidation ? null : error.message
  } finally {
    creatingModel.value = false
  }
}

const validateStatusForm = (state: ProviderConnectionStatusUpdateInput): FormError[] => {
  const errors: FormError[] = []

  if (!state.reason.trim()) {
    errors.push({ name: 'reason', message: 'Reason is required.' })
  } else if (state.reason.trim().length < 10) {
    errors.push({ name: 'reason', message: 'Reason must be at least 10 characters.' })
  } else if (state.reason.trim().length > 2000) {
    errors.push({ name: 'reason', message: 'Reason must be 2000 characters or fewer.' })
  }

  return errors
}

const submitStatusUpdate = async () => {
  if (!statusTarget.value) return

  updatingStatus.value = true
  statusError.value = null

  try {
    const updatedRevision = await api.admin.updateProviderConnectionRevisionStatus(
      providerId.value,
      statusTarget.value.id,
      {
        lifecycle_status: statusForm.value.lifecycle_status,
        reason: statusForm.value.reason.trim()
      }
    )

    statusOpen.value = false
    statusTarget.value = null
    await revisions.refresh()

    toast.add({
      title: 'Status updated',
      description: `Status for revision ${updatedRevision.route_version} has been updated to ${updatedRevision.lifecycle_status}.`,
      color: 'success',
      icon: 'i-lucide-check-circle'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    statusFormRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    statusError.value = error.isValidation ? null : error.message
  } finally {
    updatingStatus.value = false
  }
}

// Status badge component
const statusBadge = (status: string) => {
  switch (status) {
    case 'PENDING':
      return { color: 'gray', label: 'Pending' }
    case 'READY':
      return { color: 'green', label: 'Ready' }
    case 'DRAINING':
      return { color: 'yellow', label: 'Draining' }
    case 'REVOKED':
      return { color: 'red', label: 'Revoked' }
    default:
      return { color: 'gray', label: status }
  }
}

useSeoMeta({
  title: () => provider.data.value ? `${provider.data.value.name} — Provider` : 'Provider',
  description: 'Manage connection revisions for this provider.',
  robots: 'noindex, nofollow'
})
</script>

<template>
  <SpDashboardPage
    :title="provider.data.value?.name ?? 'Provider'"
    icon="i-lucide-server"
    :description="provider.data.value ? `Manage connection revisions for ${provider.data.value.name}` : 'Provider details'"
  >
    <template #actions>
      <UButton
        to="/admin/providers"
        color="neutral"
        variant="ghost"
        icon="i-lucide-arrow-left"
      >
        All providers
      </UButton>
    </template>

    <SpStateForbidden
      v-if="provider.forbidden.value"
      :code="provider.error.value?.code ?? null"
      permission="catalog.manage"
    />

    <SpAsyncSection
      :loading="provider.initialLoading.value"
      :unavailable="provider.unavailable.value"
      :failed="provider.failed.value"
      :empty="provider.data.value === null"
      :offline="provider.error.value?.code === 'network_unreachable'"
      :error-message="provider.error.value?.message"
      error-title="Provider could not be loaded"
      unavailable-title="Provider is not available"
      unavailable-description="SP Cambo could not be reached, so this provider cannot be managed right now."
      empty-title="Provider not found"
      empty-description="The requested provider could not be found."
      empty-icon="i-lucide-server-off"
      loading-variant="rows"
      @retry="provider.refresh()"
    >
      <div
        v-if="provider.data.value"
        class="space-y-4"
      >
        <!-- Provider summary -->
        <section class="rounded-lg border border-default bg-elevated/30 p-4 sm:p-5">
          <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">
                Status
              </dt>
              <dd>
                <UBadge
                  :color="provider.data.value?.enabled ? 'success' : 'neutral'"
                  variant="subtle"
                  size="sm"
                >
                  {{ provider.data.value.enabled ? 'Enabled' : 'Disabled' }}
                </UBadge>
              </dd>
            </div>
            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">
                Slug
              </dt>
              <dd class="truncate text-sm text-default">
                {{ provider.data.value.slug }}
              </dd>
            </div>
            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">
                Created
              </dt>
              <dd class="text-sm text-default">
                {{ formatDate(provider.data.value.created_at) }}
              </dd>
            </div>
            <div class="space-y-1.5">
              <dt class="text-xs text-dimmed">
                Active connection
              </dt>
              <dd class="text-sm text-default">
                {{ provider.data.value.active_connection_revision_id ? 'Yes' : 'No' }}
              </dd>
            </div>
          </dl>
        </section>

        <UAlert
          v-if="!provider.data.value.active_connection_revision_id"
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="No active provider connection"
          description="Customer requests cannot route until a successfully probed READY revision is active. Existing READY rows created before the auto-activation fix can be repaired here."
        >
          <template #actions>
            <UButton
              v-if="readyRevisionWithoutActive"
              color="warning"
              variant="subtle"
              size="sm"
              icon="i-lucide-circle-check-big"
              @click="openBestReadyRevision"
            >
              Activate READY revision
            </UButton>
          </template>
        </UAlert>

        <!-- Connection revisions -->
        <section class="space-y-4">
          <SpSectionHeading
            :level="3"
            title="Connection revisions"
            description="Manage connection revisions for this provider."
          >
            <template #actions>
              <UButton
                icon="i-lucide-plus"
                size="sm"
                @click="resetCreateForm(); createOpen = true"
              >
                New revision
              </UButton>
            </template>
          </SpSectionHeading>

          <SpAsyncSection
            :loading="revisions.initialLoading.value"
            :unavailable="revisions.unavailable.value"
            :failed="revisions.failed.value"
            :empty="revisions.isEmpty.value"
            :offline="revisions.error.value?.code === 'network_unreachable'"
            :error-message="revisions.error.value?.message"
            error-title="Connection revisions could not be loaded"
            unavailable-title="Connection revisions are not available"
            unavailable-description="SP Cambo could not be reached, so connection revisions cannot be managed right now."
            empty-title="No connection revisions"
            empty-description="Create a connection revision to configure this provider's connection settings."
            empty-icon="i-lucide-link-off"
            loading-variant="rows"
            @retry="revisions.refresh()"
          >
            <ul class="space-y-3">
              <li
                v-for="revision in revisions.data.value"
                :key="revision.id"
                class="rounded-lg border border-default bg-elevated/30 p-4"
              >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                      <p class="truncate font-medium text-highlighted">
                        Revision {{ revision.route_version }}
                      </p>
                      <UBadge
                        :color="statusBadge(revision.lifecycle_status as any).color as any"
                        variant="subtle"
                        size="sm"
                      >
                        {{ statusBadge(revision.lifecycle_status as any).label }}
                      </UBadge>
                      <UBadge
                        v-if="provider.data.value?.active_connection_revision_id === revision.id"
                        color="primary"
                        variant="subtle"
                        size="sm"
                      >
                        Active
                      </UBadge>
                    </div>

                    <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Origin
                        </dt>
                        <dd class="truncate max-w-xs">
                          {{ revision.origin }}
                        </dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Type
                        </dt>
                        <dd>{{ revision.connection_type }}</dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Timeout
                        </dt>
                        <dd>{{ revision.timeout_ms }}ms</dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Policy version
                        </dt>
                        <dd>{{ revision.policy_version }}</dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Created
                        </dt>
                        <dd>{{ formatDate(revision.created_at) }}</dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Last probe
                        </dt>
                        <dd>{{ revision.last_probe_at ? formatDateTime(revision.last_probe_at) : 'Never' }}</dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Probe status
                        </dt>
                        <dd>{{ revision.last_probe_status || 'N/A' }}</dd>
                      </div>
                    </dl>

                    <div class="flex flex-wrap items-center gap-1.5">
                      <span class="text-xs text-dimmed">Credential</span>
                      <span class="text-xs text-muted">
                        {{ revision.credential_suffix || 'Not configured' }}
                      </span>
                    </div>
                  </div>

                  <div class="flex flex-col gap-2 sm:items-end">
                    <div class="flex gap-2">
                      <UButton
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        icon="i-lucide-refresh-cw"
                        :loading="probing && probeError === null"
                        @click="probeRevision(revision)"
                      >
                        Probe
                      </UButton>
                      <UButton
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        icon="i-lucide-check-circle"
                        :disabled="!canSetActive(revision)"
                        :title="setActiveTitle(revision)"
                        @click="openSetActive(revision)"
                      >
                        Set active
                      </UButton>
                      <UButton
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        icon="i-lucide-settings"
                        :disabled="!canUpdateStatus(revision)"
                        :title="statusUpdateTitle(revision)"
                        @click="openStatusUpdate(revision)"
                      >
                        Update status
                      </UButton>
                      <UButton
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        icon="i-lucide-pencil"
                        :disabled="!canEditRevision(revision)"
                        :title="canEditRevision(revision) ? 'Edit this unused pending revision' : 'Only unused PENDING revisions can be edited'"
                        @click="openEditRevision(revision)"
                      >
                        Edit
                      </UButton>
                      <UButton
                        color="error"
                        variant="ghost"
                        size="sm"
                        icon="i-lucide-trash-2"
                        :disabled="provider.data.value?.active_connection_revision_id === revision.id"
                        :title="provider.data.value?.active_connection_revision_id === revision.id ? 'The active revision cannot be deleted' : 'Delete this unused revision'"
                        @click="openDeleteRevision(revision)"
                      >
                        Delete
                      </UButton>
                    </div>
                  </div>
                </div>
              </li>
            </ul>
          </SpAsyncSection>
        </section>

        <!-- Private models -->
        <section class="space-y-4">
          <SpSectionHeading
            :level="3"
            title="Private models"
            description="Manage private models for this provider."
          >
            <template #actions>
              <UButton
                color="neutral"
                variant="subtle"
                icon="i-lucide-scan-search"
                size="sm"
                :loading="discoveringModels"
                :disabled="!provider.data.value?.active_connection_revision_id"
                @click="discoverProviderModels"
              >
                Discover upstream
              </UButton>
              <UButton
                icon="i-lucide-plus"
                size="sm"
                @click="resetCreateModelForm(); createModelOpen = true"
              >
                New model
              </UButton>
            </template>
          </SpSectionHeading>

          <SpAsyncSection
            :loading="models.initialLoading.value"
            :unavailable="models.unavailable.value"
            :failed="models.failed.value"
            :empty="models.isEmpty.value"
            :offline="models.error.value?.code === 'network_unreachable'"
            :error-message="models.error.value?.message"
            error-title="Models could not be loaded"
            unavailable-title="Models are not available"
            unavailable-description="SP Cambo could not be reached, so models cannot be managed right now."
            empty-title="No models"
            empty-description="Create a model to manage its capabilities and limits."
            empty-icon="i-lucide-box"
            loading-variant="rows"
            @retry="models.refresh()"
          >
            <ul class="space-y-3">
              <li
                v-for="model in models.data.value"
                :key="model.id"
                class="rounded-lg border border-default bg-elevated/30 p-4"
              >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                      <p class="truncate font-medium text-highlighted">
                        {{ model.display_name }}
                      </p>
                      <UBadge
                        :color="model.commercial_resale_verified ? 'success' : 'warning'"
                        variant="subtle"
                        size="sm"
                      >
                        {{ model.commercial_resale_verified ? 'Resale verified' : 'Resale not verified' }}
                      </UBadge>
                      <UBadge
                        color="neutral"
                        variant="subtle"
                        size="sm"
                      >
                        {{ model.alias_count }} public alias{{ model.alias_count === 1 ? '' : 'es' }}
                      </UBadge>
                    </div>

                    <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Internal ID
                        </dt>
                        <dd>{{ model.internal_model_id }}</dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Created
                        </dt>
                        <dd>{{ formatDate(model.created_at) }}</dd>
                      </div>
                    </dl>
                  </div>

                  <div class="flex flex-col gap-2 sm:items-end">
                    <div class="flex flex-wrap gap-2">
                      <UButton
                        color="primary"
                        variant="subtle"
                        size="sm"
                        icon="i-lucide-route"
                        @click="openCreateAliasForModel(model)"
                      >
                        Public alias
                      </UButton>
                      <UButton
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        icon="i-lucide-pencil"
                        @click="openEditModel(model)"
                      >
                        Edit
                      </UButton>
                      <UButton
                        color="error"
                        variant="subtle"
                        size="sm"
                        icon="i-lucide-trash-2"
                        @click="deleteModelTarget = model"
                      >
                        Delete
                      </UButton>
                    </div>
                  </div>
                </div>
              </li>
            </ul>
          </SpAsyncSection>
        </section>

        <!-- Public model aliases -->
        <section class="space-y-4">
          <SpSectionHeading
            :level="3"
            title="Public models"
            description="Customer-facing aliases map private upstream models to stable names such as claude-opus-5. Pricing and packages use these aliases, not the private model IDs."
          >
            <template #actions>
              <UButton
                to="/admin/model-aliases"
                color="neutral"
                variant="subtle"
                icon="i-lucide-dollar-sign"
                size="sm"
              >
                Model pricing
              </UButton>
              <UButton
                icon="i-lucide-plus"
                size="sm"
                :disabled="models.isEmpty.value"
                @click="openCreateAliasForModel()"
              >
                New public model
              </UButton>
            </template>
          </SpSectionHeading>

          <UAlert
            v-if="!models.isEmpty.value && aliases.isEmpty.value"
            color="info"
            variant="subtle"
            icon="i-lucide-info"
            title="Private models are not customer models yet"
            description="Create at least one public alias below. After that it will appear automatically on Model pricing, Packages, Playground settings, redeem codes and API-key model selection."
          />

          <SpAsyncSection
            :loading="aliases.initialLoading.value"
            :unavailable="aliases.unavailable.value"
            :failed="aliases.failed.value"
            :empty="aliases.isEmpty.value"
            :offline="aliases.error.value?.code === 'network_unreachable'"
            :error-message="aliases.error.value?.message"
            error-title="Public models could not be loaded"
            empty-title="No public models"
            empty-description="Choose Public alias on a private model, or create a new public model here."
            empty-icon="i-lucide-route"
            loading-variant="rows"
            @retry="aliases.refresh()"
          >
            <ul class="space-y-3">
              <li
                v-for="alias in aliases.data.value"
                :key="alias.id"
                class="rounded-lg border border-default bg-elevated/30 p-4"
              >
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                  <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                      <p class="font-medium text-highlighted">
                        {{ alias.display_name }}
                      </p>
                      <code class="rounded bg-muted/40 px-2 py-0.5 font-mono text-xs">{{ alias.public_alias }}</code>
                      <UBadge
                        :color="alias.enabled ? 'success' : 'neutral'"
                        variant="subtle"
                        size="sm"
                      >
                        {{ alias.enabled ? 'Enabled' : 'Disabled' }}
                      </UBadge>
                      <UBadge
                        :color="alias.customer_visible ? 'success' : 'warning'"
                        variant="subtle"
                        size="sm"
                      >
                        {{ alias.customer_visible ? 'Customer visible' : 'Hidden' }}
                      </UBadge>
                      <UBadge
                        :color="alias.publication_ready ? 'success' : 'warning'"
                        variant="subtle"
                        size="sm"
                      >
                        {{ alias.publication_ready ? 'Route ready' : 'Publication blocked' }}
                      </UBadge>
                    </div>

                    <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Routes to
                        </dt>
                        <dd>{{ mappedModel(alias)?.display_name ?? 'Missing model' }}</dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Internal ID
                        </dt>
                        <dd class="font-mono">
                          {{ mappedModel(alias)?.internal_model_id ?? '—' }}
                        </dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Context
                        </dt>
                        <dd>{{ formatCount(alias.capabilities.context_tokens) }}</dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Max output
                        </dt>
                        <dd>{{ formatCount(alias.capabilities.max_output_tokens) }}</dd>
                      </div>
                    </dl>

                    <UAlert
                      v-if="!alias.publication_ready"
                      color="warning"
                      variant="subtle"
                      icon="i-lucide-triangle-alert"
                      :title="alias.publication_blockers[0] || 'Public model is not ready'"
                      :description="alias.publication_blockers.slice(1).join(' ') || undefined"
                    />

                    <div class="flex flex-wrap gap-1.5">
                      <UBadge
                        v-if="alias.capabilities.messages_api"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                      >
                        Anthropic Messages
                      </UBadge>
                      <UBadge
                        v-if="alias.capabilities.chat_completions_api"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                      >
                        Chat Completions
                      </UBadge>
                      <UBadge
                        v-if="alias.capabilities.responses_api"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                      >
                        Responses
                      </UBadge>
                      <UBadge
                        v-if="alias.capabilities.streaming"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                      >
                        Streaming
                      </UBadge>
                      <UBadge
                        v-if="alias.capabilities.tools"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                      >
                        Tools
                      </UBadge>
                      <UBadge
                        v-if="alias.capabilities.reasoning"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                      >
                        Reasoning
                      </UBadge>
                    </div>
                  </div>

                  <div class="flex flex-wrap gap-2">
                    <UButton
                      v-if="!alias.publication_ready"
                      color="primary"
                      variant="subtle"
                      size="sm"
                      icon="i-lucide-store"
                      @click="openPublishAlias(alias)"
                    >
                      Publish for sale
                    </UButton>
                    <UButton
                      to="/admin/model-aliases"
                      color="neutral"
                      variant="subtle"
                      size="sm"
                      icon="i-lucide-dollar-sign"
                    >
                      Pricing
                    </UButton>
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="sm"
                      icon="i-lucide-pencil"
                      @click="openEditAlias(alias)"
                    >
                      Edit
                    </UButton>
                    <UButton
                      color="error"
                      variant="ghost"
                      size="sm"
                      icon="i-lucide-trash-2"
                      @click="deleteAliasTarget = alias"
                    >
                      Delete
                    </UButton>
                  </div>
                </div>
              </li>
            </ul>
          </SpAsyncSection>
        </section>
      </div>
    </SpAsyncSection>

    <!-- Create connection revision modal -->
    <UModal
      v-model:open="createOpen"
      title="Create new connection revision"
      description="Configure a new connection revision for this provider."
    >
      <template #body>
        <UForm
          ref="createFormRef"
          :state="createForm"
          :validate="validateCreateForm"
          :validate-on="['blur', 'change']"
          class="space-y-5"
          @submit="submitCreate"
        >
          <UAlert
            v-if="createError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="createError"
          />

          <UFormField
            label="Route version"
            name="route_version"
            required
            help="The version of the routing configuration."
          >
            <UInput
              v-model="createForm.route_version"
              type="number"
              min="1"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Origin URL"
            name="origin"
            required
            help="The base URL for the provider's API (e.g., https://api.omniroute.example)."
          >
            <UInput
              v-model="createForm.origin"
              placeholder="https://api.omniroute.example"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Connection type"
            name="connection_type"
            required
            help="The type of connection to use."
          >
            <USelectMenu
              v-model="createForm.connection_type"
              :items="['omniroute', 'openai_compatible']"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Credential"
            name="credential"
            required
            help="The secret credential for accessing the provider's API."
          >
            <UInput
              v-model="createForm.credential"
              type="password"
              placeholder="••••••••"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Timeout (ms)"
            name="timeout_ms"
            required
            help="The timeout in milliseconds for API requests (1000-60000)."
          >
            <UInput
              v-model="createForm.timeout_ms"
              type="number"
              min="1000"
              max="60000"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Policy version"
            name="policy_version"
            help="The version of the header policy to use."
          >
            <UInput
              v-model="createForm.policy_version"
              type="number"
              min="1"
              class="w-full"
            />
          </UFormField>

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="creating"
              @click="createOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :loading="creating"
            >
              Create revision
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Edit connection revision modal -->
    <UModal
      v-model:open="editRevisionOpen"
      title="Edit connection revision"
      description="Only unused PENDING revisions can be changed. Leave credential blank to keep the existing secret."
    >
      <template #body>
        <UForm
          ref="editRevisionFormRef"
          :state="editRevisionForm"
          :validate="validateEditRevisionForm"
          :validate-on="['blur', 'change']"
          class="space-y-5"
          @submit="submitEditRevision"
        >
          <UAlert
            v-if="editRevisionError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="editRevisionError"
          />

          <UFormField
            label="Route version"
            name="route_version"
            required
          >
            <UInput
              v-model="editRevisionForm.route_version"
              type="number"
              min="1"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Origin URL"
            name="origin"
            required
          >
            <UInput
              v-model="editRevisionForm.origin"
              placeholder="https://api.omniroute.example"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Connection type"
            name="connection_type"
            required
          >
            <USelectMenu
              v-model="editRevisionForm.connection_type"
              :items="['omniroute', 'openai_compatible']"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Replacement credential"
            name="credential"
            :help="editRevisionTarget?.credential_configured
              ? `Current credential is ${editRevisionTarget.credential_suffix || 'configured'}. Leave blank to keep it.`
              : 'Leave blank to keep the current credential.'"
          >
            <UInput
              v-model="editRevisionForm.credential"
              type="password"
              placeholder="Leave blank to keep existing"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Timeout (ms)"
            name="timeout_ms"
            required
          >
            <UInput
              v-model="editRevisionForm.timeout_ms"
              type="number"
              min="1000"
              max="60000"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Policy version"
            name="policy_version"
          >
            <UInput
              v-model="editRevisionForm.policy_version"
              type="number"
              min="1"
              class="w-full"
            />
          </UFormField>

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="editingRevision"
              @click="editRevisionOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :loading="editingRevision"
            >
              Save changes
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Delete connection revision confirmation -->
    <UModal
      :open="deleteRevisionTarget !== null"
      title="Delete connection revision?"
      description="Only non-active revisions without request history can be deleted."
      @update:open="(open) => { if (!open && !deletingRevision) deleteRevisionTarget = null }"
    >
      <template #body>
        <div class="space-y-4">
          <UAlert
            v-if="deleteRevisionError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="deleteRevisionError"
          />

          <p class="text-sm text-muted">
            Delete <strong class="text-highlighted">Revision {{ deleteRevisionTarget?.route_version }}</strong>?
            SP Cambo will refuse this operation if the revision is active or has request history.
          </p>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="deletingRevision"
              @click="deleteRevisionTarget = null"
            >
              Cancel
            </UButton>
            <UButton
              color="error"
              icon="i-lucide-trash-2"
              :loading="deletingRevision"
              @click="confirmDeleteRevision"
            >
              Delete revision
            </UButton>
          </div>
        </div>
      </template>
    </UModal>

    <!-- Create model modal -->
    <UModal
      v-model:open="createModelOpen"
      title="Create new model"
      description="Configure a new private model for this provider."
    >
      <template #body>
        <UForm
          ref="createModelFormRef"
          :state="createModelForm"
          :validate="validateCreateModelForm"
          :validate-on="['blur', 'change']"
          class="space-y-5"
          @submit="submitCreateModel"
        >
          <UAlert
            v-if="createModelError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="createModelError"
          />

          <UFormField
            label="Internal model ID"
            name="internal_model_id"
            required
            help="Exact model name/ID accepted by OmniRoute. For this catalog keep OpenAI Codex and Gemini Google AI Studio exactly. SP Cambo backend does not read this from ANTHROPIC_MODEL; customer aliases are configured separately."
          >
            <UInput
              v-model="createModelForm.internal_model_id"
              placeholder="OpenAI Codex"
              autofocus
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Display name"
            name="display_name"
            required
            help="Admin-friendly name for this private upstream model. The customer-facing name is configured on the public model alias."
          >
            <UInput
              v-model="createModelForm.display_name"
              placeholder="Full Gemini Pro"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Commercial resale verified"
            name="commercial_resale_verified"
            help="Turn this on only after you have confirmed the upstream/provider terms allow you to resell access to this model. Customer catalog publication requires this verification."
          >
            <USwitch v-model="createModelForm.commercial_resale_verified" />
          </UFormField>

          <div class="space-y-3">
            <SpSectionHeading
              title="Capabilities"
              description="Configure the capabilities of this model."
              :level="3"
            />

            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                label="Streaming"
                name="capabilities.streaming"
              >
                <USwitch v-model="createModelForm.capabilities.streaming" />
              </UFormField>
              <UFormField
                label="Tools"
                name="capabilities.tools"
              >
                <USwitch v-model="createModelForm.capabilities.tools" />
              </UFormField>
              <UFormField
                label="Vision"
                name="capabilities.vision"
              >
                <USwitch v-model="createModelForm.capabilities.vision" />
              </UFormField>
              <UFormField
                label="Reasoning"
                name="capabilities.reasoning"
              >
                <USwitch v-model="createModelForm.capabilities.reasoning" />
              </UFormField>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                label="Context tokens"
                name="capabilities.context_tokens"
                required
              >
                <UInput
                  v-model="createModelForm.capabilities.context_tokens"
                  type="number"
                  min="1"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Max output tokens"
                name="capabilities.max_output_tokens"
                required
              >
                <UInput
                  v-model="createModelForm.capabilities.max_output_tokens"
                  type="number"
                  min="1"
                  class="w-full"
                />
              </UFormField>
            </div>
          </div>

          <div class="space-y-3">
            <SpSectionHeading
              title="Limits"
              description="Configure the rate limits for this model."
              :level="3"
            />

            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                label="Requests per minute"
                name="limits.requests_per_minute"
              >
                <UInput
                  v-model="createModelForm.limits.requests_per_minute"
                  type="number"
                  min="0"
                  placeholder="No limit"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Tokens per minute"
                name="limits.tokens_per_minute"
              >
                <UInput
                  v-model="createModelForm.limits.tokens_per_minute"
                  type="number"
                  min="0"
                  placeholder="No limit"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Concurrency"
                name="limits.concurrency"
              >
                <UInput
                  v-model="createModelForm.limits.concurrency"
                  type="number"
                  min="0"
                  placeholder="No limit"
                  class="w-full"
                />
              </UFormField>
            </div>
          </div>

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="creatingModel"
              @click="createModelOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :loading="creatingModel"
            >
              Create model
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Edit model modal -->
    <UModal
      v-model:open="editModelOpen"
      title="Edit model"
      description="Update the model configuration."
    >
      <template #body>
        <UForm
          ref="editModelFormRef"
          :state="editModelForm"
          :validate="validateEditModelForm"
          :validate-on="['blur', 'change']"
          class="space-y-5"
          @submit="submitEditModel"
        >
          <UAlert
            v-if="editModelError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="editModelError"
          />

          <UFormField
            label="Internal model ID"
            name="internal_model_id"
            required
            help="Exact model name/ID accepted by the active OmniRoute/provider connection. This database value is the runtime source of truth; do not replace it with a global ANTHROPIC_MODEL setting."
          >
            <UInput
              v-model="editModelForm.internal_model_id"
              placeholder="OpenAI Codex"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Display name"
            name="display_name"
            required
            help="Admin-friendly name for this private upstream model. The customer-facing name is configured on the public model alias."
          >
            <UInput
              v-model="editModelForm.display_name"
              placeholder="Full Gemini Pro"
              autofocus
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Commercial resale verified"
            name="commercial_resale_verified"
            help="Customer-facing publication is blocked until the upstream/provider resale terms for this model have been verified."
          >
            <USwitch v-model="editModelForm.commercial_resale_verified" />
          </UFormField>

          <div class="space-y-3">
            <SpSectionHeading
              title="Capabilities"
              description="Configure the capabilities of this model."
              :level="3"
            />

            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                label="Streaming"
                name="capabilities.streaming"
              >
                <USwitch v-model="editModelForm.capabilities.streaming" />
              </UFormField>
              <UFormField
                label="Tools"
                name="capabilities.tools"
              >
                <USwitch v-model="editModelForm.capabilities.tools" />
              </UFormField>
              <UFormField
                label="Vision"
                name="capabilities.vision"
              >
                <USwitch v-model="editModelForm.capabilities.vision" />
              </UFormField>
              <UFormField
                label="Reasoning"
                name="capabilities.reasoning"
              >
                <USwitch v-model="editModelForm.capabilities.reasoning" />
              </UFormField>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                label="Context tokens"
                name="capabilities.context_tokens"
                required
              >
                <UInput
                  v-model="editModelForm.capabilities.context_tokens"
                  type="number"
                  min="1"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Max output tokens"
                name="capabilities.max_output_tokens"
                required
              >
                <UInput
                  v-model="editModelForm.capabilities.max_output_tokens"
                  type="number"
                  min="1"
                  class="w-full"
                />
              </UFormField>
            </div>
          </div>

          <div class="space-y-3">
            <SpSectionHeading
              title="Limits"
              description="Configure the rate limits for this model."
              :level="3"
            />

            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                label="Requests per minute"
                name="limits.requests_per_minute"
              >
                <UInput
                  v-model="editModelForm.limits.requests_per_minute"
                  type="number"
                  min="0"
                  placeholder="No limit"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Tokens per minute"
                name="limits.tokens_per_minute"
              >
                <UInput
                  v-model="editModelForm.limits.tokens_per_minute"
                  type="number"
                  min="0"
                  placeholder="No limit"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Concurrency"
                name="limits.concurrency"
              >
                <UInput
                  v-model="editModelForm.limits.concurrency"
                  type="number"
                  min="0"
                  placeholder="No limit"
                  class="w-full"
                />
              </UFormField>
            </div>
          </div>

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="editingModel"
              @click="editModelOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :loading="editingModel"
            >
              Update model
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Delete model confirmation -->
    <UModal
      :open="deleteModelTarget !== null"
      title="Delete this model?"
      description="This action cannot be undone."
      @update:open="deleteModelTarget = null"
    >
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-muted">
            Deleting <strong class="text-highlighted">{{ deleteModelTarget?.display_name }}</strong> will remove it from the system.
            This action cannot be undone.
          </p>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="deletingModel"
              @click="deleteModelTarget = null"
            >
              Cancel
            </UButton>
            <UButton
              color="error"
              icon="i-lucide-trash-2"
              :loading="deletingModel"
              @click="confirmDeleteModel"
            >
              Delete permanently
            </UButton>
          </div>
        </div>
      </template>
    </UModal>

    <!-- Discover upstream models -->
    <UModal
      v-model:open="discoverOpen"
      title="Discover upstream models"
      description="Read model IDs from the active READY provider connection and import the ones you want to manage in SP Cambo."
    >
      <template #body>
        <div class="space-y-4">
          <UAlert
            v-if="discoverError"
            color="error"
            variant="subtle"
            icon="i-lucide-circle-alert"
            :description="discoverError"
          />

          <div
            v-if="discoveredModels.length === 0"
            class="rounded-lg border border-dashed border-default p-6 text-center text-sm text-muted"
          >
            The provider returned no model IDs.
          </div>

          <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div class="flex flex-wrap items-center gap-2">
                <UButton
                  size="sm"
                  color="neutral"
                  variant="subtle"
                  icon="i-lucide-list-checks"
                  :disabled="discoverSelectableModels.length === 0"
                  @click="selectAllDiscoveredModels"
                >
                  Select all ({{ discoverSelectableModels.length }})
                </UButton>
                <UButton
                  size="sm"
                  color="neutral"
                  variant="ghost"
                  icon="i-lucide-square"
                  :disabled="selectedDiscoveredModelIds.length === 0"
                  @click="unselectAllDiscoveredModels"
                >
                  Unselect all
                </UButton>
              </div>
              <span class="text-xs text-muted">
                {{ selectedDiscoveredModelIds.length }} selected
              </span>
            </div>

            <div class="rounded-lg border border-default bg-elevated/30 p-3">
              <UCheckbox
                v-model="createPublicAliasesOnImport"
                label="Create missing public aliases so these models appear in Packages"
                description="Recommended. Aliases are created hidden from customers; review protocols, pricing and resale permission, then use Publish for sale when ready."
              />
            </div>

            <div class="max-h-96 space-y-2 overflow-y-auto pr-1">
              <label
                v-for="model in discoveredModels"
                :key="model.internal_model_id"
                class="flex items-start gap-3 rounded-lg border border-default p-3"
                :class="!isDiscoveredModelSelectable(model) ? 'opacity-70' : ''"
              >
                <UCheckbox
                  :model-value="selectedDiscoveredModelIds.includes(model.internal_model_id)"
                  :disabled="!isDiscoveredModelSelectable(model)"
                  class="mt-0.5"
                  @update:model-value="toggleDiscoveredModel(model.internal_model_id, $event === true)"
                />
                <span class="min-w-0 flex-1">
                  <span class="flex flex-wrap items-center gap-2">
                    <strong class="text-sm text-highlighted">{{ model.display_name }}</strong>
                    <UBadge
                      v-if="model.already_registered"
                      color="success"
                      variant="subtle"
                      size="sm"
                    >
                      Already imported
                    </UBadge>
                    <UBadge
                      v-if="model.has_public_alias"
                      color="info"
                      variant="subtle"
                      size="sm"
                    >
                      Public alias exists
                    </UBadge>
                    <UBadge
                      v-else-if="model.already_registered"
                      color="warning"
                      variant="subtle"
                      size="sm"
                    >
                      Private only · missing alias
                    </UBadge>
                  </span>
                  <code class="mt-1 block break-all font-mono text-xs text-dimmed">{{ model.internal_model_id }}</code>
                  <span
                    v-if="!model.has_public_alias && createPublicAliasesOnImport"
                    class="mt-1 block text-xs text-muted"
                  >
                    Package alias: <code class="font-mono">{{ model.suggested_public_alias }}</code>
                  </span>
                </span>
              </label>
            </div>
          </template>

          <UAlert
            color="info"
            variant="subtle"
            icon="i-lucide-info"
            title="Why discovered models did not appear in Packages"
            description="Packages grant public model aliases, not raw private upstream model IDs. Enable the public-alias option above to create the missing bridge automatically. The alias appears in Packages immediately but stays hidden from customers until you review and publish it."
          />

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="importingModels"
              @click="discoverOpen = false"
            >
              Close
            </UButton>
            <UButton
              icon="i-lucide-download"
              :loading="importingModels"
              :disabled="selectedDiscoveredModelIds.length === 0"
              @click="importDiscoveredModels"
            >
              Import selected ({{ selectedDiscoveredModelIds.length }})
            </UButton>
          </div>
        </div>
      </template>
    </UModal>

    <!-- Create public model alias -->
    <UModal
      v-model:open="createAliasOpen"
      title="Create public model"
      description="Create the stable customer-facing model name used by API keys, pricing, packages and the Playground."
    >
      <template #body>
        <UForm
          ref="createAliasFormRef"
          :state="createAliasForm"
          :validate="validateAliasForm"
          :validate-on="['blur', 'change']"
          class="space-y-5"
          @submit="submitCreateAlias"
        >
          <UAlert
            v-if="createAliasError"
            color="error"
            variant="subtle"
            icon="i-lucide-circle-alert"
            :description="createAliasError"
          />

          <UFormField
            label="Private model"
            name="model_id"
            required
            help="Requests to this public alias are routed to the selected private upstream model."
          >
            <USelectMenu
              v-model="createAliasForm.model_id"
              :items="modelOptions"
              value-key="value"
              class="w-full"
              placeholder="Select private model"
            />
          </UFormField>

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Public alias"
              name="public_alias"
              required
              help="Customer-facing SP Cambo alias. External clients may put this alias in ANTHROPIC_MODEL when they call the SP Cambo gateway; it is then mapped to the exact private Internal model ID above."
            >
              <UInput
                v-model="createAliasForm.public_alias"
                placeholder="claude-opus-5"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Display name"
              name="display_name"
              required
            >
              <UInput
                v-model="createAliasForm.display_name"
                placeholder="Claude Opus 5"
                class="w-full"
              />
            </UFormField>
          </div>

          <div class="space-y-3">
            <SpSectionHeading
              title="API protocols"
              description="Enable only the request formats SP Cambo should accept for this public model."
              :level="3"
            />
            <div class="grid gap-3 sm:grid-cols-3">
              <UFormField
                label="Anthropic Messages"
                name="capabilities.messages_api"
              >
                <USwitch v-model="createAliasForm.capabilities.messages_api" />
              </UFormField>
              <UFormField
                label="Chat Completions"
                name="capabilities.chat_completions_api"
              >
                <USwitch v-model="createAliasForm.capabilities.chat_completions_api" />
              </UFormField>
              <UFormField
                label="Responses API"
                name="capabilities.responses_api"
              >
                <USwitch v-model="createAliasForm.capabilities.responses_api" />
              </UFormField>
            </div>
          </div>

          <div class="space-y-3">
            <SpSectionHeading
              title="Capabilities"
              description="These capabilities are enforced by the control plane before a request is sent upstream."
              :level="3"
            />
            <div class="grid gap-3 sm:grid-cols-4">
              <UFormField
                label="Streaming"
                name="capabilities.streaming"
              >
                <USwitch v-model="createAliasForm.capabilities.streaming" />
              </UFormField>
              <UFormField
                label="Tools"
                name="capabilities.tools"
              >
                <USwitch v-model="createAliasForm.capabilities.tools" />
              </UFormField>
              <UFormField
                label="Vision"
                name="capabilities.vision"
              >
                <USwitch v-model="createAliasForm.capabilities.vision" />
              </UFormField>
              <UFormField
                label="Reasoning"
                name="capabilities.reasoning"
              >
                <USwitch v-model="createAliasForm.capabilities.reasoning" />
              </UFormField>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField
                label="Context tokens"
                name="capabilities.context_tokens"
                required
              >
                <UInput
                  v-model="createAliasForm.capabilities.context_tokens"
                  type="number"
                  min="1"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Max output tokens"
                name="capabilities.max_output_tokens"
                required
              >
                <UInput
                  v-model="createAliasForm.capabilities.max_output_tokens"
                  type="number"
                  min="1"
                  class="w-full"
                />
              </UFormField>
            </div>
          </div>

          <div class="space-y-3">
            <SpSectionHeading
              title="Public limits"
              description="Optional ceilings applied to this alias. Leave blank for no alias-specific limit."
              :level="3"
            />
            <div class="grid gap-4 sm:grid-cols-3">
              <UFormField
                label="Requests/min"
                name="limits.requests_per_minute"
              >
                <UInput
                  v-model="createAliasForm.limits.requests_per_minute"
                  type="number"
                  min="1"
                  placeholder="No limit"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Tokens/min"
                name="limits.tokens_per_minute"
              >
                <UInput
                  v-model="createAliasForm.limits.tokens_per_minute"
                  type="number"
                  min="1"
                  placeholder="No limit"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Concurrency"
                name="limits.concurrency"
              >
                <UInput
                  v-model="createAliasForm.limits.concurrency"
                  type="number"
                  min="1"
                  placeholder="No limit"
                  class="w-full"
                />
              </UFormField>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Enabled"
              name="enabled"
              help="Disabled aliases are rejected by the gateway."
            >
              <USwitch v-model="createAliasForm.enabled" />
            </UFormField>
            <UFormField
              label="Customer visible"
              name="customer_visible"
              help="Keep hidden until resale verification and pricing are ready."
            >
              <USwitch v-model="createAliasForm.customer_visible" />
            </UFormField>
          </div>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="creatingAlias"
              @click="createAliasOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              icon="i-lucide-route"
              :loading="creatingAlias"
            >
              Create public model
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Edit public model alias -->
    <UModal
      v-model:open="editAliasOpen"
      title="Edit public model"
      description="Update the customer-facing alias, route mapping, capabilities and visibility."
    >
      <template #body>
        <UForm
          ref="editAliasFormRef"
          :state="editAliasForm"
          :validate="validateAliasForm"
          :validate-on="['blur', 'change']"
          class="space-y-5"
          @submit="submitEditAlias"
        >
          <UAlert
            v-if="editAliasError"
            role="alert"
            color="error"
            variant="subtle"
            icon="i-lucide-circle-alert"
            :description="editAliasError"
          />
          <UFormField
            label="Private model"
            name="model_id"
            required
          >
            <USelectMenu
              v-model="editAliasForm.model_id"
              :items="modelOptions"
              value-key="value"
              class="w-full"
            />
          </UFormField>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Public alias"
              name="public_alias"
              required
            >
              <UInput
                v-model="editAliasForm.public_alias"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Display name"
              name="display_name"
              required
            >
              <UInput
                v-model="editAliasForm.display_name"
                class="w-full"
              />
            </UFormField>
          </div>
          <div class="grid gap-3 sm:grid-cols-3">
            <UFormField
              label="Anthropic Messages"
              name="capabilities.messages_api"
            >
              <USwitch v-model="editAliasForm.capabilities.messages_api" />
            </UFormField>
            <UFormField
              label="Chat Completions"
              name="capabilities.chat_completions_api"
            >
              <USwitch v-model="editAliasForm.capabilities.chat_completions_api" />
            </UFormField>
            <UFormField
              label="Responses API"
              name="capabilities.responses_api"
            >
              <USwitch v-model="editAliasForm.capabilities.responses_api" />
            </UFormField>
          </div>
          <div class="grid gap-3 sm:grid-cols-4">
            <UFormField label="Streaming">
              <USwitch v-model="editAliasForm.capabilities.streaming" />
            </UFormField>
            <UFormField label="Tools">
              <USwitch v-model="editAliasForm.capabilities.tools" />
            </UFormField>
            <UFormField label="Vision">
              <USwitch v-model="editAliasForm.capabilities.vision" />
            </UFormField>
            <UFormField label="Reasoning">
              <USwitch v-model="editAliasForm.capabilities.reasoning" />
            </UFormField>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Context tokens"
              name="capabilities.context_tokens"
            >
              <UInput
                v-model="editAliasForm.capabilities.context_tokens"
                type="number"
                min="1"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Max output tokens"
              name="capabilities.max_output_tokens"
            >
              <UInput
                v-model="editAliasForm.capabilities.max_output_tokens"
                type="number"
                min="1"
                class="w-full"
              />
            </UFormField>
          </div>
          <div class="grid gap-4 sm:grid-cols-3">
            <UFormField label="Requests/min">
              <UInput
                v-model="editAliasForm.limits.requests_per_minute"
                type="number"
                min="1"
                placeholder="No limit"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Tokens/min">
              <UInput
                v-model="editAliasForm.limits.tokens_per_minute"
                type="number"
                min="1"
                placeholder="No limit"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Concurrency">
              <UInput
                v-model="editAliasForm.limits.concurrency"
                type="number"
                min="1"
                placeholder="No limit"
                class="w-full"
              />
            </UFormField>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField label="Enabled">
              <USwitch v-model="editAliasForm.enabled" />
            </UFormField>
            <UFormField label="Customer visible">
              <USwitch v-model="editAliasForm.customer_visible" />
            </UFormField>
          </div>
          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="editingAlias"
              @click="editAliasOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="button"
              :loading="editingAlias"
              :disabled="editingAlias"
              @click="submitEditAlias"
            >
              Save public model
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Publish public model for sale -->
    <UModal
      :open="publishAliasTarget !== null"
      title="Publish this model for sale?"
      description="SP Cambo will repair the active READY route when possible, enable the model and make this alias customer-visible."
      @update:open="(open) => { if (!open) closePublishAlias() }"
    >
      <template #body>
        <div class="space-y-4">
          <UAlert
            v-if="publishAliasError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="publishAliasError"
          />

          <UAlert
            color="warning"
            variant="subtle"
            icon="i-lucide-scale"
            title="Commercial resale confirmation required"
            description="Only continue if your upstream/provider agreement permits you to commercially resell access to this model. SP Cambo cannot verify those contract terms automatically."
          />

          <UCheckbox
            v-model="confirmResale"
            label="I confirm my upstream terms allow commercial resale of this model."
          />

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="publishingAlias"
              @click="closePublishAlias"
            >
              Cancel
            </UButton>
            <UButton
              icon="i-lucide-store"
              :loading="publishingAlias"
              :disabled="!confirmResale"
              @click="confirmPublishAlias"
            >
              Publish for sale
            </UButton>
          </div>
        </div>
      </template>
    </UModal>

    <!-- Delete public model alias -->
    <UModal
      :open="deleteAliasTarget !== null"
      title="Delete public model?"
      description="Aliases already assigned to packages or API keys cannot be deleted; disable them instead."
      @update:open="(open) => { if (!open && !deletingAlias) deleteAliasTarget = null }"
    >
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-muted">
            Delete <strong class="text-highlighted">{{ deleteAliasTarget?.public_alias }}</strong>?
          </p>
          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="deletingAlias"
              @click="deleteAliasTarget = null"
            >
              Cancel
            </UButton>
            <UButton
              color="error"
              icon="i-lucide-trash-2"
              :loading="deletingAlias"
              @click="confirmDeleteAlias"
            >
              Delete public model
            </UButton>
          </div>
        </div>
      </template>
    </UModal>

    <!-- Set active connection modal -->
    <UModal
      v-model:open="setActiveOpen"
      title="Set active connection"
      description="Set this connection revision as the active one for this provider."
    >
      <template #body>
        <div class="space-y-4">
          <UAlert
            v-if="setActiveError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="setActiveError"
          />

          <p class="text-sm text-muted">
            Setting this connection revision as active will make it the primary connection for this provider.
          </p>

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="settingActive"
              @click="setActiveOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :loading="settingActive"
              @click="submitSetActive"
            >
              Set active
            </UButton>
          </div>
        </div>
      </template>
    </UModal>

    <!-- Update status modal -->
    <UModal
      v-model:open="statusOpen"
      title="Update connection status"
      description="Update the lifecycle status of this connection revision."
    >
      <template #body>
        <UForm
          ref="statusFormRef"
          :state="statusForm"
          :validate="validateStatusForm"
          :validate-on="['blur', 'change']"
          class="space-y-5"
          @submit="submitStatusUpdate"
        >
          <UAlert
            v-if="statusError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="statusError"
          />

          <UFormField
            label="Lifecycle status"
            name="lifecycle_status"
            required
            help="The new status for this connection revision."
          >
            <USelectMenu
              v-model="statusForm.lifecycle_status"
              :items="statusTransitionOptions"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Reason"
            name="reason"
            required
            help="The reason for changing the status (10-2000 characters)."
          >
            <UTextarea
              v-model="statusForm.reason"
              :rows="4"
              placeholder="Reason for status change"
              class="w-full"
            />
          </UFormField>

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="updatingStatus"
              @click="statusOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :loading="updatingStatus"
            >
              Update status
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>
  </SpDashboardPage>
</template>
