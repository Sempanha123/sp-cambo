<script setup lang="ts">
import type { FormError } from '@nuxt/ui'
import type { AdminProvider } from '~/types/admin'

/**
 * Admin provider management page.
 */
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

const api = useSpApi()
const toast = useToast()

// Fetch providers
const providers = await useSpResource('admin:providers', () => api.admin.providers(), { server: false })

// Create provider form
const createOpen = ref(false)
const creating = ref(false)
const createForm = ref({
  name: '',
  slug: '',
  enabled: true
})
const createError = ref<string | null>(null)
const createFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('createFormRef')

const resetCreateForm = () => {
  createForm.value = { name: '', slug: '', enabled: true }
  createError.value = null
  createFormRef.value?.setErrors([])
}

const validateCreateForm = (state: typeof createForm.value): FormError[] => {
  const errors: FormError[] = []

  if (!state.name.trim()) {
    errors.push({ name: 'name', message: 'Provider name is required.' })
  } else if (state.name.trim().length > 255) {
    errors.push({ name: 'name', message: 'Provider name must be 255 characters or fewer.' })
  }

  if (!state.slug.trim()) {
    errors.push({ name: 'slug', message: 'Provider slug is required.' })
  } else if (!/^[a-z0-9-]+$/.test(state.slug.trim())) {
    errors.push({ name: 'slug', message: 'Slug must contain only lowercase letters, numbers, and hyphens.' })
  } else if (state.slug.trim().length > 255) {
    errors.push({ name: 'slug', message: 'Slug must be 255 characters or fewer.' })
  }

  return errors
}

