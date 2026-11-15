# Milestones

## 1. Foundation and R2 connectivity

Complete: standalone plugin, settings, secure credential loading, read-only R2 diagnostics, optional JIO detection, tests, packaging, and CI.

## 2. Integration contract and manifest design

Define a stable JIO completion contract and attachment manifest format. Continue to preserve local files and avoid URL delivery changes until remote state is verified.

## 3. Explicit offload operations

Design opt-in, retryable background transfers, verification, multipart uploads, and restoration. Require operator-visible status and recovery paths.

## 4. Delivery integration

Only after verified offload state, evaluate CDN delivery and compatibility with WordPress, themes, Elementor, and JIO. Keep restoration and rollback available.
