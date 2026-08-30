<script setup lang="ts">
/**
 * Compatibility parent for the API-key route family.
 *
 * Older SP Cambo releases used this exact file as the API-key list page. When a
 * ZIP is extracted over an existing Windows checkout, deleting a file from a new
 * release does not delete the old file on disk. Nuxt then treats that legacy file
 * as the parent of `api-keys/[id].vue`; without <NuxtPage /> the URL changes but
 * the list page remains visible.
 *
 * Keep this tiny parent permanently. The list lives in api-keys/index.vue and the
 * detail view lives in api-keys/[id].vue, so both fresh installs and overlay
 * upgrades render the correct child route.
 */
</script>

<template>
  <NuxtPage />
</template>
