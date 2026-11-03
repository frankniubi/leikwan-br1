# LeiKwan Panel — WHMCS server module

Order in WHMCS → panel account is created and bound automatically → the
customer lands in the panel already signed in.

## What it does

| WHMCS action | Panel call | Effect |
|---|---|---|
| Test Connection | `GET /api/whmcs/ping` | checks URL + token |
| Module Settings (plan dropdown) | `GET /api/whmcs/plans` | lists active `service_plans` |
| Create | `POST /api/whmcs/provision` | finds the tenant by WHMCS client id, else creates one (WHMCS-generated password) and binds it; creates the service |
| Change Package | `POST /api/whmcs/provision` | updates plan / bandwidth |
| Renew | `POST /api/whmcs/renew` | expiry = next due date + grace |
| Suspend / Unsuspend | `POST /api/whmcs/suspend` / `unsuspend` | |
| Terminate | `POST /api/whmcs/terminate` | |
| Log in to Control Panel (client + admin) | `POST /api/whmcs/sso` | 60-second single-use link → `/auth/whmcs/sso` → `/tenant/services/{id}` |

After checkout, `hooks.php` shows an "Open control panel" button on the order
complete page and, when "Redirect after checkout" is on and the service is
already Active, sends the customer there automatically. The product page in the
client area has the same button.

## Account binding rules

- The WHMCS **client id** is the key. A client keeps the same panel account even
  if they change email.
- A new email creates a tenant bound to that client.
- An email that already belongs to an **unbound** panel tenant is refused
  (`whmcs_email_owned_by_unbound_account`): WHMCS does not prove the buyer owns
  the mailbox, and the SSO link would hand over the account. The owner signs in
  to the panel, links the billing account (Account → billing), and the admin
  re-runs Create. Set `WHMCS_PROVISION_ADOPT_UNBOUND_EMAIL=1` on the panel only
  if WHMCS enforces email verification before checkout.
- Admin accounts and accounts bound to another client are always refused.

## Install

1. Panel `.env`: set `WHMCS_WEBHOOK_TOKEN` to a long random string and
   `APP_URL` to the public panel URL (SSO links are built from it).
2. Copy this folder to `<whmcs>/modules/servers/leikwan/`.
3. WHMCS → System Settings → Servers → Add New Server:
   - Module: **LeiKwan Panel**
   - Hostname: the panel host (or a full `https://...` URL)
   - Access Hash: the panel's `WHMCS_WEBHOOK_TOKEN`
   - Secure: ticked
   - Test Connection, then put the server in a server group.
4. Product → Module Settings: Module **LeiKwan Panel**, the server group, pick
   the **Panel Plan**, and choose "Automatically setup the product as soon as
   the first payment is received".

Options: Panel Plan (required), Bandwidth (Mbps) override (a configurable
option whose name contains "bandwidth" also overrides it), Expiry grace days
(default 3; WHMCS itself suspends overdue services), Redirect after checkout.
Free / one-time products never expire on the panel side.

The welcome email can include `{$service_username}` (the email) and
`{$service_password}`; the password only works for newly created panel
accounts. Existing accounts keep their own password and can always use the
SSO button.
