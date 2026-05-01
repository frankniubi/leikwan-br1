# Plan Forward Combination Management Design

**Goal**
Switch admin `Plan` management from low-level slave/client/sidecar selectors to grouped `Forward Combination` selection, while leaving untouched existing plans on legacy behavior until an admin edits and saves them.

## Approved behavior
- `Plan` management should use `Forward Combination` as the admin-facing management unit.
- The admin selection UX should be a grouped list of forward combinations, not a flat list.
- Old UI selectors must be removed from the `Plan` form:
  - `Allowed Slave Groups`
  - `Allowed Client Groups`
  - `Allowed Sidecar Points`
- Existing plans must **not** be auto-migrated.
- Existing plans continue to operate with legacy rules until an admin edits and saves them.
- Once an existing plan is edited and saved in the new UI, it becomes combination-based.

## Functional design
### Plan states
A plan can behave in one of two modes:
1. **Legacy plan**
   - governed by old allowed slave/client/sidecar rules
   - existing untouched plans remain here
2. **Forward-combination plan**
   - governed by explicit allowed forward combination IDs
   - new plans and edited plans move here

### Transition rule
- No background migration.
- No best-effort mapping from old selectors into combinations.
- The cutover happens only on admin save from the new `Plan` UI.

### Admin UI
On `/admin/plans`:
- remove low-level selector controls from the form
- add `Allowed Forward Combinations`
- render combinations grouped by meaningful topology traits such as backend mode / client mode / CF mode context
- each option should expose enough summary info to make safe selection decisions:
  - internal name
  - tenant label
  - backend mode
  - client mode
  - CF/location context when present

### List/table view
The plan list should summarize selected forward combinations rather than showing separate slave/client/sidecar permission columns.

### Runtime behavior
- Legacy untouched plans continue using old backend allow rules.
- Combination-based plans authorize tenant use through plan->allowed forward combinations only.
- Edited plans should ignore old low-level plan permission fields after cutover.

## Testing requirements
- Regression test for admin plan page rendering
- Regression test for plan save/load behavior with forward combination IDs
- Regression test for legacy untouched plan compatibility
- Regression test for edited-plan cutover behavior
