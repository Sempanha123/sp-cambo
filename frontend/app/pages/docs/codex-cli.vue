<script setup lang="ts">
useSeoMeta({
  title: 'Codex CLI setup',
  description: 'Add SP Cambo to Codex CLI as a custom model provider using the Responses wire API, with the key read from your environment.'
})

const { codexConfig, codexShell, openAiBase } = useCliSnippets()
</script>

<template>
  <SpDocsShell
    title="Codex CLI setup"
    description="Codex CLI supports custom OpenAI-compatible providers. SP Cambo is configured as one, with the key read from an environment variable."
  >
    <h2 id="before-you-start">
      Before you start
    </h2>
    <ul>
      <li>Codex CLI installed.</li>
      <li>An active package, an API key, and a model alias from the catalogue.</li>
      <li>
        A model whose capabilities include the Responses API — check the
        <NuxtLink to="/models">
          catalogue
        </NuxtLink> before configuring.
      </li>
    </ul>

    <h2 id="add-the-provider">
      Add the provider
    </h2>
    <p>
      Add an <code>spcambo</code> provider and a matching profile to your Codex configuration. The
      base URL <strong>must</strong> end in <code>/v1</code> for OpenAI-compatible clients — the
      opposite of the Claude Code rule.
    </p>
    <SpCodeBlock
      filename="~/.codex/config.toml"
      :code="codexConfig"
    />

    <p>
      <code>env_key</code> tells Codex which environment variable holds the credential, so your key
      never appears in the config file itself. Keep it that way: config files get committed by
      accident far more often than environment variables do.
    </p>

    <h2 id="run-it">
      Run it
    </h2>
    <SpCodeBlock
      filename="bash"
      :code="codexShell"
    />
    <p>
      If you would rather not pass <code>--profile</code> every time, make the profile your default in
      the Codex configuration.
    </p>

    <h2 id="wire-api">
      Why the Responses wire API
    </h2>
    <p>
      SP Cambo exposes the OpenAI-compatible surface as the Responses API. Configuring a Chat
      Completions wire format against <code>{{ openAiBase }}</code> will send requests to a path that
      does not exist here, and every call will fail. Set <code>wire_api = "responses"</code>.
    </p>

    <h2 id="troubleshooting">
      Troubleshooting
    </h2>
    <h3>Provider not found</h3>
    <p>
      The profile's <code>model_provider</code> must match the provider table key exactly —
      <code>spcambo</code> in both places.
    </p>

    <h3>Missing credential</h3>
    <p>
      Codex reads the variable named by <code>env_key</code>. If it is unset in the shell that launches
      Codex, you get an authentication error rather than a configuration error.
    </p>

    <h3>404 on every call</h3>
    <p>
      Either the base URL is missing its <code>/v1</code>, or the wire API is set to Chat Completions.
      Check both.
    </p>

    <h3>Model rejected</h3>
    <p>
      The alias may not exist, may not support the Responses API, or may be outside your key's scope.
      All three are visible in the dashboard and the catalogue.
    </p>

    <h2 id="keeping-keys-out-of-config">
      Keeping keys out of your config
    </h2>
    <p>
      Do not inline a key into <code>config.toml</code>, even temporarily. If you already have, rotate
      the key from the dashboard — the old secret is invalidated immediately — and remove the value
      from any backup or repository that captured the file.
    </p>
  </SpDocsShell>
</template>
