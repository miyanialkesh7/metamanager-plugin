# MetaManager Audit Process (Official)

This is the mandatory audit routine for MetaManager (plugin + daemon package). It replaces all prior ad-hoc code review practices. The purpose is to prevent the agent from compressing, summarizing, or ignoring rules and code during review.

## Process (per audit)

### Phase 1 — Document the routine
Write this file (or update it) so the process is enforced in writing before any code is read.

### Phase 2 — Marker-by-marker analysis (one at a time)
For each finding (marker), create a dedicated section in the audit report containing ALL of the following before moving to the next marker:

1. **Initial Finding** — What was observed in the code, verbatim where possible.
2. **Applicable Rules** — Exact rule numbers and text from `AGENTS.md`, repo `AGENTS.md`, and any other relevant docs. No paraphrasing. Quote the rule.
3. **Related Code** — File paths, line numbers, and the code involved. Cross-reference every file that participates in the behavior.
4. **Diagnosis** — Why the code is wrong (or right) against the rules. Trace the actual execution path. No assumptions without proof.
5. **Relationships** — How this marker connects to other markers. Split-brain, duplicated logic, cascading effects.
6. **Proof of Assumptions** — Any assumption stated must be verified against code or documented as an assumption requiring proof.

**One marker at a time.** Do not move to the next marker until the current section is complete.

### Phase 3 — Full coherence pass
Reread every marker section together. Check for:
- Contradictions between markers
- Split-brain between plugin and daemon
- Split-brain between Business Profile and CPT settings
- Split-brain between rules and code behavior
- Gaps where a rule has no corresponding code fix

### Phase 4 — Local testing
- Build or modify unit/integration tests for every marker that has a code fix
- Full test suite must pass (653+ tests)
- New tests must cover the fixed behavior

### Phase 5 — Push to dev
- Commit with marker reference in message
- Push to dev (pull first, never amend after CI)

### Phase 6 — Test on PEO + gap creek
- Deploy to test channel
- Exercise every command, feature, and setting affected by the fixes
- Verify against the rules — especially rules 0, 8, 9, 14, 15, 18

### Phase 7 — Promote to main
Only after Phase 6 verification passes.

## Why this process exists

Prior sessions compressed rules and code during review, causing:
- Rules to be silently ignored (rule 18 "No fallbacks" violated by `?? true` defaults)
- Split-brain between Business Profile and CPT settings not detected
- Silent failure patterns (transient locks, retry-without-limit) presented as features
- Code that reports "green" while discarding work

This process ensures every rule is read in full against every marker before the marker is closed.

## Applicable rule numbers (reference)

From `/home/richard/Public/Projects/AGENTS.md`:
- Rule 0: Never reinterpret, modify, shrink, expand, or change what the user says
- Rule 1: Read ALL AGENTS.md files at session start
- Rule 2: Never guess file paths
- Rule 3: Follow the pipeline dev → test → main via `gh workflow run` only
- Rule 4: Never SCP files to production servers
- Rule 5: Pull before push
- Rule 8: Never break or dismantle when failing to diagnose
- Rule 9: WordPress cron must NEVER be re-registered on page load, init, admin_init, or any request hook
- Rule 14: Failure to get a response is not evidence
- Rule 15: Do not manually configure things that the code should handle
- Rule 18: No fallbacks — missing configuration must fail loudly
- Rule 21: Attribution is NEVER auto-derived

From `metamanager-plugin/AGENTS.md`:
- Pipeline: dev → test → main via `gh workflow run` only
- CI auto-bumps MM_VERSION on every dev push
- Test channel: `/var/www/html/metamanager-test/`
- Production channel: `/var/www/html/metamanager/`
- PHP 8.4 for WP-CLI

From `metamanager/AGENTS.md`:
- Daemon updates via OS apt (gcm-maintenance timer)
- Plugin reads VERSION for display only
