# Windows Email Login And Client Picker Design - 2026-05-06

## Overview

Replace the current Windows first-run setup model based on `Master URL + Client ID + Agent Token` with a normal panel user login flow based on `email + password`.

After login, the app should let the user choose which client profile to use when the account owns multiple clients. If the account owns only one client, the app should skip the picker and continue directly into the dashboard.

After entering the app, the user must be able to switch between owned clients later without logging out.

This design intentionally does **not** add forward or port creation inside the Windows app. Forward creation stays in the panel.

## Selected Approach

**Selected:** add a thin desktop login session above the existing per-client agent-token runtime flow.

The Windows app will:

1. authenticate with panel `email + password`
2. receive a desktop login session plus the user-owned client list
3. pick a client automatically if only one exists, or show a client picker if multiple exist
4. exchange the selected client for the existing internal `agent_token`
5. continue using the current runtime APIs unchanged

This keeps the working runtime path intact while fully replacing the user-visible manual token setup flow.

## Goals

- Replace manual token-based first-run setup with email/password login.
- Let the user choose a specific client when multiple clients are owned by the same account.
- Skip client selection when exactly one client exists.
- Let the user switch clients later without logging out.
- Keep the current Windows runtime, config pull, heartbeat, bond, and CF bond behavior intact.
- Preserve the current panel ownership boundary so users can only select their own clients.

## Non-Goals

- Do not add forward or port creation into the Windows app.
- Do not expose a full forward editor in the Windows app.
- Do not rewrite `/api/client/configs/pull`, `/api/client/heartbeat`, or diagnostics APIs away from the current client-token model.
- Do not add `mptcp` or `race` support to Windows.
- Do not redesign the whole Windows dashboard shell.

## Current State

### Windows app today

The current Windows app setup flow is hard-coded around:

- `Master URL`
- `Client ID`
- `Agent Token`

Main UI files:

- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs`

The app currently initializes only when these values are present in `AgentConfig`.

### Backend today

The panel already exposes:

- `POST /api/client/login`

Current implementation:

- validates panel `email + password`
- finds active clients owned by the logged-in user
- returns the owned client list
- currently also returns the **first** client token by default

Current backend file:

- `master/src/Controllers/ClientAgentController.php`

This means the backend already contains the right authentication base. It just needs a cleaner contract for multi-client selection and later client switching.

## User Experience Design

### 1. First-run login screen

Replace the current setup overlay with a login screen that asks for:

- `Master URL`
- `Email`
- `Password`

Actions:

- `Sign In`
- optional secondary links such as `Forgot Password` and `Open Panel`

Remove from the user-visible first-run flow:

- `Client ID`
- `Agent Token`

### 2. Post-login client selection

After successful login:

- if the account has **one** active client, the app auto-selects it and enters the dashboard
- if the account has **multiple** active clients, the app shows a client picker
- if the account has **zero** active clients, the app shows a clear “no active clients assigned” state and does not enter the dashboard

The client picker should show at least:

- client name
- client id

### 3. In-app client switching

After entering the dashboard, the user can switch clients later without logging out.

The main shell should show:

- signed-in user identity
- active client identity
- `Switch Client`
- `Sign Out`

When switching clients:

1. stop current forwards
2. clear active runtime state
3. request token for the newly selected client
4. reconnect polling/runtime under the new client
5. refresh dashboard and forward list

### 4. Sign out

Sign out must:

- stop all runtime processes
- clear desktop login session
- clear saved client list
- clear active client token and client id
- return to login screen

## Backend API Contract

### Current issue

`POST /api/client/login` currently returns the first client token immediately. That is convenient for the one-client case, but awkward for explicit multi-client selection and later client switching.

### Proposed contract

#### `POST /api/client/login`

Purpose:

- authenticate the panel user
- return desktop session information and owned clients

Request:

- `email`
- `password`

Response:

- `ok`
- `session_token`
- `user` summary
- `clients: [{ id, name }]`

For the one-client case, the backend may also include the selected client/token directly, but the app should not depend on that shortcut.

#### `POST /api/client/select`

Purpose:

- activate one owned client for the current desktop login session
- return the internal client `agent_token`

Request:

- `session_token`
- `client_id`

Response:

- `ok`
- `client_id`
- `client_name`
- `agent_token`

### Security rule

Every client selection request must verify that the selected `client_id` belongs to the authenticated user behind the desktop session.

No client selection should trust local app state alone.

## Windows Runtime Contract

The Windows app should continue using the existing runtime APIs after client selection:

- `GET /api/client/configs/pull`
- `POST /api/client/heartbeat`
- `POST /api/client/diagnostics/result`

Only the **bootstrap** changes. The runtime path stays the same.

This is the key reason the selected approach is low risk.

## Local Saved State

Store locally:

- `master_url`
- `desktop_session_token`
- signed-in user summary
- available client list
- active `client_id`
- active client `agent_token`
- existing non-auth settings

Do not keep the old visible setup model as the primary flow.

The app should remember the active client and restore the dashboard directly on next launch when the saved session is still valid.

## Startup Behavior

### First run

- show login screen
- user enters `email + password`
- app receives owned client list
- auto-enter if one client
- show picker if multiple clients

### Returning user with valid session

- restore session
- restore active client
- enter dashboard directly
- start normal config polling

### Returning user with expired or invalid session

- clear auth/session state
- return to login screen
- keep non-auth settings such as language and auto-start

## Edge Cases

### No active clients

Show a clear message such as:

- “This account has no active clients assigned.”

Do not enter the runtime dashboard.

### Selected client later removed

If a saved active client is no longer available:

- keep the user logged in if the desktop session is still valid
- re-open the client picker if other clients remain
- show the no-clients state if none remain

### Network failure during login

- show retryable login error
- do not wipe a previously valid session unless the backend explicitly rejects it as invalid

### Client switch while forwards are running

- stop old runtime first
- do not allow mixed-client runtime state
- only start new runtime after the new client token is active

## UI Scope

### Replace first-run setup overlay

Current setup overlay should be replaced by:

- email/password login screen

### Add client picker overlay or panel

This should be a narrow, focused picker, not a new complex navigation system.

### Update settings page

Remove or repurpose:

- `Client ID`
- `Agent Token`

Keep:

- `Master URL`
- poll interval
- health interval
- language
- auto start
- minimize to tray

Add:

- signed-in account summary
- active client summary
- `Switch Client`
- `Sign Out`

## Files Likely In Scope

### Windows app

- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentConfig.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MasterApiClient.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/StateManager.cs`

### Backend

- `master/src/Controllers/ClientAgentController.php`
- possibly supporting auth/session utilities if a desktop login session abstraction is added

### Tests

- `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/**`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/**`
- PHP regression tests under `master/tests/**`

## Test Plan

### Backend tests

- valid email/password returns desktop login session and owned clients
- invalid credentials fail cleanly
- selecting a client not owned by the user is rejected
- one-client and multi-client flows both work
- select-client returns the right client token

### Windows app tests

- first run shows login screen instead of token setup
- one-client login skips picker
- multi-client login shows picker
- switching clients does not require logout
- sign out clears auth/session state
- expired session returns to login
- switching clients stops old forwards before loading new ones

### Regression checks

- config pull still works after client selection
- heartbeat still works
- dashboard still renders forwards correctly
- existing bond and CF bond runtime paths remain unchanged

## Success Criteria

Success means all of the following are true:

- the Windows app no longer asks normal users for `Client ID` or `Agent Token`
- users can sign in with panel `email + password`
- if exactly one client exists, the app enters directly
- if multiple clients exist, the user can choose one
- users can switch clients later without logging out
- the current runtime pull/heartbeat path still works after selection
- no forward creation feature is added to the Windows app in this pass

## Recommendation

Implement this as a narrow login bootstrap change, not a broad desktop rewrite.

That keeps the user-facing auth experience normal while preserving the runtime stack that already works.
