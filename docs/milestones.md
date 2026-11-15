# Milestones

## 1. Foundation and R2 connectivity

Complete: independent plugin, secure configuration, read-only R2 connection test, optional JIO detection, tests, package, and CI.

## 2. Background offload engine

Implemented on `feature/milestone-2-offload-engine`: durable database queue, atomic leases and recovery, confined manifests, pending JIO readiness, bounded worker, create-only R2 uploads, resumable multipart, remote verification, admin controls, and Keep Local Files enforcement. Acceptance status depends on local and CI verification recorded in the pull request.

## 3. JIO completion contract and delivery readiness

JIO 0.6.1-beta.1 does not currently expose an attachment-level completion event. Add a documented event or queryable terminal state before removing manual completion attestation. This milestone may also add explicit restoration tools and operator workflows.

## 4. Delivery integration

Evaluate CDN delivery only after verified object state, with WordPress, theme, Elementor, and JIO compatibility coverage. Provide rollback and restoration before changing any frontend URL behavior.
