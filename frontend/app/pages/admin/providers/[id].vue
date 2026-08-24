<script setup lang="ts">
import type { FormError } from '@nuxt/ui'
import type {
  AdminProviderAlias,
  AdminProviderModel,
  ProviderAliasInput,
  ProviderConnectionRevision,
  ProviderConnectionRevisionInput,
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

// Set active connection
const setActiveOpen = ref(false)
const settingActive = ref(false)
const activeRevisionId = ref<string | null>(null)
const setActiveError = ref<string | null>(null)

const openSetActive = (revision: ProviderConnectionRevision) => {
  activeRevisionId.value = revision.id
  setActiveError.value = null
  setActiveOpen.value = true
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

    await revisions.refresh()

    toast.add({
      title: 'Connection probed',
      description: `Probe for revision ${revision.route_version} completed with status: ${updatedRevision.last_probe_status}.`,
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
const statusForm = ref<ProviderConnectionStatusUpdateInput>({ lifecycle_status: 'READY', reason: '' })
const statusError = ref<string | null>(null)
const statusFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('statusFormRef')
const statusTarget = ref<ProviderConnectionRevision | null>(null)

const openStatusUpdate = (revision: ProviderConnectionRevision) => {
  statusTarget.value = revision
  statusForm.value = { lifecycle_status: revision.lifecycle_status as 'READY', reason: '' }
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
const _creatingAlias = ref(false)
const createAliasForm = ref<ProviderAliasInput>({
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
const _deleteAliasTarget = ref<AdminProviderAlias | null>(null)
const _deletingAlias = ref(false)

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
const _editingAlias = ref(false)
const editAliasForm = ref<ProviderAliasInput>({
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

const _resetEditAliasForm = () => {
  editAliasForm.value = {
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

const _openEditAlias = (alias: AdminProviderAlias) => {
  editAliasTarget.value = alias
  editAliasForm.value = {
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

// Create model form
const createModelOpen = ref(false)
const creatingModel = ref(false)
const createModelForm = ref<ProviderModelInput>({
  internal_model_id: '',
  display_name: '',
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
              <UButton
                v-if="aliases.data.value && aliases.data.value.length > 0"
                icon="i-lucide-plus"
                size="sm"
                @click="resetCreateAliasForm(); createAliasOpen = true"
              >
                New alias
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
                        {{ revision.credential_suffix ? `••••${revision.credential_suffix}` : 'Not configured' }}
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
                        :disabled="provider.data.value?.active_connection_revision_id === revision.id"
                        @click="openSetActive(revision)"
                      >
                        Set active
                      </UButton>
                      <UButton
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        icon="i-lucide-settings"
                        @click="openStatusUpdate(revision)"
                      >
                        Update status
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
                    <div class="flex gap-2">
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
            help="Use the exact model ID OmniRoute accepts (for example gc/grok-4.5 or all-gemini-3.6-flash). Customers will use a separate public alias later."
          >
            <UInput
              v-model="createModelForm.internal_model_id"
              placeholder="all-gemini-3.6-flash"
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
                <UToggle v-model="createModelForm.capabilities.streaming" />
              </UFormField>
              <UFormField
                label="Tools"
                name="capabilities.tools"
              >
                <UToggle v-model="createModelForm.capabilities.tools" />
              </UFormField>
              <UFormField
                label="Vision"
                name="capabilities.vision"
              >
                <UToggle v-model="createModelForm.capabilities.vision" />
              </UFormField>
              <UFormField
                label="Reasoning"
                name="capabilities.reasoning"
              >
                <UToggle v-model="createModelForm.capabilities.reasoning" />
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
                <UToggle v-model="editModelForm.capabilities.streaming" />
              </UFormField>
              <UFormField
                label="Tools"
                name="capabilities.tools"
              >
                <UToggle v-model="editModelForm.capabilities.tools" />
              </UFormField>
              <UFormField
                label="Vision"
                name="capabilities.vision"
              >
                <UToggle v-model="editModelForm.capabilities.vision" />
              </UFormField>
              <UFormField
                label="Reasoning"
                name="capabilities.reasoning"
              >
                <UToggle v-model="editModelForm.capabilities.reasoning" />
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
              :items="['READY', 'DRAINING', 'REVOKED']"
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
