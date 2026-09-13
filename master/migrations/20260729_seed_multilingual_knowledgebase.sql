-- Deterministic multilingual knowledgebase seed.
-- Existing topics and localized articles always win.
INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    legacy.slug, legacy.is_pinned, legacy.sort_order, legacy.created_at, legacy.updated_at
FROM knowledgebase_threads AS legacy
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key COLLATE utf8mb4_general_ci = legacy.slug COLLATE utf8mb4_general_ci
);

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', legacy.author_user_id, legacy.title, legacy.excerpt, legacy.body_html,
    legacy.status, legacy.published_at, legacy.created_at, legacy.updated_at
FROM knowledgebase_threads AS legacy
INNER JOIN knowledgebase_topics AS topic
    ON topic.topic_key COLLATE utf8mb4_general_ci = legacy.slug COLLATE utf8mb4_general_ci
WHERE legacy.slug NOT IN ('getting-started-first-forward-checklist', 'connection-modes-single-race-bond', 'bond-sidecar-setup', 'troubleshooting-common-mistakes')
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'getting-started-five-minute-overview', 1, 10, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'getting-started-five-minute-overview'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'install-client', 0, 20, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'install-client'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'getting-started-first-forward-checklist', 0, 30, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'getting-started-first-forward-checklist'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'connect-and-verify', 0, 40, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'connect-and-verify'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'connection-modes-single-race-bond', 0, 50, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'connection-modes-single-race-bond'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'bond-sidecar-setup', 0, 60, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'bond-sidecar-setup'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'troubleshooting-common-mistakes', 0, 70, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'troubleshooting-common-mistakes'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'glossary', 0, 80, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'glossary'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'no-gui-clients', 0, 90, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'no-gui-clients'
);

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Get Connected in Five Minutes', 'Follow the shortest path from an active package to a verified connection.', '<h2>What you will accomplish</h2>
<p>You will go from an active package to one verified Forward without changing advanced settings. The goal is not merely to save a configuration. The goal is to send a real request through the assigned local port and receive the expected response.</p>

<h2>Before you begin</h2>
<ul>
  <li>Confirm that your package or service is marked <strong>Active</strong>.</li>
  <li>Know the destination host, destination port, and whether the application uses TCP, UDP, or both.</li>
  <li>Have a supported Windows, Android, or Linux device ready for the Client.</li>
  <li>Never share passwords, tokens, copied install commands, private keys, or route material.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Five-minute connection path
accDescr: Start with an active package, create one Forward, install and sign in to the Client, test the assigned local port, then either finish or open troubleshooting.
A[Active package] --&gt; B[Create one Forward]
B --&gt; C[Install and sign in]
C --&gt; D[Test assigned local port]
D --&gt; E{Works?}
E -- Yes --&gt; F[Setup complete]
E -- No --&gt; G[Open troubleshooting]</pre>
<p>The shortest safe path has one decision: if an application request succeeds through the local listener, setup is complete; if it fails, keep the saved Forward and troubleshoot the visible symptom.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/01-overview.png" width="1440" height="960" alt="Tenant overview showing the three setup steps and the Create a forward action">
  <figcaption>Demo screenshot. The package, counts, and IDs shown here are demonstration values.</figcaption>
</figure>
<ol>
  <li>Open the active service in the tenant panel.</li>
  <li>Select <strong>Create Forward</strong>.</li>
  <li>Choose the offered route package and enter the destination.</li>
  <li>Save the Forward and record its assigned local port.</li>
  <li>Install the Client and sign in.</li>
  <li>Wait for a recent client sync.</li>
  <li>Point your application at the local listener.</li>
  <li>Test with the actual application protocol, such as an HTTP request, SSH connection, or game connection.</li>
</ol>

<h2>How to know it worked</h2>
<p>The panel shows an active endpoint after provisioning, the Client shows a recent sync, and the Forward reaches <strong>Connected</strong> or <strong>Running</strong>. Those signals are different from final proof. Final proof is that the application request succeeds and returns the expected result. If you control the target, its logs should also show the request arriving.</p>

<h2>If it did not work</h2>
<p><strong>Stop before changing route details at random.</strong> Keep the first Forward, note the exact error and time, then open the troubleshooting guide. A saved configuration, completed provisioning, recent sync, running process, and successful application request are separate checkpoints.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-first-forward-checklist">Create Your First Forward</a></li>
  <li><a href="/knowledgebase?thread=install-client">Install the Client</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-five-minute-overview'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Install the Client', 'Install and sign in on Windows, Android, or Linux, then confirm the client is online.', '<h2>What you will accomplish</h2>
<p>You will install the Client on Windows, Android, or Linux, sign in safely, and confirm that the device has received a recent configuration sync.</p>

<h2>Before you begin</h2>
<ul>
  <li>Have your panel <strong>Master URL</strong>, account <strong>Email</strong>, and <strong>Password</strong> ready for Windows.</li>
  <li>For Android, use an arm64 device running Android 8.0+ and be ready to approve the VPN request.</li>
  <li>For Linux, use a machine with systemd, Python 3, network access, and root-equivalent permission.</li>
  <li><strong>The Linux arm64 single-file installer supports Debian 11, 12, and 13 only.</strong> Use the regular Linux install command for amd64 or another supported flow.</li>
  <li>Treat credentials and copied commands as secrets. A Linux installer command contains a bearer agent token.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Client installation choices
accDescr: Choose the device operating system, follow its installation path, and finish by confirming a recent sync.
A[Choose device] --&gt; B{Operating system}
B -- Windows --&gt; C[Download Windows App]
B -- Android --&gt; D[Install arm64 Android app]
B -- Linux --&gt; E[Copy Linux install command]
C --&gt; F[Confirm recent sync]
D --&gt; F
E --&gt; F</pre>
<p>Each operating system has a different sign-in or installation path, but all three finish at the same observable result: the panel reports a recent client sync.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install.png" width="1440" height="960" alt="Clients Install view showing Windows, Android, and Linux installation choices">
  <figcaption>Demo screenshot. The download buttons and sync time show where to check your own session.</figcaption>
</figure>
<ol>
  <li><strong>Windows:</strong> Open <strong>Tenant → Clients</strong> and select <strong>Download Windows App</strong>.</li>
  <li>Install and open the app.</li>
  <li>Enter <strong>Master URL</strong>, <strong>Email</strong>, and <strong>Password</strong>.</li>
  <li>Select <strong>Connect</strong>.</li>
  <li>Confirm <strong>Connection successful!</strong>, the global state <strong>Connected</strong>, and each ready Forward marked <strong>Running</strong>.</li>
  <li><strong>Android:</strong> Download and install the arm64 Android app on Android 8.0+.</li>
  <li>Sign in and approve the Android VPN request.</li>
  <li>Select one of the supported LeiGod services offered by the app. Use only a service that the app lets you select.</li>
  <li>Watch the visible state move from <strong>Ready to connect</strong> to <strong>Connecting...</strong> and then <strong>Connected</strong>.</li>
  <li><strong>Linux:</strong> Open <strong>Tenant → Clients</strong>, expand <strong>Linux Install</strong>, and select <strong>Copy Install Command</strong>.</li>
  <li>For <strong>Linux ARM64 Installer</strong>, confirm that the host is arm64/aarch64 and runs Debian 11, 12, or 13.</li>
  <li>Run the copied command once with root-equivalent permission. Do not paste it into chat, tickets, logs, or shared shell history.</li>
  <li>Confirm the installer prints <code>installed leikwan-client-agent</code>.</li>
  <li>Return to the panel and confirm a recent sync. On the Linux host, you may also confirm that <code>config_pull_state.json</code> reports <code>status</code> as <code>ok</code>.</li>
</ol>

<h2>How to know it worked</h2>
<p>The panel shows a recent sync from the expected device. Windows also shows <strong>Connection successful!</strong>, global <strong>Connected</strong>, and a ready Forward as <strong>Running</strong>. Android reaches <strong>Connected</strong>. Linux reports the completed installer line and a successful pull state.</p>

<h2>If it did not work</h2>
<p><strong>Stop before sharing credentials or rerunning secret commands in public places.</strong> Check the Master URL, device clock, internet access, required permissions, and the exact visible error. On Android, confirm the VPN approval was granted. On Linux, confirm systemd and Python 3 are available, then collect diagnostics without copying the bearer agent token.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-five-minute-overview">Get Connected in Five Minutes</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'install-client'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Create Your First Forward', 'Choose a route package, enter the destination, save, and identify the assigned local port.', '<h2>What you will accomplish</h2>
<p>You will create one Forward from an active package, save only the visible destination settings, and record the assigned local port that your application will use.</p>

<h2>Before you begin</h2>
<ul>
  <li>Confirm that the package or service is <strong>Active</strong>.</li>
  <li>Know the destination hostname or IP address.</li>
  <li>Know the destination port.</li>
  <li>Know whether the application needs TCP, UDP, or both.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: First Forward form path
accDescr: Open an active package, choose a route, enter the destination and transport, save, and record the local port when the Forward is ready.
A[Open active package] --&gt; B[Choose Forward Route]
B --&gt; C[Enter Target Host and Target Port]
C --&gt; D[Choose Transport]
D --&gt; E[Save]
E --&gt; F[Forward ready]
F --&gt; G[Record assigned local port]</pre>
<p>The form asks only for the route package, destination, local port preference, and transport. Saving starts setup; the assigned local port is the value you need for the next guide.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/02-forward-form.png" width="1440" height="960" alt="Create Forward form showing Forward Route, Target Host, Target Port, Local Listen Port, and Transport">
  <figcaption>Demo screenshot. Leave Local Listen Port empty when the panel should allocate it.</figcaption>
</figure>
<ol>
  <li>Open the active service and select <strong>Create Forward</strong>.</li>
  <li>Choose the offered package under <strong>Forward Route</strong>.</li>
  <li>Enter the destination in <strong>Target Host</strong>.</li>
  <li>Enter the service port in <strong>Target Port</strong>.</li>
  <li>Leave <strong>Local Listen Port</strong> blank to accept an assigned local port, unless you were given a specific safe port.</li>
  <li>Under <strong>Transport</strong>, choose <strong>TCP</strong> for TCP-only applications.</li>
  <li>Choose <strong>TCP + UDP</strong> when the application needs both.</li>
  <li>Choose <strong>UDP</strong> only for a UDP-only application.</li>
  <li>Select <strong>Create Forward</strong> to Save.</li>
  <li>Wait for provisioning to finish, then record the assigned local port shown on the Forward card.</li>
</ol>

<h2>How to know it worked</h2>
<p>The new Forward appears with the destination and assigned local port you expect. A saved record is only the first checkpoint. Wait for active setup, then a recent client sync, and later confirm <strong>Running</strong> plus a successful application request.</p>

<h2>If it did not work</h2>
<p><strong>Stop if no Forward Route is offered or the destination is uncertain.</strong> Do not guess at hidden settings. Check that the package is active, correct only the visible fields above, and ask your administrator to publish an appropriate route package when none is available.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">Install the Client</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=glossary">Glossary</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-first-forward-checklist'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Connect and Verify', 'Choose the correct client address, test the service, and confirm that the route is working.', '<h2>What you will accomplish</h2>
<p>You will choose the address that matches where your application runs, send a request through the Forward, and separate a panel status from proof that the target application received traffic.</p>

<h2>Before you begin</h2>
<ul>
  <li>Record the Forward''s local port.</li>
  <li>Confirm that the Client has a recent sync and the Forward shows <strong>Running</strong>.</li>
  <li>Know whether the application runs on the Client device or on another device.</li>
  <li>Use a protocol-aware test. A TCP port check alone does not prove an HTTP, SSH, database, or game request works correctly.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Choose the client connection address