const submitCreate = async () => {
  creating.value = true
  createError.value = null

  try {
    const provider = await api.admin.createProvider({
      name: createForm.value.name.trim(),
      slug: createForm.value.slug.trim().toLowerCase(),
      enabled: createForm.value.enabled
    })

    createOpen.value = false
    resetCreateForm()
    await providers.refresh()

    toast.add({
      title: 'Provider created',
      description: `${provider.name} has been created successfully.`,
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

// Delete provider confirmation
const deleteTarget = ref<AdminProvider | null>(null)
const deleting = ref(false)

const confirmDelete = async () => {
  const provider = deleteTarget.value

  if (!provider) {
    return
  }

  deleting.value = true

  try {
    await api.admin.deleteProvider(provider.id)
    await providers.refresh()

    toast.add({
      title: 'Provider deleted',
      description: `${provider.name} has been deleted successfully.`,
      color: 'success',
      icon: 'i-lucide-trash-2'
    })
  } catch (cause) {
    toast.add({
      title: 'Could not delete provider',
      description: toSpApiError(cause).message,
      color: 'error',
      icon: 'i-lucide-circle-x'
    })
  } finally {
    deleting.value = false
    deleteTarget.value = null
  }
}

// Edit provider form
const editOpen = ref(false)
const editing = ref(false)
const editForm = ref({
  name: '',
  slug: '',
  enabled: true
})
const editError = ref<string | null>(null)
const editFormRef = useTemplateRef<{ setErrors: (errors: FormError[]) => void }>('editFormRef')
const editTarget = ref<AdminProvider | null>(null)

const resetEditForm = () => {
  editForm.value = { name: '', slug: '', enabled: true }
  editError.value = null
  editFormRef.value?.setErrors([])
}

const openEdit = (provider: AdminProvider) => {
  editTarget.value = provider
  editForm.value = {
    name: provider.name,
    slug: provider.slug,
    enabled: provider.enabled
  }
  editError.value = null
  editFormRef.value?.setErrors([])
  editOpen.value = true
}

const validateEditForm = (state: typeof editForm.value): FormError[] => {
  const errors: FormError[] = []

  if (!state.name.trim()) {
    errors.push({ name: 'name', message: 'Provider name is required.' })
  } else if (state.name.trim().length > 255) {
    errors.push({ name: 'name', message: 'Provider name must be 255 characters or fewer.' })
  }

  if (!state.slug.trim()) {
    errors.push({ name: 'slug', message: 'Provider slug is required.' })
  } else if (!/^[a-z0-9-]+$/.test(state.slug.trim())) {
    errors.push({ name: 'slug', message: 'Slug must contain only lowercase letters, numbers, and hyphens.' })
  } else if (state.slug.trim().length > 255) {
    errors.push({ name: 'slug', message: 'Slug must be 255 characters or fewer.' })
  }

  return errors
}

const submitEdit = async () => {
  if (!editTarget.value) return

  editing.value = true
  editError.value = null

  try {
    const updatedProvider = await api.admin.updateProvider(editTarget.value.id, {
      name: editForm.value.name.trim(),
      slug: editForm.value.slug.trim().toLowerCase(),
      enabled: editForm.value.enabled
    })

    editOpen.value = false
    resetEditForm()
    await providers.refresh()

    toast.add({
      title: 'Provider updated',
      description: `${updatedProvider.name} has been updated successfully.`,
      color: 'success',
      icon: 'i-lucide-check-circle'
    })
  } catch (cause) {
    const error = toSpApiError(cause)

    editFormRef.value?.setErrors(
      Object.entries(error.errors).map(([name, messages]) => ({
        name,
        message: messages[0] ?? 'This value is not valid.'
      }))
    )

    editError.value = error.isValidation ? null : error.message
  } finally {
    editing.value = false
  }
}

useSeoMeta({
  title: 'Providers',
  description: 'Manage AI model providers and their connections.',
  robots: 'noindex, nofollow'
})
</script>

<template>
  <SpDashboardPage
    title="Providers"
    icon="i-lucide-server"
    description="Manage AI model providers and their connections."
  >
    <template #actions>
      <UButton
        icon="i-lucide-plus"
        @click="resetCreateForm(); createOpen = true"
      >
        New provider
      </UButton>
    </template>

    <SpStateForbidden
      v-if="providers.forbidden.value"
      :code="providers.error.value?.code ?? null"
      permission="catalog.manage"
    />

    <SpAsyncSection
      :loading="providers.initialLoading.value"
      :unavailable="providers.unavailable.value"
      :failed="providers.failed.value"
      :empty="providers.isEmpty.value"
      :offline="providers.error.value?.code === 'network_unreachable'"
      :error-message="providers.error.value?.message"
      error-title="Providers could not be loaded"
      unavailable-title="Providers are not available"
      unavailable-description="SP Cambo could not be reached, so providers cannot be managed right now."
      empty-title="No providers"
      empty-description="Create a provider to manage its connection settings."
      empty-icon="i-lucide-server-off"
      loading-variant="rows"
      @retry="providers.refresh()"
    >
      <div class="space-y-4">
        <div class="rounded-lg border border-default bg-elevated/30 p-4">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 space-y-1">
              <h3 class="font-medium text-highlighted">
                Provider management
              </h3>
              <p class="text-sm text-muted">
                Create and manage AI model providers and their connection settings.
              </p>
            </div>
          </div>
        </div>

        <div class="rounded-lg border border-default bg-elevated/30 p-4">
          <div class="flex items-center justify-between gap-4">
            <h3 class="font-medium text-highlighted">
              Available providers
            </h3>
          </div>

          <SpAsyncSection
            :loading="providers.loading.value"
            :empty="providers.isEmpty.value"
            loading-variant="cards"
          >
            <ul class="mt-4 space-y-3">
              <li
                v-for="provider in providers.data.value"
                :key="provider.id"
                class="rounded-lg border border-default bg-elevated/50 p-4"
              >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                      <p class="truncate font-medium text-highlighted">
                        {{ provider.name }}
                      </p>
                      <UBadge
                        v-if="provider.enabled"
                        color="success"
                        variant="subtle"
                        size="sm"
                      >
                        Enabled
                      </UBadge>
                      <UBadge
                        v-else
                        color="neutral"
                        variant="subtle"
                        size="sm"
                      >
                        Disabled
                      </UBadge>
                    </div>

                    <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Slug
                        </dt>
                        <dd>{{ provider.slug }}</dd>
                      </div>
                      <div class="flex gap-1.5">
                        <dt class="text-dimmed">
                          Created
                        </dt>
                        <dd>{{ formatDate(provider.created_at) }}</dd>
                      </div>
                    </dl>
                  </div>

                  <div class="flex flex-col gap-2 sm:items-end">
                    <div class="flex gap-2">
                      <UButton
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        icon="i-lucide-settings"
                        :to="`/admin/providers/${provider.id}`"
                      >
                        Manage
                      </UButton>
                      <UButton
                        color="error"
                        variant="subtle"
                        size="sm"
                        icon="i-lucide-trash-2"
                        @click="deleteTarget = provider"
                      >
                        Delete
                      </UButton>
                    </div>
                  </div>
                </div>

                <div class="flex gap-2">
                  <UButton
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    icon="i-lucide-settings"
                    :to="`/admin/providers/${provider.id}`"
                  >
                    Manage
                  </UButton>
                  <UButton
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    icon="i-lucide-pencil"
                    @click="openEdit(provider)"
                  >
                    Edit
                  </UButton>
                  <UButton
                    color="error"
                    variant="subtle"
                    size="sm"
                    icon="i-lucide-trash-2"
                    @click="deleteTarget = provider"
                  >
                    Delete
                  </UButton>
                </div>
              </li>
            </ul>
          </SpAsyncSection>
        </div>
      </div>
    </SpAsyncSection>

    <!-- Edit provider modal -->
    <UModal
      v-model:open="editOpen"
      title="Edit provider"
      description="Update the provider details."
    >
      <template #body>
        <UForm
          ref="editFormRef"
          :state="editForm"
          :validate="validateEditForm"
          :validate-on="['blur', 'change']"
          class="space-y-5"
          @submit="submitEdit"
        >
          <UAlert
            v-if="editError"
            role="alert"
            icon="i-lucide-circle-alert"
            color="error"
            variant="subtle"
            :description="editError"
          />

          <UFormField
            label="Provider name"
            name="name"
            required
            help="The display name of the provider (e.g., 'OmniRoute', 'OpenAI')."
          >
            <UInput
              v-model="editForm.name"
              placeholder="OmniRoute"
              autofocus
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Slug"
            name="slug"
            required
            help="A URL-friendly identifier for the provider (e.g., 'omniroute', 'openai')."
          >
            <UInput
              v-model="editForm.slug"
              placeholder="omniroute"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Enabled"
            name="enabled"
            help="Whether this provider is enabled for use."
          >
            <UToggle
              v-model="editForm.enabled"
              on-icon="i-lucide-check"
              off-icon="i-lucide-x"
            />
          </UFormField>

          <div class="flex justify-end gap-2 pt-1">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="editing"
              @click="editOpen = false"
            >
              Cancel
            </UButton>
            <UButton
              type="submit"
              :loading="editing"
            >
              Update provider
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Create provider modal -->
    <UModal
      v-model:open="createOpen"
      title="Create new provider"
      description="A provider represents an AI model provider like OmniRoute, OpenAI, or Anthropic."
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
            label="Provider name"
            name="name"
            required
            help="The display name of the provider (e.g., 'OmniRoute', 'OpenAI')."
          >
            <UInput
              v-model="createForm.name"
              placeholder="OmniRoute"
              autofocus
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Slug"
            name="slug"
            required
            help="A URL-friendly identifier for the provider (e.g., 'omniroute', 'openai')."
          >
            <UInput
              v-model="createForm.slug"
              placeholder="omniroute"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Enabled"
            name="enabled"
            help="Whether this provider is enabled for use."
          >
            <UToggle
              v-model="createForm.enabled"
              on-icon="i-lucide-check"
              off-icon="i-lucide-x"
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
              Create provider
            </UButton>
          </div>
        </UForm>
      </template>
    </UModal>

    <!-- Delete provider confirmation -->
    <UModal
      :open="deleteTarget !== null"
      title="Delete this provider?"
      description="This action cannot be undone."
      @update:open="deleteTarget = null"
    >
      <template #body>
        <div class="space-y-4">
          <p class="text-sm text-muted">
            Deleting <strong class="text-highlighted">{{ deleteTarget?.name }}</strong> will remove it from the system.
            This action cannot be undone.
          </p>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :disabled="deleting"
              @click="deleteTarget = null"
            >
              Cancel
            </UButton>
            <UButton
              color="error"
              icon="i-lucide-trash-2"
              :loading="deleting"
              @click="confirmDelete"
            >
              Delete permanently
            </UButton>
          </div>
        </div>
      </template>
    </UModal>
  </SpDashboardPage>
</template>
