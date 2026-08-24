<script setup lang="ts">
useSeoMeta({
  title: 'Acceptable use',
  description: 'What SP Cambo access may and may not be used for, how enforcement works, and how to report abuse.'
})
</script>

<template>
  <SpLegalShell
    title="Acceptable use"
    description="A short list of things that will get your access revoked, and the reasoning behind each."
    reviewed="21 August 2026"
  >
    <h2 id="scope">
      Who this applies to
    </h2>
    <p>
      Everyone calling the SP Cambo gateway, whether directly, through a CLI tool, or through an
      application you have built. If you give someone else access using your key, their use is your
      responsibility.
    </p>

    <h2 id="prohibited">
      Not permitted
    </h2>
    <h3>Illegal activity</h3>
    <p>
      Anything unlawful where you are, or where SP Cambo operates. This includes fraud, and it includes
      using the service to plan or facilitate an offence.
    </p>

    <h3>Sexual content involving minors</h3>
    <p>
      Absolutely prohibited, in any form, including fictional or generated depictions. There is no
      warning step for this: access is terminated.
    </p>

    <h3>Targeted harm</h3>
    <p>
      Harassment campaigns, stalking, doxxing, threats, or generating content intended to intimidate a
      specific person or group.
    </p>

    <h3>Weapons and mass harm</h3>
    <p>
      Guidance for producing weapons capable of mass casualties — biological, chemical, radiological,
      nuclear — or for attacks on critical infrastructure.
    </p>

    <h3>Malware and unauthorised access</h3>
    <p>
      Building or distributing malware, ransomware, credential stealers or botnets, or attacking systems
      you are not authorised to test. Security work on systems you own or have written permission to
      test is fine; the distinction is authorisation.
    </p>

    <h3>Deception at scale</h3>
    <p>
      Impersonating a real person or organisation, generating fake reviews or fake identity documents,
      or running coordinated influence or spam operations.
    </p>

    <h3>Abuse of the service itself</h3>
    <ul>
      <li>circumventing metering, entitlement expiry, key scoping or rate limits;</li>
      <li>sharing or reselling access outside an approved reseller arrangement;</li>
      <li>probing for other customers' data or attempting to escape your own tenant;</li>
      <li>embedding a key in client-side code or a public repository — a key you have published is a key you have given away.</li>
    </ul>

    <h2 id="high-risk">
      Uses that need a human in the loop
    </h2>
    <p>
      Model output is generated text and can be confidently wrong. Do not use it as the sole basis for
      decisions in these areas:
    </p>
    <ul>
      <li>medical, psychiatric or therapeutic advice;</li>
      <li>legal advice;</li>
      <li>financial, credit, insurance or employment decisions about an individual;</li>
      <li>safety-critical or life-critical systems.</li>
    </ul>
    <p>
      These are not prohibited. They require review by a qualified person before the output affects
      anyone, and they require you to tell the affected person they are dealing with generated content.
    </p>

    <h2 id="upstream">
      Upstream policies also apply
    </h2>
    <p>
      Requests are served by upstream model providers, each with its own usage policy. Where an upstream
      policy is stricter than this page, the stricter rule is the one that governs — SP Cambo cannot
      grant permission a provider withholds.
    </p>

    <h2 id="enforcement">
      How this is enforced
    </h2>
    <p>
      SP Cambo does not read your prompts. Enforcement works from metadata, from upstream provider
      signals, and from reports.
    </p>
    <p>
      Proportionate responses in ascending order: a warning; revoking a specific key; suspending the
      account; terminating access. Severe cases — the child-safety category above, or activity that
      endangers people — skip straight to termination.
    </p>
    <p>
      Revoking a key stops future requests. It does not retroactively refund quota already spent.
    </p>

    <h2 id="reporting">
      Reporting abuse
    </h2>
    <p>
      If you believe SP Cambo access is being used to cause harm, report it. A dedicated abuse contact
      has not been published yet; until it is, this page will not print an address that does not exist.
      Use whichever channel the operator has given you, and include the timestamps and any identifiers
      you have.
    </p>

    <h2 id="questions">
      If you are not sure
    </h2>
    <p>
      Ask before you build. A short question about an intended use case is cheaper than discovering the
      answer after you have shipped and had a key revoked.
    </p>
    <p>
      The commercial rules that sit alongside this page are in
      <NuxtLink to="/legal/terms">
        terms of service
      </NuxtLink>.
    </p>
  </SpLegalShell>
</template>