accDescr: Use loopback on the same Client device, use a reachable Windows or Linux client-host address for another device with network controls as needed, and keep Android loopback-only.
A[Locate the application] --&gt; B{Same Client device?}
B -- Yes --&gt; C[Use 127.0.0.1 and local port]
B -- No --&gt; D{Client platform}
D -- Windows or Linux --&gt; E[Use reachable client-host address and port]
E --&gt; F[Allow firewall security group or NAT as needed]
D -- Android --&gt; G[Keep listener loopback-only]
C --&gt; H[Test application protocol]
F --&gt; H
G --&gt; H</pre>
<p>The deciding fact is where the application runs. Same-device traffic uses loopback. Cross-device traffic is possible only through a reachable Windows or Linux Client host and may need network access rules; Android remains loopback-only unless it is separately exposed by another supported setup.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="Forward result showing the local listen address, target, transport, and Running status">
  <figcaption>Demo screenshot. Use the local listen value from your own Forward card, not the demonstration value.</figcaption>
</figure>
<ol>
  <li>If the application is on the Client device, enter <code>127.0.0.1</code> and the Forward''s local port.</li>
  <li>If another device uses a Windows or Linux Client, enter a reachable Windows or Linux client-host address and the same local port.</li>
  <li>For cross-device access, allow that port in the host firewall.</li>
  <li>If applicable, allow it in the cloud security group.</li>
  <li>If the Client is behind a router, configure NAT only when your network owner approves it.</li>
  <li>Do not present Android as a cross-device listener. Its listener stays loopback-only unless separately exposed through an approved design.</li>
  <li>Send a request with the actual application protocol.</li>
  <li>Check the response and, when available, confirm receipt in the target logs.</li>
</ol>

<h2>How to know it worked</h2>
<p>Your application receives the expected response through the Client address and local port. The strongest confirmation is a matching request in the target logs. <strong>Connected</strong>, <strong>Running</strong>, and an open local port are useful checkpoints, but none alone proves the destination application handled the request.</p>

<h2>If it did not work</h2>
<p><strong>Stop before opening broad firewall ranges or assuming a public IP always works.</strong> Recheck same-device versus cross-device addressing, confirm the exact local port, and test the destination service from its own host. Then use diagnostics or ask support with the error, time, Client platform, and Forward name, but no secrets.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">Install the Client</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
  <li><a href="/knowledgebase?thread=glossary">Glossary</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'connect-and-verify'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Choose a Connection Mode', 'Understand Single, Race, and Bond by the outcome you need rather than internal topology.', '<h2>What you will accomplish</h2>
<p>You will choose among Single, Race, and Bond by the result you need, while avoiding promises that the selected mode or platform cannot guarantee.</p>

<h2>Before you begin</h2>
<ul>
  <li>Start with a working basic Forward when possible.</li>
  <li>Read the published route contract for your package and Client platform.</li>
  <li>Identify whether you need simplicity, path redundancy, or an explicitly supported Bond design.</li>
  <li>Do not choose a mode only because its name sounds faster.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Connection mode decision
accDescr: Choose Single for the simplest stable path, Race for supported path redundancy without aggregation, and Bond only when the plan explicitly supports it and its requirements are verified.
A[What outcome do you need?] --&gt; B{Simplest stable path?}
B -- Yes --&gt; C[Choose Single]
B -- No --&gt; D{Redundancy without aggregation?}
D -- Yes --&gt; E[Choose Race]
D -- No --&gt; F{Plan explicitly supports Bond?}
F -- Yes --&gt; G[Choose Bond and verify requirements]
F -- No --&gt; H[Ask your administrator]</pre>
<p>The safe default is Single. Race is for supported path selection or redundancy, not combined bandwidth. Bond is an advanced choice only when the published route contract says the package and platform support it.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="Forward result showing the user-facing route result instead of internal Single, Race, or Bond wiring">
  <figcaption>Demo screenshot. The tenant checks the resulting route and status; internal topology stays managed by the plan.</figcaption>
</figure>
<ol>
  <li>Choose <strong>Single</strong> for the simplest stable setup.</li>
  <li>Choose <strong>Race</strong> when the contract offers multiple usable paths and you want redundancy without aggregation.</li>
  <li>Remember that Race is not bandwidth aggregation.</li>
  <li>On Linux TCP Race, expect one winner per connection.</li>
  <li>On Linux UDP Race, the Client may fan out datagrams across usable paths.</li>
  <li>Use Race only with at least two usable endpoints. Exact behavior differs by platform.</li>
  <li>Choose <strong>Bond</strong> only when the published route contract explicitly supports it.</li>
  <li>Verify every listed Bond requirement before testing. Not every Bond route requires a Sidecar.</li>
  <li>If support is unclear, ask your administrator instead of guessing.</li>
</ol>

<h2>How to know it worked</h2>
<p>The selected mode is allowed by the package, supported by the Client platform, and the application succeeds under the intended conditions. For Race, test that supported requests continue when one usable path is unavailable. For Bond, verify the published requirements and actual application result rather than inferring performance from status alone.</p>

<h2>If it did not work</h2>
<p><strong>Stop before promising speed doubling, full configured bandwidth, or use of every path.</strong> Return to Single if it is available and stable, record the failing platform and route package, then ask your administrator whether the contract supports the desired Race or Bond behavior.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=bond-sidecar-setup">Configure a Sidecar</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
  <li><a href="/knowledgebase?thread=glossary">Glossary</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'connection-modes-single-race-bond'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Configure a Sidecar', 'Create and verify a Sidecar only when your Bond route package requires one.', '<h2>What you will accomplish</h2>
<p>You will create and verify a Sidecar only when a Bond package''s published route contract requires one. A Sidecar is intermediate target-side transport. It is not the target application.</p>

<h2>Before you begin</h2>
<ul>
  <li>Confirm that the selected Bond package explicitly requires a Sidecar.</li>
  <li>Have a target-side machine that can reach the backend service.</li>
  <li>Know the approved <strong>Sidecar Host</strong> and Bond transport port.</li>
  <li>Have root-equivalent permission on the Sidecar machine.</li>
  <li>Keep the panel-provided install command and all credentials secret.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart LR
accTitle: Bond route with a Sidecar
accDescr: The Client reaches the target service through a managed Slave path and an intermediate Sidecar.
A[Client] --&gt; B[Managed Slave path]
B --&gt; C[Sidecar]
C --&gt; D[Target service]</pre>
<p>The Sidecar sits between the managed path and the target service. It must be online and able to reach the backend, but users still verify the target application separately.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/06-target-endpoint.png" width="1440" height="960" alt="Target Endpoints form showing the host and all three listen port fields">
  <figcaption>Demo screenshot. Only create a tenant-owned endpoint when the published route contract requires it.</figcaption>
</figure>
<ol>
  <li>Open <strong>Target Endpoints</strong> under <strong>Advanced</strong>.</li>
  <li>Select the <strong>Create</strong> tab.</li>
  <li>In <strong>Create Transport Endpoint</strong>, enter a recognizable <strong>Name</strong>.</li>
  <li>Enter the approved <strong>Sidecar Host</strong>.</li>
  <li>Enter the approved Bond transport port in <strong>Transport Listen Port</strong>.</li>
  <li>Select <strong>Save Transport Endpoint</strong>.</li>
  <li>On its card, open <strong>Install Command</strong> and copy it without exposing its contents.</li>
  <li>Run it on the Sidecar machine with root-equivalent permission.</li>
  <li>Return to the panel and wait for <strong>Last Heartbeat</strong>, <strong>Agent Version</strong>, and <strong>Last Status</strong> to update.</li>
  <li>From the Sidecar machine, test the backend listener at the configured target host and port.</li>
  <li>Create or refresh the Bond Forward as directed by the published route contract.</li>
</ol>

<h2>How to know it worked</h2>
<p>The Sidecar reports a recent <strong>Last Heartbeat</strong>, the expected agent status, and the Bond Forward can carry a real application request to the target. A heartbeat alone does not prove backend health. Separately test the backend listener and, if possible, confirm the request in target logs.</p>

<h2>If it did not work</h2>
<p><strong>Stop before changing ports, addresses, or route settings at random.</strong> If heartbeat is missing, check the Sidecar service and outbound access. If heartbeat is current but the application fails, test the backend from the Sidecar host. Share diagnostics with support, but never share the generated install command, tokens, passwords, route keys, private keys, or internal line or session material.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=connection-modes-single-race-bond">Choose a Connection Mode</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'bond-sidecar-setup'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Troubleshoot Common Problems', 'Start from the symptom you can see and follow the shortest safe recovery path.', '<h2>What you will accomplish</h2>
<p>You will start with the symptom you can see, check one boundary at a time, and stop before broad or random changes make the original problem harder to identify.</p>

<h2>Before you begin</h2>
<ul>
  <li>Write down the exact error, time, Client platform, Forward name, and local port.</li>
  <li>Keep the saved Forward unchanged while you check its current state.</li>
  <li>Do not include passwords, tokens, generated commands, route keys, private keys, or internal session material in screenshots or support messages.</li>
  <li>Use the real application protocol for the final test.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Symptom-first troubleshooting
accDescr: After a failed request, check recent sync, the address, network access to the port, Forward and route health, and the target service before using diagnostics and support.
A[Application request failed] --&gt; B{Recent sync?}
B -- No --&gt; C[Restore Client sync]
B -- Yes --&gt; D{Correct address?}
D -- No --&gt; E[Use loopback or reachable client host]
D -- Yes --&gt; F{Port allowed?}
F -- No --&gt; G[Allow only the required port]
F -- Yes --&gt; H{Forward and route healthy?}
H -- No --&gt; I[Open View Diagnostics]
H -- Yes --&gt; J[Test target from its own host]
C --&gt; K[Retry application]
E --&gt; K
G --&gt; K
I --&gt; L[Diagnostics and support]
J --&gt; L</pre>
<p>The checks move from the Client toward the target. Fix the first failed boundary, retry once, and preserve evidence instead of making random topology changes.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/05-diagnostics.png" width="1440" height="960" alt="Client diagnostics view showing Run Diagnostics before View Diagnostics and a healthy result">
  <figcaption>Demo screenshot. Run a fresh check first, then open View Diagnostics for the details.</figcaption>
</figure>
<ol>
  <li>Confirm a recent sync for the expected Client.</li>
  <li>Confirm the Forward shows <strong>Running</strong>.</li>
  <li>Check the correct address: use <code>127.0.0.1</code> on the same Client device.</li>
  <li>For another device, use a reachable Windows or Linux Client-host address. Do not treat Android as a cross-device listener.</li>
  <li>Confirm the local port matches the Forward card.</li>
  <li>For cross-device use, confirm that only the required port is allowed by the firewall, security group, and NAT as applicable.</li>
  <li>Check that the forward and route are healthy in <strong>View Diagnostics</strong>.</li>
  <li>Test the target service from its own host and port.</li>
  <li>Retry with the actual application protocol.</li>
  <li>If it still fails, collect diagnostics and contact support with the symptom and time.</li>
</ol>

<h2>How to know it worked</h2>
<p>The first failing boundary becomes healthy and the real application request succeeds. A recent sync proves configuration delivery, <strong>Running</strong> proves a local process state, and a successful target response proves the complete request path.</p>

<h2>If it did not work</h2>
<p><strong>Stop before reinstalling everything, opening broad port ranges, or making random topology changes.</strong> Preserve the error and diagnostics. Contact support with the Forward name, Client platform, test time, address type, local port, and sanitized results. Never send secrets.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">Install the Client</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=glossary">Glossary</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'troubleshooting-common-mistakes'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Glossary', 'Plain-language definitions for the product and network terms used in the guides.', '<h2>What you will accomplish</h2>
<p>You will understand the product and network terms used throughout the beginner guides, without needing to learn the internal implementation.</p>

<h2>Before you begin</h2>
<ul>
  <li>Keep the relevant guide open so you can match each term to a visible field or status.</li>
  <li>Remember that similar-looking status words can prove different checkpoints.</li>
  <li>Do not copy secret values while asking what a term means.</li>
</ul>

<h2>Steps</h2>
<ol>
  <li><strong>Active package:</strong> A purchased package or service that currently allows you to create and use a Forward.</li>
  <li><strong>Forward:</strong> A saved instruction that sends traffic from a Client listener to a target host and port through an offered route.</li>
  <li><strong>Forward Route:</strong> The route package offered in the first Forward form. Choose the published option that matches your service.</li>
  <li><strong>Target Host:</strong> The hostname or IP address of the destination service.</li>
  <li><strong>Target Port:</strong> The destination service''s port.</li>
  <li><strong>Local Listen Port:</strong> The port on the Client side that your application connects to.</li>
  <li><strong>Client:</strong> The Windows, Android, or Linux software that receives your Forward configuration and creates the local listener.</li>
  <li><strong>Recent sync:</strong> Evidence that the Client recently pulled configuration from the panel. It does not prove the target application responded.</li>
  <li><strong>Running:</strong> A Client status showing that the Forward process is active. It is not final application proof.</li>
  <li><strong>Single:</strong> The simplest supported connection mode, using one route path for a request.</li>
  <li><strong>Race:</strong> A redundancy or path-selection mode. It is not bandwidth aggregation, and behavior depends on platform and transport.</li>
  <li><strong>Bond:</strong> An advanced mode used only when the package and platform explicitly support its published requirements. It does not guarantee doubled speed or use of every path.</li>
  <li><strong>Sidecar:</strong> Optional intermediate target-side transport required by some Bond routes. It is not the target application.</li>
  <li><strong>Application verification:</strong> A real request made with the intended protocol that receives the expected response, ideally confirmed in target logs.</li>
</ol>

<h2>How to know it worked</h2>
<p>You can identify which value belongs in each visible field, distinguish delivery and process states from application success, and choose the correct guide without guessing.</p>

<h2>If it did not work</h2>
<p><strong>Stop before guessing a value or sharing a secret.</strong> Use the plain cross-references below, then ask your administrator or support to explain the visible label in context.</p>

<h2>Related guides</h2>
<p>Start with “Get Connected in Five Minutes.” Use “Create Your First Forward” for form fields, “Connect and Verify” for addresses and proof, “Choose a Connection Mode” for Single, Race, and Bond, and “Configure a Sidecar” only when the published route requires one.</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'glossary'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Run the Client Without a GUI', 'Install the windowless client on Windows or macOS as a service or daemon, verify it, and remove it again.', '<h2>What you will accomplish</h2>
<p>You will set up a computer that keeps your line running on its own — a server, a mini PC, or any machine that stays switched on. Nobody has to log in, and you will not see a window or an icon. You install it once, check that it works, and from then on you manage it with one short command.</p>

<h2>Before you begin</h2>
<ul>
  <li>Your <strong>Client ID</strong> and <strong>Agent Token</strong> from the panel''s <strong>Clients</strong> page. They are the machine''s login — treat them like a password.</li>
  <li>Permission to install software on that machine (an administrator on Windows, your own password on macOS).</li>
  <li>Two minutes. The download is about 80 MB on macOS and 34 MB on Windows.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Setting up a computer without a screen
accDescr: Pick the computer, install the background client, then confirm the line is running in the panel.
 A[Pick the computer] --&gt; B{Which system}
 B -- macOS --&gt; C[Install the app, then run one command]
 B -- Windows --&gt; D[Unpack the download, then run one command]
 C --&gt; E[Check that it says running]
 D --&gt; E
 E --&gt; F[See the device online in the panel]</pre>
<p>The picture is the whole job: choose the system, run one command, then confirm. Everything after that happens in the background.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install.png" width="1440" height="960" alt="Clients page showing the download buttons and this machine''s Client ID and Agent Token">
  <figcaption>Your Client ID and Agent Token are on this page, together with the download for your system.</figcaption>
</figure>
<ol>
  <li><strong>Choose the computer.</strong> It should be one that stays on. It does not need a screen, a keyboard, or anyone logged in.</li>
  <li><strong>Install the app first.</strong> On macOS, open the downloaded disk image and drag <strong>LeiKwan Bridge</strong> into <strong>Applications</strong>. On Windows, unpack the no-GUI download into a folder of your choice.</li>
  <li><strong>Run the installer once.</strong> On macOS, open Terminal and paste the macOS command from the Clients page. On Windows, right-click the unpacked folder and choose <em>Open in Terminal</em> as administrator, then paste the Windows command. Both commands already contain this machine''s identity.</li>
  <li><strong>Check it started.</strong> Type <code>leikwan status</code>. You want to see the line listed as running.</li>
  <li><strong>Look at the panel.</strong> The device appears online on the Clients page, exactly like a computer with the normal app.</li>
  <li><strong>Later, three commands are all you need.</strong> <code>leikwan status</code> to check, <code>leikwan restart</code> if something looks stuck, and <code>leikwan update</code> to move to the newest version, and <code>leikwan update --check</code> if you only want to know whether an update exists.</li>
  <li><strong>To remove it:</strong> run the uninstall command from the same Clients page, or <code>leikwan stop</code> to switch it off for now.</li>
</ol>

<h2>How to know it worked</h2>
<p>Two simple checks. In the panel, this computer shows up as online, the same as any other device. On the computer itself, <code>leikwan status</code> prints the line as running — and it ends with a success code, so a monitoring tool can watch it for you. If you ever want the details, <code>leikwan logs -n 50</code> shows the last 50 lines and <code>leikwan doctor</code> summarises the whole setup.</p>

<h2>If it did not work</h2>
<p><strong>Stop and do not share your Agent Token with anyone — not in chat, tickets, or screenshots.</strong> The most common cause is a mistyped or expired Agent Token, which makes the panel show the device as offline while the log says the login was refused. Copy the Client ID and Agent Token again from the Clients page and run the installer command once more. If the device is online in the panel but a line still shows as stopped, wait half a minute — the panel decides which lines should run, and the computer follows within a few seconds. Still stuck? Send support the output of <code>leikwan doctor</code>, which contains no secrets.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">Install the Client</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'no-gui-clients'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '五分钟完成首次连接', '按最短路径完成套餐、转发、客户端安装和连接验证。', '<h2>你将完成什么</h2>
<p>你将从已生效的套餐或服务出发，创建一条转发，安装并登录客户端，再用真实应用协议完成首次连接验证。保存配置只是起点，最终目标是应用收到预期响应。</p>

<h2>开始前准备</h2>
<ul>
  <li>确认套餐或服务状态为<strong>有效</strong>。</li>
  <li>准备目标主机、目标端口，以及应用所需的 TCP、UDP 或两者。</li>
  <li>准备一台支持的 Windows、Android 或 Linux 设备。</li>
  <li>不要向任何人展示密码、令牌、复制的安装命令、密钥、会话或线路材料。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 五分钟首次连接路径
accDescr: 从有效套餐开始，创建一条转发，安装并登录客户端，测试系统分配的本地端口，成功后结束，失败时进入问题排查。
A[有效套餐] --&gt; B[创建一条转发]
B --&gt; C[安装并登录客户端]
C --&gt; D[测试分配的本地端口]
D --&gt; E{连接成功吗}
E -- 是 --&gt; F[首次设置完成]
E -- 否 --&gt; G[开始排查问题]</pre>
<p>最短且安全的路径只有一次判断：真实应用请求通过本地监听端口并获得正确结果，就算完成；否则先保留现有转发，再从可见症状着手排查。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/01-overview.png" width="1440" height="960" alt="租户概览页显示三步设置流程和 Create a forward 操作">
  <figcaption>演示截图：套餐、数量和 ID 都是演示值，请以你自己的面板为准。</figcaption>
</figure>
<ol>
  <li>在租户面板中打开已生效的服务。</li>
  <li>选择 <strong>Create Forward</strong>。</li>
  <li>选择系统提供的线路方案，并填写目标地址和端口。</li>
  <li>保存转发，记下系统分配的本地端口。</li>
  <li>安装客户端并登录。</li>
  <li>等待客户端出现最近同步记录。</li>
  <li>让应用连接本地监听地址和端口。</li>
  <li>使用实际应用协议验证，例如发出 HTTP 请求、建立 SSH 连接或启动游戏连接。</li>
</ol>

<h2>如何确认成功</h2>
<p>保存后已完成配置记录，预配完成后端点可用，最近同步表示配置已送达，<strong>Connected</strong> 或 <strong>Running</strong> 表示运行状态正常；这些检查点彼此独立。最终证据仍是应用请求成功，并在可查看时由目标日志确认请求到达。</p>

<h2>如果没有成功</h2>
<p><strong>先停止随意更改线路或端口。</strong>保留第一条转发，记录错误内容和发生时间，再按排查指南逐项检查。不要把保存、预配、最近同步、运行连接和应用成功混为一谈。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-first-forward-checklist">创建第一条转发</a></li>
  <li><a href="/knowledgebase?thread=install-client">安装客户端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接并验证</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-five-minute-overview'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '安装客户端', '在 Windows、Android 或 Linux 上安装并登录，然后确认客户端在线。', '<h2>你将完成什么</h2>
<p>你将在 Windows、Android 或 Linux 上安装并登录客户端，随后确认设备在线且已经收到最近的转发配置。</p>

<h2>开始前准备</h2>
<ul>
  <li>Windows 登录需要面板提供的 <strong>Master URL</strong>、<strong>Email</strong> 和 <strong>Password</strong>。</li>
  <li>Android 需要 arm64、Android 8.0+ 设备，并允许系统建立 VPN 连接。</li>
  <li>Linux 主机需要 systemd、Python 3、网络访问能力和相当于 root 的权限。</li>
  <li><strong>Linux ARM64 单文件安装包仅支持 Debian 11、12、13。</strong>其他发行版或 amd64 主机请使用对应的 Linux 安装命令。</li>
  <li>登录信息和复制的命令都属于秘密；Linux 安装命令包含可直接用于代理身份验证的令牌。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 客户端安装路径
accDescr: 按设备系统选择 Windows、Android 或 Linux 安装流程，完成后都要确认客户端最近同步。
A[选择设备] --&gt; B{操作系统}
B -- Windows --&gt; C[下载 Windows 应用]
B -- Android --&gt; D[安装 arm64 Android 应用]
B -- Linux --&gt; E[复制 Linux 安装命令]
C --&gt; F[确认最近同步]
D --&gt; F
E --&gt; F</pre>
<p>三个平台的安装入口和状态名称不同，但结束条件一致：目标设备上的客户端已连接，并且面板显示最近同步。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install.png" width="1440" height="960" alt="Clients 的 Install 页面显示 Windows、Android 和 Linux 安装入口">
  <figcaption>演示截图：按钮和同步时间用于说明位置，不代表你的设备状态。</figcaption>
</figure>
<ol>
  <li><strong>Windows：</strong>打开 <strong>Tenant → Clients</strong>，选择 <strong>Download Windows App</strong>。</li>
  <li>安装并打开应用，填写 <strong>Master URL</strong>、<strong>Email</strong> 和 <strong>Password</strong>。</li>
  <li>选择 <strong>Connect</strong>，确认出现 <strong>Connection successful!</strong>，全局状态为 <strong>Connected</strong>，可用转发显示 <strong>Running</strong>。</li>
  <li><strong>Android：</strong>在 arm64、Android 8.0+ 设备上安装应用并登录。</li>
  <li>同意 Android 的 VPN 授权，只选择应用列出的受支持 LeiGod 服务。</li>
  <li>观察状态从 <strong>Ready to connect</strong> 经过 <strong>Connecting...</strong> 变为 <strong>Connected</strong>。不要据此承诺 Android 支持 Race 或 MPTCP。</li>
  <li><strong>Linux：</strong>打开 <strong>Tenant → Clients</strong>，展开 <strong>Linux Install</strong>，选择 <strong>Copy Install Command</strong>。</li>
  <li>如果使用 <strong>Linux ARM64 Installer</strong>，请确认主机是 arm64/aarch64，并且操作系统为 Debian 11、12 或 13。</li>
  <li>以相当于 root 的权限运行一次复制的命令；不要把它粘贴到聊天、工单、日志或共享历史记录。</li>
  <li>确认安装器输出 <code>installed leikwan-client-agent</code>。</li>
  <li>回到面板查看最近同步；也可在主机上确认 <code>config_pull_state.json</code> 的 <code>status</code> 为 <code>ok</code>。</li>
</ol>

<h2>如何确认成功</h2>
<p>面板应显示预期客户端的最近同步。Windows 同时显示 <strong>Connection successful!</strong>、<strong>Connected</strong> 和转发的 <strong>Running</strong>；Android 到达 <strong>Connected</strong>；Linux 安装完成且拉取状态正常。之后仍需单独验证真实应用连接。</p>

<h2>如果没有成功</h2>
<p><strong>先停止公开凭据或反复运行秘密命令。</strong>核对 Master URL、设备时间、网络和权限。Android 要确认 VPN 授权；Linux 要确认 systemd 与 Python 3 可用。收集错误时删除代理身份验证令牌等秘密内容。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-five-minute-overview">五分钟完成首次连接</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接并验证</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'install-client'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '创建第一条转发', '选择线路方案、填写目标地址并记下系统分配的本地端口。', '<h2>你将完成什么</h2>
<p>你将从已生效的套餐创建第一条转发，只填写表单中看得见的目标和传输选项，并记下应用后续连接所需的本地端口。</p>

<h2>开始前准备</h2>
<ul>
  <li>确认套餐或服务状态为<strong>有效</strong>。</li>
  <li>准备目标服务的主机名或 IP 地址。</li>
  <li>确认目标端口，以及应用使用 TCP、UDP 还是两者。</li>
  <li>不要猜测表单之外的内部设置。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 第一条转发表单路径
accDescr: 打开有效套餐，选择转发线路，填写目标和传输方式，保存后等待预配完成并记下系统分配的本地端口。
A[打开有效套餐] --&gt; B[选择 Forward Route]
B --&gt; C[填写 Target Host 和 Target Port]
C --&gt; D[选择 Transport]
D --&gt; E[保存]
E --&gt; F[等待转发可用]
F --&gt; G[记下分配的本地端口]</pre>
<p>第一张表单只需要线路方案、目标地址、目标端口、本地端口偏好和传输类型。保存会启动预配流程，系统分配的本地端口是下一步连接客户端时要使用的值。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/02-forward-form.png" width="1440" height="960" alt="Create Forward 表单显示 Forward Route、Target Host、Target Port、Local Listen Port 和 Transport">
  <figcaption>演示截图：如果没有指定端口，把 Local Listen Port 留空，让面板分配。</figcaption>
</figure>
<ol>
  <li>打开已生效的服务，选择 <strong>Create Forward</strong>。</li>
  <li>在 <strong>Forward Route</strong> 中选择系统提供的线路方案。</li>
  <li>在 <strong>Target Host</strong> 填写目标主机名或 IP。</li>
  <li>在 <strong>Target Port</strong> 填写目标服务端口。</li>
  <li>没有指定安全端口时，将 <strong>Local Listen Port</strong> 留空，让系统分配本地端口。</li>
  <li>在 <strong>Transport</strong> 中为仅使用 TCP 的应用选择 <strong>TCP</strong>。</li>
  <li>应用同时需要两种传输时选择 <strong>TCP + UDP</strong>，仅使用 UDP 时才选择 <strong>UDP</strong>。</li>
  <li>选择 <strong>Create Forward</strong> 完成保存。</li>
  <li>等待预配完成，再从转发卡片记下系统分配的本地端口。</li>
</ol>

<h2>如何确认成功</h2>
<p>新转发卡片应显示预期的目标和本地端口。保存记录、预配完成、客户端最近同步、显示 <strong>Running</strong> 和应用成功是五个不同检查点；先等待配置可用，再在客户端同步后验证真实请求。</p>

<h2>如果没有成功</h2>
<p><strong>先停止猜测隐藏选项。</strong>如果没有可选的 Forward Route，或目标地址不确定，请确认套餐有效，只修正上述可见字段，并联系管理员发布合适的线路方案。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安装客户端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接并验证</a></li>
  <li><a href="/knowledgebase?thread=glossary">名词解释</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-first-forward-checklist'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '连接并验证', '选择正确的客户端地址，测试服务并确认转发已经工作。', '<h2>你将完成什么</h2>
<p>你将根据应用所在设备选择正确的客户端地址，通过转发发出真实请求，并区分面板状态、运行连接和目标应用成功这几类证据。</p>

<h2>开始前准备</h2>
<ul>
  <li>记下转发的本地端口。</li>
  <li>确认客户端有最近同步，转发显示 <strong>Running</strong>。</li>
  <li>确认应用与客户端在同一台设备上，还是位于另一台设备。</li>
  <li>准备符合应用协议的测试；仅检查 TCP 端口不能证明 HTTP、SSH、数据库或游戏请求可用。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 选择客户端连接地址
accDescr: 应用与客户端同机时使用回环地址，跨设备时仅使用可达的 Windows 或 Linux 客户端主机地址并按需配置网络规则，Android 保持仅回环监听。
A[确认应用所在设备] --&gt; B{与客户端同机吗}
B -- 是 --&gt; C[使用 127.0.0.1 和本地端口]
B -- 否 --&gt; D{客户端平台}
D -- Windows 或 Linux --&gt; E[使用可达主机地址和端口]
E --&gt; F[按需允许防火墙安全组或 NAT]
D -- Android --&gt; G[保持仅回环监听]
C --&gt; H[测试实际应用协议]
F --&gt; H
G --&gt; H</pre>
<p>地址选择取决于应用在哪里运行。同机连接使用回环地址；跨设备只能通过网络可达的 Windows 或 Linux 客户端主机，并可能需要有限的网络放行；Android 的监听默认仅限回环，除非另有受支持的暴露方案。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="转发结果卡片显示本地监听地址、目标、传输类型和 Running 状态">
  <figcaption>演示截图：连接应用时使用你自己的 Forward 卡片中的本地监听值。</figcaption>
</figure>
<ol>
  <li>应用与客户端同机时，填写 <code>127.0.0.1</code> 和转发的本地端口。</li>
  <li>另一台设备需要访问 Windows 或 Linux 客户端时，填写可访问的 Windows 或 Linux 客户端主机地址和相同端口。</li>
  <li>只在主机防火墙中放行所需端口。</li>
  <li>云主机按需在安全组中放行该端口。</li>
  <li>客户端位于路由器后方时，仅在网络负责人批准后配置 NAT。</li>
  <li>不要把 Android 当作跨设备监听器；没有单独暴露方案时，它仅监听回环地址。</li>
  <li>使用实际应用协议发出请求。</li>
  <li>检查应用响应；可查看目标端时，再核对目标日志。</li>
</ol>

<h2>如何确认成功</h2>
<p>应用通过所选客户端地址和本地端口获得预期响应，目标日志也能对应到该请求时，连接才真正通过。<strong>Connected</strong>、<strong>Running</strong> 和开放的本地端口都是中间状态，不能单独证明目标应用已处理请求。</p>

<h2>如果没有成功</h2>
<p><strong>先停止开放大范围端口；公网 IP 并非默认连接入口。</strong>重新核对同机或跨设备地址、本地端口和目标服务；先从目标主机测试后端，再提交已去除秘密的错误、时间、客户端平台和转发名称。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安装客户端</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a></li>
  <li><a href="/knowledgebase?thread=glossary">名词解释</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'connect-and-verify'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '选择连接模式', '按稳定、容错或多路径需求理解 Single、Race 和 Bond。', '<h2>你将完成什么</h2>
<p>你将按稳定、容错和多路径需求理解 Single、Race 与 Bond，为转发选择已被线路方案和客户端平台支持的连接模式，并避开无法保证的性能承诺。</p>

<h2>开始前准备</h2>
<ul>
  <li>如果条件允许，先让一条基础转发稳定工作。</li>
  <li>阅读套餐发布的线路约定，并确认客户端平台。</li>
  <li>分清自己需要的是简单稳定、路径容错，还是明确受支持的 Bond 方案。</li>
  <li>不要只因为名称听起来更快就更改模式。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 连接模式选择路径
accDescr: 需要最简单的稳定路径时选择 Single，需要受支持的非聚合容错时选择 Race，只有方案明确支持且要求均已核实时才选择 Bond。
A[你需要什么结果] --&gt; B{最简单的稳定路径吗}
B -- 是 --&gt; C[选择 Single]
B -- 否 --&gt; D{需要非聚合容错吗}
D -- 是 --&gt; E[选择 Race]
D -- 否 --&gt; F{方案明确支持 Bond 吗}
F -- 是 --&gt; G[选择 Bond 并核对要求]
F -- 否 --&gt; H[咨询管理员]</pre>
<p>安全默认选项是 Single。Race 用于受支持的路径选择或容错，不会合并带宽；Bond 只适用于发布的线路约定明确支持该套餐和平台的情况。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="转发结果页显示用户可见的线路结果，而不是内部 Single、Race 或 Bond 组合">
  <figcaption>演示截图：租户检查线路结果和状态，内部拓扑由方案管理。</figcaption>
</figure>
<ol>
  <li>需要最简单、稳定的连接时选择 <strong>Single</strong>。</li>
  <li>线路约定提供至少两个可用端点，且需要不聚合带宽的容错时选择 <strong>Race</strong>。</li>
  <li>Race 不是带宽聚合。</li>
  <li>Linux 的 TCP Race 通常每条连接选择一个胜出路径。</li>
  <li>Linux 的 UDP Race 可能把数据报发送到多个可用路径。</li>
  <li>Race 的具体行为因客户端平台而异，Android 不承诺 Race 或 MPTCP。</li>
  <li>只有已发布的线路约定明确支持时才选择 <strong>Bond</strong>。</li>
  <li>逐项核对 Bond 要求；仅在线路约定要求时使用 Sidecar。</li>
  <li>支持范围不清楚时咨询管理员，不要猜测。</li>
</ol>

<h2>如何确认成功</h2>
<p>连接模式必须同时符合套餐约定和客户端平台能力，真实应用也要在预期条件下成功。Race 应验证一个可用路径失效后受支持的请求仍能继续；Bond 应核对约定和实际结果，不能根据状态推断速度翻倍、跑满带宽或所有路径都被使用。</p>

<h2>如果没有成功</h2>
<p><strong>先停止承诺加速倍数或所有路径同时工作。</strong>如果 Single 可用且稳定，先恢复 Single，记录失败的平台和线路方案，再请管理员确认所需的 Race 或 Bond 行为是否受支持。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=bond-sidecar-setup">配置 Sidecar</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a></li>
  <li><a href="/knowledgebase?thread=glossary">名词解释</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'connection-modes-single-race-bond'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '配置 Sidecar', '仅在线路方案明确需要时创建并验证 Sidecar。', '<h2>你将完成什么</h2>
<p>你将仅在线路方案明确要求时创建并验证 Sidecar。Sidecar 是目标侧的中间传输组件，不是目标应用，也不是 Bond 转发的通用必备组件。</p>

<h2>开始前准备</h2>
<ul>
  <li>确认所选 Bond 套餐已发布的线路约定明确要求 Sidecar。</li>
  <li>准备一台可以访问后端服务的目标侧主机。</li>
  <li>向管理员确认 <strong>Sidecar Host</strong> 和 Bond 传输端口。</li>
  <li>准备 Sidecar 主机上相当于 root 的权限。</li>
  <li>面板生成的安装命令和所有凭据都必须保密。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart LR
accTitle: 使用 Sidecar 的 Bond 线路
accDescr: 客户端经过托管的 Slave 路径到达中间 Sidecar，再由 Sidecar 连接目标服务。
A[客户端] --&gt; B[托管 Slave 路径]
B --&gt; C[Sidecar]
C --&gt; D[目标服务]</pre>
<p>Sidecar 位于托管路径与目标服务之间。它既要在线，也要能访问后端监听；但最终仍需通过真实应用请求单独验证目标服务。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/06-target-endpoint.png" width="1440" height="960" alt="Target Endpoints 表单显示主机和三个监听端口字段">
  <figcaption>演示截图：只有已发布线路约定要求时，才创建租户自有 endpoint。</figcaption>
</figure>
<ol>
  <li>在 <strong>Advanced</strong> 下打开 <strong>Target Endpoints</strong>。</li>
  <li>选择 <strong>Create</strong> 标签页。</li>
  <li>在 <strong>Create Transport Endpoint</strong> 中填写容易识别的 <strong>Name</strong>。</li>
  <li>填写管理员确认的 <strong>Sidecar Host</strong>。</li>
  <li>在 <strong>Transport Listen Port</strong> 填写获准的 Bond 传输端口。</li>
  <li>选择 <strong>Save Transport Endpoint</strong>。</li>
  <li>在卡片中打开 <strong>Install Command</strong> 并复制，但不要展示命令内容。</li>
  <li>以相当于 root 的权限在 Sidecar 主机上运行命令。</li>
  <li>回到面板，等待 <strong>Last Heartbeat</strong>、<strong>Agent Version</strong> 和 <strong>Last Status</strong> 更新。</li>
  <li>从 Sidecar 主机直接测试配置目标地址和端口上的后端监听服务。</li>
  <li>根据已发布线路约定创建或刷新 Bond 转发，并验证客户端连接。</li>
</ol>

<h2>如何确认成功</h2>
<p>Sidecar 应报告最近心跳和预期代理状态，Bond 转发也应能把真实应用请求送到目标。仅有心跳不能证明后端健康；必须另行测试后端监听服务，并尽可能在目标日志中确认请求。</p>

<h2>如果没有成功</h2>
<p><strong>先停止随意更改端口、地址或线路设置。</strong>没有心跳时检查 Sidecar 服务与出站网络；心跳正常但应用失败时，从 Sidecar 主机测试后端。联系支持时不要提交令牌、密码、命令、密钥、会话或线路材料。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=connection-modes-single-race-bond">选择连接模式</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接并验证</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'bond-sidecar-setup'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '排查常见问题', '从看得见的症状开始，按最短且安全的顺序恢复连接。', '<h2>你将完成什么</h2>
<p>你将从看得见的连接症状开始，沿客户端、转发、线路和目标服务逐段检查，修复第一个失败边界，而不是用随机改动掩盖原问题。</p>

<h2>开始前准备</h2>
<ul>
  <li>记下准确错误、发生时间、客户端平台、转发名称和本地端口。</li>
  <li>检查期间保留已保存的转发，不要先改线路拓扑。</li>
  <li>截图和支持消息中不得包含密码、令牌、生成命令、密钥、会话或线路材料。</li>
  <li>最终重试必须使用真实应用协议。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 从症状开始排查连接
accDescr: 请求失败后依次检查最近同步、客户端地址、端口访问、转发与线路健康和目标服务，最后再使用诊断与支持。
A[应用请求失败] --&gt; B{最近同步正常吗}
B -- 否 --&gt; C[恢复客户端同步]
B -- 是 --&gt; D{地址正确吗}
D -- 否 --&gt; E[使用回环或可达客户端主机]
D -- 是 --&gt; F{端口已放行吗}
F -- 否 --&gt; G[只放行所需端口]
F -- 是 --&gt; H{转发和线路健康吗}
H -- 否 --&gt; I[打开 View Diagnostics]
H -- 是 --&gt; J[从目标主机测试服务]
C --&gt; K[重试应用]
E --&gt; K
G --&gt; K
I --&gt; L[整理诊断并联系支持]
J --&gt; L</pre>
<p>排查顺序从客户端逐步走向目标端。发现第一个失败边界后只修复该处，再重试一次，同时保存错误证据，避免随机修改拓扑。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/05-diagnostics.png" width="1440" height="960" alt="客户端诊断页面显示先 Run Diagnostics 再 View Diagnostics，并显示健康结果">
  <figcaption>演示截图：先运行一次新诊断，再打开 View Diagnostics 查看详情。</figcaption>
</figure>
<ol>
  <li>确认预期客户端有最近同步。</li>
  <li>确认转发显示 <strong>Running</strong>。</li>
  <li>检查地址是否正确：应用同机时使用 <code>127.0.0.1</code>。</li>
  <li>跨设备时只能使用可达的 Windows 或 Linux 客户端主机地址，Android 不作为跨设备监听器。</li>
  <li>确认本地端口与转发卡片一致。</li>
  <li>跨设备连接时确认端口已放行，并只在适用的防火墙、安全组和 NAT 中开放该端口。</li>
  <li>在 <strong>View Diagnostics</strong> 检查转发和线路是否健康。</li>
  <li>先从目标服务自己的主机测试目标服务。</li>
  <li>使用实际应用协议重新测试。</li>
  <li>仍然失败时，整理已脱敏的诊断信息并联系支持人员。</li>
</ol>

<h2>如何确认成功</h2>
<p>第一个失败边界恢复后，真实应用请求应成功。最近同步只证明配置送达，<strong>Running</strong> 只证明本地进程运行，目标应用返回正确结果才证明整条连接完成。</p>

<h2>如果没有成功</h2>
<p><strong>先停止全部重装、开放大范围端口或随机更改拓扑。</strong>保存错误与诊断，向支持提供转发名称、客户端平台、测试时间、地址类型、本地端口和脱敏结果，绝不发送秘密。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安装客户端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接并验证</a></li>
  <li><a href="/knowledgebase?thread=glossary">名词解释</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'troubleshooting-common-mistakes'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '名词解释', '用通俗语言解释指南中出现的产品和网络术语。', '<h2>你将完成什么</h2>
<p>你将用通俗语言理解入门指南中的产品和网络术语，从而知道转发该填什么、客户端状态代表什么，以及连接何时才算真正验证成功。</p>

<h2>开始前准备</h2>
<ul>
  <li>打开正在阅读的指南，把术语与页面中的字段或状态对应起来。</li>
  <li>外观相似的状态可能代表不同检查点，不要互相替代。</li>
  <li>询问术语含义时，不要复制任何密码、令牌、命令、密钥或内部材料。</li>
</ul>

<h2>操作步骤</h2>
<ol>
  <li><strong>有效套餐：</strong>当前允许创建并使用转发的已购买套餐或服务。</li>
  <li><strong>转发：</strong>把客户端监听收到的流量，经系统提供的线路送到目标主机和端口的一条已保存规则。</li>
  <li><strong>Forward Route：</strong>第一张转发表单提供的线路方案，应选择与当前服务相符的已发布选项。</li>
  <li><strong>Target Host：</strong>目标服务的主机名或 IP 地址。</li>
  <li><strong>Target Port：</strong>目标服务监听的端口。</li>
  <li><strong>Local Listen Port：</strong>应用连接客户端时使用的本地监听端口。</li>
  <li><strong>客户端：</strong>在 Windows、Android 或 Linux 上接收转发配置并建立本地监听的软件。</li>
  <li><strong>最近同步：</strong>证明客户端最近从面板拉取了配置，但不能证明目标应用已经响应。</li>
  <li><strong>Running：</strong>表示客户端中的转发进程正在运行，不等于应用连接成功。</li>
  <li><strong>Single：</strong>使用一条受支持路径处理请求的简单连接模式。</li>
  <li><strong>Race：</strong>用于路径选择或容错，不是带宽聚合；行为因平台和传输类型而异。</li>
  <li><strong>Bond：</strong>只有套餐与平台明确支持发布要求时才使用的高级模式；不保证速度翻倍、跑满带宽或每条路径都参与。</li>
  <li><strong>Sidecar：</strong>某些 Bond 线路要求的可选目标侧中间传输，不是目标应用。</li>
  <li><strong>应用验证：</strong>使用预定协议发出真实请求并获得正确响应，最好还能在目标日志中确认。</li>
</ol>

<h2>如何确认成功</h2>
<p>你能够为可见字段选择正确的值，区分配置送达、进程运行和应用成功，并根据问题跳转到合适的指南，而不是猜测内部设置。</p>

<h2>如果没有成功</h2>
<p><strong>先停止猜值或分享秘密。</strong>使用下方链接回到对应操作场景；仍不清楚时，请管理员或支持人员结合页面上的可见标签解释。</p>

<h2>相关指南</h2>
<p>首次使用请阅读<a href="/knowledgebase?thread=getting-started-five-minute-overview">五分钟完成首次连接</a>；填写表单请看<a href="/knowledgebase?thread=getting-started-first-forward-checklist">创建第一条转发</a>；地址与成功证据请看<a href="/knowledgebase?thread=connect-and-verify">连接并验证</a>；模式选择请看<a href="/knowledgebase?thread=connection-modes-single-race-bond">选择连接模式</a>；仅在线路明确要求时阅读<a href="/knowledgebase?thread=bond-sidecar-setup">配置 Sidecar</a>。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'glossary'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '无界面运行客户端', '在 Windows 或 macOS 上把无界面客户端安装为服务或守护进程，验证并卸载。', '<h2>你将完成什么</h2>
<p>你会让一台电脑自己把线路跑起来 —— 一台服务器、一台小主机，或者任何长期开机的机器。没有人需要登录，桌面上不会出现窗口或图标。装一次、确认一次，之后用一条短命令管理它。</p>

<h2>开始前准备</h2>
<ul>
  <li>面板「客户端」页面上的 <strong>Client ID</strong> 和 <strong>Agent Token</strong>，它们就是这台机器的登录凭据，请像密码一样保管。</li>
  <li>在这台机器上安装软件的权限（Windows 需要管理员，macOS 需要你自己的密码）。</li>
  <li>两分钟时间。macOS 下载约 80 MB，Windows 约 34 MB。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 不用屏幕的电脑设置流程
accDescr: 选择电脑，安装后台客户端，然后在面板确认线路已经在运行。
A[选择电脑] --&gt; B{哪个系统}
B -- macOS --&gt; C[先装应用，再运行一条命令]
B -- Windows --&gt; D[解压下载包，再运行一条命令]
C --&gt; E[确认显示 running]
D --&gt; E
E --&gt; F[在面板看到设备在线]</pre>
<p>整件事就是这张图：选系统、运行一条命令、确认结果。之后的一切都在后台进行。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install.png" width="1440" height="960" alt="客户端页面，显示下载入口以及这台机器的 Client ID 与 Agent Token">
  <figcaption>Client ID 和 Agent Token 就在这个页面上，旁边的下载按你的系统选择。</figcaption>
</figure>
<ol>
  <li><strong>挑选电脑。</strong>用一台长期开机的机器，不需要显示器、不需要键盘，也不需要有人登录。</li>
  <li><strong>先安装应用。</strong>macOS：打开下载的磁盘映像，把 <strong>LeiKwan Bridge</strong> 拖进 <strong>应用程序</strong>。Windows：把无界面版下载包解压到一个文件夹。</li>
  <li><strong>运行一次安装命令。</strong>macOS：打开「终端」，粘贴客户端页面上的 macOS 命令。Windows：在解压出来的文件夹上右键，以管理员身份打开终端，粘贴页面上的 Windows 命令。两条命令里都已经带了这台机器的身份。</li>
  <li><strong>确认已经启动。</strong>输入 <code>leikwan status</code>，看到转发显示为 running 就对了。</li>
  <li><strong>回到面板看一眼。</strong>这台设备会像装了普通客户端的电脑一样显示在线。</li>
  <li><strong>以后只需要三条命令。</strong>查看用 <code>leikwan status</code>，觉得卡住用 <code>leikwan restart</code>，升级用 <code>leikwan update</code>；如果只想看看有没有新版本，用 <code>leikwan update --check</code>。</li>
  <li><strong>需要卸载时：</strong>运行同一个客户端页面上的卸载命令，或者先用 <code>leikwan stop</code> 暂时关掉它。</li>
</ol>

<h2>如何确认成功</h2>
<p>两个简单检查。面板里这台电脑显示在线，和其他设备一样；在电脑上运行 <code>leikwan status</code>，线路显示为 running，并且命令以成功状态结束，所以监控工具也可以直接用它。想看细节时，<code>leikwan logs -n 50</code> 显示最近 50 行日志，<code>leikwan doctor</code> 一次列清整体状态。</p>

<h2>如果没有成功</h2>
<p><strong>先停止把 Agent Token 发给任何人 —— 聊天、工单、截图都不行。</strong>最常见的原因是 Agent Token 抄错或已失效：面板会显示设备离线，日志里会写着登录被拒绝。回到客户端页面重新复制 Client ID 与 Agent Token，再运行一次安装命令即可。如果面板显示设备在线、但某条转发仍是停止状态，等半分钟 —— 转发由面板决定，电脑会在几秒内跟上。仍然不通，就把 <code>leikwan doctor</code> 的输出发给客服，那份内容不含任何机密。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安装客户端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接与验证</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">常见问题排查</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'no-gui-clients'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '五分鐘完成首次連線', '按最短路徑完成方案、轉發、用戶端安裝和連線驗證。', '<h2>你將完成什麼</h2>
<p>你會從有效方案開始，完成一條轉發，安裝用戶端，並用真實應用程式確認首次連線。重點不只是把設定儲存起來，而是透過系統分配的本機連接埠送出要求，收到預期回應。</p>

<h2>開始前準備</h2>
<ul>
  <li>確認方案或服務標示為<strong>有效</strong>。</li>
  <li>準備目標位址、目標連接埠，以及應用程式使用 TCP、UDP 或兩者的資訊。</li>
  <li>準備一台支援的 Windows、Android 或 Linux 裝置安裝用戶端。</li>
  <li>不要分享密碼、權杖、複製的安裝指令、私密金鑰或線路資料。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 五分鐘首次連線流程
accDescr: 從有效方案開始，建立一條轉發，安裝並登入用戶端，測試分配的本機連接埠，再依連線是否成功完成設定或開始排查問題。
A[有效方案] --&gt; B[建立一條轉發]
B --&gt; C[安裝並登入用戶端]
C --&gt; D[測試分配的本機連接埠]
D --&gt; E{連線成功嗎}
E -- 是 --&gt; F[首次設定完成]
E -- 否 --&gt; G[開始排查問題]</pre>
<p>最短且安全的流程只有一個判斷點：應用程式要求成功，就代表首次設定完成；若失敗，保留已儲存的轉發，從看得見的症狀開始排查。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/01-overview.png" width="1440" height="960" alt="租戶概覽頁顯示三步設定流程和 Create a forward 操作">
  <figcaption>示範截圖：方案、數量和 ID 都是示範值，請以自己的面板為準。</figcaption>
</figure>
<ol>
  <li>在租戶面板開啟有效服務。</li>
  <li>選擇<strong>建立轉發</strong>。</li>
  <li>選擇面板提供的線路方案，填入目標位址和連接埠。</li>
  <li>儲存轉發，記下系統分配的本機連接埠。</li>
  <li>安裝用戶端並登入。</li>
  <li>等待面板出現最近同步。</li>
  <li>讓應用程式連到本機監聽位址與連接埠。</li>
  <li>使用實際應用協定測試，例如 HTTP 要求、SSH 連線或遊戲連線。</li>
</ol>

<h2>如何確認成功</h2>
<p>佈建完成後，面板會顯示有效端點，用戶端有最近同步，轉發則顯示 <strong>Connected</strong> 或 <strong>Running</strong>。這些是不同階段的狀態；完整證明仍是應用程式要求成功並取得預期結果。若你管理目標服務，也應能在記錄中看到要求抵達。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止隨意調整線路細節。</strong>保留第一條轉發，記下確切錯誤和時間，再開啟排查指南。設定已儲存、佈建完成、最近同步、程序執行中，以及應用程式成功，是五個不同的檢查點。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-first-forward-checklist">建立第一條轉發</a></li>
  <li><a href="/knowledgebase?thread=install-client">安裝用戶端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-five-minute-overview'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '安裝用戶端', '在 Windows、Android 或 Linux 上安裝並登入，然後確認用戶端在線。', '<h2>你將完成什麼</h2>
<p>你會在 Windows、Android 或 Linux 上安裝並登入用戶端，接著確認裝置已在線，並收到最近的轉發設定。</p>

<h2>開始前準備</h2>
<ul>
  <li>Windows 登入需要 App 顯示的 <strong>Master URL</strong>、<strong>Email</strong> 和 <strong>Password</strong>。</li>
  <li>Android 需要 arm64、Android 8.0+ 裝置，並準備允許系統建立 VPN 連線。</li>
  <li>Linux 主機需要 systemd、Python 3、可用的網路，以及等同 root 的權限。</li>
  <li><strong>Linux ARM64 單檔安裝包僅支援 Debian 11、12、13。</strong>其他發行版或 amd64 主機請使用對應的 Linux 安裝指令。</li>
  <li>登入資訊和複製的指令都屬於機密；Linux 安裝指令內含用於 Agent 身分驗證權杖。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 用戶端安裝流程
accDescr: 依裝置的作業系統選擇 Windows、Android 或 Linux 安裝流程，完成後都要確認用戶端最近同步。
A[選擇裝置] --&gt; B{作業系統}
B -- Windows --&gt; C[下載 Windows App]
B -- Android --&gt; D[安裝 arm64 Android App]
B -- Linux --&gt; E[複製 Linux 安裝指令]
C --&gt; F[確認最近同步]
D --&gt; F
E --&gt; F</pre>
<p>三個平台的安裝入口和畫面狀態不同，但完成條件一致：目標裝置上的用戶端已連線，面板也顯示最近同步。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install.png" width="1440" height="960" alt="Clients 的 Install 頁面顯示 Windows、Android 和 Linux 安裝入口">
  <figcaption>示範截圖：按鈕和同步時間只用來說明位置，不代表你的裝置狀態。</figcaption>
</figure>
<ol>
  <li><strong>Windows：</strong>開啟 <strong>Tenant → Clients → Install</strong>（租戶 → 線路 → Install），選擇 <strong>Download Windows App</strong>（下載 Windows App）。</li>
  <li>安裝並開啟 App，填入 <strong>Master URL</strong>、<strong>Email</strong> 和 <strong>Password</strong>。</li>
  <li>選擇 <strong>Connect</strong>，確認出現 <strong>Connection successful!</strong>，整體狀態為 <strong>Connected</strong>，可用轉發顯示 <strong>Running</strong>。</li>
  <li><strong>Android：</strong>在 arm64、Android 8.0+ 裝置安裝 App 並登入。</li>
  <li>允許 Android 的 VPN 要求，只選擇 App 列出的支援的 LeiGod 服務。</li>
  <li>觀察狀態從 <strong>Ready to connect</strong>，經過 <strong>Connecting...</strong>，最後成為 <strong>Connected</strong>。不能因此承諾 Android 支援 Race 或 MPTCP。</li>
  <li><strong>Linux：</strong>開啟 <strong>Tenant → Clients → Install</strong>（租戶 → 線路 → Install），展開 <strong>Linux Install</strong>（Linux 安裝），選擇 <strong>Copy Install Command</strong>（複製安裝指令）。</li>
  <li>如果使用 <strong>Linux ARM64 Installer</strong>，請確認主機是 arm64/aarch64，且作業系統為 Debian 11、12 或 13。</li>
  <li>以等同 root 的權限執行一次複製的指令；不要貼到聊天、工單、記錄或共用的 Shell 歷程。</li>
  <li>確認安裝程式輸出 <code>installed leikwan-client-agent</code>。</li>
  <li>回到面板查看最近同步；也可在主機確認 <code>config_pull_state.json</code> 的 <code>status</code> 為 <code>ok</code>。</li>
</ol>

<h2>如何確認成功</h2>
<p>面板會顯示預期用戶端的最近同步。Windows 也會出現 <strong>Connection successful!</strong>、整體 <strong>Connected</strong> 和轉發 <strong>Running</strong>；Android 到達 <strong>Connected</strong>；Linux 則完成安裝並成功拉取設定。之後仍要另外驗證真實應用程式連線。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止公開登入資料或重複執行含機密的指令。</strong>核對 Master URL、裝置時間、網路和必要權限。Android 要確認已允許 VPN；Linux 要確認 systemd 與 Python 3 可用。收集診斷時，請移除 Agent 身分驗證權杖等機密內容。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-five-minute-overview">五分鐘完成首次連線</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'install-client'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '建立第一條轉發', '選擇線路方案、填寫目標位址並記下系統分配的本機連接埠。', '<h2>你將完成什麼</h2>
<p>你會從有效方案建立一條轉發，只填寫畫面上看得到的目標設定，並記下應用程式之後要使用的本機連接埠。</p>

<h2>開始前準備</h2>
<ul>
  <li>確認方案或服務狀態為<strong>有效</strong>。</li>
  <li>準備目標服務的主機名稱或 IP 位址。</li>
  <li>準備目標服務使用的連接埠。</li>
  <li>確認應用程式需要 TCP、UDP，或兩者都需要。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 第一條轉發的表單流程
accDescr: 開啟有效方案，選擇轉發路由，填寫目標與傳輸方式，儲存後等待轉發可用，再記下分配的本機連接埠。
A[開啟有效方案] --&gt; B[選擇轉發路由]
B --&gt; C[填寫目標主機和目標連接埠]
C --&gt; D[選擇傳輸方式]
D --&gt; E[儲存]
E --&gt; F[等待轉發可用]
F --&gt; G[記下分配的本機連接埠]</pre>
<p>表單只需要線路方案、目標、偏好的本地連接埠和傳輸方式。儲存後會開始佈建；下一篇指南真正需要的是分配的本機連接埠。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/02-forward-form.png" width="1440" height="960" alt="Create Forward 表單顯示 Forward Route、Target Host、Target Port、Local Listen Port 和 Transport">
  <figcaption>示範截圖：沒有指定連接埠時，把 Local Listen Port 留空，讓面板分配。</figcaption>
</figure>
<ol>
  <li>開啟有效服務，選擇<strong>建立轉發</strong>。</li>
  <li>在 <strong>Forward Route</strong>（轉發路由）選擇面板提供的線路方案。</li>
  <li>在 <strong>Target Host</strong>（目標主機）填入目標位址。</li>
  <li>在 <strong>Target Port</strong>（目標連接埠）填入服務連接埠。</li>
  <li>除非已取得特定且安全的連接埠，否則將 <strong>Local Listen Port</strong>（本地監聽連接埠）留空，接受系統分配。</li>
  <li>在 <strong>Transport</strong>（傳輸）選擇 <strong>TCP</strong>，供只使用 TCP 的應用程式使用。</li>
  <li>應用程式同時需要兩種協定時，選擇 <strong>TCP + UDP</strong>。</li>
  <li>只有完全使用 UDP 的應用程式才選擇 <strong>UDP</strong>。</li>
  <li>選擇 <strong>Create Forward</strong>（建立轉發）以儲存。</li>
  <li>等待佈建完成，再從轉發卡片記下系統分配的本機連接埠。</li>
</ol>

<h2>如何確認成功</h2>
<p>新的轉發會顯示預期的目標和本機連接埠。資料已儲存只是第一個檢查點；接著要等設定啟用、用戶端最近同步，最後看到 <strong>Running</strong> 且應用程式成功收到回應。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止操作，特別是沒有可選的轉發路由，或目標資訊不確定時。</strong>不要猜測隱藏設定。先確認方案有效，只修正上述可見欄位；若沒有合適線路，請管理員發布可用的方案。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安裝用戶端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a></li>
  <li><a href="/knowledgebase?thread=glossary">名詞解釋</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-first-forward-checklist'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '連線並驗證', '選擇正確的用戶端位址，測試服務並確認轉發已經運作。', '<h2>你將完成什麼</h2>
<p>你會依應用程式所在位置選擇正確的用戶端位址，透過轉發送出要求，並分清面板狀態和目標應用程式確實收到流量的證明。</p>

<h2>開始前準備</h2>
<ul>
  <li>記下轉發的本機連接埠。</li>
  <li>確認用戶端有最近同步，轉發顯示 <strong>Running</strong>。</li>
  <li>確認應用程式是在用戶端裝置，還是在另一台裝置執行。</li>
  <li>使用能理解協定的測試；只確認 TCP 連接埠開啟，不代表 HTTP、SSH、資料庫或遊戲要求能正確運作。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 選擇用戶端連線位址
accDescr: 應用程式與用戶端在同一台裝置時使用迴送位址；不同裝置使用可連到的 Windows 或 Linux 用戶端主機位址並視需要調整網路規則；Android 保持只監聽迴送位址。
A[確認應用程式所在裝置] --&gt; B{與用戶端在同一台裝置嗎}
B -- 是 --&gt; C[使用 127.0.0.1 和本機連接埠]
B -- 否 --&gt; D{用戶端平台}
D -- Windows 或 Linux --&gt; E[使用可連到的主機位址與連接埠]
E --&gt; F[視需要開放防火牆安全群組或 NAT]
D -- Android --&gt; G[保持只監聽迴送位址]
C --&gt; H[測試實際應用協定]
F --&gt; H
G --&gt; H</pre>
<p>決定使用哪個位址的關鍵，是應用程式在哪裡執行。同一台裝置使用迴送位址；跨裝置只能透過可連到的 Windows 或 Linux 用戶端主機，且可能需要調整網路存取規則。Android 仍維持只監聽迴送位址，除非另有核准且支援的架構。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="轉發結果卡片顯示本機監聽位址、目標、傳輸方式和 Running 狀態">
  <figcaption>示範截圖：連線應用程式時，使用自己 Forward 卡片上的本機監聽值。</figcaption>
</figure>
<ol>
  <li>應用程式在用戶端裝置時，填入 <code>127.0.0.1</code> 和轉發的本機連接埠。</li>
  <li>其他裝置要透過 Windows 或 Linux 用戶端連線時，填入可連到的 Windows 或 Linux 用戶端主機位址和相同連接埠。</li>
  <li>跨裝置使用時，只在主機防火牆開放該連接埠。</li>
  <li>若使用雲端主機，也要在安全群組開放相同連接埠。</li>
  <li>用戶端位於路由器後方時，只有在網路管理者核准後才設定 NAT。</li>
  <li>不要把 Android 描述成跨裝置監聽服務；除非有核准的支援設計，否則它只監聽迴送位址。</li>
  <li>用實際應用協定送出要求。</li>
  <li>檢查回應；若能查看目標服務記錄，也要確認要求確實抵達。</li>
</ol>

<h2>如何確認成功</h2>
<p>應用程式透過用戶端位址和本機連接埠取得預期回應。最有力的證明是目標服務記錄中有對應要求。<strong>Connected</strong>、<strong>Running</strong> 和本機連接埠開啟都很有用，但任何一項都不能單獨證明目標應用程式已處理要求。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止開放大範圍防火牆規則，也不要假設任何公用 IP 都能使用。</strong>重新確認是同裝置或跨裝置、核對本機連接埠，並從目標服務所在主機測試服務。接著查看診斷，或向支援提供錯誤、時間、用戶端平台和轉發名稱，但不要附上機密。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安裝用戶端</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a></li>
  <li><a href="/knowledgebase?thread=glossary">名詞解釋</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'connect-and-verify'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '選擇連線模式', '按穩定、容錯或多路徑需求理解 Single、Race 和 Bond。', '<h2>你將完成什麼</h2>
<p>你會按照穩定、容錯與多路徑需求理解 Single、Race 和 Bond，為轉發選擇線路方案及用戶端平台支援的連線模式，同時避開無法保證的效能承諾。</p>

<h2>開始前準備</h2>
<ul>
  <li>如果可以，先讓一條基本轉發穩定運作。</li>
  <li>閱讀方案已發布的線路約定，並確認用戶端平台。</li>
  <li>分清楚你需要的是簡單穩定、路徑容錯，或明確支援的 Bond 架構。</li>
  <li>不要只因模式名稱聽起來比較快就更換設定。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 連線模式選擇流程
accDescr: 需要最簡單的穩定路徑時選擇 Single，需要支援的非聚合容錯時選擇 Race，只有方案明確支援且要求都已核對時才選擇 Bond。
A[你需要什麼結果] --&gt; B{最簡單的穩定路徑嗎}
B -- 是 --&gt; C[選擇 Single]
B -- 否 --&gt; D{需要非聚合容錯嗎}
D -- 是 --&gt; E[選擇 Race]
D -- 否 --&gt; F{方案明確支援 Bond 嗎}
F -- 是 --&gt; G[選擇 Bond 並核對要求]
F -- 否 --&gt; H[詢問管理員]</pre>
<p>安全的預設值是 Single。Race 用於支援的路徑選擇或容錯，不會合併頻寬；Bond 只有在線路約定明確支援該方案與平台時，才是合適的進階選項。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="轉發結果頁顯示使用者可見的線路結果，而不是內部 Single、Race 或 Bond 組合">
  <figcaption>示範截圖：租戶檢查線路結果和狀態，內部拓撲由方案管理。</figcaption>
</figure>
<ol>
  <li>需要最簡單且穩定的連線時，選擇 <strong>Single</strong>。</li>
  <li>線路約定提供至少兩個可用端點，而且需要不聚合頻寬的容錯時，選擇 <strong>Race</strong>。</li>
  <li>Race 不是頻寬聚合。</li>
  <li>Linux 的 TCP Race 通常會為每條連線選出一條勝出路徑。</li>
  <li>Linux 的 UDP Race 可能把資料包送往多條可用路徑。</li>
  <li>Race 的實際行為依用戶端平台而異；Android 不承諾 Race 或 MPTCP。</li>
  <li>只有已發布的線路約定明確支援時，才選擇 <strong>Bond</strong>。</li>
  <li>逐項核對 Bond 的要求；僅在線路約定要求時使用 Sidecar。</li>
  <li>支援範圍不清楚時詢問管理員，不要自行猜測。</li>
</ol>

<h2>如何確認成功</h2>
<p>連線模式必須同時符合方案約定和用戶端平台能力，真實應用程式也要在預期條件下成功。Race 應驗證一條可用路徑失效後，受支援的要求仍可繼續；Bond 應核對約定與實際結果，不能只從狀態推論速度加倍、跑滿頻寬或使用所有路徑。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止承諾加速倍數或所有路徑會同時運作。</strong>若 Single 可用且穩定，先改回 Single，記下失敗的平台和線路方案，再請管理員確認需要的 Race 或 Bond 行為是否受支援。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=bond-sidecar-setup">設定 Sidecar</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a></li>
  <li><a href="/knowledgebase?thread=glossary">名詞解釋</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'connection-modes-single-race-bond'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '設定 Sidecar', '僅在線路方案明確需要時建立並驗證 Sidecar。', '<h2>你將完成什麼</h2>
<p>只有 Bond 方案已發布的線路約定明確要求時，你才會建立並驗證 Sidecar。Sidecar 是目標端的中介傳輸元件，不是目標應用程式。</p>

<h2>開始前準備</h2>
<ul>
  <li>確認所選 Bond 方案明確要求 Sidecar。</li>
  <li>準備一台可以連到後端服務的目標端主機。</li>
  <li>確認核准的 <strong>Sidecar 主機</strong> 與 Bond 傳輸連接埠。</li>
  <li>準備 Sidecar 主機上等同 root 的權限。</li>
  <li>面板產生的安裝指令與所有憑證都必須保密。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart LR
accTitle: 使用 Sidecar 的 Bond 線路
accDescr: 用戶端經過受管理的 Slave 路徑與中介 Sidecar，最後連到目標服務。
A[用戶端] --&gt; B[受管理的 Slave 路徑]
B --&gt; C[Sidecar]
C --&gt; D[目標服務]</pre>
<p>Sidecar 位於受管理路徑與目標服務之間。它必須在線，也要能連到後端；但目標應用程式是否成功，仍需另外驗證。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/06-target-endpoint.png" width="1440" height="960" alt="Target Endpoints 表單顯示主機和三個監聽連接埠欄位">
  <figcaption>示範截圖：只有已發佈線路約定要求時，才建立租戶自有 endpoint。</figcaption>
</figure>
<ol>
  <li>在<strong>進階</strong>下開啟<strong>目標端點</strong>。</li>
  <li>選擇<strong>建立</strong>分頁。</li>
  <li>在<strong>建立傳輸端點</strong>填入容易辨識的<strong>名稱</strong>。</li>
  <li>填入核准的 <strong>Sidecar 主機</strong>。</li>
  <li>在<strong>傳輸監聽連接埠</strong>填入核准的 Bond 傳輸連接埠。</li>
  <li>選擇<strong>儲存傳輸端點</strong>。</li>
  <li>在端點卡片開啟<strong>安裝指令</strong>並複製，過程中不要暴露內容。</li>
  <li>以等同 root 的權限在 Sidecar 主機執行指令。</li>
  <li>回到面板，等待<strong>最後一次心跳</strong>、<strong>代理版本</strong>和<strong>最後狀態</strong>更新。</li>
  <li>從 Sidecar 主機測試設定的目標位址和連接埠上，後端監聽服務是否可連線。</li>
  <li>依已發布的線路約定建立或重新整理 Bond 轉發。</li>
</ol>

<h2>如何確認成功</h2>
<p>Sidecar 會回報最近的<strong>最後一次心跳</strong>和預期的 Agent 狀態，Bond 轉發也能把真實應用程式要求送到目標。只有心跳不能證明後端健康；請另外測試後端監聽服務，若可以，也在目標服務記錄中確認要求。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止隨意更改連接埠、位址或線路設定。</strong>沒有心跳時，檢查 Sidecar 服務和對外網路；心跳正常但應用程式失敗時，從 Sidecar 主機測試後端。向支援提供已清除機密的診斷，但不要分享產生的安裝指令、權杖、密碼、線路金鑰、私密金鑰、內部工作階段或線路資料。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=connection-modes-single-race-bond">選擇連線模式</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'bond-sidecar-setup'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '排查常見問題', '從看得見的症狀開始，按最短且安全的順序恢復連線。', '<h2>你將完成什麼</h2>
<p>你會從看得見的症狀開始，一次檢查一個邊界，並在大範圍或隨意變更讓原始問題更難辨認前停下來。</p>

<h2>開始前準備</h2>
<ul>
  <li>記下確切錯誤、時間、用戶端平台、轉發名稱和本機連接埠。</li>
  <li>檢查目前狀態時，先不要更動已儲存的轉發。</li>
  <li>截圖或聯絡支援時，不要包含密碼、權杖、產生的指令、線路金鑰、私密金鑰或內部工作階段資料。</li>
  <li>最後一步必須使用實際應用協定測試。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 從症狀開始排查連線
accDescr: 應用程式要求失敗後依序檢查最近同步、位址、連接埠的網路存取、轉發與線路健康，以及目標服務，再使用診斷與支援。
A[應用程式要求失敗] --&gt; B{最近同步正常嗎}
B -- 否 --&gt; C[恢復用戶端同步]
B -- 是 --&gt; D{位址正確嗎}
D -- 否 --&gt; E[使用迴送位址或可連到的用戶端主機]
D -- 是 --&gt; F{連接埠已開放嗎}
F -- 否 --&gt; G[只開放必要連接埠]
F -- 是 --&gt; H{轉發和線路健康嗎}
H -- 否 --&gt; I[開啟查看診斷]
H -- 是 --&gt; J[從目標主機測試服務]
C --&gt; K[重試應用程式]
E --&gt; K
G --&gt; K
I --&gt; L[整理診斷後聯絡支援]
J --&gt; L</pre>
<p>檢查順序從用戶端一路走向目標。修正第一個失敗的邊界後重試一次，保留證據，不要隨意變更拓撲。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/05-diagnostics.png" width="1440" height="960" alt="用戶端診斷頁面顯示先 Run Diagnostics 再 View Diagnostics，並顯示健康結果">
  <figcaption>示範截圖：先執行一次新的診斷，再開啟 View Diagnostics 查看詳情。</figcaption>
</figure>
<ol>
  <li>確認預期的用戶端有最近同步。</li>
  <li>確認轉發顯示 <strong>Running</strong>。</li>
  <li>檢查位址是否正確：同一台用戶端裝置使用 <code>127.0.0.1</code>。</li>
  <li>其他裝置要連線時，使用可連到的 Windows 或 Linux 用戶端主機位址；不要把 Android 當成跨裝置監聽服務。</li>
  <li>確認本機連接埠和轉發卡片一致。</li>
  <li>跨裝置使用時，依實際環境確認防火牆、安全群組和 NAT 只讓必要的連接埠已開放。</li>
  <li>在<strong>查看診斷</strong>確認轉發和線路健康。</li>
  <li>從目標服務所在主機測試目標服務和連接埠。</li>
  <li>用實際應用協定重試。</li>
  <li>若仍然失敗，收集已清除機密的診斷，附上症狀和時間聯絡支援。</li>
</ol>

<h2>如何確認成功</h2>
<p>第一個失敗的邊界恢復正常，真實應用程式要求也成功。最近同步證明設定已送達，<strong>Running</strong> 證明本機程序狀態，目標成功回應才證明整段連線完成。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止重新安裝所有元件、開放大範圍連接埠或隨意變更拓撲。</strong>保留錯誤與診斷，向支援提供轉發名稱、用戶端平台、測試時間、位址類型、本機連接埠和已清理的結果，絕對不要傳送機密。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安裝用戶端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a></li>
  <li><a href="/knowledgebase?thread=glossary">名詞解釋</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'troubleshooting-common-mistakes'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '名詞解釋', '用淺白語言解釋指南中出現的產品和網路術語。', '<h2>你將完成什麼</h2>
<p>你會看懂入門指南使用的產品與網路術語，不必先理解內部實作，也能分辨欄位、狀態與驗證結果。</p>

<h2>開始前準備</h2>
<ul>
  <li>保留正在閱讀的指南，方便把每個名詞對照到畫面上的欄位或狀態。</li>
  <li>外觀看起來相近的狀態，可能代表不同的檢查點。</li>
  <li>詢問名詞時，不要連同機密數值一起複製。</li>
</ul>

<h2>操作步驟</h2>
<ol>
  <li><strong>有效方案：</strong>目前允許你建立並使用轉發的已購買方案或服務。</li>
  <li><strong>轉發：</strong>一項已儲存的規則，讓流量從用戶端監聽服務，經過面板提供的線路，送到目標位址與連接埠。</li>
  <li><strong>轉發路由：</strong>建立第一條轉發時可選的線路方案。選擇符合目前服務的已發布項目。</li>
  <li><strong>目標主機：</strong>目標服務的主機名稱或 IP 位址。</li>
  <li><strong>目標連接埠：</strong>目標服務使用的連接埠。</li>
  <li><strong>本地監聽連接埠：</strong>應用程式在用戶端這一側連線的連接埠。</li>
  <li><strong>用戶端：</strong>Windows、Android 或 Linux 軟體，負責取得轉發設定並建立本機監聽服務。</li>
  <li><strong>最近同步：</strong>表示用戶端最近從面板取得設定；它不能證明目標應用程式已回應。</li>
  <li><strong>Running：</strong>表示轉發程序正在用戶端執行；它不是應用程式成功的最終證明。</li>
  <li><strong>Single：</strong>最簡單的支援連線模式，讓一個要求使用一條線路路徑。</li>
  <li><strong>Race：</strong>用於容錯或路徑選擇的模式。它不是頻寬聚合，實際行為會因平台和傳輸方式而異。</li>
  <li><strong>Bond：</strong>只有方案與平台明確支援已發布要求時才使用的進階模式；它不保證速度加倍，也不保證每次都使用所有路徑。</li>
  <li><strong>Sidecar：</strong>部分 Bond 線路需要的選用目標端中介傳輸元件，不是目標應用程式。</li>
  <li><strong>應用程式驗證：</strong>使用預定協定送出真實要求並取得預期回應；最好也能在目標服務記錄中確認。</li>
</ol>

<h2>如何確認成功</h2>
<p>你能判斷畫面上每個欄位該填什麼，能區分設定送達、程序執行和應用程式成功，也能在不猜測的情況下選到正確指南。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止猜測欄位內容或分享機密。</strong>使用下方相關指南，仍不清楚時，請管理員或支援依畫面上的實際標籤說明。</p>

<h2>相關指南</h2>
<p>先閱讀<a href="/knowledgebase?thread=getting-started-five-minute-overview">五分鐘完成首次連線</a>。表單欄位請看<a href="/knowledgebase?thread=getting-started-first-forward-checklist">建立第一條轉發</a>；位址與成功證明請看<a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a>；Single、Race 和 Bond 請看<a href="/knowledgebase?thread=connection-modes-single-race-bond">選擇連線模式</a>；只有已發布的線路明確要求時，才使用<a href="/knowledgebase?thread=bond-sidecar-setup">設定 Sidecar</a>。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'glossary'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '無介面執行用戶端', '在 Windows 或 macOS 上把無介面用戶端安裝為服務或守護程序，驗證並移除。', '<h2>你將完成什麼</h2>
<p>你會讓一台電腦自己把線路跑起來 —— 一台伺服器、一台小主機，或任何長期開機的機器。沒有人需要登入，桌面上不會出現視窗或圖示。裝一次、確認一次，之後用一條短指令管理它。</p>

<h2>開始前準備</h2>
<ul>
  <li>面板「用戶端」頁面上的 <strong>Client ID</strong> 與 <strong>Agent Token</strong>，它們就是這台機器的登入憑證，請像密碼一樣保管。</li>
  <li>在這台機器上安裝軟體的權限（Windows 需要系統管理員，macOS 需要你自己的密碼）。</li>
  <li>兩分鐘時間。macOS 下載約 80 MB，Windows 約 34 MB。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 不用螢幕的電腦設定流程
accDescr: 選擇電腦，安裝背景用戶端，然後在面板確認線路已經在運作。
A[選擇電腦] --&gt; B{哪個系統}
B -- macOS --&gt; C[先裝應用程式，再執行一條指令]
B -- Windows --&gt; D[解壓縮下載包，再執行一條指令]
C --&gt; E[確認顯示 running]
D --&gt; E
E --&gt; F[在面板看到裝置在線]</pre>
<p>整件事就是這張圖：選系統、執行一條指令、確認結果。之後的一切都在背景進行。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install.png" width="1440" height="960" alt="用戶端頁面，顯示下載入口以及這台機器的 Client ID 與 Agent Token">
  <figcaption>Client ID 與 Agent Token 就在這個頁面上，旁邊的下載依你的系統選擇。</figcaption>
</figure>
<ol>
  <li><strong>挑選電腦。</strong>用一台長期開機的機器，不需要螢幕、不需要鍵盤，也不需要有人登入。</li>
  <li><strong>先安裝應用程式。</strong>macOS：打開下載的磁碟映像，把 <strong>LeiKwan Bridge</strong> 拖進 <strong>應用程式</strong>。Windows：把無介面版下載包解壓縮到一個資料夾。</li>
  <li><strong>執行一次安裝指令。</strong>macOS：打開「終端機」，貼上用戶端頁面上的 macOS 指令。Windows：在解壓縮出來的資料夾上按右鍵，以系統管理員身分開啟終端機，貼上頁面上的 Windows 指令。兩條指令裡都已經帶了這台機器的身份。</li>
  <li><strong>確認已經啟動。</strong>輸入 <code>leikwan status</code>，看到轉發顯示為 running 就對了。</li>
  <li><strong>回到面板看一眼。</strong>這台裝置會像裝了普通用戶端的電腦一樣顯示在線。</li>
  <li><strong>之後只需要三條指令。</strong>查看用 <code>leikwan status</code>，覺得卡住用 <code>leikwan restart</code>，升級用 <code>leikwan update</code>；如果只想看看有沒有新版本，用 <code>leikwan update --check</code>。</li>
  <li><strong>需要移除時：</strong>執行同一個用戶端頁面上的移除指令，或先用 <code>leikwan stop</code> 暫時關掉它。</li>
</ol>

<h2>如何確認成功</h2>
<p>兩個簡單檢查。面板裡這台電腦顯示在線，和其他裝置一樣；在電腦上執行 <code>leikwan status</code>，線路顯示為 running，而且指令以成功狀態結束，所以監控工具也可以直接用它。想看細節時，<code>leikwan logs -n 50</code> 顯示最近 50 行日誌，<code>leikwan doctor</code> 一次列清整體狀態。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止把 Agent Token 發給任何人 —— 聊天、工單、截圖都不行。</strong>最常見的原因是 Agent Token 抄錯或已失效：面板會顯示裝置離線，日誌裡會寫著登入被拒絕。回到用戶端頁面重新複製 Client ID 與 Agent Token，再執行一次安裝指令即可。如果面板顯示裝置在線、但某條轉發仍是停止狀態，等半分鐘 —— 轉發由面板決定，電腦會在幾秒內跟上。仍然不通，就把 <code>leikwan doctor</code> 的輸出傳給客服，那份內容不含任何機密。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安裝用戶端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線與驗證</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">常見問題排查</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'no-gui-clients'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );
